CREATE TABLE asset_ai_prepass (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    stage ENUM('SUBJECT_FIRST') NOT NULL,
    model VARCHAR(64) NOT NULL,
    result_json JSON NOT NULL,
    confidence_overall FLOAT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_asset_prepass (asset_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
