CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    root_path VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE project_roles (
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner','admin','artist','editor','viewer') NOT NULL,
    PRIMARY KEY (project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE entity_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    type_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT,
    metadata_json JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id) REFERENCES entity_types(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_entity_slug (project_id, slug)
);

CREATE TABLE assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    asset_key VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NULL,
    asset_type VARCHAR(100) NOT NULL,
    primary_entity_id INT NULL,
    description TEXT,
    status ENUM('active','deprecated','archived') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (primary_entity_id) REFERENCES entities(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_asset_key (project_id, asset_key)
);

CREATE TABLE asset_entities (
    asset_id INT NOT NULL,
    entity_id INT NOT NULL,
    PRIMARY KEY (asset_id, entity_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
);

CREATE TABLE asset_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    version INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_hash VARCHAR(255),
    mime_type VARCHAR(255),
    width INT NULL,
    height INT NULL,
    file_size_bytes BIGINT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    review_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    notes TEXT,
    ai_diff JSON NULL,
    ai_confidence DECIMAL(5,4) NULL,
    UNIQUE KEY uniq_revision (asset_id, version),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE file_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_hash VARCHAR(255),
    file_size_bytes BIGINT NULL,
    mime_type VARCHAR(255) NULL,
    asset_revision_id INT NULL,
    classification_state ENUM('unclassified','entity_only','outfit_assigned','pose_assigned','view_assigned','fully_classified') NOT NULL DEFAULT 'unclassified',
    status ENUM('untracked','linked','orphaned','missing') NOT NULL DEFAULT 'untracked',
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_file (project_id, file_path),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_revision_id) REFERENCES asset_revisions(id) ON DELETE SET NULL
);

CREATE TABLE entity_file_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    file_inventory_id INT NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_entity_file (entity_id, file_inventory_id),
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE CASCADE
);

CREATE TABLE classification_axes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    axis_key VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    applies_to ENUM('character','location','scene','chapter','prop','background','item','creature','project_custom') NOT NULL,
    has_predefined_values TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_axis_per_type (axis_key, applies_to)
);

CREATE TABLE classification_axis_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_axis_value (axis_id, value_key),
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);

CREATE TABLE inventory_classifications (
    file_inventory_id INT NOT NULL,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (file_inventory_id, axis_id),
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);

CREATE TABLE revision_classifications (
    revision_id INT NOT NULL,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (revision_id, axis_id),
    FOREIGN KEY (revision_id) REFERENCES asset_revisions(id) ON DELETE CASCADE,
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);

CREATE TABLE asset_classifications (
    asset_id INT NOT NULL,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (asset_id, axis_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);

CREATE TABLE entity_infos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
);

CREATE TABLE ai_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    file_inventory_id INT NULL,
    revision_id INT NULL,
    action VARCHAR(100) NOT NULL,
    input_payload JSON NULL,
    output_payload JSON NULL,
    diff_payload JSON NULL,
    confidence DECIMAL(5,4) NULL,
    status ENUM('ok','error','skipped') NOT NULL DEFAULT 'ok',
    error_message TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (revision_id) REFERENCES asset_revisions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ai_audit_project_created (project_id, created_at),
    INDEX idx_ai_audit_revision (revision_id)
);

CREATE TABLE ai_review_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    file_inventory_id INT NOT NULL,
    status ENUM('pending','auto_assigned','needs_review') NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) NULL,
    suggested_assignment JSON NULL,
    confidence DECIMAL(5,4) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_review_file (file_inventory_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_review_project_status (project_id, status)
);

CREATE TABLE ai_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    asset_id INT NULL,
    entity_id INT NULL,
    created_by INT NULL,
    job_type VARCHAR(100) NOT NULL,
    status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ai_jobs_project_status (project_id, status),
    INDEX idx_ai_jobs_needs_review (needs_review),
    INDEX idx_ai_jobs_asset (asset_id),
    INDEX idx_ai_jobs_entity (entity_id)
);

CREATE TABLE ai_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    asset_revision_id INT NULL,
    executed_by INT NULL,
    status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    input_payload JSON NULL,
    output_payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    FOREIGN KEY (job_id) REFERENCES ai_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_revision_id) REFERENCES asset_revisions(id) ON DELETE SET NULL,
    FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ai_runs_job_status (job_id, status),
    INDEX idx_ai_runs_needs_review (needs_review)
);

CREATE TABLE asset_ai (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    classified_fields JSON NULL,
    embedding_json JSON NULL,
    confidence DECIMAL(5,4) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_asset_ai (asset_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    INDEX idx_asset_ai_confidence (confidence)
);

CREATE TABLE entity_embeddings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    embedding_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_entity_embedding (entity_id),
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    INDEX idx_entity_embeddings_entity (entity_id)
);
