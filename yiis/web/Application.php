<?php

namespace app\yiis\web;

use Yii;
use Swoole\ExitException;
use yii\base\Component;

class Application extends \yii\web\Application
{
    private $_config;

    public function __construct($config = [])
    {
        Yii::$app = $this;
        static::setInstance($this);

        $this->state = self::STATE_BEGIN;

        $this->preInit($config);
        $this->_config = $config;

        Component::__construct($config);
    }

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

    public function run()
    {
        $this->initRequest();

        try {
            return parent::run();
        } catch (\Exception|\Throwable $e) {
            echo sprintf("error %s:%d %s\n", $e->getFile(), $e->getLine(), $e->getMessage());
            $response = $this->getResponse();
            $response->setStatusCode(500);
            $response->content = "exception " . $e->getMessage();
            $this->getResponse()->send();
        }
    }

    protected function initRequest()
    {
        $this->_view = Yii::createObject($this->_config['components']['view']);
        $this->_request = Yii::createObject($this->_config['components']['request']);
        $this->_response = Yii::createObject($this->_config['components']['response']);
        $this->getResponse()->clear();
        $this->_session = Yii::createObject($this->_config['components']['session']);

        $session = $this->getSession();
        if ($session->getIsActive()) {
            $session->destroy();
        }
        if ($session->useCookies) {
            $name = $session->getName();
            if (isset($this->getRequest()->swooleRequest->cookies[$name])) {
                $session->setId($this->getRequest()->swooleRequest->cookies[$name]);
            } else {
                $session->regenerateID(true);
            }
        }
    }

    private $_view;
    public function getView()
    {
        return $this->_view;
    }

    private $_request;

    public function getRequest()
    {
        return $this->_request;
    }

    private $_response;

    public function getResponse()
    {
        return $this->_response;
    }

    private $_session;

    public function getSession()
    {
        return $this->_session;
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