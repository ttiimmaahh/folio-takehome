-- Scheduled publishing: when a document becomes visible to recipients.
-- Stored as a UTC datetime string ('YYYY-MM-DD HH:MM:SS'). NULL = live immediately.
ALTER TABLE documents ADD COLUMN publish_at TEXT;
