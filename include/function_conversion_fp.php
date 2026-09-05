<?php
/*|-------------------------------------------------
|*|	AVS Conversion Functions
|*| Convert H264
|*|-------------------------------------------------
|*/	

require_once dirname(__FILE__). '/function_watermark.php';

function scale ($iw, $ih, $rw, $rh) {
	if (($iw/$ih)<=($rw/$rh)) {
		$ow = $iw*$rh/$ih;
		$oh = $rh;
	} else {
		$oh = $ih*$rw/$iw;
		$ow = $rw;
	}
	$ow = floor($ow/2)*2;
	$oh = floor($oh/2)*2;
	$scale = "-vf scale=".$ow.":".$oh;
	return $scale;
}

function ratio($a, $b) {
    $gcd = function($a, $b) use (&$gcd) {
        return ($a % $b) ? $gcd($b, $a % $b) : $b;
    };
    $g = $gcd($a, $b);
    return $a/$g . ':' . $b/$g;
}

function get_mediainfo_data($videofile) {
	global $config;
	$varr = array();
	$output1 = array();
	$output2 = array();
	$media_general = $config['BASE_DIR']."/scripts/media_general.txt";
	$media_video = $config['BASE_DIR']."/scripts/media_video.txt";
	if (!preg_match("/mediainfo$/is", $config['mediainfo'])){
		$error = 'Mediainfo error';
	}else{
		$command1 = $config['mediainfo']." --Inform=file://".$media_general." ".$videofile;
		exec($command1,$output1);
		$command2 = $config['mediainfo']." --Inform=file://".$media_video." ".$videofile;
		exec($command2,$output2);
		$error = '';
	}
	$varr['error'] = $error;
	$varr['media_gen_cmd'] = $command1;
	$varr['media_vid_cmd'] = $command2;
	$varr['media_gen_out'] = $output1;
	$varr['media_vid_out'] = $output2;
	return $varr;
}

function videoInfo($vi) {
	foreach($vi['media_gen_out'] as $line){
		if (preg_match("/^(General_|Video_).+?\=.*/", $line)){
			$line_arr = explode("=", $line);
			$video_info[$line_arr[0]] = $line_arr[1];
		}
	}
	foreach($vi['media_vid_out'] as $line){
		if (preg_match("/^(General_|Video_).+?\=.*/", $line)){
			$line_arr = explode("=", $line);
			$video_info[$line_arr[0]] = $line_arr[1];
		}
	}	
	echo "\n".$nl."Media Descriptors Commands\n".$nl;
	echo "Comand 1: ".$vi['media_gen_cmd']."\n";
	echo "Comand 2: ".$vi['media_vid_cmd']."\n";
	echo "\n".$nl."Media Info\n".$nl;
	foreach ($video_info as $key => $val){
		echo "\$video_info['".$key."'] = '".$val."';\n";
	}
	return $video_info;
}

function get_ffprobe_data($videofile) {
	global $config;
	$varr = array();
	$output1 = array();
	$output2 = array();

	$command1 = $config['ffprobe']." -v error -select_streams v:0 -show_entries stream=codec_long_name,codec_name,width,height,display_aspect_ratio,duration -of default=noprint_wrappers=1 ".$videofile."";	
	exec($command1,$output1);

	$command2 = $config['ffprobe']." -v error -show_entries format=filename,format_name,duration,size -of default=noprint_wrappers=1 ".$videofile."";	
	exec($command2,$output2);

	$varr['stream'] = $output1;
	$varr['format'] = $output2;	
	$varr['ffp_cmd1'] = $command1;
	$varr['ffp_cmd2'] = $command2;	
	return $varr;
}

function ffpInfo($vi) {
	$nl = "=========================================================\n";
	$video_info = array();
	foreach($vi['stream'] as $line){
		$line_arr = explode("=", $line);
		switch ($line_arr[0]) {
			case 'width':
				$video_info[$line_arr[0]] = intval($line_arr[1]);
				break;
			case 'height':
				$video_info[$line_arr[0]] = intval($line_arr[1]);
				break;
			default:
				$video_info[$line_arr[0]] = $line_arr[1];
		}
		if (isset($video_info['display_aspect_ratio']) && ($video_info['display_aspect_ratio'] == '0:1' || $video_info['display_aspect_ratio'] == 'N/A' || $video_info['display_aspect_ratio'] == '')) {
			if (!empty($video_info['width']) && !empty($video_info['height'])) {
				$video_info['display_aspect_ratio'] = ratio($video_info['width'], $video_info['height']);
			}
		}
		
	}
	foreach($vi['format'] as $line){
		$line_arr = explode("=", $line);
		switch ($line_arr[0]) {
			case 'duration':
				$video_info[$line_arr[0]] = floatval($line_arr[1]);
				break;
			case 'size':
				$video_info[$line_arr[0]] = intval($line_arr[1]);
				break;
			default:
				$video_info[$line_arr[0]] = $line_arr[1];
		}
		if (isset($video_info['filename'])) {
			$filename_arr = explode(".",$video_info['filename']);
			$video_info['file_extension'] = end($filename_arr);
		}
		
	}	
	echo "\n".$nl."FFProbe Command\n".$nl;
	echo "Comand 1: ".$vi['ffp_cmd1']."\n";
	echo "Comand 2: ".$vi['ffp_cmd2']."\n";	

	echo "\n".$nl."FFProbe Info\n".$nl;
	foreach ($video_info as $key => $val){
		echo "\$video_info['".$key."'] = '".$val."';\n";
	}
	return $video_info;
}

function print_log($txt) {
	global $config;
	if ($config['log_conversion']){
		print ($txt);
	}
}

function modproc($cmd) {
	$cmd = str_replace(" ;", " 2>&1 ;", $cmd)." 2>&1";
	$nl = "=========================================================\n";
	echo "\n".$nl."Command:\n".$nl.$cmd."\n\n";
	exec($cmd,$out);
	$outs = '';
	if (!empty($out)) {
		foreach($out as $outd){
			$outs .= $outd."\n";
		}
	}
	echo "Output:\n".$outs."\n\n";
}

function getEncodings() {
	global $config, $conn;
	$sql = "SELECT * FROM encoding WHERE status ='1' ORDER BY height DESC";
	$rs = $conn->execute($sql);
    $encodings = $rs->getrows();
	end($encodings);
	$lastkey = key($encodings);
	$encodings[$lastkey]['lq'] = true;
	return $encodings;
}

function convert ($e, $vid, $video_name, $video_info) {
	global $config;
	$wmCfg = wm_video_config($vid);
	$nl = "=========================================================\n";

	// Output :: Vars
	echo "\n".$nl."Output - Conversion Config:\n".$nl;
	echo "Label: ".$e['label']."\n";
	echo "Resolution: ".$e['width'].'x'.$e['height']."\n";
	echo "Constant Rate Factor: ".$e['crf']."\n";
	echo "Preset: ".$e['preset']."\n";
	echo "iOS Compatability: ".$e['ios']."\n";
	echo "Fast Start: ".$e['faststart']."\n";	
	echo "Copy Only: ".$e['copyonly']."\n";

	if(!isset($config['encode_height'] ) ) {

		if (($e['height'] <= $video_info['height'] || $e['width'] <= $video_info['width']) || $e['lq']  ) {

			//Check cut intro
			$sql 	= "SELECT cut FROM video WHERE VID = '" .$vid. "' LIMIT 1";
			$rs		= selectQuery($sql);
			$cut	= $rs['cut'];
			if ($cut) {
				$add_cut = " -ss ".$cut;
			} else {
				$add_cut = "";
			}		
			
			// Source Video Path info
			$src = $config['VDO_DIR']."/".$video_name;

			// HD Paths info		
			$output = $config['H264_DIR']."/".$vid."_".$e['label'].".".$e['format'];
			
			if ($e['faststart']) {
				$faststart = "-movflags +faststart";
			} else {
				$faststart = "";			
			}
			$copyH264 = ($video_info['file_extension'] == "mp4" && strpos($video_info['format_name'], 'mp4') !== false && $video_info['codec_name'] == "h264" && strpos($video_info['codec_long_name'], 'MPEG-4') !== false && strpos($video_info['codec_long_name'], 'AVC') !== false);
			if ($e['copyonly'] && $copyH264 && !wm_force_reencode($wmCfg)) {
				// Fast path: the source is ALREADY H.264/MP4 (e.g. files from the
				// Mass Video Grabber / yt-dlp). Re-encoding it is pure CPU waste —
				// remux to the target with faststart instead (seconds, not minutes).
				if ($cut) {
					$cmd = $config['ffmpeg'].$add_cut." -i \"".$src."\" -c copy -y \"".$output."\"";
					modproc($cmd);
				} elseif ($e['faststart']) {
					$cmd = $config['ffmpeg']." -i \"".$src."\" -c copy -movflags +faststart -y \"".$output."\"";
					modproc($cmd);
				} else {
					if (@copy($src,$output)) {
						echo "\n"."COPY ONLY: source is already H.264 MP4 — copied instead of re-encoded!\n\n";
					}
				}
			} else {
				$scale = "";
				$scaleInner = "";
				if (isset($e['lq']) && $e['lq'] && ($e['height'] > $video_info['height'] || $e['width'] > $video_info['width'])) {
					if ($e['height'] > 480) {
						$e['label'] = 'HD';
					} else {
						$e['label'] = 'SD';
					}
				} else {
					$scaleInner = "scale='if(gt(a,4/3),".$e['width'].",-1)':'if(gt(a,4/3),-1,".$e['height'].")'";
					$scale = "-vf scale=\"'if(gt(a,4/3),".$e['width'].",-1)':'if(gt(a,4/3),-1,".$e['height'].")'\"";
				}
				$wmArgs = wm_build_args($wmCfg, $scaleInner, $video_info, $e);
				$vfilter = ($wmArgs !== '') ? $wmArgs : $scale;
				$output = $config['H264_DIR']."/".$vid."_".$e['label'].".".$e['format'];
				$cmd = $config['ffmpeg'].$add_cut." -i \"".$src."\" -threads 0 -c:v libx264 -preset ".$e['preset']." -crf ".$e['crf']." ".$vfilter." ".$e['ios']." ".$faststart." -y \"".$output."\"";	
				modproc($cmd);
			}
			if (file_exists($output) && filesize($output) > 100) {
				// Evita duplicar formatos se a 1a passagem rodar 2x (ex: reprocess no meio da conversao)
				$chk_sql = "SELECT formats, lformats FROM video WHERE VID = '".(int)$vid."' LIMIT 1";
				$chk_rs = selectQuery($chk_sql);
				$format_str = $e['height'].".".$e['label'].".".$e['format'];
				$f_arr = !empty($chk_rs['formats']) ? array_filter(array_map('trim', explode(',', $chk_rs['formats']))) : array();
				if (!in_array($format_str, $f_arr)) {
					$f_arr[] = $format_str;
				}
				$new_formats = implode(',', array_unique($f_arr));

				$lf_arr = !empty($chk_rs['lformats']) ? array_filter(array_map('trim', explode(',', $chk_rs['lformats']))) : array();
				if (!in_array($e['label'], $lf_arr)) {
					$lf_arr[] = $e['label'];
				}
				$new_lformats = implode(', ', array_unique($lf_arr));

				$sql = "UPDATE video SET formats = '".$new_formats."', lformats = '".$new_lformats."' WHERE VID = '".(int)$vid."'";
				executeQuery($sql);
				echo "\n".$nl."SQL:\n".$nl.$sql."\n\n";

				$config['encode_height'] = $e['height'];
				echo "\n".$nl."Sending to queue - second pass: VID:".$vid.", Skip:".$e['height']."\n\n";	
				insert_q_sp($vid,$e['height'],$video_info);
				
			} else {
				@chmod($output, 0777);
				@unlink($output);			
				$scale = scale($video_info['width'], $video_info['height'], $e['width'], $e['height']);
				$scaleInner = preg_replace('/^-vf /', '', $scale);
				$wmArgs = wm_build_args($wmCfg, $scaleInner, $video_info, $e);
				$vfilter = ($wmArgs !== '') ? $wmArgs : $scale;
				echo "\n"."Retrying using fixed scale: ".$scale."\n";
				$cmd = $config['ffmpeg'].$add_cut." -i \"".$src."\" -threads 0 -c:v libx264 -preset ".$e['preset']." -crf ".$e['crf']." ".$vfilter." ".$e['ios']." ".$faststart." -y \"".$output."\"";
				modproc($cmd);
				if (file_exists($output) && filesize($output) > 100) {
					$chk_sql = "SELECT formats, lformats FROM video WHERE VID = '".(int)$vid."' LIMIT 1";
					$chk_rs = selectQuery($chk_sql);
					$format_str = $e['height'].".".$e['label'].".".$e['format'];
					$f_arr = !empty($chk_rs['formats']) ? array_filter(array_map('trim', explode(',', $chk_rs['formats']))) : array();
					if (!in_array($format_str, $f_arr)) {
						$f_arr[] = $format_str;
					}
					$new_formats = implode(',', array_unique($f_arr));

					$lf_arr = !empty($chk_rs['lformats']) ? array_filter(array_map('trim', explode(',', $chk_rs['lformats']))) : array();
					if (!in_array($e['label'], $lf_arr)) {
						$lf_arr[] = $e['label'];
					}
					$new_lformats = implode(', ', array_unique($lf_arr));

					$sql = "UPDATE video SET formats = '".$new_formats."', lformats = '".$new_lformats."' WHERE VID = '".(int)$vid."'";
					executeQuery($sql);
					echo "\n".$nl."SQL:\n".$nl.$sql."\n\n";

					$config['encode_height'] = $e['height'];
					echo "\n".$nl."Sending to queue - second pass: VID:".$vid.", Skip:".$e['height']."\n\n";
					insert_q_sp($vid,$e['height'],$video_info);
				}
			}
		} else {
			echo "\n"."SKIP CONVERSION: Output resolution is higher than the input resloution!\n\n";
		}
	}
}

function insert_q_sp($vid, $height, $info) {

	global $config;
	$link = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass']);
	if($link){	
		$dbs = mysqli_select_db($link, $config['db_name']);
		$sql = "SELECT * FROM conversion_queue_sp WHERE VID = '".$vid."' LIMIT 1";
		echo "\nSQL:".$sql."\n";
		$res = mysqli_query($link, $sql);
		$count = $res ? mysqli_num_rows($res) : 0;
		if ($count !=1) {
			$uid = $info['UID'];
			$video_name = mysqli_real_escape_string($link,$info['video_name']);
			$video_path = mysqli_real_escape_string($link,$info['video_path']);
			$title = mysqli_real_escape_string($link,$info['title']);	
			$sql = "INSERT INTO conversion_queue_sp SET VID = '".$vid."', UID = '".intval($uid)."', video_name = '".$video_name."', video_path = '".$video_path."', skip = '".intval($height)."', title = '".$title."', addtime = '".time()."'";
			echo "\nINSERT INTO QUEUE SECOND PASS SQL:".$sql."\n";
			@mysqli_query($link, $sql);
			@mysqli_query($link, "DELETE FROM conversion_queue_fp WHERE VID = '".$vid."' LIMIT 1");
		}

		mysqli_close($link);
	}

}

function postThumbs($vid, $src) {
	global $config;

	$bak_src = $src.'.bak';
	if (file_exists($bak_src) && filesize($bak_src) > 100) {	
		echo "\n"."Extracting thumbnails: ".$src."\n\n";
		extract_video_thumbs($bak_src, $vid, 'all', $config['thumbnail_remove_bb'], $config['thumbnail_keep_ar']);
		return;		
	}
	
	$sql     = "SELECT formats, server FROM video WHERE VID = '" .$vid. "' LIMIT 1";
	$rs      = selectQuery($sql);
    $formats = $rs['formats'];
    $server  = $rs['server'];

	$formats = explode(',', $formats);
	foreach ($formats as $format) {
		 unset($f);
		 $f    = explode('.', $format);
		 $vf[] = $config['H264_DIR'].'/'.$vid."_".$f[1].".".$f[2];
	}
	if ($server != '') {
		foreach ($formats as $format) {
			 unset($f);
			 $f    = explode('.', $format);
			 // GCS buckets são organizados por pasta por vídeo: h264/{VID}/{label}.{ext};
			 // FTP/local mantêm o layout plano antigo: h264/{VID}_{label}.{ext}
			 $h264Path = (strpos($server, 'storage.googleapis.com') !== false)
			 	       ? '/h264/'.$vid.'/'.$f[1].'.'.$f[2]
			 	       : '/h264/'.$vid."_".$f[1].".".$f[2];
			 $vfs[] = $server.$h264Path;
		}		
	}
	foreach ($vf as $file) {
		if (file_exists($file) && filesize($file) > 100) {
			echo "\n"."Extracting thumbnails: ".$file."\n\n";
			extract_video_thumbs($file, $vid, 'all', $config['thumbnail_remove_bb'], $config['thumbnail_keep_ar']);
			if ($config['vthumbs'] == '1') {
				extract_video_vthumbs($file, $vid, false);
			}				
			return;
		}
	}
	foreach ($vfs as $file) {
		if (file_url_exists($file)) {
			echo "\n"."Extracting thumbnails: ".$file."\n\n";
			extract_video_thumbs($file, $vid, 'all', $config['thumbnail_remove_bb'], $config['thumbnail_keep_ar']);
			if ($config['vthumbs'] == '1') {
				extract_video_vthumbs($file, $vid, false);
			}				
			return;
		}
	}
	if (file_exists($src) && filesize($src) > 100) {	
		echo "\n"."Extracting thumbnails: ".$src."\n\n";
		extract_video_thumbs($src, $vid, 'all', $config['thumbnail_remove_bb'], $config['thumbnail_keep_ar']);
		if ($config['vthumbs'] == '1') {
			extract_video_vthumbs($file, $vid, false);
		}			
		return;		
	}
}

function postConversion($vid,$src) {
	global $config;

	$nl = "=========================================================\n";

	$sql  	     = "SELECT formats, active FROM video WHERE VID = '" .$vid. "' LIMIT 1";
	$rs 	     = selectQuery($sql);
    $formats     = array_values(array_unique(array_filter(array_map('trim', explode(',', $rs['formats'])))));
    $status      = $rs['active'];	
	
	$hd          = 0;	
	// Respect manual suspension: if admin set active=0 during conversion, keep it 0
	if ($status == '0') {
		$active = 0;
	} elseif ($status == '1') {
		$active = 1;
	} elseif ($config['approve'] == '0' && !empty($formats)) {
		$active = 1;
	} else {
		$active = 0;
	}

	$sd_f        = explode('.', end($formats));
	$sd_vf       = $config['H264_DIR'].'/'.$vid."_".$sd_f[1].".".$sd_f[2];
	$sd_ffp_data = get_ffprobe_data($sd_vf);
	$sd_vi       = ffpInfo($sd_ffp_data);
	
	if (intval($sd_f[0]) > 480) {
		$hd = 1;
	}
	$sql = 	"UPDATE video SET 
			active = '".$active."', 
			duration = '".$sd_vi['duration']."',
			width_sd = '".$sd_vi['width']."', 
			height_sd = '".$sd_vi['height']."', 
			aspect_sd = '".$sd_vi['display_aspect_ratio']."',
			last_update = '".time()."' 
			WHERE VID = '".(int)$vid."' LIMIT 1";
			
	if (count($formats)>1) {
		$hd_f    = explode('.', $formats[0]);
		$hd_vf = $config['H264_DIR'].'/'.$vid."_".$hd_f[1].".".$hd_f[2];
		$hd_ffp_data = get_ffprobe_data($hd_vf);
		$hd_vi = ffpInfo($hd_ffp_data);
		

		if (intval($hd_f[0]) > 480) {
			$hd = 1;
			$sql = 	"UPDATE video SET 
					active = '".$active."', 
					duration = '".$hd_vi['duration']."', 
					width_hd = '".$hd_vi['width']."', 
					height_hd = '".$hd_vi['height']."', 
					aspect_hd = '".$hd_vi['display_aspect_ratio']."', 
					width_sd = '".$sd_vi['width']."', 
					height_sd = '".$sd_vi['height']."', 
					aspect_sd = '".$sd_vi['display_aspect_ratio']."', 
					hd = '".$hd."',
					last_update = '".time()."' 
					WHERE VID = '".(int)$vid."' LIMIT 1";			
		}
	} else {
		if ($hd == 1) {
			$sql = 	"UPDATE video SET 
					active = '".$active."', 
					duration = '".$sd_vi['duration']."', 
					width_hd = '".$sd_vi['width']."', 
					height_hd = '".$sd_vi['height']."', 
					aspect_hd = '".$sd_vi['display_aspect_ratio']."', 
					width_sd = '".$sd_vi['width']."', 
					height_sd = '".$sd_vi['height']."', 
					aspect_sd = '".$sd_vi['display_aspect_ratio']."', 
					hd = '".$hd."',
					last_update = '".time()."' 
					WHERE VID = '".(int)$vid."' LIMIT 1";				
		}
	}
	
	executeQuery($sql);
	echo "\n".$nl."SQL:\n".$nl.$sql."\n\n";

	// Multi-Server Transfer
	if (isset($config['multi_server']) && $config['multi_server'] == '1') {
		require_once $config['BASE_DIR'] . '/include/function_server.php';
		$server = get_server();
		if ($server) {
			echo "\n[Multi-Server] Iniciando transferencia para o servidor secundario ID #" . $server['server_id'] . " (" . $server['server_ip'] . ")...\n";
			upload_video_formats($vid, $formats, $server);
		} else {
			echo "\n[Multi-Server] Nenhum servidor secundario ativo disponivel na fila. O video sera mantido no servidor principal.\n";
		}
	}
}

/*|*****************************************
|*| Function :: DB SELECTOR
|*|*****************************************
|*/ 
function executeQuery($query) {
	global $config;
	$link = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass']);
	if($link){	
		$dbs = mysqli_select_db($link, $config['db_name']);
		$result = mysqli_query($link, $query);
		if($result){
			$id = mysqli_insert_id($link);
		}
		$err = mysqli_error($link);
		mysqli_close($link);
	}else{
		$err = 'Could not connect to '.$dbs.': ' . mysqli_error($link);
	}
	$result = (intval($id) > 0) ? $id : $result;
	$result = ($err != "") ? "Sql Error :: ".$err."<br/>" : $result;
		return $result;
}
	
function selectQuery($query) {
	global $config;
	$link = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass']);
	if($link){	
		$dbs = mysqli_select_db($link, $config['db_name']);
		$result = mysqli_fetch_array(mysqli_query($link, $query), MYSQLI_BOTH);
		$err = mysqli_error($link);
		mysqli_close($link);
	} else {
		$err = 'Could not connect to '.$dbs.': ' . mysqli_close($link);
	}
	$result = ($err != "") ? "Sql Error :: ".$err."<br/>" : $result;
	return $result;
}	
?>