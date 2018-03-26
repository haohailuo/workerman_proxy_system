<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;
use GlobalData\Client;

//可以处理的地址列表
global $address;
// 实例化worker
global $config;
$worker = new Worker($config["proxy_server_address"]);
$worker->name = 'proxy_server';
$worker->count = 8;

global $request_count;
$request_count = 0;

/**
 * 初始化异步connection
 */
$worker->onWorkerStart = function($worker){
	global $config;
	global $cache;
	$cache = new Client( "127.0.0.1:".$config["cache_port"] );
	require_once 'config/host.php';
	/**
	 *  循环去请求已经被隔离的地址，如果通的话，重新添加进去
	 */
	Timer::add(10, function () {
		global $remove_address_list;
		if(!is_array($remove_address_list)){
			$remove_address_list = array();
		}
		foreach ($remove_address_list as $k => $in) {
			foreach ($in as $ik => $n) {
				$async_connection = new AsyncTcpConnection ( $n);
				$kk = $k;
				$nn = $n;
				$async_connection->onConnect = function($async_connection)use($ik,$kk,$nn){
					global $remove_address_list;
					unset($remove_address_list[$kk][$ik]);
					dd_log("恢复==>".$kk."-->".[$ik]);
					global $host_arr;
					foreach ($host_arr[$kk] as $k3 => $n3) {
						if($n3["name"]==$nn){
							global $host_list;
							for ($i2=0;$i2<$n3["quan"];$i2++) {
								$host_list[$kk][] = $n3["name"];
							}
							break;
						}
					}
				};
				//如果链接出错
				$async_connection->onError = function ($async_connection)use($kk,$nn){
				};
				$async_connection->connect();
			}
		}
	});
};

$worker->onMessage = function ($connection, $buffer)use($worker) {
	global $host_arr;
	//添加客户端IP进包体
	add_clientip_into_httpbuffer($connection, $buffer);
	//简单解析http包
	decode_http_header($buffer);
	//使用算法分发请求
	$address = select_address();
	var_dump($address);
	if($address==false){
		$connection->close(create_html($_SERVER["HTTP_HOST"]." : 503 "));
		return;
	}
	$host = $_SERVER["HTTP_HOST"];
	//构建异步链接
	$remote_connection = new AsyncTcpConnection ( $address);
	$remote_connection->onConnect = function($remote_connection)use($connection,$address,$host,$buffer){
		//链接成功就刷新
		global $config;
		write_cache($host."_healthcheck_failcount_".$address,$config["healthcheck_failcount_num"]);
		$remote_connection->send($buffer);
	};
	//如果链接出错
	$remote_connection->onError = function ($remote_connection)use($connection,$address,$host){
		//放入健康缓存中
		$num = read_cache($host."_healthcheck_failcount_".$address);
		if($num==0){
			//触发移除方法
			dd_log("移除=>".$host." [".$address."]");
			remove_address($host,$address);
		}else{
			write_cache($host."_healthcheck_failcount_".$address,$num-1);
		}
		$connection->send (create_html(" 503 error !"));
		$connection->close ();
		return;
	};
	
	// 自己pipe
	$remote_connection->onMessage  = function($remote_connection, $return_buffer)use($connection) {
		$connection->send($return_buffer);
	};
	$remote_connection->onClose       = function ($remote_connection) use ($connection) {
		$connection->destroy();
	};
	$connection->onBufferFull  = function ($connection) use ($remote_connection) {
		$remote_connection->pauseRecv();
	};
	$connection->onBufferDrain = function ($connection) use ($remote_connection) {
		$remote_connection->resumeRecv();
	};
	$connection->onMessage   = function ($connection, $data) use ($remote_connection) {
		//应该在这里判断是否还是当初的diqu
		$remote_connection->send($data);
	};
	$connection->onClose   = function ($connection) use ($remote_connection) {
		global $config;
		// 已经处理请求数
		global $request_count;
		// 如果请求数达到max_request
		if (++$request_count >= $config["max_request"] && $config["max_request"] > 0) {
			Worker::stopAll();
		}
		$remote_connection->destroy();
	};
	$remote_connection->onBufferFull  = function ($remote_connection) use ($connection) {
		$connection->pauseRecv();
	};
	$remote_connection->onBufferDrain = function ($remote_connection) use ($connection) {
		$connection->resumeRecv();
	};
	$remote_connection->connect();
};