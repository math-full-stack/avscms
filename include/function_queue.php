<?php
/*|-------------------------------------------------
|*|	AVS Conversion Queue Functions
|*|-------------------------------------------------
|*/	

defined('_VALID') or die('Restricted Access!');

function remove_overdue($table) {
	
	global $config, $conn;	
	$timeout = $config['q_timeout'];
	$overdue = time() - ($timeout * 3600);
	$sql= "DELETE FROM ".$table." WHERE status = '1' AND start < '".$overdue."'";
	$conn->execute($sql);	
}

function active_conversions($table) {
	global $conn;		
	$sql	= "SELECT * FROM ".$table." WHERE status = '1'";
	$rs  	= $conn->execute($sql);	
	return $conn->Affected_Rows();
}

function check_q() {

	global $config, $conn;
	remove_overdue('conversion_queue_fp');

	if (active_conversions('conversion_queue_fp') < intval($config['q_limit'])) {
		
		$sql	= "SELECT * FROM conversion_queue_fp WHERE status = '0' ORDER BY addtime ASC LIMIT ".intval($config['q_limit']);
		$rs 	= $conn->execute($sql);
		if ($conn->Affected_Rows()>0) {
			$videos = $rs->getrows();
			foreach($videos as $video) {				
				$video_name	= $video['video_name'];
				$video_id 	= $video['VID'];
				$video_path = $video['video_path'];
				// Skip if video was suspended (active=0) or deleted – remove from queue
				$chk = $conn->execute("SELECT active FROM video WHERE VID = '".intval($video_id)."' LIMIT 1");
				if ($conn->Affected_Rows() != 1 || $chk->fields['active'] == '0') {
					$conn->execute("DELETE FROM conversion_queue_fp WHERE VID = '".intval($video_id)."' LIMIT 1");
					continue;
				}
				$script 	= $config['BASE_DIR']."/scripts/convert_videos_fp.php";
				if(file_exists($video_path)) {
					$cmd = $config['phppath']." ".$script." ".$video_name." ".$video_id." ".$video_path."";
					$sql = "UPDATE conversion_queue_fp SET status='1', start = '".time()."' WHERE VID = '".$video_id."' LIMIT 1";
					$conn->execute($sql);
					// Atualizar last_update para防止 timeout
					$conn->execute("UPDATE video SET last_update = '".time()."' WHERE VID = '".intval($video_id)."' LIMIT 1");
					log_in_back($config['LOG_DIR']. '/' .$video_id. '.log', $cmd);
					$lg = $config['LOG_DIR']. '/' .$video_id. '.log2';
					run_in_bg($cmd.' > '.$lg);	
					return true;
				} else {
					$sql = "DELETE FROM conversion_queue_fp WHERE VID = '".$video_id."' LIMIT 1";
					$conn->execute($sql);
				}
			}
			unset($videos);
		}
	}

	remove_overdue('conversion_queue_sp');

	$sp_limit = max(1, intval(isset($config['q_limit']) ? $config['q_limit'] : 1));
	if (active_conversions('conversion_queue_sp') < $sp_limit) {

		$sql = "SELECT * FROM conversion_queue_sp WHERE STATUS = '0' ORDER BY addtime LIMIT 1";
		$rs = $conn->execute($sql);	
		if ($conn->Affected_Rows()==1) {
			$video_name = $rs->fields['video_name'];
			$video_id 	= $rs->fields['VID'];
			$video_path = $rs->fields['video_path'];
			$skip 		= $rs->fields['skip'];
			// Skip if video was suspended (active=0) or deleted – remove from queue
			$chk = $conn->execute("SELECT active FROM video WHERE VID = '".intval($video_id)."' LIMIT 1");
			if ($conn->Affected_Rows() != 1 || $chk->fields['active'] == '0') {
				$conn->execute("DELETE FROM conversion_queue_sp WHERE VID = '".intval($video_id)."' LIMIT 1");
				return false;
			}
			$script 	= $config['BASE_DIR']."/scripts/convert_videos_sp.php";
			if(file_exists($video_path)) {					
				$cmd = $config['phppath']." ".$script." ".$video_name." ".$video_id." ".$video_path." ".$skip."";
				$sql = "UPDATE conversion_queue_sp SET status='1', start = '".time()."' WHERE VID = '".$video_id."' LIMIT 1";
				$conn->execute($sql);
				// Atualizar last_update para防止 timeout
				$conn->execute("UPDATE video SET last_update = '".time()."' WHERE VID = '".intval($video_id)."' LIMIT 1");
				log_in_back($config['LOG_DIR']. '/' .$video_id. '.log', $cmd);
				$lg = $config['LOG_DIR']. '/' .$video_id. '.log3';
				run_in_bg($cmd.' > '.$lg);	
				return true;
			} else {
				$sql = "DELETE FROM conversion_queue_sp WHERE VID = '".$video_id."' LIMIT 1";
				$conn->execute($sql);			
			}
		}
	}
	return false;
}

function pump_conversion_queue() {
    global $config, $conn;

    // Starts queued full-process (h264 multi-format) conversions up to the
    // configured "Max Simultaneous Conversions" (config q_limit). Stock
    // check_q() only starts ONE conversion per call, so after a batch of
    // grabs the queue drains slowly (1 per web hit / cron tick). This lets
    // the mass-grabber cron actually saturate the queue like the panel
    // setting promises, without changing the web request behaviour.
    if (!isset($config['conversion_q']) || $config['conversion_q'] != '1') {
        return 0;
    }
    remove_overdue('conversion_queue_fp');
    remove_overdue('conversion_queue_sp');

    $limit = max(1, intval(isset($config['q_limit']) ? $config['q_limit'] : 1));
    $started = 0;

    // 1. Pump first pass queue (conversion_queue_fp)
    while ($started < $limit) {
        if (active_conversions('conversion_queue_fp') >= $limit) {
            break;
        }
        $rs = $conn->execute("SELECT * FROM conversion_queue_fp WHERE status = '0' ORDER BY addtime ASC LIMIT 1");
        if ($conn->Affected_Rows() < 1) {
            break;
        }
        $video = $rs->fields;
        $video_name = $video['video_name'];
        $video_id   = intval($video['VID']);
        $video_path = $video['video_path'];

        // Skip suspended (active=0) or deleted videos - drop them from queue
        $chk = $conn->execute("SELECT active FROM video WHERE VID = '" . $video_id . "' LIMIT 1");
        if ($conn->Affected_Rows() != 1 || $chk->fields['active'] == '0') {
            $conn->execute("DELETE FROM conversion_queue_fp WHERE VID = '" . $video_id . "' LIMIT 1");
            continue;
        }
        if (!file_exists($video_path)) {
            $conn->execute("DELETE FROM conversion_queue_fp WHERE VID = '" . $video_id . "' LIMIT 1");
            continue;
        }

        $script = $config['BASE_DIR'] . "/scripts/convert_videos_fp.php";
        $cmd = $config['phppath'] . " " . $script . " " . $video_name . " " . $video_id . " " . $video_path;
        $conn->execute("UPDATE conversion_queue_fp SET status='1', start = '" . time() . "' WHERE VID = '" . $video_id . "' LIMIT 1");
        $conn->execute("UPDATE video SET last_update = '" . time() . "' WHERE VID = '" . $video_id . "' LIMIT 1");
        log_in_back($config['LOG_DIR'] . '/' . $video_id . '.log', $cmd);
        $lg = $config['LOG_DIR'] . '/' . $video_id . '.log2';
        run_in_bg($cmd . ' > ' . $lg);
        $started++;
    }

    // 2. Pump second pass queue (conversion_queue_sp)
    while (active_conversions('conversion_queue_sp') < $limit) {
        $rs = $conn->execute("SELECT * FROM conversion_queue_sp WHERE status = '0' ORDER BY addtime ASC LIMIT 1");
        if ($conn->Affected_Rows() < 1) {
            break;
        }
        $video = $rs->fields;
        $video_name = $video['video_name'];
        $video_id   = intval($video['VID']);
        $video_path = $video['video_path'];
        $skip       = $video['skip'];

        $chk = $conn->execute("SELECT active FROM video WHERE VID = '" . $video_id . "' LIMIT 1");
        if ($conn->Affected_Rows() != 1 || $chk->fields['active'] == '0') {
            $conn->execute("DELETE FROM conversion_queue_sp WHERE VID = '" . $video_id . "' LIMIT 1");
            continue;
        }
        if (!file_exists($video_path)) {
            $conn->execute("DELETE FROM conversion_queue_sp WHERE VID = '" . $video_id . "' LIMIT 1");
            continue;
        }

        $script = $config['BASE_DIR'] . "/scripts/convert_videos_sp.php";
        $cmd = $config['phppath'] . " " . $script . " " . $video_name . " " . $video_id . " " . $video_path . " " . $skip;
        $conn->execute("UPDATE conversion_queue_sp SET status='1', start = '" . time() . "' WHERE VID = '" . $video_id . "' LIMIT 1");
        $conn->execute("UPDATE video SET last_update = '" . time() . "' WHERE VID = '" . $video_id . "' LIMIT 1");
        log_in_back($config['LOG_DIR'] . '/' . $video_id . '.log', $cmd);
        $lg = $config['LOG_DIR'] . '/' . $video_id . '.log3';
        run_in_bg($cmd . ' > ' . $lg);
        $started++;
    }

    return $started;
}

function insert_into_q_fp($vid, $video_name, $video_path) {

	global $conn; 

	$sql	= "SELECT * FROM conversion_queue_fp WHERE VID = '".$vid."' LIMIT 1";
	$rs 	= $conn->execute($sql);
	if ($conn->Affected_Rows()!=1) {
		$sql 	= "SELECT * FROM video WHERE VID = '".$vid."' LIMIT 1";
		$rs		= $conn->execute($sql);
		$uid 	= $rs->fields['UID'];
		$title 	= $rs->fields['title'];
		$sql 	= "INSERT INTO conversion_queue_fp SET 
					VID = '".intval($vid)."', 
					UID = '".intval($uid)."', 
					video_name = ".$conn->qStr($video_name).", 
					video_path = ".$conn->qStr($video_path).", 
					title = ".$conn->qStr($title).", 
					addtime = '".time()."'";
		$conn->execute($sql);
		// Atualizar last_update para防止 timeout
		$conn->execute("UPDATE video SET last_update = '".time()."' WHERE VID = '".intval($vid)."' LIMIT 1");
	}
	check_q();	
}

function insert_into_q_sp($vid, $skip) {

	global $conn;

	$sql 	= "SELECT * FROM conversion_queue_sp WHERE VID = '".$vid."' LIMIT 1";
	$rs	 	= $conn->execute($sql);
	if ($conn->Affected_Rows()!=1) {
		$sql 	= "SELECT * FROM conversion_queue_fp WHERE VID = '".$vid."' LIMIT 1";
		$rs		= $conn->execute($sql);
		if ($conn->Affected_Rows()==1) {
			$uid 		= $rs->fields['UID'];
			$video_name = $rs->fields['video_name'];
			$video_path = $rs->fields['video_path'];
			$title 		= $rs->fields['title'];
			$sql 		= "INSERT INTO conversion_queue_sp SET 
							VID = '".$vid."', 
							UID = '".intval($uid)."', 
							video_name = ".$conn->qStr($video_name).", 
							video_path = ".$conn->qStr($video_path).", 
							skip = '".$skip."', 
							title = ".$conn->qStr($title).", 
							addtime = '".time()."'";
			$conn->execute($sql);
		}
	}
	check_q();	
}

function log_in_back ($file_path, $text) {   

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
                                                
    if( fwrite($handle, $text. "\n") == FALSE ) {
        return false;
    }
                                                            
    @fclose($handle);
} 

function run_in_bg($Command, $Priority = 0) {
	// Se o comando ja tem redirecionamento (contém '>'), não sobrescrever para /dev/null
	// Caso contrário mantém comportamento antigo
	if (strpos($Command, '>') !== false) {
		if($Priority) {
			$PID = shell_exec("nice -n $Priority $Command 2>&1 & echo $!");
		} else {
			$PID = shell_exec("$Command 2>&1 & echo $!");
		}
	} else {
		if($Priority) {
			$PID = shell_exec("nice -n $Priority $Command > /dev/null 2>&1 & echo $!");
		} else {
			$PID = shell_exec("$Command > /dev/null 2>&1 & echo $!");
		}
	}
	return($PID);
}

?>