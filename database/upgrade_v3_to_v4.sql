CREATE TABLE IF NOT EXISTS inventory_classifications (
    file_inventory_id INT NOT NULL,
    axis_id INT NOT NULL,
    value_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (file_inventory_id, axis_id),
    FOREIGN KEY (file_inventory_id) REFERENCES file_inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (axis_id) REFERENCES classification_axes(id) ON DELETE CASCADE
);
