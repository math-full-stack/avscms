<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Retorna o próximo servidor ativo na fila por rotação (Round-Robin com base em last_used)
 */
function get_server()
{
    global $conn;

    $sql = "SELECT * FROM servers WHERE status = '1' ORDER BY last_used ASC";
    $rs  = $conn->execute($sql);
    if ($rs && $conn->Affected_Rows() > 0) {
        $servers = $rs->getrows();
        foreach ($servers as $server) {
            if ($server['current_used'] == '1') {
                continue;
            } else {
                return $server;
            }
        }
        return $servers[0];
    }
    return false;
}

/**
 * Marca o servidor como em uso no momento
 */
function update_server_used($server)
{
    global $conn;
    if (isset($server['server_id'])) {
        $conn->execute("UPDATE servers SET current_used = '1' WHERE server_id = " . intval($server['server_id']) . " LIMIT 1");
    }
}

/**
 * Atualiza o timestamp de último uso e libera o status de uso
 */
function update_server($server)
{
    global $conn;
    if (isset($server['server_id'])) {
        $conn->execute("UPDATE servers SET last_used = '" . date('Y-m-d H:i:s') . "', current_used = '0'
                        WHERE server_id = " . intval($server['server_id']) . " LIMIT 1");
    }
}

/**
 * Faz o upload de todos os formatos convertidos de um vídeo (H.264 MP4) para o servidor secundário
 * Suporta FTP e Google Cloud Storage (GCS).
 * @param int $vid
 * @param array|string $formats
 * @param array $server
 * @return bool
 */
function upload_video_formats($vid, $formats, $server)
{
    global $config, $conn;

    if (!$server) {
        return false;
    }

    $serverType = isset($server['server_type']) ? $server['server_type'] : 'ftp';

    if ($serverType === 'gcs') {
        return upload_video_formats_gcs($vid, $formats, $server);
    }

    // Default: FTP
    return upload_video_formats_ftp($vid, $formats, $server);
}

/**
 * Upload via FTP (lógica original)
 */
function upload_video_formats_ftp($vid, $formats, $server)
{
    global $config, $conn;

    if (empty($server['server_ip']) || empty($server['ftp_username'])) {
        return false;
    }

    $ip       = $server['server_ip'];
    $username = $server['ftp_username'];
    $password = $server['ftp_password'];
    $ftpRoot  = rtrim($server['ftp_root'], '/');

    if (!function_exists('ftp_connect')) {
        echo "\n[Multi-Server] Extensão PHP FTP não encontrada.\n";
        return false;
    }

    $connId = @ftp_connect($ip, 21, 30);
    if (!$connId) {
        echo "\n[Multi-Server] Falha ao conectar ao FTP: " . $ip . "\n";
        return false;
    }

    $login = @ftp_login($connId, $username, $password);
    if (!$login) {
        echo "\n[Multi-Server] Falha no login FTP para o usuário: " . $username . "\n";
        @ftp_close($connId);
        return false;
    }

    @ftp_pasv($connId, true);

    if (!empty($ftpRoot)) {
        if (!@ftp_chdir($connId, $ftpRoot)) {
            @ftp_mkdir($connId, $ftpRoot);
            @ftp_chdir($connId, $ftpRoot);
        }
    }

    if (!@ftp_chdir($connId, 'h264')) {
        @ftp_mkdir($connId, 'h264');
        @ftp_chdir($connId, 'h264');
    }

    if (is_string($formats)) {
        $formats = explode(',', $formats);
    }

    $h264Dir = isset($config['H264_DIR']) ? $config['H264_DIR'] : $config['BASE_DIR'] . '/media/videos/h264';
    $success = true;

    foreach ($formats as $fmt) {
        $fmt = trim($fmt);
        if (empty($fmt)) continue;

        $parts = explode('.', $fmt);
        if (count($parts) >= 3) {
            $filename = $vid . '_' . $parts[1] . '.' . $parts[2];
        } else {
            $filename = $vid . '_' . $fmt;
        }

        $localFile = $h264Dir . '/' . $filename;
        if (file_exists($localFile)) {
            echo "\n[Multi-Server] Enviando arquivo: " . $filename . " (" . round(filesize($localFile) / 1024 / 1024, 2) . " MB) para " . $ip . "...";
            @ftp_delete($connId, $filename);
            $uploadOk = @ftp_put($connId, $filename, $localFile, FTP_BINARY);
            if ($uploadOk) {
                echo " [OK]\n";
            } else {
                echo " [FALHA]\n";
                $success = false;
            }
        }
    }

    @ftp_close($connId);

    if ($success) {
        $videoUrl = rtrim($server['video_url'], '/');
        $conn->execute("UPDATE video SET server = " . $conn->qStr($videoUrl) . " WHERE VID = " . intval($vid) . " LIMIT 1");
        update_server($server);
        echo "\n[Multi-Server] Vídeo ID " . $vid . " vinculado ao servidor: " . $videoUrl . "\n";
        return true;
    }

    return false;
}

/**
 * Upload via Google Cloud Storage
 */
function upload_video_formats_gcs($vid, $formats, $server)
{
    global $config, $conn;

    $keyPath = $server['gcs_key_path'];
    $bucket  = $server['gcs_bucket'];

    if (empty($keyPath) || empty($bucket)) {
        echo "\n[Multi-Server-GCS] Configuração incompleta: key_path ou bucket não definidos.\n";
        return false;
    }

    // Resolver caminho absoluto da chave
    if (!file_exists($keyPath)) {
        // Tentar relativo ao BASE_DIR
        $keyPathRelative = $config['BASE_DIR'] . '/' . $keyPath;
        if (file_exists($keyPathRelative)) {
            $keyPath = $keyPathRelative;
        } else {
            echo "\n[Multi-Server-GCS] Arquivo de chave não encontrado: " . $keyPath . "\n";
            return false;
        }
    }

    require_once $config['BASE_DIR'] . '/classes/gcs.class.php';

    $gcs = new GCS($keyPath, $bucket);

    if (is_string($formats)) {
        $formats = explode(',', $formats);
    }

    $h264Dir = isset($config['H264_DIR']) ? $config['H264_DIR'] : $config['BASE_DIR'] . '/media/videos/h264';
    $success = true;

    foreach ($formats as $fmt) {
        $fmt = trim($fmt);
        if (empty($fmt)) continue;

        $parts = explode('.', $fmt);
        if (count($parts) >= 3) {
            $filename = $vid . '_' . $parts[1] . '.' . $parts[2];
            $label    = $parts[1] . '.' . $parts[2];
        } else {
            $filename = $vid . '_' . $fmt;
            $label    = $fmt;
        }

        $localFile = $h264Dir . '/' . $filename;
        if (file_exists($localFile)) {
            $fileSizeMB = round(filesize($localFile) / 1024 / 1024, 2);

            // Organização do bucket: uma pasta por vídeo -> h264/{VID}/{label}.{ext}
            // (ex.: h264/88/720p.mp4). O nome local permanece plano ({VID}_{label}.mp4).
            $object = 'h264/' . $vid . '/' . $label;
            echo "\n[Multi-Server-GCS] Enviando: " . $filename . " (" . $fileSizeMB . " MB) para gs://" . $bucket . "/" . $object;

            // Objeto PRIVADO: o player acessa via V4 Signed URLs (expirantes),
            // nunca por URL pública adivinhável.
            $gsUri = $gcs->upload($localFile, $object, 'video/mp4', array(
                'cacheControl' => 'private, max-age=0, no-store'
            ));

            if ($gsUri !== false) {
                echo " [OK] -> " . $gsUri . "\n";
                // Remove arquivo local após upload bem-sucedido
                if (isset($config['del_original_video']) && $config['del_original_video'] == '1') {
                    @unlink($localFile);
                }
            } else {
                echo " [FALHA] " . $gcs->getError() . "\n";
                $success = false;
            }
        }
    }

    if ($success) {
        // A video_url do servidor GCS deve ser a URL pública do bucket
        // ex: https://storage.googleapis.com/novinhasbr-cdn1
        $videoUrl = rtrim($server['video_url'], '/');
        $conn->execute("UPDATE video SET server = " . $conn->qStr($videoUrl) . " WHERE VID = " . intval($vid) . " LIMIT 1");
        update_server($server);
        echo "\n[Multi-Server-GCS] Vídeo ID " . $vid . " vinculado ao bucket: gs://" . $bucket . "\n";
        return true;
    }

    return false;
}
?>
