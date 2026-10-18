-- Encrypted provider configuration and minimal rate limiting. No patient content is persisted.
CREATE TABLE IF NOT EXISTS ai_provider_settings(
 provider VARCHAR(20) PRIMARY KEY,
 model VARCHAR(100) NOT NULL,
 api_key_encrypted TEXT NULL,
 enabled TINYINT NOT NULL DEFAULT 0,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_use_limits(
 staff_id BIGINT NOT NULL PRIMARY KEY,
 window_start INT UNSIGNED NOT NULL,
 attempts INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
