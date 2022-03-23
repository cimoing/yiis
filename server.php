<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'production');
const APP_PATH = __DIR__;

require __DIR__ . '/vendor/autoload.php';

// 通过重写此加载方法可修改全局Yii
require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

$server = new Swoole\Http\Server('0.0.0.0', 9501);
$server->set([
    'worker_num' => 4,
    'enable_static_handler' => true,
    'document_root' => APP_PATH . '/web',
]);

$server->on('WorkerStart', function (\Swoole\Server $server, $workerId) {
    $config = require __DIR__ . '/config/web.php';

    if (!isset($config['components']['response'])) {
        $config['components']['response'] = [];
    }
    Yii::$container = new \app\yiis\di\Container();
    new \app\yiis\web\Application($config);
});

$server->on('Request', function ($request, $response) {
    $context = \Swoole\Coroutine::getContext();
    $context['request'] = $request;
    $context['response'] = $response;
    Yii::$app->run();
});

$server->start();