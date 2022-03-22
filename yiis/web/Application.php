<?php

namespace app\yiis\web;

use Yii;
use Swoole\ExitException;
use app\yiis\di\AbstractModule;

class Application extends \yii\web\Application
{
    use AbstractModule;

    protected function bootstrap()
    {
        Yii::setAlias('@webroot', APP_PATH . '/web');
        Yii::setAlias('@web', '/');
        \yii\base\Application::bootstrap();
    }

    public function coreComponents()
    {
        return array_merge(parent::coreComponents(), [
            'request' => ['class' => Request::class],
            'response' => ['class' => Response::class],
            'session' => ['class' => Session::class],
            'errorHandler' => ['class' => 'yii\web\ErrorHandler'],
        ]);
    }

    public function end($status = 0, $response = null)
    {
        if ($this->state === self::STATE_BEFORE_REQUEST || $this->state === self::STATE_HANDLING_REQUEST) {
            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);
        }

        if ($this->state !== self::STATE_SENDING_RESPONSE && $this->state !== self::STATE_END) {
            $this->state = self::STATE_END;
            $response = $response ?: $this->getResponse();
            $response->send();
        }

        if (YII_ENV_TEST) {
            throw new ExitException($status);
        }

        return $status;
    }
}