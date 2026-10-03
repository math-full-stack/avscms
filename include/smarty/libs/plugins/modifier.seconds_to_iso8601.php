<?php
/**
 * Smarty modifier to convert seconds to ISO 8601 duration format
 * Example: 3661 -> PT1H1M1S
 */
function smarty_modifier_seconds_to_iso8601($seconds) {
    $seconds = intval($seconds);
    if ($seconds <= 0) {
        return 'PT0S';
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $result = 'PT';
    if ($hours > 0) {
        $result .= $hours . 'H';
    }
    if ($minutes > 0) {
        $result .= $minutes . 'M';
    }
    $result .= $secs . 'S';
    
    return $result;
}