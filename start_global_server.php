<?php
/**
 * 进程间变量共享服务，类似于redis
 */
use Workerman\Worker;
use GlobalData\Server;

global $config;

$global_worker = new Server('0.0.0.0', $config["cache_port"]);
