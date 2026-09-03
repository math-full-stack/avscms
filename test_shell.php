<?php
echo "disable_functions: ".ini_get('disable_functions')."\n";
echo "shell_exec exists: ".(function_exists('shell_exec')?'yes':'no')."\n";
echo "exec exists: ".(function_exists('exec')?'yes':'no')."\n";
echo "which nohup: ".shell_exec('which nohup 2>&1')."\n";
echo "which php: ".shell_exec('which php 2>&1')."\n";
echo "whoami: ".shell_exec('whoami 2>&1')."\n";
echo "PATH: ".getenv('PATH')."\n";
echo "test shell_exec: ".shell_exec('echo hello 2>&1')."\n";
echo "test nohup: ".shell_exec('nohup echo hello 2>&1 | head')."\n";
echo "test php -v: ".shell_exec('/Applications/XAMPP/xamppfiles/bin/php -v 2>&1 | head -n 1')."\n";
