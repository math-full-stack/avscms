<?php
defined('_VALID') or die('Restricted Access!');

class Session
{
    private static $_sess_db;

    public static function open() {
        global $config;    
    
        $host = $config['db_host'];
        $port = 3306;
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = intval($m[2]);
        }
        if (self::$_sess_db = @mysqli_connect($host, $config['db_user'], $config['db_pass'], null, $port)) {
            return mysqli_select_db(self::$_sess_db, $config['db_name']);
        }
        
        return false;
    }

    public static function close() {
        return mysqli_close(self::$_sess_db);
    }

    public static function read($session_id) {
        $sql = sprintf("SELECT `session_data` FROM `sessions` WHERE `session_id` = '%s'", mysqli_real_escape_string(self::$_sess_db, $session_id));
        if ($result = mysqli_query(self::$_sess_db, $sql)) {
            if (mysqli_num_rows($result)) {
                $record = mysqli_fetch_assoc($result);
                return $record['session_data'];
            }
        }

        return '';
    }

    public static function write($session_id, $session_data)
    {
	    $sql = sprintf("REPLACE INTO `sessions` VALUES('%s', '%s', '%s')", mysqli_real_escape_string(self::$_sess_db, $session_id),
						mysqli_real_escape_string(self::$_sess_db, time()), mysqli_real_escape_string(self::$_sess_db, $session_data) );
		
        return mysqli_query(self::$_sess_db, $sql);
	}

    public static function destroy( $session_id )
    {
	    $sql = sprintf("DELETE FROM `sessions` WHERE `session` = '%s'", $session_id);
		return mysqli_query(self::$_sess_db, $sql);
	}
    
    public static function gc($max) {
	    $sql = sprintf("DELETE FROM `sessions` WHERE `session_expires` < '%s'", mysqli_real_escape_string(self::$_sess_db, time() - $max));
		return mysqli_query(self::$_sess_db, $sql);
	}
}
?>
