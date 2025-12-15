CREATE TABLE entity_profile_pictures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
);
