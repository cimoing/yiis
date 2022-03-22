<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'production');
const APP_PATH = __DIR__;

require __DIR__ . '/vendor/autoload.php';

// 通过重写此加载方法可修改全局Yii
require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

$server = new Swoole\Http\Server('0.0.0.0', 9501);
$server->set([
    'enable_static_handler' => true,
    'document_root' => APP_PATH . '/web',
]);

$server->on('Request', function ($request, $response) {
    $config = require __DIR__ . '/config/web.php';

    $config['components']['request']['swooleRequest'] = $request;

    if (!isset($config['components']['response'])) {
        $config['components']['response'] = [];
    }
    $config['components']['response']['swooleResponse'] = $response;
    Yii::$container = new \app\yiis\di\Container();

    (new \app\yiis\web\Application($config))->run();
});

$server->start();