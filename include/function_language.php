<?php
defined('_VALID') or die('Restricted Access!');

if (extension_loaded('mbstring')) {
    @ini_set('mbstring.internal_encoding', 'UTF-8');
    define('MB_STRING', TRUE);
} else {
    define('MB_STRING', FALSE);
}

if ($config['force_utf8'] == '1') {
	if (!preg_match('/^.$/u', 'ñ')) {
  		die('<a href="http://php.net/pcre">PCRE</a> has not been compiled with UTF-8 support. '.
      		'See <a href="http://php.net/manual/reference.pcre.pattern.modifiers.php">PCRE Pattern Modifiers</a> '.
      		'for more information. This application cannot be run without UTF-8 support.');
	}
}

if (!MB_STRING) {
	function mb_strlen($string)
	{
		return strlen($string);
	}
	
	function mb_strpos($string, $needle, $offset=NULL)
	{
		return strpos($string, $needle, $offset);
	}
	
	function mb_strtolower($string)
	{
		return strtolower($string);
	}
	
	function mb_strtoupper($string)
	{
		return strtoupper($string);
	}
	
	function mb_substr($string, $start, $length=NULL)
	{
		return substr($string, $start, $length);
	}
}

function insert_language($options)
{
	global $languages;
	return $languages[$_SESSION['language']]['flag'];
}
?>
