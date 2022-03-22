<?php

namespace app\yiis\web;

use Yii;

class Session extends \yii\web\Session
{
    public function init()
    {
        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
    }

    public function getIsActive()
    {
        return true;
    }
}