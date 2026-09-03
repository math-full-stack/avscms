<?php
if (isset($_GET['lang']) && $_GET['lang'] !== '') {
	if (wp_file_name_ok($_GET['lang'])) {
		$lang_include = $_GET['lang'];
	} else {
		$lang_include = DEFAULT_LANG;
	}
} else if (isset($_POST['lang']) && $_POST['lang'] !== '') {
	if (wp_file_name_ok($_POST['lang'])) {
		$lang_include = $_POST['lang'];
	} else {
		$lang_include = DEFAULT_LANG;
	}
} else {
	$lang_include = DEFAULT_LANG;
}
?>
