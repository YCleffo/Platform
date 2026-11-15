ALTER TABLE device_management.devices ADD COLUMN state_version bigint NOT NULL DEFAULT 0;
ALTER TABLE device_management.devices ADD COLUMN agent_state jsonb NOT NULL DEFAULT '{}';
ALTER TABLE device_management.device_sessions ADD COLUMN evidence jsonb NOT NULL DEFAULT '{}';
ALTER TABLE device_management.device_sessions ADD COLUMN classroom_id uuid;
UPDATE device_management.device_sessions s SET classroom_id=d.classroom_id
    FROM device_management.devices d WHERE d.id=s.device_id;
CREATE INDEX device_sessions_history ON device_management.device_sessions(device_id,created_at DESC,id DESC);
