-- ============================================================
-- Base CRM/e-commerce schema. (PostgreSQL)
-- NOTE: docker-compose.yml originally pointed at ./db/init.sql,
-- which was never checked into the repo, so a fresh `docker compose up`
-- could not create products/inventory/customers/prescriptions.
-- This file restores that base schema (inferred from the PHP queries
-- in api.php, add_product_api.php, inventory_api.php, add_prescription_api.php)
-- so the stack boots cleanly. It runs before 001_finance_tables.sql.
-- ============================================================
-- On Render this database (teahub-db) is shared with the TeaHub
-- project. All VisionFlow tables live in a dedicated "visionflow"
-- schema instead of "public" so a same-named table from TeaHub
-- (e.g. users, products, customers) never collides with these.
-- ============================================================

CREATE SCHEMA IF NOT EXISTS visionflow;
SET search_path TO visionflow, public;

CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    shape VARCHAR(50),
    material VARCHAR(50),
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    img VARCHAR(500),
    stock_status VARCHAR(20) DEFAULT 'in_stock'
        CHECK (stock_status IN ('in_stock','low_stock','out_of_stock')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    shape VARCHAR(50),
    material VARCHAR(50),
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    image_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(120) UNIQUE NOT NULL,
    phone VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prescriptions (
    id SERIAL PRIMARY KEY,
    customer_id INT NOT NULL,
    eye_type VARCHAR(10) NOT NULL,   -- 'left' / 'right'
    sphere DECIMAL(5,2),
    cylinder DECIMAL(5,2),
    axis INT,
    pd DECIMAL(5,2),
    exam_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- A handful of demo products/inventory so the catalog isn't empty on first boot
INSERT INTO products (id, name, shape, material, price, img, stock_status) VALUES
    (1, 'Classic Aviator', 'Aviator', 'Metal', 129.99, 'https://via.placeholder.com/300', 'in_stock'),
    (2, 'Round Retro', 'Round', 'Acetate', 89.99, 'https://via.placeholder.com/300', 'in_stock'),
    (3, 'Bold Square', 'Square', 'Titanium', 149.99, 'https://via.placeholder.com/300', 'in_stock'),
    (4, 'Cat-Eye Classic', 'Cat-Eye', 'Acetate', 109.99, 'https://via.placeholder.com/300', 'low_stock')
ON CONFLICT (id) DO NOTHING;

-- Keep the id sequence ahead of the manually-numbered seed rows above,
-- otherwise the next SERIAL-generated insert would collide with id 1-4.
SELECT setval(pg_get_serial_sequence('products', 'id'), (SELECT MAX(id) FROM products));

-- The Inventory Dashboard (inventory.html) reads from `inventory`, a
-- separate table from the public-facing `products` catalog above -- seed it
-- too so that page isn't empty on first boot.
INSERT INTO inventory (id, name, shape, material, price, image_url) VALUES
    (1, 'Classic Aviator', 'Aviator', 'Metal', 129.99, 'https://via.placeholder.com/300'),
    (2, 'Round Retro', 'Round', 'Acetate', 89.99, 'https://via.placeholder.com/300'),
    (3, 'Bold Square', 'Square', 'Titanium', 149.99, 'https://via.placeholder.com/300'),
    (4, 'Cat-Eye Classic', 'Cat-Eye', 'Acetate', 109.99, 'https://via.placeholder.com/300')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('inventory', 'id'), (SELECT MAX(id) FROM inventory));

-- ------------------------------------------------------------------
-- Demo patients + prescriptions, so Patient Records (patients.html)
-- shows real rows instead of an empty table on first boot. Each patient
-- gets a right-eye (OD) and left-eye (OS) reading -- 4 patients x 2 specs
-- = 8 prescription rows.
-- ------------------------------------------------------------------
INSERT INTO customers (id, first_name, last_name, email, phone) VALUES
    (1, 'Aiko',   'Tanaka',  'aiko.tanaka@example.com',  '9876543210'),
    (2, 'Wei',    'Chen',    'wei.chen@example.com',     '9876500001'),
    (3, 'Priya',  'Sharma',  'priya.sharma@example.com', '9876500002'),
    (4, 'Marcus', 'Johnson', 'marcus.johnson@example.com','9876500003')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('customers', 'id'), (SELECT MAX(id) FROM customers));

INSERT INTO prescriptions (id, customer_id, eye_type, sphere, cylinder, axis, pd, exam_date) VALUES
    (1, 1, 'OD', -1.25,  -0.50,  90,  31.0, CURRENT_DATE - INTERVAL '12 days'),
    (2, 1, 'OS', -1.50,  -0.75,  85,  31.5, CURRENT_DATE - INTERVAL '12 days'),
    (3, 2, 'OD', -2.75,  -1.00, 100,  32.0, CURRENT_DATE - INTERVAL '30 days'),
    (4, 2, 'OS', -2.50,  -0.75, 110,  32.0, CURRENT_DATE - INTERVAL '30 days'),
    (5, 3, 'OD',  0.75,  -0.25,  70,  30.0, CURRENT_DATE - INTERVAL '3 days'),
    (6, 3, 'OS',  0.50,  -0.25,  75,  30.5, CURRENT_DATE - INTERVAL '3 days'),
    (7, 4, 'OD', -3.50,  -1.25,  60,  33.0, CURRENT_DATE - INTERVAL '60 days'),
    (8, 4, 'OS', -3.25,  -1.00,  65,  33.0, CURRENT_DATE - INTERVAL '60 days')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('prescriptions', 'id'), (SELECT MAX(id) FROM prescriptions));
