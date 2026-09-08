<?php
defined('_VALID') or die('Restricted Access!');

class Session
{
    private static function conn() {
        global $conn;
        return $conn;
    }

    public static function open() {
        return true;
    }

    public static function close() {
        return true;
    }

    public static function read($session_id) {
        $c = self::conn();
        if (!$c) return '';

        $rs = $c->Execute("SELECT `session_data` FROM `sessions` WHERE `session_id` = " . $c->qStr($session_id));
        if ($rs && !$rs->EOF) {
            return $rs->fields['session_data'];
        }
        return '';
    }

    public static function write($session_id, $session_data)
    {
        $c = self::conn();
        if (!$c) return false;

        $c->Execute("REPLACE INTO `sessions` VALUES(" . $c->qStr($session_id) . ", " . $c->qStr(time()) . ", " . $c->qStr($session_data) . ")");
        return true;
    }

    public static function destroy($session_id)
    {
        $c = self::conn();
        if (!$c) return false;

        $c->Execute("DELETE FROM `sessions` WHERE `session_id` = " . $c->qStr($session_id));
        return true;
    }

    public static function gc($max) {
        $c = self::conn();
        if (!$c) return false;

        $c->Execute("DELETE FROM `sessions` WHERE `session_expires` < " . $c->qStr(time() - $max));
        return true;
    }
}
?>
