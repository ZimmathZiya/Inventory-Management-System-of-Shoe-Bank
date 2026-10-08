-- ============================================================================
-- Footwear Wholesale ERP — Database Schema (Phase 1)
-- MySQL 8 / MariaDB, InnoDB, utf8mb4
-- Import via phpMyAdmin or:  mysql -u USER -p DBNAME < database/schema.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Roles & Users (Auth / RBAC)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(30)  NOT NULL,           -- 'admin' | 'staff'
    label       VARCHAR(50)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id       TINYINT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    username      VARCHAR(60)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(30)  NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role_id),
    KEY idx_users_deleted (deleted_at),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Reference data
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brands (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80) NOT NULL,
    origin     ENUM('imported','local') NOT NULL DEFAULT 'imported',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brands_name (name),
    KEY idx_brands_origin (origin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(40) NOT NULL,             -- Gents / Ladies / Boys / Girls / Kids
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supplier directory retained for the initial reference data. Import purchases
-- store the invoice supplier name directly, while this table remains available
-- for supplier records and historical seed data.
CREATE TABLE IF NOT EXISTS suppliers (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(150) NOT NULL,
    country        VARCHAR(80) NULL,
    contact_person VARCHAR(100) NULL,
    email          VARCHAR(100) NULL,
    phone          VARCHAR(30) NULL,
    address        TEXT NULL,
    city           VARCHAR(80) NULL,
    notes          TEXT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_name (name),
    KEY idx_suppliers_created_by (created_by),
    CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS size_sets (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id   SMALLINT UNSIGNED NULL,
    label         VARCHAR(20) NOT NULL,          -- '5-9', '6-10', etc.
    default_pairs TINYINT UNSIGNED NOT NULL,     -- suggested pairs per set
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_size_sets_category (category_id),
    CONSTRAINT fk_size_sets_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brand-wide and art-number-prefix discount rules (prefix overrides brand)
CREATE TABLE IF NOT EXISTS discount_rules (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type             ENUM('brand','prefix') NOT NULL,
    brand_id         SMALLINT UNSIGNED NULL,
    art_prefix       VARCHAR(20) NULL,           -- e.g. 'W', 'A'
    discount_percent DECIMAL(5,2) NOT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_discount_brand (brand_id),
    KEY idx_discount_prefix (art_prefix),
    CONSTRAINT fk_discount_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Settings (key/value — editable in the Settings screen)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`       VARCHAR(60) NOT NULL,
    `value`     TEXT NULL,
    `type`      ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
    `group`     VARCHAR(40) NOT NULL DEFAULT 'general',
    label       VARCHAR(120) NULL,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Products (Imported / Local / Custom) + media + history
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type               ENUM('imported','local','custom') NOT NULL DEFAULT 'imported',
    brand_id           SMALLINT UNSIGNED NULL,
    art_no             VARCHAR(60) NULL,
    name               VARCHAR(150) NULL,
    category_id        SMALLINT UNSIGNED NULL,
    size_set_id        SMALLINT UNSIGNED NULL,
    pairs_in_set       TINYINT UNSIGNED NULL,
    set_weight_grams   INT UNSIGNED NULL,
    -- Imported cost inputs / outputs (null for local & custom)
    indian_price       DECIMAL(12,2) NULL,
    discount_percent   DECIMAL(5,2)  NULL,
    lkr_rate_used      DECIMAL(8,4)  NULL,
    clearance_rate_used DECIMAL(12,2) NULL,
    final_cost         DECIMAL(12,2) NULL,
    -- Selling prices (optional for all types)
    wholesale_price    DECIMAL(12,2) NULL,
    retail_price       DECIMAL(12,2) NULL,
    -- Inventory (tracked by SET)
    stock_sets         INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    barcode            VARCHAR(64) NULL,
    notes              TEXT NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_products_type (type),
    KEY idx_products_brand (brand_id),
    KEY idx_products_category (category_id),
    KEY idx_products_art (art_no),
    KEY idx_products_deleted (deleted_at),
    KEY idx_products_stock (stock_sets),
    UNIQUE KEY uq_product_brand_article (brand_id, art_no),
    CONSTRAINT fk_products_brand    FOREIGN KEY (brand_id)    REFERENCES brands (id)     ON DELETE SET NULL,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_products_sizeset  FOREIGN KEY (size_set_id) REFERENCES size_sets (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_images (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    INT UNSIGNED NOT NULL,
    path          VARCHAR(255) NOT NULL,         -- stored original (relative to /storage/uploads)
    thumb_path    VARCHAR(255) NULL,
    original_name VARCHAR(255) NULL,
    colour        VARCHAR(40) NULL,
    is_main       TINYINT(1) NOT NULL DEFAULT 0,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pimg_product (product_id),
    CONSTRAINT fk_pimg_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Price-change history (append-only snapshot)
CREATE TABLE IF NOT EXISTS product_prices (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  INT UNSIGNED NOT NULL,
    price_type  ENUM('final_cost','wholesale','retail') NOT NULL,
    old_value   DECIMAL(12,2) NULL,
    new_value   DECIMAL(12,2) NULL,
    changed_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pprice_product (product_id),
    CONSTRAINT fk_pprice_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock movement log
CREATE TABLE IF NOT EXISTS stock_history (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    INT UNSIGNED NOT NULL,
    change_qty    INT NOT NULL,                  -- +in / -out (sets)
    balance_after INT NOT NULL,
    reason        VARCHAR(60) NOT NULL,          -- 'manual_in','manual_out','adjustment','sale','arrival'
    ref_type      VARCHAR(40) NULL,
    ref_id        INT UNSIGNED NULL,
    note          VARCHAR(255) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_product (product_id),
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flexible article transactions keep incomplete/manual articles out of Product Master.
CREATE TABLE IF NOT EXISTS article_transactions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_no  VARCHAR(30) NOT NULL,
    transaction_type ENUM('purchase','customer_return','purchase_return','stock_in','stock_out','return_in','damage','loss','adjustment') NOT NULL,
    customer_id     INT UNSIGNED NULL,
    treatment       ENUM('customer_credit','outstanding_reduction','refund','replacement','stock_only') NULL,
    return_reason   VARCHAR(40) NULL,
    item_condition  VARCHAR(40) NULL,
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax             DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    grand_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_before  DECIMAL(12,2) NULL,
    balance_after   DECIMAL(12,2) NULL,
    reference       VARCHAR(100) NULL,
    notes           TEXT NULL,
    status          ENUM('completed','reversed') NOT NULL DEFAULT 'completed',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_article_transaction_no (transaction_no),
    KEY idx_article_transaction_customer (customer_id),
    KEY idx_article_transaction_type (transaction_type),
    CONSTRAINT fk_article_transaction_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_transaction_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id  INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NULL,
    article_no      VARCHAR(60) NOT NULL,
    brand_name      VARCHAR(80) NULL,
    colour          VARCHAR(60) NULL,
    size_set_label  VARCHAR(30) NULL,
    quantity        INT UNSIGNED NOT NULL,
    unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total      DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_delta     INT NOT NULL DEFAULT 0,
    previous_stock  INT NULL,
    new_stock       INT NULL,
    notes           VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_article_item_transaction (transaction_id),
    KEY idx_article_item_product (product_id),
    KEY idx_article_item_number (article_no),
    CONSTRAINT fk_article_item_transaction FOREIGN KEY (transaction_id) REFERENCES article_transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_article_item_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Audit trail
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id   INT UNSIGNED NULL,
    meta        JSON NULL,
    ip          VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_user (user_id),
    KEY idx_activity_entity (entity_type, entity_id),
    KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Phase 2: Customers, Payments, Cheques, Ledger, Intelligence
-- ============================================================================

CREATE TABLE IF NOT EXISTS customers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name              VARCHAR(100) NOT NULL,
    phone             VARCHAR(30) NULL,
    email             VARCHAR(100) NULL,
    address           TEXT NULL,
    city              VARCHAR(50) NULL,
    region            VARCHAR(50) NULL,
    customer_type     ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
    credit_limit      DECIMAL(12,2) NOT NULL DEFAULT 0,
    credit_period_days SMALLINT UNSIGNED NULL,        -- NULL = shop default setting
    outstanding_due   DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes             TEXT NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_customers_type (customer_type),
    KEY idx_customers_deleted (deleted_at),
    CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Added here because article_transactions is declared with the inventory tables,
-- before the customer table exists during a fresh schema import. The conditional
-- DDL keeps repeated container startups safe.
SET @article_customer_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'article_transactions'
      AND CONSTRAINT_NAME = 'fk_article_transaction_customer'
);
SET @article_customer_fk_sql := IF(
    @article_customer_fk_exists = 0,
    'ALTER TABLE article_transactions ADD CONSTRAINT fk_article_transaction_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE article_customer_fk_stmt FROM @article_customer_fk_sql;
EXECUTE article_customer_fk_stmt;
DEALLOCATE PREPARE article_customer_fk_stmt;

CREATE TABLE IF NOT EXISTS payments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id       INT UNSIGNED NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    payment_date      DATE NULL,
    payment_method    ENUM('cash','bank_transfer','cheque','card') NOT NULL,
    reference         VARCHAR(100) NULL,
    notes             TEXT NULL,
    recorded_by       INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payments_customer (customer_id),
    KEY idx_payments_method (payment_method),
    KEY idx_payments_created (created_at),
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cheques (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id        INT UNSIGNED NOT NULL,
    cheque_number     VARCHAR(30) NOT NULL,
    bank_name         VARCHAR(80) NULL,
    cheque_date       DATE NOT NULL,                 -- date written on the cheque
    deposit_date      DATE NULL,                     -- when the owner plans to bank it
    deposited_at      DATETIME NULL,                 -- when it was actually banked
    amount            DECIMAL(12,2) NOT NULL,
    status            ENUM('pending','deposited','cleared','bounced','cancelled') NOT NULL DEFAULT 'pending',
    bounce_reason     VARCHAR(255) NULL,
    image_path        VARCHAR(255) NULL,
    thumb_path        VARCHAR(255) NULL,
    status_updated_at DATETIME NULL,
    status_updated_by INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cheques_number (cheque_number),
    KEY idx_cheques_status (status),
    KEY idx_cheques_date (cheque_date),
    CONSTRAINT fk_cheques_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE,
    CONSTRAINT fk_cheques_status_by FOREIGN KEY (status_updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ledger: append-only transaction log per customer with running balance
CREATE TABLE IF NOT EXISTS customer_transactions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id       INT UNSIGNED NOT NULL,
    transaction_type  ENUM('sale','payment','credit_memo','adjustment','opening_balance') NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    running_balance   DECIMAL(12,2) NOT NULL,
    transaction_date  DATE NULL,
    reference_type    VARCHAR(40) NULL,
    reference_id      INT UNSIGNED NULL,
    bill_number       VARCHAR(60) NULL,
    image_path        VARCHAR(255) NULL,
    thumb_path        VARCHAR(255) NULL,
    due_date          DATE NULL,
    description       VARCHAR(255) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cust_trans_customer (customer_id),
    KEY idx_cust_trans_type (transaction_type),
    KEY idx_cust_trans_created (created_at),
    CONSTRAINT fk_cust_trans_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_cust_trans_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customer intelligence: classification & metrics
CREATE TABLE IF NOT EXISTS customer_intelligence (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id           INT UNSIGNED NOT NULL UNIQUE,
    classification        ENUM('vip','regular','at_risk','dormant','prospect') NOT NULL DEFAULT 'regular',
    lifetime_value        DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_purchases       INT NOT NULL DEFAULT 0,
    total_paid            DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_credit_sales    DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_cash_sales      DECIMAL(14,2) NOT NULL DEFAULT 0,
    average_order_value   DECIMAL(12,2) NOT NULL DEFAULT 0,
    last_purchase_date    DATE NULL,
    last_payment_date     DATE NULL,
    days_since_purchase   INT NULL,
    purchase_frequency    INT NULL,
    avg_payment_days      DECIMAL(6,1) NULL,          -- mean days from sale to settlement
    on_time_rate          DECIMAL(5,2) NULL,          -- % of credit sales settled in time
    payment_behaviour     ENUM('reliable','slow','defaulter','unknown') NOT NULL DEFAULT 'unknown',
    overdue_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    overdue_days          INT NULL,
    oldest_unpaid_date    DATE NULL,
    credit_utilization    DECIMAL(5,2) NOT NULL DEFAULT 0,
    computed_at           DATETIME NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cust_intel_classification (classification),
    KEY idx_cust_intel_updated (updated_at),
    CONSTRAINT fk_cust_intel_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Module 5: Import Purchase, Clearance & Goods Arrival Management
--
-- Lifecycle: purchase -> invoice upload -> clearance assignment -> in transit
--            -> arrival -> parcel verification -> quantity verification
--            -> confirm -> inventory update
-- ============================================================================

-- A single import purchase, sourced from one supplier invoice.
CREATE TABLE IF NOT EXISTS purchases (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_number       VARCHAR(30) NOT NULL,          -- auto: PUR-2026-000001
    -- Imports arrive in INR through a clearance agent; local buys are already in
    -- LKR and go straight to the shelf. One history, two shapes.
    source                ENUM('import','local') NOT NULL DEFAULT 'import',
    supplier_name         VARCHAR(150) NOT NULL,
    supplier_invoice_no   VARCHAR(60) NULL,
    invoice_date          DATE NULL,
    purchase_date         DATE NOT NULL,
    invoice_type          ENUM('pdf','image','handwritten','manual') NOT NULL DEFAULT 'manual',
    expected_arrival_date DATE NULL,
    total_invoice_value   DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency              ENUM('INR','LKR') NOT NULL DEFAULT 'INR',
    total_weight_kg       DECIMAL(10,2) NOT NULL DEFAULT 0,
    expected_parcels      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes                 TEXT NULL,
    status                ENUM('draft','awaiting_clearance','assigned','in_transit',
                               'arrived','verification_pending','completed')
                          NOT NULL DEFAULT 'draft',
    extraction_raw        JSON NULL,                     -- what the extractor returned
    extraction_confirmed  TINYINT(1) NOT NULL DEFAULT 0, -- owner verified extracted data
    -- Landed costing: the rate inputs are snapshotted so a past costing stays
    -- explainable after the settings change.
    costed_at             DATETIME NULL,
    lkr_rate_used         DECIMAL(8,4) NULL,
    clearance_rate_used   DECIMAL(12,2) NULL,
    handling_charge_used  DECIMAL(12,2) NULL,
    rounding_step_used    SMALLINT NULL,
    created_by            INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_number (purchase_number),
    UNIQUE KEY uq_purchase_supplier_invoice (source, supplier_name, supplier_invoice_no),
    KEY idx_purchase_status (status),
    KEY idx_purchase_supplier (supplier_name),
    KEY idx_purchase_date (purchase_date),
    CONSTRAINT fk_purchase_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Line items from the supplier invoice. brand_name/size_set_label keep the raw
-- invoice text; brand_id/product_id are filled once mapped to our catalogue.
CREATE TABLE IF NOT EXISTS purchase_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id     INT UNSIGNED NOT NULL,
    brand_id        SMALLINT UNSIGNED NULL,
    brand_name      VARCHAR(80) NULL,              -- as written on the invoice
    art_no          VARCHAR(60) NULL,
    colour          VARCHAR(60) NULL,
    category_id     SMALLINT UNSIGNED NULL,
    size_set_id     SMALLINT UNSIGNED NULL,
    size_set_label  VARCHAR(30) NULL,              -- '5x8', '6-10', ...
    pairs_per_set   TINYINT UNSIGNED NULL,
    set_weight_grams INT UNSIGNED NULL,            -- recorded when the shipment is costed
    quantity_sets   INT NOT NULL DEFAULT 0,
    quantity_pairs  INT NOT NULL DEFAULT 0,
    unit_price      DECIMAL(12,2) NULL,
    landed_cost_per_pair DECIMAL(12,2) NULL,       -- result of the costing run
    line_total      DECIMAL(14,2) NULL,
    product_id      INT UNSIGNED NULL,             -- mapped inventory product
    -- Result of the art-no lookup during verification:
    --   matched   = an existing product was found and reused
    --   new       = no match; a product will be created on confirmation
    --   unmatched = needs the owner to decide
    match_status    ENUM('matched','new','unmatched') NOT NULL DEFAULT 'unmatched',
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pitem_purchase (purchase_id),
    KEY idx_pitem_brand (brand_id),
    KEY idx_pitem_category (category_id),
    KEY idx_pitem_size_set (size_set_id),
    KEY idx_pitem_product (product_id),
    CONSTRAINT fk_pitem_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_pitem_brand    FOREIGN KEY (brand_id)    REFERENCES brands (id)    ON DELETE SET NULL,
    CONSTRAINT fk_pitem_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_pitem_size_set FOREIGN KEY (size_set_id) REFERENCES size_sets (id) ON DELETE SET NULL,
    CONSTRAINT fk_pitem_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clearance agents: clear goods through customs and deliver them to the shop.
CREATE TABLE IF NOT EXISTS clearance_persons (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,
    phone           VARCHAR(30) NULL,              -- mobile number
    address         TEXT NULL,
    wage_per_kilo   DECIMAL(8,2) NOT NULL DEFAULT 0, -- LKR per kilo cleared
    notes           TEXT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Junction: many clearance persons <-> many purchases.
-- Normal case: one agent, one row. Rare case: several agents split the weight.
CREATE TABLE IF NOT EXISTS purchase_clearance_assignments (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id         INT UNSIGNED NOT NULL,
    clearance_person_id INT UNSIGNED NOT NULL,
    assigned_weight_kg  DECIMAL(10,2) NOT NULL DEFAULT 0,
    parcel_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    assignment_date     DATE NOT NULL,
    rate_per_kg         DECIMAL(8,2) NULL,          -- snapshot of the agent's rate
    clearance_cost      DECIMAL(12,2) NULL,         -- assigned_weight_kg * rate_per_kg
    payment_status      ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    paid_at             DATETIME NULL,
    status              ENUM('assigned','in_transit','delivered','cancelled')
                        NOT NULL DEFAULT 'assigned',
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pca_purchase_person (purchase_id, clearance_person_id),
    KEY idx_pca_purchase (purchase_id),
    KEY idx_pca_person (clearance_person_id),
    KEY idx_pca_status (status),
    CONSTRAINT fk_pca_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_pca_person   FOREIGN KEY (clearance_person_id) REFERENCES clearance_persons (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Physical parcels belonging to a purchase, carried by one assignment.
CREATE TABLE IF NOT EXISTS parcels (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id     INT UNSIGNED NOT NULL,
    assignment_id   INT UNSIGNED NULL,             -- which agent brought it
    parcel_number   VARCHAR(40) NOT NULL,          -- PARCEL-2026-000001
    weight_kg       DECIMAL(10,2) NOT NULL DEFAULT 0,
    carton_count    SMALLINT UNSIGNED NOT NULL DEFAULT 1, -- cartons / bags
    arrived_weight_kg DECIMAL(10,2) NULL,          -- shop's scale reading
    arrival_date    DATE NULL,
    status          ENUM('expected','received','damaged','missing') NOT NULL DEFAULT 'expected',
    remarks         TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parcel_number (parcel_number),
    KEY idx_parcel_purchase (purchase_id),
    KEY idx_parcel_assignment (assignment_id),
    KEY idx_parcel_status (status),
    CONSTRAINT fk_parcel_purchase   FOREIGN KEY (purchase_id)   REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_parcel_assignment FOREIGN KEY (assignment_id) REFERENCES purchase_clearance_assignments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One arrival/verification session per purchase. Inventory is only written
-- when confirmed_at is set AND inventory_updated flips to 1.
CREATE TABLE IF NOT EXISTS goods_arrivals (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id       INT UNSIGNED NOT NULL,
    arrival_date      DATE NOT NULL,
    parcels_expected  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    parcels_received  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    weight_expected_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    weight_received_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    counting_mode     ENUM('final','incremental') NOT NULL DEFAULT 'final',
    partial_receipt   TINYINT(1) NOT NULL DEFAULT 0, -- owner accepted a short delivery
    status            ENUM('pending','parcels_verified','counting','verified','confirmed')
                      NOT NULL DEFAULT 'pending',
    remarks           TEXT NULL,
    verified_by       INT UNSIGNED NULL,
    verified_at       DATETIME NULL,
    confirmed_by      INT UNSIGNED NULL,
    confirmed_at      DATETIME NULL,
    inventory_updated TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arrival_purchase (purchase_id),
    KEY idx_arrival_status (status),
    CONSTRAINT fk_arrival_purchase    FOREIGN KEY (purchase_id)  REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_arrival_verified_by FOREIGN KEY (verified_by)  REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_arrival_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-product expected vs received for an arrival.
CREATE TABLE IF NOT EXISTS arrival_items (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    arrival_id       INT UNSIGNED NOT NULL,
    purchase_item_id INT UNSIGNED NOT NULL,
    product_id       INT UNSIGNED NULL,
    expected_pairs   INT NOT NULL DEFAULT 0,
    received_pairs   INT NOT NULL DEFAULT 0,
    received_sets    INT NULL,                     -- resolved at confirmation
    status           ENUM('pending','matched','shortage','excess') NOT NULL DEFAULT 'pending',
    remarks          VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arrival_item (arrival_id, purchase_item_id),
    KEY idx_aitem_arrival (arrival_id),
    KEY idx_aitem_status (status),
    CONSTRAINT fk_aitem_arrival FOREIGN KEY (arrival_id)       REFERENCES goods_arrivals (id) ON DELETE CASCADE,
    CONSTRAINT fk_aitem_pitem   FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_aitem_product FOREIGN KEY (product_id)       REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incremental counting: one row per count entry, usually one per parcel.
-- arrival_items.received_pairs is the running SUM of these when counting_mode
-- is 'incremental'.
CREATE TABLE IF NOT EXISTS arrival_counts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    arrival_item_id INT UNSIGNED NOT NULL,
    parcel_id       INT UNSIGNED NULL,
    counted_pairs   INT NOT NULL,
    note            VARCHAR(255) NULL,
    counted_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acount_item (arrival_item_id),
    KEY idx_acount_parcel (parcel_id),
    CONSTRAINT fk_acount_item   FOREIGN KEY (arrival_item_id) REFERENCES arrival_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_acount_parcel FOREIGN KEY (parcel_id)       REFERENCES parcels (id) ON DELETE SET NULL,
    CONSTRAINT fk_acount_user   FOREIGN KEY (counted_by)      REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every document tied to a shipment. purchase_id is NULL for a calculation note
-- captured on the fly and attached to a purchase later.
CREATE TABLE IF NOT EXISTS purchase_attachments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id   INT UNSIGNED NULL,
    type          ENUM('supplier_invoice_pdf','invoice_image','handwritten_note',
                       'calculation_note','clearance_doc','parcel_photo',
                       'delivery_receipt','other') NOT NULL DEFAULT 'other',
    path          VARCHAR(255) NOT NULL,
    thumb_path    VARCHAR(255) NULL,
    original_name VARCHAR(255) NULL,
    mime_type     VARCHAR(100) NULL,
    size_bytes    INT UNSIGNED NULL,
    caption       VARCHAR(255) NULL,
    uploaded_by   INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pattach_purchase (purchase_id),
    KEY idx_pattach_type (type),
    CONSTRAINT fk_pattach_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT fk_pattach_user     FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Sales, Expenses & Profitability
--
-- Revenue and the cost of what was sold, plus the operating expenses that sit
-- between gross and net profit. See migration 003 for the reasoning behind the
-- snapshot columns.
-- ============================================================================

-- One invoice. Wholesale or retail, cash or credit.
--
-- total_cost is the landed cost of the goods on this invoice SNAPSHOTTED at the
-- moment of sale — re-costing a shipment must never rewrite the profit on a
-- sale that already happened.
--
-- `costed` is 0 when a line was sold from a product with no landed cost. Those
-- invoices are still revenue but are excluded from profit, because counting
-- their cost as zero would make the shop look more profitable than it is.
CREATE TABLE IF NOT EXISTS sales (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_number    VARCHAR(30) NOT NULL,            -- auto: INV-2026-000001
    customer_id       INT UNSIGNED NULL,               -- NULL = walk-in counter sale
    customer_name     VARCHAR(100) NULL,               -- snapshot / walk-in name
    sale_type         ENUM('wholesale','retail') NOT NULL DEFAULT 'wholesale',
    payment_type      ENUM('cash','credit') NOT NULL DEFAULT 'credit',
    sale_date         DATE NOT NULL,
    due_date          DATE NULL,
    subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_cost        DECIMAL(14,2) NOT NULL DEFAULT 0,
    gross_profit      DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_paid       DECIMAL(14,2) NOT NULL DEFAULT 0,
    costed            TINYINT(1) NOT NULL DEFAULT 1,
    status            ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    notes             TEXT NULL,
    created_by        INT UNSIGNED NULL,
    cancelled_at      DATETIME NULL,
    cancelled_by      INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_invoice_number (invoice_number),
    KEY idx_sales_customer (customer_id),
    KEY idx_sales_date (sale_date),
    KEY idx_sales_status (status),
    KEY idx_sales_payment_type (payment_type),
    KEY idx_sales_due (due_date),
    CONSTRAINT fk_sales_customer     FOREIGN KEY (customer_id)   REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_created_by   FOREIGN KEY (created_by)    REFERENCES users (id)     ON DELETE SET NULL,
    CONSTRAINT fk_sales_cancelled_by FOREIGN KEY (cancelled_by)  REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoice lines. STOCK MOVES IN SETS, MONEY IS PER PAIR — the same split the
-- stock valuation and the cost calculator use.
CREATE TABLE IF NOT EXISTS sale_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id        INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED NULL,
    art_no         VARCHAR(60)  NULL,
    product_name   VARCHAR(150) NULL,
    brand_id       SMALLINT UNSIGNED NULL,
    brand_name     VARCHAR(80)  NULL,
    colour         VARCHAR(60)  NULL,
    sets           INT NOT NULL DEFAULT 0,
    pairs_in_set   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    pairs          INT NOT NULL DEFAULT 0,
    unit_price     DECIMAL(12,2) NOT NULL DEFAULT 0,  -- per pair
    unit_cost      DECIMAL(12,2) NULL,                -- per pair, landed, at sale time
    line_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_cost      DECIMAL(14,2) NULL,
    line_profit    DECIMAL(14,2) NULL,
    sort_order     SMALLINT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sitem_sale (sale_id),
    KEY idx_sitem_product (product_id),
    KEY idx_sitem_brand (brand_id),
    CONSTRAINT fk_sitem_sale    FOREIGN KEY (sale_id)    REFERENCES sales (id)    ON DELETE CASCADE,
    CONSTRAINT fk_sitem_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
    CONSTRAINT fk_sitem_brand   FOREIGN KEY (brand_id)   REFERENCES brands (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(60) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_expense_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operating expenses: everything between gross profit and net profit.
CREATE TABLE IF NOT EXISTS expenses (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    expense_date   DATE NOT NULL,
    category_id    SMALLINT UNSIGNED NULL,
    amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash','bank_transfer','cheque','card','other') NOT NULL DEFAULT 'cash',
    payee          VARCHAR(120) NULL,
    reference      VARCHAR(100) NULL,
    description    VARCHAR(255) NULL,
    reference_type VARCHAR(40) NULL,
    reference_id   INT UNSIGNED NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_expenses_date (expense_date),
    KEY idx_expenses_category (category_id),
    KEY idx_expenses_deleted (deleted_at),
    KEY idx_expenses_ref (reference_type, reference_id),
    CONSTRAINT fk_expenses_category   FOREIGN KEY (category_id) REFERENCES expense_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
CREATE TABLE IF NOT EXISTS product_media (
    path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    mime_type VARCHAR(40) NOT NULL,
    contents MEDIUMBLOB NOT NULL,
    PRIMARY KEY (path),
    CONSTRAINT fk_product_media_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
