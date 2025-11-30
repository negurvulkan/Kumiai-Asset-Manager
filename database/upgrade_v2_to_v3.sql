ALTER TABLE assets
    ADD COLUMN asset_key VARCHAR(255) NOT NULL AFTER name,
    ADD COLUMN display_name VARCHAR(255) NULL AFTER asset_key,
    ADD UNIQUE KEY uniq_asset_key (project_id, asset_key);

CREATE TABLE IF NOT EXISTS asset_classifications (
    asset_id INT NOT NULL,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (asset_id, axis_id),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);
