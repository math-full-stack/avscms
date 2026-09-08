<?php
defined('_VALID') or die('Restricted Access!');

class Session
{
    private static $_sess_db;

    private static function ensureConnection() {
        global $config;
    
        if (!self::$_sess_db || !@mysqli_ping(self::$_sess_db)) {
            $host = $config['db_host'];
            $port = 3306;
            $socket = null;

            // Cloud Run: use Unix socket for Cloud SQL Auth Proxy
            if (isset($_ENV['K_SERVICE']) && file_exists('/cloudsql/novinhasbr:southamerica-east1:pornozinho-sql')) {
                $host = 'localhost';
                $port = 0;
                $socket = '/cloudsql/novinhasbr:southamerica-east1:pornozinho-sql';
            } elseif (preg_match('/^(.+):(\d+)$/', $host, $m)) {
                $host = $m[1];
                $port = intval($m[2]);
            }

            if ($socket) {
                if (self::$_sess_db = @mysqli_connect('localhost', $config['db_user'], $config['db_pass'], null, 0, $socket)) {
                    mysqli_select_db(self::$_sess_db, $config['db_name']);
                    mysqli_query(self::$_sess_db, "SET SESSION wait_timeout = 300");
                    return true;
                }
            } else {
                if (self::$_sess_db = @mysqli_connect($host, $config['db_user'], $config['db_pass'], null, $port)) {
                    mysqli_select_db(self::$_sess_db, $config['db_name']);
                    mysqli_query(self::$_sess_db, "SET SESSION wait_timeout = 300");
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    public static function open() {
        return self::ensureConnection();
    }

    public static function close() {
        return mysqli_close(self::$_sess_db);
    }

    public static function read($session_id) {
        if (!self::ensureConnection()) return '';
        
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
        if (!self::ensureConnection()) return false;
        
	    $sql = sprintf("REPLACE INTO `sessions` VALUES('%s', '%s', '%s')", mysqli_real_escape_string(self::$_sess_db, $session_id),
						mysqli_real_escape_string(self::$_sess_db, time()), mysqli_real_escape_string(self::$_sess_db, $session_data) );
		
        return mysqli_query(self::$_sess_db, $sql);
	}

    public static function destroy( $session_id )
    {
        if (!self::ensureConnection()) return false;
        
	    $sql = sprintf("DELETE FROM `sessions` WHERE `session` = '%s'", $session_id);
		return mysqli_query(self::$_sess_db, $sql);
	}
    
    public static function gc($max) {
        if (!self::ensureConnection()) return false;
        
	    $sql = sprintf("DELETE FROM `sessions` WHERE `session_expires` < '%s'", mysqli_real_escape_string(self::$_sess_db, time() - $max));
		return mysqli_query(self::$_sess_db, $sql);
	}
}
?>
