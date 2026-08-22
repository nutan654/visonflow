"""
Bank reconciliation module for the AI/analytics service.

Endpoints:
  POST /reconcile/upload   - upload a bank statement CSV (date, amount, description)
  POST /reconcile/match    - run the matching algorithm against recorded payments
  GET  /reconcile/summary  - matched / unmatched / flagged counts + total discrepancy
  GET  /reconcile/transactions - list bank_transactions rows (optionally filtered by status)

Matching rules:
  1. Exact match:  same amount, txn_date within +/-1 day of a payment.payment_date -> 'matched'
  2. Fuzzy match:   same amount, txn_date within +/-5 days -> 'matched' with confidence 'fuzzy'
                     (a real product would ask for manual confirmation; here we auto-accept
                     fuzzy matches but tag them so the UI can highlight them for review)
  3. No match:      -> 'flagged', with a human-readable reason
"""

import io
import csv as csv_module
from datetime import date, datetime, timedelta
from typing import Optional

import pandas as pd
from fastapi import APIRouter, UploadFile, File, HTTPException, Query
from pydantic import BaseModel

try:
    import psycopg2
    import psycopg2.extras
except ImportError:  # pragma: no cover
    psycopg2 = None

router = APIRouter(prefix="/reconcile", tags=["reconciliation"])

import os

DB_CONFIG = dict(
    host=os.environ.get("DB_HOST", "db"),
    port=os.environ.get("DB_PORT", "5432"),
    user=os.environ.get("DB_USER", "postgres"),
    password=os.environ.get("DB_PASS", ""),
    dbname=os.environ.get("DB_NAME", "visionflow_db"),
    # teahub-db is shared with the TeaHub project, so VisionFlow's tables
    # live in their own "visionflow" schema instead of "public" to avoid
    # colliding with TeaHub's tables of the same name (users, products,
    # customers, invoices, payments, vendors, ...). "public" stays second
    # in the path as a harmless fallback.
    options="-c search_path=visionflow,public",
)


def get_connection():
    if psycopg2 is None:
        raise HTTPException(status_code=500, detail="psycopg2 is not installed in the ai-api container")
    return psycopg2.connect(cursor_factory=psycopg2.extras.RealDictCursor, **DB_CONFIG)


def ensure_schema():
    """Self-healing schema bootstrap for the ai-api service.

    Mirrors audit_log.php's ensure_schema() on the PHP side: on Render the
    migrations/ SQL files aren't run automatically, so if this service's
    first request arrives before the PHP app has bootstrapped the database
    (e.g. on a cold start), reconciliation/AR endpoints would otherwise hit
    "relation ... does not exist". Both migration files are idempotent
    (CREATE TABLE IF NOT EXISTS / INSERT ... ON CONFLICT DO NOTHING), so
    applying them here is always safe.
    """
    if psycopg2 is None:
        return
    try:
        conn = psycopg2.connect(**{k: v for k, v in DB_CONFIG.items()})
        conn.autocommit = True
        cur = conn.cursor()
        cur.execute("SELECT to_regclass('visionflow.customers')")
        if cur.fetchone()[0]:
            cur.close()
            conn.close()
            return
        base_dir = os.path.dirname(os.path.abspath(__file__))
        for rel in ("migrations/000_base_schema.sql", "migrations/001_finance_tables.sql"):
            path = os.path.join(base_dir, rel)
            if not os.path.exists(path):
                continue
            with open(path, "r", encoding="utf-8") as f:
                sql = f.read()
            try:
                cur.execute(sql)
            except Exception as e:  # noqa: BLE001
                print(f"ensure_schema: failed applying {rel}: {e}")
        cur.close()
        conn.close()
    except Exception as e:  # noqa: BLE001
        print(f"ensure_schema: skipped (could not connect): {e}")


class MatchResult(BaseModel):
    matched: int
    fuzzy_matched: int
    flagged: int
    total_discrepancy: float


@router.post("/upload")
async def upload_statement(file: UploadFile = File(...), batch_id: Optional[str] = None):
    """Accepts a CSV with columns: date, amount, description. Inserts rows as unmatched."""
    if not file.filename.lower().endswith(".csv"):
        raise HTTPException(status_code=400, detail="Please upload a .csv file")

    raw = await file.read()
    try:
        df = pd.read_csv(io.BytesIO(raw))
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Could not parse CSV: {e}")

    df.columns = [c.strip().lower() for c in df.columns]
    required = {"date", "amount", "description"}
    if not required.issubset(set(df.columns)):
        raise HTTPException(status_code=400, detail=f"CSV must contain columns: {', '.join(required)}")

    df["date"] = pd.to_datetime(df["date"], errors="coerce").dt.date
    if df["date"].isna().any():
        raise HTTPException(status_code=400, detail="Some rows have an unparseable date")

    batch = batch_id or f"batch-{datetime.utcnow().strftime('%Y%m%d%H%M%S')}"

    conn = get_connection()
    inserted = 0
    try:
        with conn.cursor() as cur:
            for _, row in df.iterrows():
                cur.execute(
                    """INSERT INTO bank_transactions (txn_date, amount, description, imported_batch_id, status)
                       VALUES (%s, %s, %s, %s, 'unmatched')""",
                    (row["date"], float(row["amount"]), str(row["description"])[:255], batch),
                )
                inserted += 1
        conn.commit()
    finally:
        conn.close()

    return {"status": "success", "batch_id": batch, "rows_inserted": inserted}


@router.post("/match", response_model=MatchResult)
async def run_matching():
    """
    Pulls all unmatched bank_transactions and all payments, matches by amount + date
    proximity using a pandas merge, and updates bank_transactions accordingly.
    """
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id, txn_date, amount, description FROM bank_transactions WHERE status = 'unmatched'")
            bank_rows = cur.fetchall()

            cur.execute("SELECT id, payment_date, amount FROM payments")
            payment_rows = cur.fetchall()
    finally:
        pass

    if not bank_rows:
        conn.close()
        return MatchResult(matched=0, fuzzy_matched=0, flagged=0, total_discrepancy=0.0)

    bank_df = pd.DataFrame(bank_rows)
    pay_df = pd.DataFrame(payment_rows) if payment_rows else pd.DataFrame(columns=["id", "payment_date", "amount"])

    exact_count = 0
    fuzzy_count = 0
    flagged_count = 0
    discrepancy_total = 0.0

    used_payment_ids = set()

    with conn.cursor() as cur:
        for _, txn in bank_df.iterrows():
            txn_amount = float(txn["amount"])
            txn_date = txn["txn_date"]
            if isinstance(txn_date, str):
                txn_date = datetime.strptime(txn_date, "%Y-%m-%d").date()

            candidates = pay_df[
                (pay_df["amount"].astype(float).round(2) == round(txn_amount, 2))
                & (~pay_df["id"].isin(used_payment_ids))
            ].copy()

            best_match_id = None
            confidence = None

            if not candidates.empty:
                candidates["day_diff"] = candidates["payment_date"].apply(
                    lambda d: abs((d if not isinstance(d, str) else datetime.strptime(d, "%Y-%m-%d").date()) - txn_date).days
                )
                exact = candidates[candidates["day_diff"] <= 1]
                fuzzy = candidates[(candidates["day_diff"] > 1) & (candidates["day_diff"] <= 5)]

                if not exact.empty:
                    best_match_id = int(exact.sort_values("day_diff").iloc[0]["id"])
                    confidence = "exact"
                elif not fuzzy.empty:
                    best_match_id = int(fuzzy.sort_values("day_diff").iloc[0]["id"])
                    confidence = "fuzzy"

            if best_match_id is not None:
                used_payment_ids.add(best_match_id)
                cur.execute(
                    """UPDATE bank_transactions
                       SET status = 'matched', matched_payment_id = %s, match_confidence = %s, flag_reason = NULL
                       WHERE id = %s""",
                    (best_match_id, confidence, int(txn["id"])),
                )
                if confidence == "exact":
                    exact_count += 1
                else:
                    fuzzy_count += 1
            else:
                reason = f"No payment record found for Rs.{txn_amount:,.2f} on {txn_date.isoformat()}"
                cur.execute(
                    """UPDATE bank_transactions
                       SET status = 'flagged', flag_reason = %s
                       WHERE id = %s""",
                    (reason, int(txn["id"])),
                )
                flagged_count += 1
                discrepancy_total += txn_amount

    conn.commit()
    conn.close()

    return MatchResult(
        matched=exact_count,
        fuzzy_matched=fuzzy_count,
        flagged=flagged_count,
        total_discrepancy=round(discrepancy_total, 2),
    )


@router.get("/summary")
async def get_summary():
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """SELECT status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total_amount
                   FROM bank_transactions GROUP BY status"""
            )
            rows = cur.fetchall()
    finally:
        conn.close()

    summary = {"matched": {"count": 0, "total_amount": 0.0},
               "unmatched": {"count": 0, "total_amount": 0.0},
               "flagged": {"count": 0, "total_amount": 0.0}}

    for row in rows:
        summary[row["status"]] = {"count": row["count"], "total_amount": float(row["total_amount"])}

    return {"status": "success", "summary": summary,
            "total_discrepancy": summary["flagged"]["total_amount"]}


@router.get("/transactions")
async def list_transactions(status: Optional[str] = Query(None, description="unmatched | matched | flagged")):
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            if status:
                cur.execute(
                    "SELECT * FROM bank_transactions WHERE status = %s ORDER BY txn_date DESC", (status,)
                )
            else:
                cur.execute("SELECT * FROM bank_transactions ORDER BY txn_date DESC")
            rows = cur.fetchall()
    finally:
        conn.close()

    for r in rows:
        if isinstance(r.get("txn_date"), (date, datetime)):
            r["txn_date"] = r["txn_date"].isoformat()
        if isinstance(r.get("created_at"), (date, datetime)):
            r["created_at"] = r["created_at"].isoformat()
        r["amount"] = float(r["amount"])

    return {"status": "success", "data": rows}
