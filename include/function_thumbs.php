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
 * Raiz das URLs de thumbs quando existe um servidor GCS ativo.
 *
 * O bucket é HNS/UBLA (nada é público); a base retornada aponta para o proxy
 * gcs_thumbs.php (slash-style: callers concatenam {vid}/{arquivo}), que gera
 * V4 signed URLs por arquivo. Usada pelo JS do hover-preview/rotator e pelos
 * callers de {insert name=thumb_path}.
 * Retorna '' quando não há servidor GCS (modo local/FTP intacto).
 *
 * @return string
 */
function get_gcs_thumbs_base()
{
	global $conn, $config;

	static $base = null;
	if ($base !== null) {
		return $base;
	}

	$base = '';
	$sql  = "SELECT gcs_bucket, video_url FROM servers WHERE server_type = 'gcs' AND status = '1' ORDER BY server_id ASC LIMIT 1";
	$rs   = $conn->execute($sql);
	if ($conn->Affected_Rows() == 1) {
		// Bucket GCS é HNS/UBLA (acesso por IAM, sem público): as thumbs são
		// servidas via proxy que gera V4 signed URLs. Os callers montam
		// {base}{VID}/{arquivo}, e o proxy parseia o caminho (v={VID}/{arquivo}).
		$base = $config['BASE_URL'] . '/gcs_thumbs.php?v=';
	}

	return $base;
}

/**
 * Base das URLs de thumbs de um vídeo específico — fonte única de verdade.
 *
 * - Vídeo vinculado a um servidor GCS: URL do proxy gcs_thumbs.php (que gera
 *   signed URLs), já que a mídia derivada é sincronizada para lá em modo privado.
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
			// Thumbs no bucket são privadas (HNS/UBLA) e são entregues via proxy
			// de signed URLs em slash-style. Os callers montam
			// {base}/{arquivo} — e o proxy parseia v={VID}/{arquivo}.
			$cache[$vid] = $config['BASE_URL'] . '/gcs_thumbs.php?v=' . $vid;
		}
	}

	return $cache[$vid];
}

function get_thumb_url($vid)
{
	return get_video_thumb_base($vid);
}

/**
 * URL pública de um frame de thumbnail — fonte única de verdade.
 *
 * - Vídeo no bucket GCS: serve via proxy de signed URLs (slash-style,
 *   thumb{...} do bucket).
 * - Vídeo local: só usa o frame se o arquivo existir em disco; senão fallback
 *   default.jpg.
 *
 * @param int $vid
 * @param int $frame
 * @return string
 */
function get_video_thumb_src($vid, $frame)
{
	global $config;

	$base = get_video_thumb_base($vid);
	$num  = intval($frame);
	$tmb_url_def = $config['BASE_URL'].'/media/videos/tmb/default.jpg';

	if (strpos($base, 'gcs_thumbs.php') !== false) {
		return $config['BASE_URL'].'/gcs_thumbs.php?v='.$vid.'/'.$num.'.jpg';
	}

	$path_dir = get_thumb_dir($vid);
	$tmb = $path_dir.'/'.$num.'.jpg';
	if (file_exists($tmb) && is_file($tmb)) {
		return $base.'/'.$num.'.jpg';
	} else {
		return $tmb_url_def;
	}
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
	// 0777 efetivo (o mkdir acima é mascarado pelo umask -> 755): o pipeline
	// roda como usuários diferentes (CLI manual vs workers do Apache).
	if (!is_writable($path)) {
		@chmod($path, 0777);
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