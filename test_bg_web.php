<?php
define('_VALID', true);
define('_ADMIN', true);
require 'include/config.php';
$cmd = "nohup '". $config['phppath'] ."' -r 'file_put_contents(\"/tmp/test_bg_web.log\", \"hello web \".time().\"\\n\", FILE_APPEND);' > /dev/null 2>&1 & echo $!";
$pid = shell_exec($cmd);
echo "PID: $pid\n";
echo "cmd: $cmd\n";
sleep(1);
echo "exists: ".file_exists("/tmp/test_bg_web.log")."\n";
echo @file_get_contents("/tmp/test_bg_web.log")."\n";
