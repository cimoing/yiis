<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');
const APP_PATH = __DIR__;

require __DIR__ . '/vendor/autoload.php';

// 通过重写此加载方法可修改全局Yii
require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

$server = new Swoole\Http\Server('127.0.0.1', 9501);

$server->on('Request', function ($request, $response) {
    $config = require __DIR__ . '/config/web.php';

    $config['components']['request']['swooleRequest'] = $request;

    if (!isset($config['components']['response'])) {
        $config['components']['response'] = [];
    }
    $config['components']['response']['swooleResponse'] = $response;


    (new \app\yiis\web\Application($config))->run();
});

$server->start();