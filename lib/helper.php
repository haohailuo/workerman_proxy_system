<?php
use Workerman\Connection\AsyncTcpConnection;

/**
 * udp日志打印
 */
function dd_log($msg, $dir = "default") {
	global $log_connection;
	if(!$log_connection){
		global $config;
		$log_connection = new AsyncTcpConnection ( "udp://127.0.0.1:".$config["log_server_port"]);
		$log_connection->connect ();
	}
	$arr["dir"] = $dir;
	$arr["msg"] = $msg;
	$log_connection->send(json_encode($arr));
}

/**
 * 读取缓存
 */
function read_cache($k_name) {
	global $cache;
	if ($cache != null && isset($cache->$k_name)) {
		var_export($cache->$k_name, true);
		return $cache->$k_name;
	} else {
		return false;
	}
}

/**
 * 写入缓存
 *
 * @param unknown $key_name
 */
function write_cache($k_name, $data) {
	global $cache;
	if ($cache != null) {
		$cache->$k_name = $data;
	}
}

/**
 * 域名配置
 */
function set_host($host,$ips=null){
	global $host_arr;
	global $host_list;

	if(!is_array($host_arr)){
		$host_arr = array();
	}
	if($ips==null){
		var_dump("error:  host:$host , ips is null !");
		exit();
	}
	//构建域名IPS对照数组
	$host_arr[$host] = array();
	foreach ($ips as $k => $n) {
		$ip = array();
		$ip["name"] = $k;
		$ip["quan"] = $n;
		$host_arr[$host][] = $ip;
		//加入域名解析链表
		for ($i2=0;$i2<$n;$i2++) {
			$host_list[$host][] = $k;
		}
		//放入健康缓存中
		global $config;
		write_cache($host."_healthcheck_failcount_".$k,$config["healthcheck_failcount_num"]);
	}

}

function decode_http_header($buffer){
	//首先解析http协议
	list($http_header, $http_body) = explode("\r\n\r\n", $buffer, 2);
	$header_data = explode("\r\n", $http_header);
	list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ',
		$header_data[0]);
	unset($header_data[0]);
	foreach ($header_data as $content) {
            // \r\n\r\n
		if (empty($content)) {
			continue;
		}
		list($key, $value)       = explode(':', $content, 2);
		$key                     = str_replace('-', '_', strtoupper($key));
		$value                   = trim($value);
		$_SERVER['HTTP_' . $key] = $value;
		switch ($key) {
                // HTTP_HOST
			case 'HOST':
			$tmp                    = explode(':', $value);
			$_SERVER['SERVER_NAME'] = $tmp[0];
			if (isset($tmp[1])) {
				$_SERVER['SERVER_PORT'] = $tmp[1];
			}
			break;
                // cookie
			case 'COOKIE':
			parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
			break;
                // content-type
			case 'CONTENT_TYPE':
			if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
				if ($pos = strpos($value, ';')) {
					$_SERVER['CONTENT_TYPE'] = substr($value, 0, $pos);
				} else {
					$_SERVER['CONTENT_TYPE'] = $value;
				}
			} else {
				$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
				$http_post_boundary      = '--' . $match[1];
			}
			break;
			case 'CONTENT_LENGTH':
			$_SERVER['CONTENT_LENGTH'] = $value;
			break;
		}
	}	
}

/**
 * 将真实的客户端IP写入http包体
 */
function add_clientip_into_httpbuffer(&$connection,&$buffer){
	list ( $http_header, $http_body ) = @explode ( "\r\n\r\n", $buffer, 2 );
	$http_header = $http_header."\r\nHTTP_X_FORWARDED_FOR: ".$connection->getRemoteIp();
	$buffer = $http_header."\r\n\r\n".$http_body;
}

/**
 * 构建返回http
 */
function create_html($msg){
	$msgLength = strlen($msg);
	return "
	HTTP/1.1 200 OK\r\n\r\n"
	.$msg;
}

/**
 * 选择目标服务器的算法
 * 加权轮询算法
 */
function select_address(){
	$host = $_SERVER["HTTP_HOST"];
	global $host_list;
	if(!array_key_exists($host, $host_list)){
		return false;
	}
	$hosts = $host_list[$host];
	$host_i_name = $host."_host_list_i";
	global $$host_i_name;
	if(!is_numeric($$host_i_name)){
		$$host_i_name = -1;
	}

	$$host_i_name++;
	if(count($hosts) == 0){
		return false;
	}
	if($$host_i_name>=count($hosts)){
		$$host_i_name = 0;
	}
	
	return $hosts[$$host_i_name];
}

/**
 * 移除出错的链接地址
 * 修复后需要重启
 */
function remove_address($host,$address){
	global $host_list;
	if(!array_key_exists($host, $host_list)){
		return false;
	}
	//从链表中删除
	for ($i=0; $i < count($host_list[$host]); $i++) { 
		if($host_list[$host][$i]==$address){
			array_splice($host_list[$host],$i,1); 
			$i--;
		}
	}
	//然后将$address存入，方便循环
	global $remove_address_list;
	if(!is_array($remove_address_list)){
		$remove_address_list = array();
	}
	$remove_address_list[$host][] = $address;
}