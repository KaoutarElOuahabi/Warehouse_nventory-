-- Warehouse Inventory App — MySQL schema
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NOT NULL,
    role ENUM('admin','control','entry') NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    imported_by INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batch_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS part_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_number VARCHAR(64) NOT NULL UNIQUE,
    unit VARCHAR(32) NOT NULL,
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    status ENUM(
        'NOT_STARTED','IN_PROGRESS','COMPLETED_OK','COMPLETED_CONTROL_REQUIRED',
        'CONTROL_IN_PROGRESS','CONTROLLED'
    ) NOT NULL DEFAULT 'NOT_STARTED',
    known_in_stock TINYINT(1) NOT NULL DEFAULT 0,
    completed_by INT NULL,
    completed_at DATETIME NULL,
    controlled_by INT NULL,
    controlled_at DATETIME NULL,
    control_observations TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_addr_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_addr_controlled_by FOREIGN KEY (controlled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expected_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    address_id INT NOT NULL,
    address_code VARCHAR(64) NOT NULL,
    hu VARCHAR(64) NOT NULL,
    part_number VARCHAR(64) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_addr FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE CASCADE,
    INDEX idx_exp_hu (hu),
    INDEX idx_exp_addr (address_id),
    INDEX idx_exp_batch_active (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS physical_counts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    address_id INT NOT NULL,
    hu VARCHAR(64) NULL,
    hu_not_available TINYINT(1) NOT NULL DEFAULT 0,
    part_number VARCHAR(64) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    source ENUM('data_entry','control_added') NOT NULL DEFAULT 'data_entry',
    entered_by INT NOT NULL,
    entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by INT NULL,
    deleted_at DATETIME NULL,
    last_edited_by INT NULL,
    last_edited_at DATETIME NULL,
    control_note TEXT NULL,
    hu_active VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN is_deleted = 0 THEN hu ELSE NULL END) VIRTUAL,
    CONSTRAINT fk_phys_addr FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE CASCADE,
    CONSTRAINT fk_phys_entered_by FOREIGN KEY (entered_by) REFERENCES users(id),
    INDEX idx_phys_addr (address_id),
    INDEX idx_phys_hu (hu),
    UNIQUE KEY uniq_active_addr_hu (address_id, hu_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(64) NOT NULL,
    entity_id INT NULL,
    action VARCHAR(64) NOT NULL,
    actor_id INT NULL,
    actor_name VARCHAR(128) NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, password_hash, full_name, role, active)
VALUES (
    'admin',
    '$2y$12$g/Mg9CEL8ohmKNIoEU9XcOqOJEb4RwhUgKPka25wWszfyzG1ZLB3u',
    'Admin User',
    'admin',
    1
)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    full_name = VALUES(full_name),
    role = VALUES(role),
    active = VALUES(active);

SET FOREIGN_KEY_CHECKS = 1;
