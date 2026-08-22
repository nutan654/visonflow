-- ============================================================
-- Finance & Operations layer (PostgreSQL)
-- Adds: audit logging, bank reconciliation, AR/AP ledger,
--       vendors & purchase orders, on top of the existing
--       VisionFlow CRM/inventory schema.
-- Safe to run multiple times (IF NOT EXISTS guards).
-- ============================================================
-- Run in the "visionflow" schema (see 000_base_schema.sql) so these
-- tables don't collide with any same-named TeaHub tables in "public"
-- on the shared teahub-db instance.
-- ============================================================

CREATE SCHEMA IF NOT EXISTS visionflow;
SET search_path TO visionflow, public;

-- ---------- 1. Audit Trail ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(50) NULL,
    action VARCHAR(50) NOT NULL,          -- 'CREATE', 'UPDATE', 'DELETE'
    table_affected VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_table_record ON audit_log(table_affected, record_id);
CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_log(created_at);

-- ---------- 2. Bank Reconciliation ----------
CREATE TABLE IF NOT EXISTS bank_transactions (
    id SERIAL PRIMARY KEY,
    txn_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255),
    imported_batch_id VARCHAR(50),
    matched_payment_id INT NULL,
    status VARCHAR(20) DEFAULT 'unmatched'
        CHECK (status IN ('unmatched','matched','flagged')),
    match_confidence VARCHAR(20) NULL,     -- 'exact', 'fuzzy', NULL
    flag_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_bank_txn_status ON bank_transactions(status);
CREATE INDEX IF NOT EXISTS idx_bank_txn_date ON bank_transactions(txn_date);

-- ---------- 3. AR/AP Ledger ----------
CREATE TABLE IF NOT EXISTS invoices (
    id SERIAL PRIMARY KEY,
    invoice_number VARCHAR(30) UNIQUE,
    order_id INT NULL,                     -- links to an existing order/cart record if present
    customer_name VARCHAR(120) NULL,
    amount DECIMAL(12,2) NOT NULL,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending'
        CHECK (status IN ('pending','paid','partially_paid','overdue','void')),
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
CREATE INDEX IF NOT EXISTS idx_invoices_due_date ON invoices(due_date);

CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    method VARCHAR(30),                    -- 'card','bank_transfer','cash','upi'
    reference VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id);

-- ---------- 4. Vendors & Purchase Orders (AP) ----------
CREATE TABLE IF NOT EXISTS vendors (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact_email VARCHAR(100),
    contact_phone VARCHAR(30),
    payment_terms_days INT DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id SERIAL PRIMARY KEY,
    po_number VARCHAR(30) UNIQUE,
    vendor_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,    -- free-text link to inventory item name
    quantity INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'ordered'
        CHECK (status IN ('ordered','received','paid','cancelled')),
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    received_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id)
);
CREATE INDEX IF NOT EXISTS idx_po_vendor ON purchase_orders(vendor_id);
CREATE INDEX IF NOT EXISTS idx_po_status ON purchase_orders(status);

-- ---------- Seed data (safe, small) ----------
INSERT INTO vendors (id, name, contact_email, payment_terms_days) VALUES
    (1, 'Lens Craft Supplies', 'orders@lenscraft.example', 30),
    (2, 'Frame Works Co.', 'accounts@frameworks.example', 45)
ON CONFLICT (id) DO NOTHING;

-- Keep sequences ahead of every manually-numbered seed id inserted above,
-- across both this file and 000_base_schema.sql.
SELECT setval(pg_get_serial_sequence('vendors', 'id'), (SELECT MAX(id) FROM vendors));

-- Purchase orders (AP / Vendors page) -- modest, realistic quantities for a
-- small optical shop so outstanding payables/inventory valuation aren't
-- zero, but don't dwarf the retail invoice revenue below.
INSERT INTO purchase_orders (id, po_number, vendor_id, product_name, quantity, unit_cost, status, order_date, expected_date, received_date) VALUES
    (1, 'PO-1001', 1, 'Classic Aviator lens blanks', 5,  42.00, 'received', CURRENT_DATE - INTERVAL '40 days', CURRENT_DATE - INTERVAL '30 days', CURRENT_DATE - INTERVAL '28 days'),
    (2, 'PO-1002', 2, 'Bold Square titanium frames', 4,  65.00, 'paid',     CURRENT_DATE - INTERVAL '35 days', CURRENT_DATE - INTERVAL '25 days', CURRENT_DATE - INTERVAL '24 days'),
    (3, 'PO-1003', 1, 'Round Retro acetate frames', 15,  38.50, 'ordered',  CURRENT_DATE - INTERVAL '5 days',  CURRENT_DATE + INTERVAL '10 days', NULL),
    (4, 'PO-1004', 2, 'Cat-Eye Classic frames',      3,  47.25, 'received', CURRENT_DATE - INTERVAL '15 days', CURRENT_DATE - INTERVAL '5 days', CURRENT_DATE - INTERVAL '4 days')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('purchase_orders', 'id'), (SELECT MAX(id) FROM purchase_orders));

-- Invoices (AR / Invoices page) -- spread across current / 0-30 / 31-60 /
-- 61+ aging buckets, plus several already paid, so the aging report and
-- financial dashboard have something realistic to show on first boot.
INSERT INTO invoices (id, invoice_number, customer_name, amount, tax_amount, status, issue_date, due_date) VALUES
    (1, 'INV-2001', 'Aiko Tanaka',    129.99, 13.00, 'paid',           CURRENT_DATE - INTERVAL '50 days', CURRENT_DATE - INTERVAL '20 days'),
    (2, 'INV-2002', 'Wei Chen',        89.99,  9.00, 'partially_paid', CURRENT_DATE - INTERVAL '20 days', CURRENT_DATE - INTERVAL '5 days'),
    (3, 'INV-2003', 'Priya Sharma',   149.99, 15.00, 'pending',        CURRENT_DATE - INTERVAL '5 days',  CURRENT_DATE + INTERVAL '10 days'),
    (4, 'INV-2004', 'Marcus Johnson', 109.99, 11.00, 'overdue',        CURRENT_DATE - INTERVAL '80 days', CURRENT_DATE - INTERVAL '50 days'),
    (5, 'INV-2005', 'Wei Chen',       129.99, 13.00, 'overdue',        CURRENT_DATE - INTERVAL '95 days', CURRENT_DATE - INTERVAL '65 days'),
    (6, 'INV-2006', 'Priya Sharma',   149.99, 15.00, 'paid',           CURRENT_DATE - INTERVAL '15 days', CURRENT_DATE + INTERVAL '15 days'),
    (7, 'INV-2007', 'Marcus Johnson', 199.99, 20.00, 'paid',           CURRENT_DATE - INTERVAL '10 days', CURRENT_DATE + INTERVAL '20 days'),
    (8, 'INV-2008', 'Aiko Tanaka',     89.99,  9.00, 'paid',           CURRENT_DATE - INTERVAL '3 days',  CURRENT_DATE + INTERVAL '27 days')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('invoices', 'id'), (SELECT MAX(id) FROM invoices));

-- Payments against the invoices above (INV-2001/2006/2007/2008 paid in
-- full, INV-2002 partially paid).
INSERT INTO payments (id, invoice_id, amount, payment_date, method, reference) VALUES
    (1, 1, 142.99, CURRENT_DATE - INTERVAL '45 days', 'card',          'TXN-88213'),
    (2, 2, 50.00,  CURRENT_DATE - INTERVAL '10 days', 'bank_transfer', 'TXN-88420'),
    (3, 6, 164.99, CURRENT_DATE - INTERVAL '12 days', 'card',          'TXN-88512'),
    (4, 7, 219.99, CURRENT_DATE - INTERVAL '8 days',  'upi',           'TXN-88599'),
    (5, 8, 98.99,  CURRENT_DATE - INTERVAL '2 days',  'cash',          'TXN-88650')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('payments', 'id'), (SELECT MAX(id) FROM payments));

-- A couple of bank statement lines (Reconciliation page) -- one that
-- matches the INV-2001 payment above exactly, one left unmatched so the
-- "Run Matching" demo has both a match and a flagged discrepancy to show.
INSERT INTO bank_transactions (id, txn_date, amount, description, imported_batch_id, status) VALUES
    (1, CURRENT_DATE - INTERVAL '45 days', 142.99, 'CARD PAYMENT - A TANAKA', 'seed-demo', 'unmatched'),
    (2, CURRENT_DATE - INTERVAL '7 days',   75.00, 'UNKNOWN DEPOSIT',         'seed-demo', 'unmatched')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('bank_transactions', 'id'), (SELECT MAX(id) FROM bank_transactions));

-- A couple of audit trail entries (Audit Trail page) so it isn't blank
-- before any real edits have been made through the app.
INSERT INTO audit_log (id, user_id, username, action, table_affected, record_id, old_value, new_value, created_at) VALUES
    (1, NULL, 'system', 'CREATE', 'products',  1, NULL, '{"name":"Classic Aviator","price":129.99}', CURRENT_TIMESTAMP - INTERVAL '50 days'),
    (2, NULL, 'system', 'CREATE', 'vendors',   1, NULL, '{"name":"Lens Craft Supplies"}',             CURRENT_TIMESTAMP - INTERVAL '40 days')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('audit_log', 'id'), (SELECT MAX(id) FROM audit_log));
