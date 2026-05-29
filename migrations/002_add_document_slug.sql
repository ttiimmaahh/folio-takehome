-- Human-readable document IDs. A per-document slug (e.g. 'welcome-packet-3k')
-- that staff can say, type, or paste. Complements the per-recipient share token
-- rather than replacing it: the slug identifies the document, the token still
-- gates access. SQLite can't ADD a UNIQUE column via ALTER, so we enforce
-- uniqueness with a separate index.
ALTER TABLE documents ADD COLUMN slug TEXT;
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);
