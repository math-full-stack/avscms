-- Migration: Insert GCS server in production
-- Idempotent: only inserts if no GCS server exists yet

INSERT INTO servers (url, video_url, server_type, gcs_bucket, gcs_key_path, gcs_signed_ttl, current_used, status)
SELECT
    'https://storage.googleapis.com/novinhasbr-cdn1',
    'https://storage.googleapis.com/novinhasbr-cdn1',
    'gcs',
    'novinhasbr-cdn1',
    'include/gcs-service-account.json',
    21600,
    '0',
    '1'
WHERE NOT EXISTS (
    SELECT 1 FROM servers WHERE server_type = 'gcs' LIMIT 1
);
