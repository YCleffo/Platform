ALTER TABLE education.thread_members ADD COLUMN last_read_message_id uuid;
ALTER TABLE education.notifications ADD COLUMN message_id uuid REFERENCES education.messages(id);
CREATE INDEX notifications_message ON education.notifications(message_id);
