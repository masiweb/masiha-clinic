-- Clinic appointment labels, user-manual overrides, and independent hashtag colors.
-- Seed only absent names; do not overwrite later changes made by the administrator.
ALTER TABLE hashtags ADD COLUMN IF NOT EXISTS color VARCHAR(7) NOT NULL DEFAULT '#0f766e';
CREATE TABLE IF NOT EXISTS session_label_modes(
  session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  selection_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  booking_source ENUM('scheduled','walkin') NOT NULL DEFAULT 'scheduled',
  explanation VARCHAR(255) NOT NULL DEFAULT '',
  CONSTRAINT fk_slm_session FOREIGN KEY (session_id) REFERENCES physio_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS patient_label_modes(
  pid BIGINT NOT NULL PRIMARY KEY,
  selection_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  CONSTRAINT fk_plm_patient FOREIGN KEY(pid) REFERENCES patients(pid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'ویزیت','#6366F1',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='ویزیت' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'ویزیت درمان','#F97316',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='ویزیت درمان' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'بیمار جدید','#0EA5E9',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='بیمار جدید' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'نرمال','#10B981',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='نرمال' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'حضوری','#F59E0B',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='حضوری' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'ورزش درمانی','#8B5CF6',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='ورزش درمانی' AND deleted=0);
INSERT INTO labels(name,color,active,is_default,deleted)
SELECT 'تمرین پلاس','#EC4899',1,0,0 WHERE NOT EXISTS(SELECT 1 FROM labels WHERE name='تمرین پلاس' AND deleted=0);
