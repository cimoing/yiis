<?php

namespace app\yiis\web;

class Application extends \yii\web\Application
{
    public function coreComponents()
    {
        return array_merge(parent::coreComponents(), [
            'request' => ['class' => Request::class],
            'response' => ['class' => Response::class],
            'session' => ['class' => 'yii\web\Session'],
            'errorHandler' => ['class' => 'yii\web\ErrorHandler'],
        ]);
    }
}