-- Warehouse Inventory App - SQLite schema (used for local dev/testing - production target is MySQL)
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NOT NULL,
    role VARCHAR(16) NOT NULL CHECK(role IN ('admin','control','entry')),
    active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(255) NOT NULL,
    row_count INTEGER NOT NULL DEFAULT 0,
    imported_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS part_master (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    part_number VARCHAR(64) NOT NULL UNIQUE,
    unit VARCHAR(32) NOT NULL,
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'NOT_STARTED' CHECK(status IN (
        'NOT_STARTED','IN_PROGRESS','COMPLETED_OK','COMPLETED_CONTROL_REQUIRED',
        'CONTROL_IN_PROGRESS','CONTROLLED')),
    known_in_stock INTEGER NOT NULL DEFAULT 0,
    completed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    completed_at DATETIME NULL,
    controlled_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    controlled_at DATETIME NULL,
    control_observations TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS expected_stock (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NOT NULL REFERENCES import_batches(id) ON DELETE CASCADE,
    address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
    address_code VARCHAR(64) NOT NULL,
    hu VARCHAR(64) NOT NULL,
    part_number VARCHAR(64) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_exp_hu ON expected_stock(hu);
CREATE INDEX IF NOT EXISTS idx_exp_addr ON expected_stock(address_id);

CREATE TABLE IF NOT EXISTS physical_counts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
    hu VARCHAR(64) NULL,
    hu_not_available INTEGER NOT NULL DEFAULT 0,
    part_number VARCHAR(64) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'data_entry' CHECK(source IN ('data_entry','control_added')),
    entered_by INTEGER NOT NULL REFERENCES users(id),
    entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    deleted_by INTEGER NULL,
    deleted_at DATETIME NULL,
    last_edited_by INTEGER NULL,
    last_edited_at DATETIME NULL,
    control_note TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_phys_addr ON physical_counts(address_id);
CREATE INDEX IF NOT EXISTS idx_phys_hu ON physical_counts(hu);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type VARCHAR(64) NOT NULL,
    entity_id INTEGER NULL,
    action VARCHAR(64) NOT NULL,
    actor_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    actor_name VARCHAR(128) NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
