CREATE TABLE ai_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    file_inventory_id INT NULL,
    action VARCHAR(100) NOT NULL,
    input_payload JSON NULL,
    output_payload JSON NULL,
    confidence DECIMAL(5,4) NULL,
    status ENUM('ok','error','skipped') NOT NULL DEFAULT 'ok',
    error_message TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ai_audit_project_created (project_id, created_at)
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
