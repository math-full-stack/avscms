<?php
defined('_VALID') or die('Restricted Access!');
$config = array();
$config['BASE_URL'] = 'http://localhost/avscms';
$config['RELATIVE'] = '/avscms';
$config['BASE_DIR'] = dirname(dirname(__FILE__));
$config['TMP_DIR'] = $config['BASE_DIR']. '/tmp';
$config['TMP_URL'] = $config['BASE_URL']. '/tmp';
$config['LOG_DIR'] = $config['BASE_DIR']. '/tmp/logs';
$config['IMG_DIR'] = $config['BASE_DIR']. '/images';
$config['IMG_URL'] = 'https://novinhasbr.net/images';
$config['PHO_DIR'] = $config['BASE_DIR']. '/media/users';
$config['PHO_URL'] = 'https://novinhasbr.net/media/users';
$config['VDO_DIR'] = $config['BASE_DIR']. '/media/videos/vid';
$config['VDO_URL'] = 'https://novinhasbr.net/media/videos/vid';
$config['FLVDO_DIR'] = $config['BASE_DIR']. '/media/videos/flv';
$config['FLVDO_URL'] = 'https://novinhasbr.net/media/videos/flv';
$config['TMB_DIR'] = $config['BASE_DIR']. '/media/videos/tmb';
$config['TMB_URL'] = 'https://novinhasbr.net/media/videos/tmb';

$config['HD_DIR'] = $config['BASE_DIR'].'/media/videos/hd';
$config['HD_URL'] = 'https://novinhasbr.net/media/videos/hd';
$config['IPHONE_DIR'] = $config['BASE_DIR'].'/media/videos/iphone';
$config['IPHONE_URL'] = 'https://novinhasbr.net/media/videos/iphone';
$config['H264_DIR'] = $config['BASE_DIR'].'/media/videos/h264';
$config['H264_URL'] = 'https://novinhasbr.net/media/videos/h264';		
?>
