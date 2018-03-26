<?php
require_once 'Workerman/Autoloader.php';
require_once 'lib/helper.php';
require_once 'config/config.php';

use Workerman\Worker;

define('RUN_DIR', __DIR__ . "/");

// 日志
Worker::$logFile = __DIR__ . "/log/info.log";
Worker::$stdoutFile = __DIR__ . "/log/echo.log";

$server_ip = exec("ifconfig eth0 | grep 'inet' | awk '{print $2}'");
Worker::$pidFile = RUN_DIR."_".$server_ip.'.pid';

foreach (glob(RUN_DIR . 'start_*.php') as $start_file) {
	require_once $start_file;
}
Worker::runAll();