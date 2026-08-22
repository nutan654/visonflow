# VisionFlow

Enterprise-grade optical CRM and AI-driven e-commerce prototype combining patient management, eyewear inventory, prescriptions, and predictive analytics in a containerized microservice architecture.

## Overview

VisionFlow integrates an optical patient management system with an eyewear e-commerce interface. The application uses a PHP-based web service for core CRM and inventory operations and a Python FastAPI service for predictive analytics.

The complete system is containerized using Docker with separate services for the web application, AI API, and PostgreSQL database.

## Features

- Patient and medical record management
- Optical lens prescription management
- Eyewear inventory CRUD operations
- E-commerce product and cart management
- AI-powered trend and predictive analysis
- Interactive analytics dashboards using Chart.js
- REST-based communication between frontend, PHP backend, and Python API
- Japanese and English interface localization
- Docker-based multi-service deployment
- PostgreSQL relational database with initialized schema

### Finance & Operations layer

- **Audit trail** — immutable log of every create/update/delete on inventory and pricing records, with before/after state (`audit-log.html`)
- **Bank reconciliation** — CSV statement upload with an exact/fuzzy matching algorithm (pandas) against recorded payments, flagging unexplained discrepancies (`reconciliation.html`)
- **AR ledger** — invoicing, partial/full payment tracking, and an automated 0–30/31–60/61+ day aging report (`invoices.html`)
- **AP ledger** — vendor and purchase-order tracking, with outstanding payables and PO-based inventory valuation (`vendors.html`)
- **Financial reporting dashboard** — revenue, AR vs AP, inventory valuation, and gross margin, with CSV export (`financial-dashboard.html`)

## Tech Stack

- **Frontend:** HTML, CSS, JavaScript, Chart.js
- **Backend:** PHP 8, Apache
- **AI Service:** Python, FastAPI
- **Database:** PostgreSQL 16
- **API:** REST
- **Containerization:** Docker, Docker Compose
- **Database Access:** PDO
- **Localization:** JavaScript, localStorage

## Architecture

```text
Client
  |
  +--------------------> PHP / Apache
  |                         |
  |                         v
  |                      PostgreSQL
  |
  +--------------------> Python / FastAPI
                            |
                            v
                       Predictive Analytics
```

The Docker Compose configuration runs three services:

- `web` — PHP and Apache application
- `ai-api` — Python FastAPI predictive service
- `db` — PostgreSQL database

## Project Structure

```text
visonflow/
├── index.html
├── admin.html
├── patients.html
├── inventory.html
├── prescriptions.html
├── trends.html
├── cart.html
├── app.js
├── style.css
├── predictor.py
├── api.php
├── login_api.php
├── inventory_api.php
├── add_product_api.php
├── add_prescription_api.php
├── get_prescriptions_api.php
│
├── audit_log.php                  # shared DB connection + log_change() helper
├── get_audit_log_api.php
├── audit-log.html
│
├── add_invoice_api.php
├── record_payment_api.php
├── get_ar_aging_api.php
├── invoices.html
│
├── add_vendor_api.php
├── create_po_api.php
├── vendors.html
│
├── reconciliation.py              # FastAPI router, mounted into predictor.py
├── reconciliation.html
│
├── get_financial_summary_api.php
├── financial-dashboard.html
│
├── migrations/
│   ├── 000_base_schema.sql        # products/inventory/customers/prescriptions
│   └── 001_finance_tables.sql     # audit_log, bank_transactions, invoices,
│                                   # payments, vendors, purchase_orders
│
├── docker-compose.yml
├── Dockerfile.php
├── Dockerfile.python
└── LICENSE
```

## Default login

The web app seeds one admin account the first time `login_api.php` runs:

```
Username: admin
Password: admin
```

**Change this before deploying anywhere public.** Easiest way: log in once, then
update the row directly —

```bash
docker compose exec -T db psql -U postgres -d visionflow_db \
  -c "UPDATE users SET password = '<new_bcrypt_hash>' WHERE username = 'admin';"
```

Generate a bcrypt hash for `<new_bcrypt_hash>` with:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
```

## Running Locally

### Prerequisites

- Docker
- Docker Compose

### Configure database credentials

Copy `.env.example` to `.env` and set a real `DB_PASS` before running anywhere
other than your own machine. Without a `.env` file, `docker-compose.yml` falls
back to a default `postgres` / `postgres` credential pair — fine for local
dev, **not fine for a public server** (the Postgres image, unlike the old
MySQL one, won't start with an empty password at all).

```bash
cp .env.example .env
# edit .env and set DB_PASS to a real password
```

### Start the Application

```bash
git clone https://github.com/nutan654/visonflow.git
cd visonflow
docker compose up --build
```

The services are exposed on:

```text
Web Application: http://localhost
AI API:          http://localhost:8000
PostgreSQL:      localhost:5432
```

To stop the containers:

```bash
docker compose down
```

## Database

VisionFlow uses PostgreSQL 16 with the database:

```text
visionflow_db
```

The schema is loaded automatically on first boot from every `.sql` file in `migrations/`, run in
filename order (`000_base_schema.sql`, then `001_finance_tables.sql`). If the `db_data` volume
already exists from a previous run, Postgres won't re-run init scripts — apply new migrations
manually instead:

```bash
docker compose exec -T db psql -U postgres -d visionflow_db -f /docker-entrypoint-initdb.d/001_finance_tables.sql
```

## Using the finance & operations features

1. **Audit trail** — automatic. Every insert/update/delete through `inventory_api.php` and
   `add_product_api.php` is logged; view it at `/audit-log.html`.
2. **AR / Invoicing** — go to `/invoices.html`, create an invoice, then record a payment against
   it. The AR aging cards update automatically.
3. **AP / Vendors** — go to `/vendors.html`, add a vendor, then raise a purchase order against
   them. Move the PO through `ordered → received → paid` to track outstanding payables.
4. **Bank reconciliation** — go to `/reconciliation.html` and upload a CSV with `date, amount,
   description` columns (see `sample_bank_statement.csv`), then click **Run Matching**. Exact
   matches (same amount, ±1 day) and fuzzy matches (±5 days) are matched against `payments`;
   anything left over is flagged with a reason.
5. **Financial dashboard** — `/financial-dashboard.html` rolls all of the above into revenue,
   AR vs AP, inventory valuation, and gross margin, with CSV export per chart.

## Deploying on Render

Render runs each Dockerfile as a persistent web service, which is the closest
public-hosting match to `docker compose up`. This account's free plan only
allows one Postgres instance, and it's already in use by the TeaHub project
(`teahub-db`), so `render.yaml` does **not** provision its own database.
Instead it points `visionflow-web` and `visionflow-ai-api` at the
existing `teahub-db`, with `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`
wired automatically via `fromDatabase` and `DB_NAME` set to the literal
value `visionflow_db` (double-check this matches teahub-db's actual
database name in the Render dashboard — see the comment block at the top
of `render.yaml` if it doesn't). Because the database is shared,
**all of VisionFlow's tables live in their own `visionflow` Postgres
schema** (not `public`) so they can never collide with TeaHub's tables of
the same name (`users`, `products`, `customers`, `invoices`, `payments`,
`vendors`, ...) — every DB connection in the app sets
`search_path=visionflow,public`.

**The schema and demo data are applied automatically — no manual `psql`
step required.** Every PHP request (via `get_db_connection()` in
`audit_log.php`) and the ai-api's startup both check whether
`visionflow.customers` already exists and, if not, run
`migrations/000_base_schema.sql` and `001_finance_tables.sql`
themselves. Both files are safe to run repeatedly (`CREATE TABLE IF NOT
EXISTS` / `INSERT ... ON CONFLICT DO NOTHING`), so this "just works" the
first time any page or endpoint is hit after deploy, and does nothing on
every request after that. This is what fixes a fresh deploy showing
`relation "customers" does not exist`.

1. **Confirm `teahub-db` already exists** in this Render workspace before
   deploying this blueprint — it is not created for you.
2. **Push this repo to GitHub**, then in the Render dashboard: **New → Blueprint**,
   point it at the repo. Render will provision `visionflow-web` and
   `visionflow-ai-api` from `render.yaml`, wired to `teahub-db`.
3. **Leave `AI_API_BASE` blank** on `visionflow-web` for now — the Blueprint
   will prompt for it since it's `sync: false` (the only field Render can't
   auto-wire).
4. Once both services finish their first deploy, **copy `visionflow-ai-api`'s
   public URL** from its Render dashboard page (looks like
   `https://visionflow-ai-api-xxxx.onrender.com`).
5. Open `visionflow-web` → **Environment**, paste that URL into `AI_API_BASE`,
   and trigger a redeploy. This value gets substituted into
   `reconciliation.html` and `trends.html` at container boot (see
   `docker-entrypoint-apache.sh`) so those two pages can reach the FastAPI
   service across its separate domain.
6. Visit `visionflow-web`'s URL, log in with `admin` / `admin` (see below),
   and every page — Patient Records, Inventory, AR/Invoices, AP/Vendors,
   Reconciliation, Financial Reports, Audit Trail — will already show demo
   data: 4 patients with full OD/OS prescriptions, catalog + inventory
   items, vendors and purchase orders, invoices across every AR aging
   bucket, payments, a couple of bank statement lines, and a starter audit
   trail.

Render's free tier spins services down after inactivity, so the first request
after idling will be slow (10–30s) while it wakes back up — expected, not a bug.
The free Postgres plan also expires after 30 days unless upgraded — fine for
trying this out, not for anything long-lived.

## Why this doesn't run on Vercel

Vercel only runs static files and serverless functions (Node/Python/Go/Ruby) —
it can't run a persistent Apache+PHP process or a long-lived database
container, which is what this app is built on. Deploying there would mean
rewriting the entire PHP backend as serverless functions — a different
project, not a config change. Render (above) is the deploy target that
actually matches this codebase.

## Before deploying publicly

- [ ] Set a real `DB_PASS` in `.env` for local/self-hosted Docker (see above) — Render's managed Postgres already generates its own strong password, so this only applies outside Render.
- [ ] Change the default `admin` / `admin` login (see **Default login** above).
- [ ] Don't expose port `5432` (Postgres) to the public internet on a self-hosted `docker-compose.yml` deploy — only `web` (80) and `ai-api` (8000) need to be reachable. Render's managed Postgres isn't publicly exposed by default.
- [ ] `setup_admin.php` has been removed from this repo (it duplicated the seeding `login_api.php` already does, and was an unauthenticated endpoint). Don't re-add anything similar.

## License

This project is licensed under the MIT License.
