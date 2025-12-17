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

ALTER TABLE ai_audit_logs
    ADD COLUMN revision_id INT NULL AFTER file_inventory_id,
    ADD COLUMN diff_payload JSON NULL AFTER output_payload,
    ADD INDEX idx_ai_audit_revision (revision_id),
    ADD CONSTRAINT fk_ai_audit_revision FOREIGN KEY (revision_id) REFERENCES asset_revisions(id) ON DELETE SET NULL;

ALTER TABLE asset_revisions
    ADD COLUMN ai_diff JSON NULL AFTER notes,
    ADD COLUMN ai_confidence DECIMAL(5,4) NULL AFTER ai_diff;
