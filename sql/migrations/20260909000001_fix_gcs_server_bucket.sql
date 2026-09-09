-- Migration: Correção do bucket GCS na tabela servers
-- O bucket real é pornozinho-cdn1 (novinhasbr-cdn1 não existe/está errado).
-- Idempotente: só age quando o servidor GCS ainda referencia o bucket antigo.

UPDATE servers
SET url = 'https://storage.googleapis.com/pornozinho-cdn1',
    video_url = 'https://storage.googleapis.com/pornozinho-cdn1',
    gcs_bucket = 'pornozinho-cdn1',
    status = '1'
WHERE server_type = 'gcs'
  AND gcs_bucket = 'novinhasbr-cdn1';