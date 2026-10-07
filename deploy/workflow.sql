-- Additive migration: never rewrites appointments, imports, finance or stock.
CREATE TABLE IF NOT EXISTS visit_workflows (
 session_id BIGINT UNSIGNED PRIMARY KEY,
 state VARCHAR(20) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 arrived_at DATETIME NULL,
 called_at DATETIME NULL,
 treatment_started_at DATETIME NULL,
 treatment_finished_at DATETIME NULL,
 departed_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(session_id) REFERENCES physio_sessions(id),
 INDEX(state), INDEX(arrived_at),
 CHECK(state IN ('scheduled','referred','waiting','in_service','visited','discharged','absent','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS visit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 from_state VARCHAR(20) NULL,
 to_state VARCHAR(20) NOT NULL,
 actor_id BIGINT NOT NULL,
 source VARCHAR(20) NOT NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 details JSON NOT NULL,
 FOREIGN KEY(session_id) REFERENCES physio_sessions(id),
 UNIQUE(session_id,version), INDEX(session_id,occurred_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Historical snapshots acknowledge what is known, without inventing arrival times.
INSERT IGNORE INTO visit_workflows(session_id,state)
SELECT id,CASE
 WHEN status='cancelled' THEN 'cancelled' WHEN status='absent' THEN 'absent'
 WHEN status='done' THEN 'visited' WHEN turn_state='in_service' THEN 'in_service'
 WHEN turn_state='waiting' THEN 'waiting' WHEN turn_state='called' THEN 'referred'
 ELSE 'scheduled' END FROM physio_sessions;
INSERT IGNORE INTO visit_events(session_id,version,event_type,to_state,actor_id,source,details)
SELECT session_id,0,'legacy_snapshot',state,0,'migration',
 JSON_OBJECT('timestamps_known',FALSE,'note','Snapshot only; original appointment and import records preserved')
FROM visit_workflows;
