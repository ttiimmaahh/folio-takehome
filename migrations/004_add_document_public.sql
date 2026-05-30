-- Public visibility. When 1, the document is viewable at /d/{slug} by anyone —
-- no token and no email gate (document status and scheduling still apply).
-- Default 0 (private): sharing stays per-recipient unless explicitly made public.
ALTER TABLE documents ADD COLUMN is_public INTEGER NOT NULL DEFAULT 0;
