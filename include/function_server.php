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
 * Resolve o cliente GCS para uma linha de servidor.
 *
 * Fonte única do padrão "resolve caminho absoluto da chave + instancia GCS"
 * usado por todos os helpers de upload/delete de mídia abaixo.
 *
 * @param array $server Linha da tabela servers (server_type = 'gcs')
 * @return GCS|false
 */
function gcs_get_client($server)
{
    global $config;

    if (empty($server['gcs_key_path']) || empty($server['gcs_bucket'])) {
        return false;
    }

    $keyPath = $server['gcs_key_path'];

    // Resolver caminho absoluto da chave (relativo ao BASE_DIR quando preciso)
    if (!file_exists($keyPath)) {
        $keyPathRelative = $config['BASE_DIR'] . '/' . $keyPath;
        if (file_exists($keyPathRelative)) {
            $keyPath = $keyPathRelative;
        } else {
            return false;
        }
    }

    require_once $config['BASE_DIR'] . '/classes/gcs.class.php';

    return new GCS($keyPath, $server['gcs_bucket']);
}

/**
 * Upload via Google Cloud Storage
 */
function upload_video_formats_gcs($vid, $formats, $server)
{
    global $config, $conn;

    $bucket = isset($server['gcs_bucket']) ? $server['gcs_bucket'] : '';

    if (empty($server['gcs_key_path']) || empty($bucket)) {
        echo "\n[Multi-Server-GCS] Configuração incompleta: key_path ou bucket não definidos.\n";
        return false;
    }

    $gcs = gcs_get_client($server);
    if (!$gcs) {
        echo "\n[Multi-Server-GCS] Arquivo de chave não encontrado: " . $server['gcs_key_path'] . "\n";
        return false;
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

        // Mídia derivada (thumbs, sprite, miniclips) acompanha o vídeo no bucket.
        upload_video_thumbs_gcs($vid, $server);

        return true;
    }

    return false;
}

/**
 * Envia TODA a mídia derivada de um vídeo (thumbnails, sprite e miniclips de
 * hover) para o bucket GCS, sob o prefixo público thumbs/{VID}/.
 *
 * Os objetos ficam publicRead — mesma exposição que os thumbs locais de hoje
 * (media/videos/tmb*), permitindo cache em browser/CDN e o hover-preview com
 * URLs simples. Os vídeos principais continuam privados (V4 signed URLs).
 *
 * Idempotente: pode rodar quantas vezes for preciso (upload substitui o objeto).
 *
 * @param int         $vid                  ID do vídeo (VID)
 * @param array       $server               Linha da tabela servers (server_type = 'gcs')
 * @param string|null $onlyFile             Sincroniza apenas um arquivo (ex.: 'sprite.jpg')
 * @param bool        $silent               Suprime o log de progresso (contexto web/JSON)
 * @param bool|null   $deleteLocalOverride  Força remover (true) ou manter (false) a pasta
 *                                          local após o upload; null segue del_original_video
 * @return bool
 */
function upload_video_thumbs_gcs($vid, $server, $onlyFile = null, $silent = false, $deleteLocalOverride = null)
{
    global $config;

    $log = function ($msg) use ($silent) {
        if (!$silent) {
            echo $msg;
        }
    };

    $gcs = gcs_get_client($server);
    if (!$gcs) {
        $log("\n[Multi-Server-GCS] Thumbs: chave ou bucket não resolvidos.\n");
        return false;
    }

    require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
    $thumbDir = get_thumb_dir(intval($vid));
    if (!is_dir($thumbDir)) {
        $log("\n[Multi-Server-GCS] Thumbs do vídeo " . intval($vid) . " não encontrados localmente (nada a sincronizar).\n");
        return true;
    }

    // Sprite do timeline preview: quando sincronizamos a pasta inteira e o
    // player Main usa preview, garante o sprite.jpg local ANTES de listar os
    // arquivos — assim ele entra no lote enviado e o tmb/{VID} local pode ser
    // removido logo depois sem perder o preview do player.
    $spriteState = 'disabled';
    if ($onlyFile === null) {
        $spriteState = ensure_video_sprite_local(intval($vid));
    }

    // Arquivos a enviar: um único (sprite, por exemplo) ou a pasta inteira.
    $files = array();
    if ($onlyFile !== null) {
        if (file_exists($thumbDir . '/' . $onlyFile) && is_file($thumbDir . '/' . $onlyFile)) {
            $files[] = $onlyFile;
        }
    } else {
        $handle = @opendir($thumbDir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $thumbDir . '/' . $entry;
                if (is_file($full)) {
                    $files[] = $entry;
                }
            }
            closedir($handle);
            sort($files);
        }
    }

    if (empty($files)) {
        $log("\n[Multi-Server-GCS] Nenhum arquivo de mídia para sincronizar no vídeo " . intval($vid) . ".\n");
        return true;
    }

    $vid     = intval($vid);
    $success = true;
    $mimeMap = array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm'
    );

    foreach ($files as $file) {
        $localPath = $thumbDir . '/' . $file;
        $object    = 'thumbs/' . $vid . '/' . $file;
        $ext       = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime      = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';

        $log("\n[Multi-Server-GCS] Thumb: " . $file . " (" . round(filesize($localPath) / 1024, 1) . " KB) -> gs://" . $server['gcs_bucket'] . "/" . $object);

        $gsUri = $gcs->upload($localPath, $object, $mime, array(
            'acl' => 'publicRead',
            // Mídia derivada é imutável por vídeo: cache longo em browser/CDN.
            'cacheControl' => 'public, max-age=604800'
        ));

        if ($gsUri !== false) {
            $log(" [OK]\n");
        } else {
            $log(" [FALHA] " . $gcs->getError() . "\n");
            $success = false;
        }
    }

    // Deleção da pasta local governada pela MESMA config dos vídeos
    // (del_original_video): só depois do upload confirmado (sem falhas) e
    // apenas quando o sprite não é mais necessário localmente (ou já foi
    // garantido acima). Em caso de falha o local permanece como fallback.
    if ($success && $onlyFile === null) {
        $deleteLocal = ($deleteLocalOverride !== null)
            ? $deleteLocalOverride
            : (isset($config['del_original_video']) && $config['del_original_video'] == '1');

        if ($deleteLocal) {
            if ($spriteState === 'failed') {
                $log("\n[Multi-Server-GCS] Thumbs locais mantidos: sprite do timeline preview não pôde ser gerado (vídeo " . $vid . ").\n");
            } elseif (delete_local_video_thumbs($vid)) {
                $log("\n[Multi-Server-GCS] Thumbs locais removidos após upload confirmado (tmb/" . $vid . ").\n");
            } else {
                $log("\n[Multi-Server-GCS] Falha ao remover thumbs locais (tmb/" . $vid . ").\n");
            }
        }
    }

    return $success;
}

/**
 * Busca a linha de servidor vinculada a uma URL de vídeo (video.server), com
 * cache por request — usada pelos helpers de mídia e pelo sync.
 *
 * @param string $videoUrl Valor de video.server (ex.: https://storage.googleapis.com/bucket)
 * @return array|false
 */
function get_server_by_video_url($videoUrl)
{
    global $conn;

    static $cache = array();

    $videoUrl = trim((string) $videoUrl);
    if ($videoUrl === '') {
        return false;
    }

    if (array_key_exists($videoUrl, $cache)) {
        return $cache[$videoUrl];
    }

    $sql = "SELECT * FROM servers WHERE video_url = " . $conn->qStr($videoUrl) . " LIMIT 1";
    $rs  = $conn->execute($sql);
    $cache[$videoUrl] = ($conn->Affected_Rows() == 1) ? $rs->fields : false;

    return $cache[$videoUrl];
}

/**
 * Sincroniza a mídia derivada (thumbs/sprite/miniclips) de um vídeo com o
 * bucket GCS, quando o vídeo estiver vinculado a um servidor GCS.
 *
 * No-op para vídeos locais/FTP. Usada pelos fluxos de regeneração (admin),
 * pelo sprite on-demand e pelo backfill.
 *
 * @param int         $vid      ID do vídeo
 * @param string|null $onlyFile Sincroniza apenas um arquivo (ex.: 'sprite.jpg')
 * @param bool        $silent   Suprime o log de progresso (contexto web/JSON)
 * @return bool
 */
function sync_video_thumbs($vid, $onlyFile = null, $silent = false, $deleteLocalOverride = null)
{
    global $conn;

    $vid = intval($vid);
    if ($vid <= 0) {
        return false;
    }

    $sql = "SELECT server FROM video WHERE VID = " . $vid . " LIMIT 1";
    $rs  = $conn->execute($sql);
    if ($conn->Affected_Rows() != 1 || empty($rs->fields['server'])) {
        return false;
    }

    $server = get_server_by_video_url($rs->fields['server']);
    if (!$server || !isset($server['server_type']) || $server['server_type'] !== 'gcs') {
        return false;
    }

    return upload_video_thumbs_gcs($vid, $server, $onlyFile, $silent, $deleteLocalOverride);
}

/**
 * Indica se um vídeo está vinculado a um servidor GCS (mídia servida do
 * bucket). Usado pelos fluxos de admin/regeneração para não depender da
 * existência de arquivos locais após a limpeza automática (del_original_video).
 *
 * @param int $vid ID do vídeo
 * @return bool
 */
function video_is_on_gcs($vid)
{
    global $conn;

    static $cache = array();

    $vid = intval($vid);
    if (array_key_exists($vid, $cache)) {
        return $cache[$vid];
    }

    $cache[$vid] = false;
    $sql = "SELECT server FROM video WHERE VID = " . $vid . " LIMIT 1";
    $rs  = $conn->execute($sql);
    if ($conn->Affected_Rows() == 1 && !empty($rs->fields['server'])) {
        $server = get_server_by_video_url($rs->fields['server']);
        $cache[$vid] = ($server && isset($server['server_type']) && $server['server_type'] === 'gcs');
    }

    return $cache[$vid];
}

/**
 * Mantém o sprite do timeline preview do bucket em dia (gerado sob demanda na
 * primeira visita ao player). No-op para vídeos locais/FTP.
 *
 * @param int  $vid    ID do vídeo
 * @param bool $silent Suprime o log de progresso (contexto web)
 * @return bool
 */
function sync_video_sprite($vid, $silent = false)
{
    return sync_video_thumbs($vid, 'sprite.jpg', $silent);
}

/**
 * Gera o sprite.jpg local de um vídeo (a partir das thumbs 1..20) quando o
 * player Main usa timeline preview e o sprite está desatualizado ou ausente.
 *
 * Replica a lógica de regeneração do video.php, centralizada aqui para que o
 * pipeline de conversão e o backfill possam garanti-lo ANTES de enviar a pasta
 * ao bucket (e de remover o tmb/{VID} local).
 *
 * @param int $vid ID do vídeo
 * @return string 'ok' sprite local pronto | 'disabled' player sem timeline
 *                preview (sprite não é necessário) | 'failed' não foi possível
 *                gerar (mantém pasta local como fallback)
 */
function ensure_video_sprite_local($vid)
{
    global $config, $conn;

    static $previewCache = null;
    if ($previewCache === null) {
        $previewCache = '0';
        $sql = "SELECT timeline_preview FROM player WHERE profile = 'Main' LIMIT 1";
        $rs  = $conn->execute($sql);
        if ($conn->Affected_Rows() == 1) {
            $previewCache = $rs->fields['timeline_preview'];
        }
    }

    if ($previewCache != '1') {
        return 'disabled';
    }

    if (!function_exists('imagecreatefromjpeg')) {
        return 'failed';
    }

    require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
    $thumb_dir = get_thumb_dir(intval($vid));
    if (!is_dir($thumb_dir) || count(glob($thumb_dir . '/*.jpg')) == 0) {
        return 'failed';
    }

    require_once $config['BASE_DIR'] . '/classes/sprite.class.php';
    if (!class_exists('images_to_sprite')) {
        return 'failed';
    }

    $sprite = new images_to_sprite(
        $thumb_dir,
        $thumb_dir . '/sprite',
        $config['img_max_width'],
        $config['img_max_height']
    );

    if ($sprite->sprite_is_stale()) {
        $sprite->create_sprite();
    }

    return is_file($thumb_dir . '/sprite.jpg') ? 'ok' : 'failed';
}

/**
 * Remove a pasta local de thumbs de um vídeo (tmb/tmbN/{VID}) — usada após
 * upload GCS confirmado quando del_original_video governa a limpeza local.
 *
 * No-op quando a pasta não existe. Nunca recria a pasta (diferente de
 * get_thumb_dir(), que faz mkdir do volume quando ausente).
 *
 * @param int $vid ID do vídeo
 * @return bool true quando a pasta foi removida ou já não existia
 */
function delete_local_video_thumbs($vid)
{
    global $config;

    $vid  = intval($vid);
    if ($vid <= 0) {
        return true;
    }

    $index = intval(($vid - 1) / $config['max_thumb_folders']);
    $tmb_folder = ($index === 0) ? 'tmb' : 'tmb' . $index;
    $dir  = $config['BASE_DIR'] . '/media/videos/' . $tmb_folder . '/' . $vid;

    if (!is_dir($dir)) {
        return true;
    }

    require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
    return delete_directory($dir);
}

/**
 * Confere se o bucket GCS já guarda ao menos um formato H.264 do vídeo.
 *
 * Usada como fail-safe ANTES de remover o original local (vid/{VID}.mp4): só
 * deletamos a fonte se houver cópia reproduzível no bucket (o upload de todos
 * os formatos pode falhar parcialmente e o sp/legacy não deve destruir a única
 * cópia restante nesse caso).
 *
 * @param int   $vid    ID do vídeo
 * @param array $server Linha da tabela servers (server_type = 'gcs')
 * @return bool true quando existe pelo menos um objeto h264/{VID}/...
 */
function gcs_video_has_formats($vid, $server)
{
    $gcs = gcs_get_client($server);
    if (!$gcs) {
        return false;
    }

    $vid = intval($vid);

    // Layout atual: h264/{VID}/{label}.{ext}
    $list = $gcs->listObjects('h264/' . $vid . '/');
    if (is_array($list) && count($list) > 0) {
        return true;
    }

    // Layout legado: h264/{VID}_{label}.{ext}
    $list = $gcs->listObjects('h264/' . $vid . '_');
    return (is_array($list) && count($list) > 0);
}

/**
 * Remove do bucket GCS todos os objetos de um vídeo.
 *
 * Apaga os vídeos (layout atual h264/{VID}/{label}.{ext} e o plano legado
 * h264/{VID}_{label}.{ext}) e a mídia derivada (thumbs/{VID}/ — thumbs,
 * sprite e miniclips). Falhas de API/credencial não matam o request:
 * o registro local é removido mesmo que o bucket não possa ser alcançado.
 *
 * @param int   $video_id ID do vídeo (VID)
 * @param array $server   Linha da tabela servers (server_type = 'gcs')
 * @return int|false Número de objetos removidos; false se nada pôde ser feito
 */
function delete_video_gcs( $video_id, $server )
{
    if (empty($server['gcs_key_path']) || empty($server['gcs_bucket'])) {
        return false;
    }

    $gcs = gcs_get_client($server);
    if (!$gcs) {
        return false;
    }

    $video_id = intval($video_id);
    $deleted  = 0;
    $objects  = array();

    // Layout atual: h264/{VID}/{label}.{ext}
    $list = $gcs->listObjects('h264/' . $video_id . '/');
    if (is_array($list)) {
        $objects = array_merge($objects, $list);
    }

    // Layout legado: h264/{VID}_{label}.{ext}
    $list = $gcs->listObjects('h264/' . $video_id . '_');
    if (is_array($list)) {
        $objects = array_merge($objects, $list);
    }

    // Mídia derivada: thumbs/{VID}/
    $list = $gcs->listObjects('thumbs/' . $video_id . '/');
    if (is_array($list)) {
        $objects = array_merge($objects, $list);
    }

    foreach (array_unique($objects) as $object) {
        if ($gcs->deleteObject($object)) {
            ++$deleted;
        }
    }

    return $deleted;
}

/**
 * Remove do bucket GCS apenas a mídia derivada de um vídeo (thumbs/{VID}/).
 *
 * @param int   $video_id ID do vídeo (VID)
 * @param array $server   Linha da tabela servers (server_type = 'gcs')
 * @return int|false Número de objetos removidos; false se nada pôde ser feito
 */
function delete_video_thumbs_gcs( $video_id, $server )
{
    $gcs = gcs_get_client($server);
    if (!$gcs) {
        return false;
    }

    $video_id = intval($video_id);
    $deleted  = 0;
    $list     = $gcs->listObjects('thumbs/' . $video_id . '/');
    if (is_array($list)) {
        foreach ($list as $object) {
            if ($gcs->deleteObject($object)) {
                ++$deleted;
            }
        }
    }

    return $deleted;
}
?>
