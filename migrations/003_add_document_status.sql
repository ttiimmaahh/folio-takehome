-- Document lifecycle status, for takedown. 'live' = normal; 'disabled' = taken
-- down (treated as not-found to recipients, so it stays obscure). Scheduling is
-- a separate axis (publish_at), so a doc can be live-but-scheduled or disabled.
ALTER TABLE documents ADD COLUMN status TEXT NOT NULL DEFAULT 'live';
