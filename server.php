<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/vendor/autoload.php';

// 通过重写此加载方法可修改全局Yii
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$server = new Swoole\Http\Server('127.0.0.1', 9501);

$server->on('Request', function ($request, $response) {
    $config = require __DIR__ . '/config/web.php';

    (new yii\web\Application($config))->run();
});