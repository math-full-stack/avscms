<?php
defined('_VALID') or die('Restricted Access!');
require_once ($config['BASE_DIR']. '/include/function_thumbs.php');
require $config['BASE_DIR']. '/classes/image.class.php';

if (!function_exists('file_url_exists')) {
function file_url_exists($url){
    if (empty($url)) return false;
    $ch = curl_init($url);    
    if (strpos($url, 'X-Goog-') !== false || strpos($url, 'X-Amz-') !== false) {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RANGE, '0-0');
    } else {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code == 200 || $code == 206);
}
}

function compareColors($colorA, $colorB, $threshold) {
    $deviation =  abs($colorA['red'] - $colorB['red']) + abs($colorA['green'] - $colorB['green']) + abs($colorA['blue'] - $colorB['blue']);
	if ($deviation <= $threshold) return true;
	else return false;
}

function video_files($vid, $all=false) {
	global $config, $conn;
	
	$vf = array('dir' => array(), 'url' => array());
	$sql = "SELECT * FROM video WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
	$rs  = $conn->execute($sql);
	if (!$rs || $conn->Affected_Rows() != 1) {
		return $vf;
	}
	$video_row   = $rs->fields;
	$formats 	 = $video_row['formats'];
	$sd_file 	 = $video_row['ipod_filename'];
	$hd_file 	 = $video_row['hd_filename'];
	$flv_file	 = $video_row['flvdoname'];	
	$video_name	 = $video_row['vdoname'];	
	$server  	 = $video_row['server'];

	$formats_arr = array();
	if ($formats) {
		$formats_arr = array_unique(array_filter(array_map('trim', explode(',', $formats))));
	}

	// 1. Sempre verificar se existem arquivos locais primeiro
	if (!empty($formats_arr)) {		
		foreach ($formats_arr as $format) {
			$f = explode('.', $format);
			if (count($f) >= 3) {
				$local_f = $config['H264_DIR'].'/'.$vid."_".$f[1].".".$f[2];
				if (file_exists($local_f) && filesize($local_f) > 100) {
					$vf['dir'][] = $local_f;
				}
			}
		}
	}
	if ($hd_file && file_exists($config['HD_DIR']."/".$sd_file)) {		
		$vf['dir'][] = $config['HD_DIR']."/".$sd_file;
	}
	if ($sd_file && file_exists($config['IPHONE_DIR']."/".$hd_file)) {
		$vf['dir'][] = $config['IPHONE_DIR']."/".$hd_file;
	}

	// 2. Se o vídeo foi para servidor secundário (GCS ou FTP)
	if ($server != '') {
		// Usar get_video_sources que é a fonte de verdade para GCS e Multi-server
		$sources = get_video_sources($video_row);
		if (!empty($sources['files'])) {
			// Ordenar preferindo resoluções médias/leves (ex: 720p ou 480p) para thumbs mais rápidas
			$preferred = array();
			$others = array();
			foreach ($sources['files'] as $sf) {
				$h = intval($sf['height']);
				if ($h >= 360 && $h <= 720) {
					$preferred[] = $sf;
				} else {
					$others[] = $sf;
				}
			}
			$all_sources = array_merge($preferred, $others);
			foreach ($all_sources as $sf) {
				if (!empty($sf['url'])) {
					$vf['url'][] = $sf['url'];
					$vf['server_h264_fn'][] = $sf['file'];
				}
			}
		} else {
			// Fallback legado caso get_video_sources não tenha retornado arquivos
			if (!empty($formats_arr)) {
				foreach ($formats_arr as $format) {
					$f = explode('.', $format);
					if (count($f) >= 3) {
						$vf['url'][] = $server.'/h264/'.$vid."_".$f[1].".".$f[2];
						$vf['server_h264_fn'][] = $vid."_".$f[1].".".$f[2];
					}
				}
			}
		}
		if ($hd_file) {			
			$vf['url'][] = $server."/iphone/".$sd_file;
			$vf['server_hd_fn'] = $vid.".mp4";
		}
		if ($sd_file) {
			$vf['url'][] = $server."/hd/".$hd_file;
			$vf['server_sd_fn'] = $vid.".mp4";
		}
	} else {
		// Local: caso nenhum arquivo local tenha sido encontrado ainda no passo 1
		if (empty($vf['dir']) && !empty($formats_arr)) {
			foreach ($formats_arr as $format) {
				$f = explode('.', $format);
				if (count($f) >= 3) {
					$vf['dir'][] = $config['H264_DIR'].'/'.$vid."_".$f[1].".".$f[2];
				}
			}
		}
	}

	if ($all) {
		if (file_exists($config['VDO_DIR']."/".$video_name)) {
			$vf['dir'][] = $config['VDO_DIR']."/".$video_name;
		}
		if ($flv_file && file_exists($config['FLVDO_DIR']."/".$flv_file)) {
			$vf['dir'][] = $config['FLVDO_DIR']."/".$flv_file;
		}
	}
	return $vf;
}

function detect_black_bars($src, $coef) {

	$image_path = $src;

	$jpg = imagecreatefromjpeg($image_path);
	$black = array("red" => 0, "green" => 0, "blue" => 0, "alpha" => 0);

	$removeLeft = 0;
	for($x = 0; $x < (imagesx($jpg)*$coef); $x++) {
		for($y = 0; $y < imagesy($jpg); $y++) {
			$color = imagecolorsforindex($jpg, imagecolorat($jpg, $x, $y));
			if(!compareColors($color, $black, 30)){
				break 2;
			}
		}
		$removeLeft += 1;
	}

	$removeRight = 0;
	for($x = imagesx($jpg)-1; $x > (imagesx($jpg)*(1-$coef)); $x--) {
		for($y = 0; $y < imagesy($jpg); $y++) {
			$color = imagecolorsforindex($jpg, imagecolorat($jpg, $x, $y));
			if(!compareColors($color, $black, 30)){
				break 2;
			}
		}
		$removeRight += 1;
	}

	$removeTop = 0;
	for($y = 0; $y < (imagesy($jpg)*$coef); $y++) {
		for($x = 0; $x < imagesx($jpg); $x++) {
			$color = imagecolorsforindex($jpg, imagecolorat($jpg, $x, $y));
			if(!compareColors($color, $black, 30)){
				break 2;
			}
		}
		$removeTop += 1;
	}

	$removeBottom = 0;
	for($y = imagesy($jpg)-1; $y > (imagesy($jpg)*(1-$coef)); $y--) {
		for($x = 0; $x < imagesx($jpg); $x++) {
			$color = imagecolorsforindex($jpg, imagecolorat($jpg, $x, $y));
			if(!compareColors($color, $black, 30)){
				break 2;
			}
		}
		$removeBottom += 1;
	}

	$removeLeft += 5;
	$removeRight += 5;
	$removeTop += 7;
	$removeBottom += 7;
	imagedestroy($jpg);	
	
	return array('left' => $removeLeft, 'right' => $removeRight, 'top' => $removeTop, 'bottom' => $removeBottom);
	

}

function remove_black_bars($src, $removeLeft, $removeRight, $removeTop, $removeBottom) {
	$image_path = $src;
	$jpg = imagecreatefromjpeg($image_path);
	$cropped = imagecreatetruecolor(imagesx($jpg) - ($removeLeft + $removeRight), imagesy($jpg) - ($removeTop + $removeBottom));
	imagecopy($cropped, $jpg, 0, 0, $removeLeft, $removeTop, imagesx($cropped), imagesy($cropped));

	header("Content-type: image/jpeg");
	imagejpeg($cropped, $image_path, 95);
	imagedestroy($cropped);
	imagedestroy($jpg);		
}


function process_thumb($src, $dst_w, $dst_h, $keep_ar = true) {

    $image      = new VImageConv();
	list ($width, $height) = getimagesize($src);
	
	if($keep_ar) {
		$aspect_src = $width/$height;
		$aspect_dst = $dst_w/$dst_h;
		
		if ($aspect_src < $aspect_dst) {
			$crop_w = $width;		
			$crop_h = floor(($dst_h*$width)/$dst_w);
			$crop_x = 0;
			$crop_y = floor (($height - $crop_h)/2);
		}
		else {
			$crop_w = floor(($dst_w*$height)/$dst_h);
			$crop_h = $height;
			$crop_x = floor (($width - $crop_w)/2);
			$crop_y = 0;		
		}
		$image->process($src, $src, 'EXACT', $crop_w, $crop_h);
		$image->crop($crop_x, $crop_y, $crop_w, $crop_h, true);
	}
	$image->process($src, $src, 'EXACT', $dst_w, $dst_h);
	$image->resize(true, true);

}

function get_video_duration($video_path, $video_id)
{
    global $config, $conn;
    $cmd = $config['ffprobe']. " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($video_path);
    log_conversion($config['LOG_DIR']. '/' .$video_id. '.log', $cmd);
    exec($cmd, $output);
    log_conversion($config['LOG_DIR']. '/' .$video_id. '.log', implode("\n", $output));
	$dur = isset($output[0]) ? floatval($output[0]) : 0;
	if ($dur <= 0 && $video_id > 0) {
		$d_sql = "SELECT duration FROM video WHERE VID = " . intval($video_id) . " LIMIT 1";
		$d_rs  = $conn->execute($d_sql);
		if ($d_rs && !empty($d_rs->fields['duration'])) {
			$dur = floatval($d_rs->fields['duration']);
		}
	}
	return $dur;
}

/**
 * Probe simples da duração de um arquivo de mídia (ffprobe).
 *
 * Usado para validar os clipes gerados: fontes corrompidas (ex.: conversão
 * interrompida com "Invalid NAL unit size") fazem o ffmpeg abortar cedo e
 * deixar um arquivo minúsculo que passa no check de filesize, mas não tem a
 * duração da montagem planejada.
 *
 * @param string $file Caminho do arquivo
 * @return float Duração em segundos (0 quando não foi possível ler)
 */
function probe_video_duration($file)
{
    global $config;

    if (empty($file) || !file_exists($file)) {
        return 0;
    }

    $cmd = $config['ffprobe'] . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($file);
    @exec($cmd, $output);
    return isset($output[0]) ? floatval($output[0]) : 0;
}

/**
 * Computes the output size for a thumbnail so it keeps the source aspect
 * ratio inside the target box (fit) and never upscales a small source.
 *
 * @param int $src_w  Source width
 * @param int $src_h  Source height
 * @param int $box_w  Maximum box width
 * @param int $box_h  Maximum box height
 * @return array      [width, height] — both even, >= 2
 */
function thumb_target_dims($src_w, $src_h, $box_w, $box_h)
{
	$src_w = max(2, (int)$src_w);
	$src_h = max(2, (int)$src_h);
	$box_w = max(2, (int)$box_w);
	$box_h = max(2, (int)$box_h);

	if ($src_w <= $box_w && $src_h <= $box_h) {
		$w = $src_w;
		$h = $src_h;
	} else {
		$scale = min($box_w / $src_w, $box_h / $src_h);
		$w = $src_w * $scale;
		$h = $src_h * $scale;
	}

	$w = max(2, (int)(floor($w / 2) * 2));
	$h = max(2, (int)(floor($h / 2) * 2));

	return array($w, $h);
}

function extract_video_thumbs ($video_path, $video_id, $target = 'all', $black_bars = false, $keep_ar = true, $admin = false) {

	global $config, $conn;
  
	// Logfile
	$logfile = $config['LOG_DIR'].'/'.$video_id.'.thumbs.log';
	@chmod($logfile,0777);
	@unlink($logfile);   

	// Get Duration of Video from Database
	$duration = get_video_duration($video_path, $video_id);

	// Get video width for automatic thumbnail sizing
	$ffprobe_cmd = $config['ffprobe']." -v error -select_streams v:0 -show_entries stream=width,height -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($video_path);
	exec($ffprobe_cmd, $width_out);
	$video_width = isset($width_out[0]) ? (int)$width_out[0] : 0;
	$video_height = isset($width_out[1]) ? (int)$width_out[1] : 0;
	
	$thumb_width_player 	= $video_width > 0 ? min(($video_width * 5) / 10, 1280) : 1280;
	$thumb_height_player 	= $video_height > 0 ? min(($video_height * 5) / 10, 720) : 720;
	$thumb_width_max 		= $video_width > 0 ? min(($video_width * 2) / 10, 384) : 384;
	$thumb_height_max 		= $video_height > 0 ? min(($video_height * 2) / 10, 216) : 216;

		// High-quality target sizing (overrides the legacy %-based caps above):
	//   - default.jpg : player cover poster   (thumbnail_player_width/height)
	//   - 1..20.jpg   : grid/rotation frames  (img_max_width/img_max_height)
	if ($video_width > 0 && $video_height > 0) {
		// Quality floors: admin values are only honored when they are ABOVE the
		// high-quality minimums below. A stale config.local.php (or one never
		// updated on the VM) must not silently shrink the thumbs back to the
		// old 320-384px sizes. Source-sized thumbs are never upscaled anyway.
		$player_w = max(1920, (isset($config['thumbnail_player_width'])  && (int)$config['thumbnail_player_width']  > 0) ? (int)$config['thumbnail_player_width']  : 1920);
		$player_h = max(1080, (isset($config['thumbnail_player_height']) && (int)$config['thumbnail_player_height'] > 0) ? (int)$config['thumbnail_player_height'] : 1080);
		$grid_w   = max(960,  (isset($config['img_max_width'])  && (int)$config['img_max_width']  > 0) ? (int)$config['img_max_width']  : 960);
		$grid_h   = max(540,  (isset($config['img_max_height']) && (int)$config['img_max_height'] > 0) ? (int)$config['img_max_height'] : 540);

		if ($keep_ar) {
			// Preserve source aspect ratio, never upscale a small source.
			list($thumb_width_player, $thumb_height_player) = thumb_target_dims($video_width, $video_height, $player_w, $player_h);
			list($thumb_width_max, $thumb_height_max)       = thumb_target_dims($video_width, $video_height, $grid_w, $grid_h);
		} else {
			// Fill the exact target box (center-crop handled by process_thumb).
			$thumb_width_player  = $player_w;
			$thumb_height_player = $player_h;
			$thumb_width_max     = $grid_w;
			$thumb_height_max    = $grid_h;
		}
	}

	// Only continue if source video exists
	if (file_exists($video_path) || file_url_exists($video_path)) {
  	
		// Temp & Final Thumbnails Directories
		$temp_thumbs_folder  = $config['TMP_DIR'].'/thumbs/'.$video_id;
		if ($admin) {
			$final_thumbs_folder = $config['TMP_DIR'].'/thumbs/'.$video_id.'_adm';
		} else {
			$final_thumbs_folder = get_thumb_dir($video_id);			
		}
		
		// Create Thumbs Directories
		if (!file_exists($temp_thumbs_folder)) {
			@mkdir($temp_thumbs_folder, 0777);
		}
		
		if (!file_exists($final_thumbs_folder)) {		
			@mkdir($final_thumbs_folder, 0777);
		}
		// Duration - set se = start/end
		if ($duration > 5) {
			$se = 2;
		} elseif ($duration > 3) {
			$se = 1;
		} elseif ($duration > 2) {
			$se = 0.5;
		} else {
			$se = 0;
		}

		$random = rand(0,floor($duration/10));

		$se = $se + $random;
		$seconds = $duration - (2*$se);
		
		// Divided by 20 thumbs
		$timeseg = $seconds/20;

		// Loop for 20 thumbs
		for ($i=0;$i<=20;$i++) {
			if ($target == 'main' && $i == 0) {
				continue;
			}
			if ($target == 'player' && $i > 0) {
				continue;
			}			
			if ($i==0) {
				// Destination
				$final_thumbnail = $final_thumbs_folder.'/default.jpg';
				// Get Seek Time
				$ss = (rand(0,$seconds)) + $se;
			} else {
				// Destination
				$final_thumbnail = $final_thumbs_folder.'/'.$i.'.jpg';
				// Get Seek Time
				$ss = ($i * $timeseg) + $se;
			}

			// Work out seconds to hh:mm:ss format
			$hms = "";
			$hours = intval($ss / 3600); 
			$hms .= str_pad($hours, 2, "0", STR_PAD_LEFT). ':';
			$minutes = intval(($ss / 60) % 60); 
			$hms .= str_pad($minutes, 2, "0", STR_PAD_LEFT). ':';
			$secs = intval($ss % 60); 
			$hms .= str_pad($secs, 2, "0", STR_PAD_LEFT);	
			$seek = $hms;			

			// Temporary filename convention. used by ffmpeg only.
			$temp_thumbs = $temp_thumbs_folder.'/%08d.jpg'; 

			// Temporary Thumbnail File
			$temp_thumb_file = $temp_thumbs_folder.'/00000001.jpg'; 


			// Set Permission and Delete Temporary Thumbnail File
			@chmod($temp_thumb_file,0777);
			@unlink($temp_thumb_file);			

			// Thumbnails extraction commands
			if ( $config['thumbs_tool'] == 'ffmpeg' ) {
				// FFMPEG Command
				$cmd = $config['ffmpeg']." -ss ".$seek." -i ".escapeshellarg($video_path)." -frames:v 1 -an -q:v 2 -vcodec mjpeg -y ".escapeshellarg($temp_thumbs);
			} else {      
				// Mplayer Command
				$cmd = $config['mplayer']." -zoom ".escapeshellarg($video_path)." -ss ".$seek." -nosound -frames 1 -vf scale=-1:-1 -vo jpeg:outdir=".escapeshellarg($temp_thumbs_folder);
			}

			// Send data to logfile
			log_conversion($logfile, $cmd);

			// Execute Command
			exec($cmd, $output);

			// Send data to logfile
			log_conversion($logfile, implode("\n", $output));

			// Fallback resiliente: se for URL remota e o primeiro frame não gerou (ex: timeout de rede no streaming direto),
			// baixa o arquivo para TMP_DIR uma única vez e extrai dele
			if (!file_exists($temp_thumb_file) && (strpos($video_path, 'http://') === 0 || strpos($video_path, 'https://') === 0)) {
				$dl_temp = $config['TMP_DIR'] . '/thumb_dl_' . $video_id . '.mp4';
				if (!file_exists($dl_temp) || filesize($dl_temp) < 100) {
					$fp = @fopen($dl_temp, 'w+');
					if ($fp) {
						$ch = curl_init($video_path);
						curl_setopt($ch, CURLOPT_FILE, $fp);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
						curl_setopt($ch, CURLOPT_TIMEOUT, 60);
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
						curl_exec($ch);
						curl_close($ch);
						fclose($fp);
					}
				}
				if (file_exists($dl_temp) && filesize($dl_temp) > 100) {
					$video_path = $dl_temp;
					$cmd = $config['ffmpeg']." -ss ".$seek." -i ".escapeshellarg($video_path)." -frames:v 1 -an -q:v 2 -vcodec mjpeg -y ".escapeshellarg($temp_thumbs);
					exec($cmd, $output);
				}
			}

			// Check if file exists
			if (file_exists($temp_thumb_file)) {
				copy($temp_thumb_file, $final_thumbnail);
				// Set permission
				@chmod($temp_thumb_file,0777);				
			}

		}

		// Delete Temporary Downloaded Video if created
		if (isset($dl_temp) && file_exists($dl_temp)) {
			@unlink($dl_temp);
		}

		// Delete Temporary Thumbnail
		delete_directory($temp_thumbs_folder);

	}
	
	if ($black_bars) {
		$left = 0;
		$right = 0;
		$top = 0;
		$bottom = 0;
		
		for ($i=0;$i<=20;$i++) {
			if ($target == 'main' && $i == 0) {
				continue;
			}
			if ($target == 'player' && $i > 0) {
				continue;
			}			
			if ($i==0) {
				$final_thumbnail = $final_thumbs_folder.'/default.jpg';
				$bb = detect_black_bars($final_thumbnail, 0.20);
				if ($left == 0) {
					$left = $bb['left'];
				} else {
					$left = min($left, $bb['left']);
				}
				if ($right == 0) {
					$right = $bb['right'];
				} else {
					$right = min($right, $bb['right']);
				}
				if ($top == 0) {
					$top = $bb['top'];
				} else {
					$top = min($top, $bb['top']);
				}
				if ($bottom == 0) {
					$bottom = $bb['bottom'];
				} else {
					$bottom = min($bottom, $bb['bottom']);
				}				
			} else {
				$final_thumbnail = $final_thumbs_folder.'/'.$i.'.jpg';
				$bb = detect_black_bars($final_thumbnail, 0.25);
				if ($left == 0) {
					$left = $bb['left'];
				} else {
					$left = min($left, $bb['left']);
				}
				if ($right == 0) {
					$right = $bb['right'];
				} else {
					$right = min($right, $bb['right']);
				}
				if ($top == 0) {
					$top = $bb['top'];
				} else {
					$top = min($top, $bb['top']);
				}
				if ($bottom == 0) {
					$bottom = $bb['bottom'];
				} else {
					$bottom = min($bottom, $bb['bottom']);
				}				
			}
		}
	}
	for ($i=0;$i<=20;$i++) {
		if ($target == 'main' && $i == 0) {
			continue;
		}
		if ($target == 'player' && $i > 0) {
			continue;
		}			
		if ($i==0) {
			$final_thumbnail = $final_thumbs_folder.'/default.jpg';
			if ($black_bars) {
				remove_black_bars($final_thumbnail, $left, $right, $top, $bottom);
			}
			process_thumb($final_thumbnail, $thumb_width_player, $thumb_height_player, $keep_ar);
			sharp_image($final_thumbnail);
		} else {
			$final_thumbnail = $final_thumbs_folder.'/'.$i.'.jpg';
			if ($black_bars) {
				remove_black_bars($final_thumbnail, $left, $right, $top, $bottom);			
			}
			process_thumb($final_thumbnail, $thumb_width_max, $thumb_height_max, $keep_ar);
			sharp_image($final_thumbnail);					
		}
	}	
  
	return;
}


function extract_video_vthumbs($video_path, $video_id, $img_thumbs = true) {

	// High-quality hover preview (rich montage). Falls back to the legacy
	// routine below only when the HQ pass could not produce both files.
	if (extract_video_vthumbs_hq($video_path, $video_id, $img_thumbs)) {
		return true;
	}
	
	global $config, $conn;

    $duration   = get_video_duration($video_path, $video_id);

	if ($duration == 0) {
		return false;
	}

	$full=false;
	
	if ( $duration > 30 ) {
		$step	= floor($duration/14);
		$ss 	= intval($step);
	} else {
		$full	= true;
		$ss		= intval($duration/2);
	}
	
	$final_thumbs_folder = get_thumb_dir($video_id);

	@mkdir($final_thumbs_folder, 0777);
	@chmod($final_thumbs_folder, 0777);
	$cmd_parts='';

	$width  = $config['img_max_width'];
	$height = $config['img_max_height'];
	$area = $width * $height;
	$default_width = 640;

	$copy_mp4 = $final_thumbs_folder.'/video_copy.mp4';	
	$copy_webm = $final_thumbs_folder.'/video_copy.webm';
	$copy_default = $final_thumbs_folder.'/default_copy.jpg';
	$copy_thumb = $final_thumbs_folder.'/thumb_copy.jpg';

	$dst_mp4 = $final_thumbs_folder.'/video.mp4';	
	$dst_webm = $final_thumbs_folder.'/video.webm';
	$dst_default = $final_thumbs_folder.'/default.jpg';
	$dst_thumb = $final_thumbs_folder.'/thumb.jpg';

	$default_command = $config['ffmpeg']. " -ss ".$ss." -i " .$video_path. " -f image2 -vf scale='min(".$default_width."\,iw)':-1 -vframes 1 -y " .$copy_default;
	$thumb_command = $config['ffmpeg']. ' -ss '.$ss.' -i ' .$video_path. ' -f image2 -s ' .$width. 'x' .$height. ' -vframes 1 -y ' .$copy_thumb;

	if ($full != false) {
		if($config['thumbexact']=='1') {
			$webm_command =  $config['ffmpeg'].' -i '.$video_path. ' -ss 3 -filter_complex crop='.$width.':'.$height.',scale=iw:ih -codec:v libvpx -crf 20 -b:v 2500k -deadline good -cpu-used 1 -an -y '.$copy_webm;
			$ffmpeg_command =  $config['ffmpeg'].' -i '.$video_path. ' -ss 3 -filter_complex crop='.$width.':'.$height.',scale=iw:ih -codec:v libx264 -crf 18 -preset medium -an -y '.$copy_mp4;
		}	else {
			$webm_command =  $config['ffmpeg'].' -i '.$video_path. ' -ss 3 -filter_complex scale='.$width.':'.$height.' -codec:v libvpx -crf 20 -b:v 2500k -deadline good -cpu-used 1 -an -y '.$copy_webm;
			$ffmpeg_command =  $config['ffmpeg'].' -i '.$video_path. ' -ss 3 -filter_complex scale='.$width.':'.$height.' -codec:v libx264 -crf 18 -preset medium -an -y '.$copy_mp4;	
		}
	} else {		
		$i = 0;
		while($i <= 12 ) {
			$t=2; 
			$cmd_parts.= ' -ss '.$ss.' -t '.$t.' -i '.$video_path;
			$ss = $ss+$step;
			if ( $ss > $duration ) {
				$ss = $ss-$step;
			}
			++$i;
		}
		if ($config['thumbexact']=='1') {		
			$webm_command =  $config['ffmpeg'].' '.$cmd_parts. ' -filter_complex "[0][1][2][3][4][5][6][7]concat=n=8:v=1:a=0",crop='.$width.':'.$height.',scale=iw:ih -codec:v libvpx -crf 20 -b:v 2500k -deadline good -cpu-used 1 -an -y '.$copy_webm;
			$ffmpeg_command =  $config['ffmpeg'].' '.$cmd_parts. ' -filter_complex "[0][1][2][3][4][5][6][7]concat=n=8:v=1:a=0",crop='.$width.':'.$height.',scale=iw:ih -codec:v libx264 -crf 18 -preset medium -an -y '.$copy_mp4;
		} else {
			$webm_command =  $config['ffmpeg'].' '.$cmd_parts. ' -filter_complex "[0][1][2][3][4][5][6][7]concat=n=8:v=1:a=0",scale='.$width.':'.$height.' -codec:v libvpx -crf 20 -b:v 2500k -deadline good -cpu-used 1 -an -y '.$copy_webm;
			$ffmpeg_command =  $config['ffmpeg'].' '.$cmd_parts. ' -filter_complex "[0][1][2][3][4][5][6][7]concat=n=8:v=1:a=0",scale='.$width.':'.$height.' -codec:v libx264 -crf 18 -preset medium -an -y '.$copy_mp4;
		}
	
	}

	@exec($ffmpeg_command);
	@exec($webm_command );

	if($img_thumbs != false) { 
		@exec($default_command);
	}
	@exec($thumb_command);

	// Mesma validação de duração do caminho HQ: fonte corrompida aborta o
	// ffmpeg cedo e deixaria um clipe-lixo que passa no check de filesize.
	$expected_dur = ($full) ? max(0.5, $duration - 3) : 16.0; // 8 cortes x 2s
	$dur_mp4  = probe_video_duration($copy_mp4);
	$dur_webm = probe_video_duration($copy_webm);

	if( file_exists($copy_webm) && filesize($copy_webm)>100 && file_exists($copy_mp4) && filesize($copy_mp4)>100
	    && $dur_mp4 >= $expected_dur * 0.5 && $dur_webm >= $expected_dur * 0.5  ) {			
		if(file_exists($dst_webm)) @chmod($dst_webm,0777);
		if(file_exists($dst_mp4)) @chmod($dst_mp4,0777);

		@copy($copy_webm,$dst_webm); @unlink($copy_webm); 
		@copy($copy_mp4,$dst_mp4); @unlink($copy_mp4);
		if(file_exists($copy_default) && filesize($copy_default) ) {
			if(file_exists($dst_default)) @chmod($dst_default,0777);
			@copy($copy_default,$dst_default); 
			sharp_image($dst_default);
			@chmod($copy_default,0777); @unlink($copy_default);
		}
		if(file_exists($copy_thumb) && filesize($copy_thumb) ) {
			if(file_exists($dst_thumb)) @chmod($dst_thumb,0777);
			@copy($copy_thumb,$dst_thumb); 
			sharp_image($dst_thumb);
			@chmod($copy_thumb,0777); @unlink($copy_thumb);
		}
		$sql = "UPDATE video SET vthumbs = '1' WHERE VID = '".(int)$video_id."'";
		$conn->execute($sql);		
		return true;
	}
	return false;
}       

/**
 * High-quality hover preview ("quick clip").
 *
 * Builds a silent 30 fps montage of up to 8 two-second cuts spread evenly
 * through the video, centered on the clip box (like the CSS object-fit) and
 * encodes both a WebM (primary hover source) and an MP4 (fallback/iOS) at a
 * config-driven resolution with proper quality settings.
 */
function extract_video_vthumbs_hq($video_path, $video_id, $img_thumbs = true) {

	global $config, $conn;

	$duration = get_video_duration($video_path, $video_id);
	if ($duration <= 0) {
		return false;
	}

	// Hover-clip target resolution: dedicated vthumb_width/vthumb_height keys
	// win when set; otherwise follow the grid thumbnails (img_max_*).
	// Hover-clip canvas floor: never below 960x540 (matches the grid thumbs
	// and covers the hero card sharply). Admin values above the floor win.
	$clip_w = max(960, (isset($config['vthumb_width'])  && (int)$config['vthumb_width']  > 0) ? (int)$config['vthumb_width']  : 960);
	$clip_h = max(540, (isset($config['vthumb_height']) && (int)$config['vthumb_height'] > 0) ? (int)$config['vthumb_height'] : 540);
	$clip_w = (int) floor($clip_w / 2) * 2;
	$clip_h = (int) floor($clip_h / 2) * 2;
	if ($clip_w < 2) $clip_w = 2;
	if ($clip_h < 2) $clip_h = 2;

	$dim_cmd = $config['ffprobe'] . " -v error -select_streams v:0 -show_entries stream=width,height -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($video_path);
	@exec($dim_cmd, $dim_out);
	$src_w = isset($dim_out[0]) ? intval($dim_out[0]) : 0;
	$src_h = isset($dim_out[1]) ? intval($dim_out[1]) : 0;
	$is_portrait = ($src_w > 0 && $src_h > $src_w);

	$final_thumbs_folder = get_thumb_dir($video_id);

	@mkdir($final_thumbs_folder, 0777);
	@chmod($final_thumbs_folder, 0777);

	$copy_mp4     = $final_thumbs_folder.'/video_copy.mp4';
	$copy_webm    = $final_thumbs_folder.'/video_copy.webm';
	$copy_default = $final_thumbs_folder.'/default_copy.jpg';
	$copy_thumb   = $final_thumbs_folder.'/thumb_copy.jpg';

	$dst_mp4     = $final_thumbs_folder.'/video.mp4';
	$dst_webm    = $final_thumbs_folder.'/video.webm';
	$dst_default = $final_thumbs_folder.'/default.jpg';
	$dst_thumb   = $final_thumbs_folder.'/thumb.jpg';

	// --- Montage plan ----------------------------------------------------
	// Skip the first/last second (intro/outro, credits) and pick up to 8
	// two-second cuts spread evenly through the whole video.
	$seg_len  = 2.0;
	$skip     = 1.0;
	$usable   = max($duration - ($skip * 2.0), 0.1);
	$segs     = (int) min(8, max(1, floor($usable / $seg_len)));
	$starts   = array();

	if ($segs <= 1) {
		$segs     = 1;
		$seg_len  = max(0.5, $duration - ($skip * 2.0));
		$starts[] = max(0.0, ($duration - $seg_len) / 2.0);
	} else {
		$spacing = ($usable - $seg_len) / ($segs - 1);
		for ($i = 0; $i < $segs; $i++) {
			$starts[] = $skip + ($i * $spacing);
		}
	}

	// Input cuts: fast seek (input option) + short -t window per segment.
	$cmd_parts = '';
	foreach ($starts as $st) {
		$cmd_parts .= ' -ss ' . number_format($st, 3, '.', '') . ' -t ' . number_format($seg_len, 3, '.', '') . ' -i ' . escapeshellarg($video_path);
	}

	// Cover the clip box (center-crop) and normalize to 30 fps CFR so the
	// hover playback starts instantly and loops smoothly. Portrait sources are
	// composed on the 16:9 canvas: the full vertical scene centered sharp over
	// a blurred full-bleed backdrop, so the preview fills the card side-to-side
	// without cropping the subject.
	$filter = '';
	for ($i = 0; $i < $segs; $i++) {
		$filter .= '[' . $i . ':v]';
	}
	$filter .= 'concat=n=' . $segs . ':v=1:a=0[vc];';
	if ($is_portrait) {
		$filter .= '[vc]fps=30,setpts=PTS-STARTPTS[vc30];';
		$filter .= '[vc30]split=2[bgsrc][fgsrc];';
		$filter .= '[bgsrc]scale=' . $clip_w . ':' . $clip_h . ':force_original_aspect_ratio=increase,crop=' . $clip_w . ':' . $clip_h . ',boxblur=20:4[bg];';
		$filter .= '[fgsrc]scale=' . $clip_w . ':' . $clip_h . ':force_original_aspect_ratio=decrease[fg];';
		// O fps do overlay vem do próprio [vc30] (fps=30 acima). A opção 'fps'
		// do filtro overlay foi REMOVIDA no FFmpeg 9 ("Option not found"), o que
		// quebrava o clipe de vídeos verticais em silêncio (vthumbs ficava 0).
		$filter .= '[bg][fg]overlay=(W-w)/2:(H-h)/2[vout]';
	} else {
		$filter .= '[vc]scale=' . $clip_w . ':' . $clip_h . ':force_original_aspect_ratio=increase,crop=' . $clip_w . ':' . $clip_h . ',fps=30,setpts=PTS-STARTPTS[vout]';
	}

	// MP4 fallback (iOS/older Safari): high-quality H.264, faststart + short GOP.
	$mp4_cmd = $config['ffmpeg'] . $cmd_parts
		. ' -filter_complex "' . $filter . '"'
		. ' -map "[vout]"'
		. ' -c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p -profile:v main -level 4.0 -movflags +faststart -g 60 -an'
		. ' -y ' . escapeshellarg($copy_mp4);

	// WebM (primary hover source): VP9 constant-quality encode. IMPORTANT: do
	// not switch back to VP8 with "-b:v 0 -crf N" — ffmpeg ignores the CRF in
	// that mode and silently caps the stream at ~256 kb/s (verified), which is
	// what made the old previews blocky/soft. VP9 honors -crf with -b:v 0.
	$webm_cmd = $config['ffmpeg'] . $cmd_parts
		. ' -filter_complex "' . $filter . '"'
		. ' -map "[vout]"'
		. ' -c:v libvpx-vp9 -crf 33 -b:v 0 -deadline good -cpu-used 4 -row-mt 1 -pix_fmt yuv420p -g 60 -an'
		. ' -y ' . escapeshellarg($copy_webm);

	@exec($mp4_cmd);
	@exec($webm_cmd);

	// Optional poster/grid copies (legacy img_thumbs=true path) — fresh,
	// high-quality captures instead of the old hard -s distortion.
	if ($img_thumbs) {
	$poster_w = max(1920, (isset($config['thumbnail_player_width'])  && (int)$config['thumbnail_player_width']  > 0) ? (int)$config['thumbnail_player_width']  : 1920);
	$poster_h = max(1080, (isset($config['thumbnail_player_height']) && (int)$config['thumbnail_player_height'] > 0) ? (int)$config['thumbnail_player_height'] : 1080);
	$thumb_w  = max(960,  (isset($config['img_max_width'])  && (int)$config['img_max_width']  > 0) ? (int)$config['img_max_width']  : 960);
	$thumb_h  = max(540,  (isset($config['img_max_height']) && (int)$config['img_max_height'] > 0) ? (int)$config['img_max_height'] : 540);

		$ss0 = number_format($starts[0], 3, '.', '');

		$default_command = $config['ffmpeg'] . ' -ss ' . $ss0 . ' -i ' . escapeshellarg($video_path)
			. ' -frames:v 1 -q:v 2 -vf scale=' . $poster_w . ':' . $poster_h . ':force_original_aspect_ratio=decrease -an -y ' . escapeshellarg($copy_default);
		$thumb_command = $config['ffmpeg'] . ' -ss ' . $ss0 . ' -i ' . escapeshellarg($video_path)
			. ' -frames:v 1 -q:v 2 -vf scale=' . $thumb_w . ':' . $thumb_h . ':force_original_aspect_ratio=decrease -an -y ' . escapeshellarg($copy_thumb);

		@exec($default_command);
		@exec($thumb_command);
	}

	// Valida a duração real dos encodes: fonte corrompida aborta o ffmpeg cedo
	// e deixa arquivos pequenos que passariam no check de filesize acima. A
	// montagem deve ter ~segs x seg_len segundos; tolerância de 50% cobre
	// cortes de GOP sem aceitar lixo (ex.: clipe de 0.4s para 16s esperados).
	$expected_dur = max(0.5, $segs * $seg_len);
	$dur_mp4  = probe_video_duration($copy_mp4);
	$dur_webm = probe_video_duration($copy_webm);

	if (file_exists($copy_webm) && filesize($copy_webm) > 100
	    && file_exists($copy_mp4) && filesize($copy_mp4) > 100
	    && $dur_mp4 >= $expected_dur * 0.5 && $dur_webm >= $expected_dur * 0.5) {
		if (file_exists($dst_webm)) @chmod($dst_webm, 0777);
		if (file_exists($dst_mp4)) @chmod($dst_mp4, 0777);

		@copy($copy_webm, $dst_webm); @unlink($copy_webm);
		@copy($copy_mp4, $dst_mp4); @unlink($copy_mp4);

		if ($img_thumbs) {
			if (file_exists($copy_default) && filesize($copy_default)) {
				if (file_exists($dst_default)) @chmod($dst_default, 0777);
				@copy($copy_default, $dst_default);
				sharp_image($dst_default);
				@chmod($copy_default, 0777); @unlink($copy_default);
			}
			if (file_exists($copy_thumb) && filesize($copy_thumb)) {
				if (file_exists($dst_thumb)) @chmod($dst_thumb, 0777);
				@copy($copy_thumb, $dst_thumb);
				sharp_image($dst_thumb);
				@chmod($copy_thumb, 0777); @unlink($copy_thumb);
			}
		} else {
			@unlink($copy_default);
			@unlink($copy_thumb);
		}

		$sql = "UPDATE video SET vthumbs = '1' WHERE VID = '" . (int)$video_id . "'";
		$conn->execute($sql);
		return true;
	}

	@unlink($copy_mp4);
	@unlink($copy_webm);
	@unlink($copy_default);
	@unlink($copy_thumb);
	return false;
}

function sharp_image($image) {
	if(file_exists($image)) {
		$img = imagecreatefromjpeg($image);
		$sharpen = array(
			array(0,  -1,  0),
			array(-1, 28, -1),
			array(0,  -1,  0),
		);
		$divisor = array_sum(array_map('array_sum', $sharpen));
		imageconvolution($img, $sharpen, $divisor, 0);
		imagejpeg($img, $image, 95);
	}
}

function log_conversion($file_path, $text)
{   
    $file_dir = dirname($file_path);
    if( !file_exists($file_dir) or !is_dir($file_dir) or !is_writable($file_dir) ) {
        return false;
    }
                    
    $write_mode = 'w';
    if( file_exists($file_path) && is_file($file_path) && is_writable($file_path) ) {
        $write_mode = 'a';
    }
                                
    if( !$handle = fopen($file_path, $write_mode) ) {
        return false;
    }
                                                
    if( fwrite($handle, $text. "\n") == false ) {
        return false;
    }
                                                            
    @fclose($handle);
}

/**
 * Resolves the secondary server row linked to a video.
 *
 * The video.server column stores the server's video_url; we join back to the
 * servers table exactly like the original duplicated queries did.
 *
 * @param array $video Video row (must contain VID and server)
 * @return array|false Server row or false when the video has no server
 */
function get_video_server($video)
{
    global $conn;

    if (empty($video['server'])) {
        return false;
    }

    $sql = "SELECT * FROM video v, servers s
            WHERE v.VID = " . intval($video['VID']) . " AND v.server = s.video_url LIMIT 1";
    $rs  = $conn->execute($sql);

    if ( $conn->Affected_Rows() == 1 ) {
        return $rs->fields;
    }

    return false;
}

/**
 * Builds the playback sources for a video — single source of truth for the
 * URL logic that used to be duplicated in video.php, view.php, embed.php and
 * the download endpoints.
 *
 * URL strategy per server type:
 *   - gcs   : V4 signed URLs (short-lived, bucket stays PRIVATE)
 *   - ftp   : public URL on the secondary server (video_url/h264/...)
 *   - local : this site's own media/videos directory
 *
 * When $mykey and $iv are given, every URL is additionally obfuscated with the
 * legacy encryptPhp() layer (AES-256-CBC) so the existing Video.js client-side
 * decryption pipeline keeps working unchanged. The Media Bunny engine receives
 * the plain URLs (they are expiring signed URLs anyway).
 *
 * @param array       $video Video row
 * @param string|null $mykey Optional AES obfuscation key (8 chars)
 * @param string|null $iv    Optional AES obfuscation IV (16 chars)
 * @return array
 */
function get_video_sources($video, $mykey = null, $iv = null)
{
    global $config;

    $sources = array(
        'files'       => array(),
        'iphone_url'  => null,
        'hd_url'      => null,
        'server_type' => 'local',
        'server'      => false
    );

    $server = get_video_server($video);
    $sources['server'] = $server;

    $serverType = 'local';
    if ($server) {
        $serverType = (isset($server['server_type']) && $server['server_type'] === 'gcs') ? 'gcs' : 'ftp';
    }
    $sources['server_type'] = $serverType;

    // --- GCS: prepare the signer (V4 signed URLs) ---
    $gcs  = null;
    $ttl  = 21600;
    $sign = false;

    if ($serverType === 'gcs' && !empty($server['gcs_key_path']) && !empty($server['gcs_bucket'])) {
        $keyPath = $server['gcs_key_path'];
        if (!file_exists($keyPath)) {
            $relative = $config['BASE_DIR'] . '/' . $keyPath;
            if (file_exists($relative)) {
                $keyPath = $relative;
            }
        }
        if (file_exists($keyPath)) {
            require_once $config['BASE_DIR'] . '/classes/gcs.class.php';
            $gcs  = new GCS($keyPath, $server['gcs_bucket']);
            $ttl  = (isset($server['gcs_signed_ttl']) && intval($server['gcs_signed_ttl']) > 0)
                  ? intval($server['gcs_signed_ttl']) : 21600;
            $sign = true;
        }
    }

    // --- Non-GCS: plain root URL ---
    $videoRoot = '';
    if (!$sign) {
        if ($server) {
            $videoRoot = rtrim($server['video_url'], '/');
        }
        if ($videoRoot === '') {
            $videoRoot          = $config['BASE_URL'] . '/media/videos';
            $sources['server_type'] = 'local';
        }
    }

    /**
     * Builds the playback URL for an object path (GCS: "h264/12/480p.mp4",
     * local/FTP: "h264/12_480p.mp4").
     * @param string $object
     * @return string
     */
    $makeUrl = function ($object) use ($sign, $gcs, $ttl, $videoRoot) {
        if ($sign) {
            $signed = $gcs->getSignedUrl($object, $ttl);
            return $signed !== false ? $signed : $videoRoot . '/' . $object;
        }
        return $videoRoot . '/' . ltrim($object, '/');
    };

    // --- Regular formats ---
    // GCS bucket is organized per video: h264/{VID}/{label}.{ext}
    // (e.g. h264/88/720p.mp4). Local/FTP keep the legacy flat layout
    // h264/{VID}_{label}.{ext} (local files on the VM are flat).
    $formats = array();
    if (!empty($video['formats'])) {
        $formats = explode(',', $video['formats']);
    }

    $vid = intval($video['VID']);

    foreach ($formats as $value) {
        $f = explode('.', trim($value));
        if (count($f) < 3) {
            continue;
        }

        $file   = $vid . '_' . $f[1] . '.' . $f[2]; // legacy flat name (info only)
        $object = ($sources['server_type'] === 'gcs')
                ? 'h264/' . $vid . '/' . $f[1] . '.' . $f[2]
                : 'h264/' . $file;
        $url    = $makeUrl($object);

        $entry = array(
            'height' => $f[0],
            'label'  => $f[1],
            'format' => $f[2],
            'file'   => $file,
            'url'    => $url
        );

        // Legacy AES obfuscation layer (Video.js pipeline) — optional
        if ($mykey !== null && $iv !== null && $mykey !== '' && $iv !== '') {
            $entry['url'] = encryptPhp($url, $mykey, $iv);
        }

        $sources['files'][] = $entry;
    }

    // --- iphone / hd single-file formats (used when video.iphone / video.hd) ---
    if (isset($video['iphone']) && intval($video['iphone']) == 1) {
        $sources['iphone_url'] = $makeUrl('iphone/' . $vid . '.mp4');
    }
    if (isset($video['hd']) && intval($video['hd']) == 1) {
        $sources['hd_url'] = $makeUrl('hd/' . $vid . '.mp4');
    }

    return $sources;
}
?>