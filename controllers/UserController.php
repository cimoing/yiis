<?php

namespace app\controllers;

class UserController extends \yii\web\Controller
{
    public function actionGet()
    {
        return $this->asJson([
            'foo' => 'bar',
        ]);
    }
}