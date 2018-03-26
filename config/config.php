<?php
/**
 * 配置文件
 */
global $config;
$config = array(
	//监听地址
	"proxy_server_address" => "tcp://0.0.0.0:8080",
	//日志服务端口
	"log_server_port" => "9999",
	//缓存服务端口
	"cache_port" => "9999",
	//每个目标服务器允许链接失败几次
	"healthcheck_failcount_num" => 5,
	//达到这个次数worker进程重启
	"max_request" => 10000
);