-- Migration: Point GCS server key_path outside the webroot
-- The old value 'include/gcs-service-account.json' pointed at a file inside
-- /var/www/avscms/include/ which would be served over HTTP. The runtime
-- resolves the key from GCS_KEY_PATH/GCS_KEY_JSON env first, so this value is
-- only a fallback — but keep it pointing outside the webroot too.
-- Idempotent.

UPDATE servers
SET gcs_key_path = '/etc/avscms/gcs-service-account.json'
WHERE server_type = 'gcs'
  AND gcs_key_path = 'include/gcs-service-account.json';