<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Caminho local de thumbs de um vídeo (pasta tmb/tmbN por volume).
 *
 * Lógica legada extraída para que get_video_thumb_base() possa reusá-la como
 * fallback (vídeos locais/FTP continuam servidos de media/videos/tmb*).
 *
 * @param int $vid
 * @return string
 */
function get_thumb_url_local($vid)
{
	global $config;

	$index = intval( ($vid - 1) / $config['max_thumb_folders'] );
	$tmb_folder = 'tmb';
	if ($index !== 0) {
		$tmb_folder = 'tmb'.$index;
	}

	return $config['BASE_URL'].'/media/videos/'.$tmb_folder.'/'.$vid;
}

/**
 * Raiz pública dos thumbs no bucket GCS, quando existe um servidor GCS ativo
 * (ex.: https://storage.googleapis.com/novinhasbr-cdn1/thumbs).
 *
 * Usada pelo JS do hover-preview/rotator: os vídeos em produção vivem no
 * bucket, então o cliente monta thumbs/{VID}/... a partir desta raiz.
 * Retorna '' quando não há servidor GCS (modo local/FTP intacto).
 *
 * @return string
 */
function get_gcs_thumbs_base()
{
	global $conn;

	static $base = null;
	if ($base !== null) {
		return $base;
	}

	$base = '';
	$sql  = "SELECT gcs_bucket, video_url FROM servers WHERE server_type = 'gcs' AND status = '1' ORDER BY server_id ASC LIMIT 1";
	$rs   = $conn->execute($sql);
	if ($conn->Affected_Rows() == 1) {
		if (!empty($rs->fields['video_url'])) {
			$base = rtrim($rs->fields['video_url'], '/') . '/thumbs';
		} elseif (!empty($rs->fields['gcs_bucket'])) {
			$base = 'https://storage.googleapis.com/' . $rs->fields['gcs_bucket'] . '/thumbs';
		}
	}

	return $base;
}

/**
 * Base das URLs de thumbs de um vídeo específico — fonte única de verdade.
 *
 * - Vídeo vinculado a um servidor GCS: URL pública do bucket (thumbs/{VID}),
 *   já que a mídia derivada é sincronizada para lá em publicRead.
 * - Demais casos: mesmo caminho local de sempre (BASE_URL/media/videos/...).
 *
 * Cache por request (estático) para não repetir consultas ao banco por vídeo
 * dentro da mesma página (grade + hero + related reusam os mesmos VIDs).
 *
 * @param int $vid
 * @return string
 */
function get_video_thumb_base($vid)
{
	global $config, $conn;

	static $cache = array();

	$vid = intval($vid);
	if (array_key_exists($vid, $cache)) {
		return $cache[$vid];
	}

	// Fallback local por padrão; trocado abaixo quando o vídeo está no GCS.
	$cache[$vid] = get_thumb_url_local($vid);

	$sql = "SELECT server FROM video WHERE VID = " . $vid . " LIMIT 1";
	$rs  = $conn->execute($sql);
	if ($conn->Affected_Rows() == 1 && !empty($rs->fields['server'])) {
		require_once $config['BASE_DIR'] . '/include/function_server.php';
		$server = get_server_by_video_url($rs->fields['server']);
		if ($server && isset($server['server_type']) && $server['server_type'] === 'gcs') {
			if (!empty($server['video_url'])) {
				$cache[$vid] = rtrim($server['video_url'], '/') . '/thumbs/' . $vid;
			} elseif (!empty($server['gcs_bucket'])) {
				$cache[$vid] = 'https://storage.googleapis.com/' . $server['gcs_bucket'] . '/thumbs/' . $vid;
			}
		}
	}

	return $cache[$vid];
}

function get_thumb_url($vid)
{
	return get_video_thumb_base($vid);
}

function get_thumb_dir($vid) 
{               
	global $config;
	
	$index = intval( ($vid - 1) / $config['max_thumb_folders'] );
	$tmb_folder = 'tmb';
	if ($index !== 0) {
		$tmb_folder = 'tmb'.$index;
	}
	$path = $config['BASE_DIR'].'/media/videos/'.$tmb_folder;
	
	if (!file_exists($path)) {
		mkdir($path, 0777, true);
	}	
	
	$output = $path.'/'.$vid;

	return $output;
}

function delete_directory($dirname) {
	if (is_dir($dirname))
		$dir_handle = opendir($dirname);
	if (!$dir_handle)
		return false;
	while($file = readdir($dir_handle)) {
		if ($file != "." && $file != "..") {
			if (!is_dir($dirname."/".$file))
				unlink($dirname."/".$file);
			else
				delete_directory($dirname.'/'.$file);
		}
	}
	closedir($dir_handle);
	rmdir($dirname);
	return true;
}

?>