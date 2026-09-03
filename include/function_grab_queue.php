<?php
/*|-------------------------------------------------
|*|	Mass Grabber - Real-time Queue Processing
|*|-------------------------------------------------
|*/	

defined('_VALID') or die('Restricted Access!');

/**
 * Check if grabber_settings table exists.
 * @return bool
 */
function grabber_settings_exist() {
    global $conn;
    static $checked = null;
    if ($checked !== null) return $checked;
    $rs = @$conn->Execute("SHOW TABLES LIKE 'grabber_settings'");
    $checked = ($rs && $rs->RecordCount() > 0);
    return $checked;
}

/**
 * Get a setting from grabber_settings table.
 * @param string $key
 * @param string $default
 * @return string
 */
function grabber_setting($key, $default = '') {
    global $conn;
    if (!grabber_settings_exist()) return $default;
    $rs = $conn->Execute("SELECT setting_value FROM grabber_settings WHERE setting_key = " . $conn->qStr($key) . " LIMIT 1");
    if (!$rs || $rs->EOF) return $default;
    return $rs->fields['setting_value'];
}

/**
 * Set a setting in grabber_settings table.
 * @param string $key
 * @param string $value
 * @return bool
 */
function grabber_setting_set($key, $value) {
    global $conn;
    if (!grabber_settings_exist()) return false;
    $existing = $conn->Execute("SELECT setting_key FROM grabber_settings WHERE setting_key = " . $conn->qStr($key) . " LIMIT 1");
    if ($existing && $existing->RecordCount() > 0) {
        $conn->Execute("UPDATE grabber_settings SET setting_value = " . $conn->qStr($value) . ", updated_at = " . time() . " WHERE setting_key = " . $conn->qStr($key) . " LIMIT 1");
    } else {
        $conn->Execute("INSERT INTO grabber_settings (setting_key, setting_value, updated_at) VALUES (" . $conn->qStr($key) . ", " . $conn->qStr($value) . ", " . time() . ")");
    }
    return true;
}

/**
 * Real-time grab queue processing.
 * Called on every page load (like check_q() for conversion queue).
 * When realtime_enabled = 1, triggers grabber_cron.php in background.
 * Rate-limited to once every 30 seconds via lock file.
 */
function check_grab_queue() {
    global $config, $conn;

    // Safety: skip if table doesn't exist
    if (!grabber_settings_exist()) return;

    // Check if realtime is enabled
    if (grabber_setting('realtime_enabled', '0') !== '1') return;

    // Rate limit: don't run more than once every 30 seconds
    $lockFile = $config['BASE_DIR'] . '/cache/grabber_rt.lock';
    $lockDir = dirname($lockFile);
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

    if (file_exists($lockFile)) {
        $lastRun = intval(@file_get_contents($lockFile));
        if (time() - $lastRun < 30) return;
    }
    @file_put_contents($lockFile, time());

    // Spawn grabber_cron.php in background
    $cronScript = $config['BASE_DIR'] . '/scripts/grabber_cron.php';
    if (!file_exists($cronScript)) return;

    $cmd = sprintf('%s %s > /dev/null 2>&1 &',
        escapeshellarg($config['phppath']),
        escapeshellarg($cronScript)
    );
    @shell_exec($cmd);
}

?>
