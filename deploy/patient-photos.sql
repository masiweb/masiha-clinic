-- A single private portrait per patient. No changes to clinical or financial fields.
CREATE TABLE IF NOT EXISTS patient_profile_photos(
 pid BIGINT NOT NULL PRIMARY KEY,
 storage_name VARCHAR(96) NOT NULL,
 mime VARCHAR(32) NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_patient_profile_photos_pid FOREIGN KEY(pid) REFERENCES patients(pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
