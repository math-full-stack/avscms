<?php
$tests = [
 "nohup php" => "nohup '/Applications/XAMPP/xamppfiles/bin/php' -r 'file_put_contents(\"/tmp/test_bg2.log\", \"nohup \".time().\"\\n\", FILE_APPEND);' > /dev/null 2>&1 & echo \$!",
 "php bg" => "'/Applications/XAMPP/xamppfiles/bin/php' -r 'file_put_contents(\"/tmp/test_bg2.log\", \"bg \".time().\"\\n\", FILE_APPEND);' > /dev/null 2>&1 & echo \$!",
 "bash -c" => "bash -c '\"/Applications/XAMPP/xamppfiles/bin/php\" -r '\''file_put_contents(\"/tmp/test_bg2.log\", \"bash \".time().\"\\n\", FILE_APPEND);'\'' > /dev/null 2>&1 &' ; echo \$!",
 "nohup bash" => "nohup bash -c '\"/Applications/XAMPP/xamppfiles/bin/php\" -r '\''file_put_contents(\"/tmp/test_bg2.log\", \"nohupbash \".time().\"\\n\", FILE_APPEND);'\'' > /dev/null 2>&1 &' ; echo \$!",
];
foreach($tests as $name=>$cmd){
  echo "=== $name ===\n";
  echo "CMD: $cmd\n";
  $pid = shell_exec($cmd);
  echo "PID: $pid\n";
  sleep(1);
  echo "log exists: ".file_exists("/tmp/test_bg2.log")." content: ".@file_get_contents("/tmp/test_bg2.log")."\n";
  @unlink("/tmp/test_bg2.log");
  echo "\n";
}
