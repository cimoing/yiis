<?php

namespace app\yiis\web;

use Swoole\Coroutine;
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
        } catch (\Throwable $e) {
            echo sprintf("error %s:%d %s\n", $e->getFile(), $e->getLine(), $e->getMessage());

            $content = print_r(Coroutine::getBackTrace(), true);

            $response = $this->getResponse();
            $response->setStatusCode(500);
            $response->content = "exception " . $e->getMessage() . PHP_EOL . $content;
            $this->getResponse()->send();
        }
        foreach ($this->getLog()->targets as $target) {
            $target->export();
        }
    }

    protected function initRequest()
    {
        $context = Coroutine::getContext();

        // reset state object
        $this->set('request', $this->_config['components']['request']);
        $this->set('response', $this->_config['components']['response']);
        $this->set('view', $this->_config['components']['view']);
        $this->set('session', $this->_config['components']['session']);
        $this->set('redis', $this->_config['components']['redis']);
        $this->set('cache', $this->_config['components']['cache']);
        $context['_view']  = Yii::createObject($this->_config['components']['view']);
        $context['_request'] = Yii::createObject($this->_config['components']['request']);
        $context['_response'] = Yii::createObject($this->_config['components']['response']);
        $this->getResponse()->clear();
        $context['_session'] = Yii::createObject($this->_config['components']['session']);

        $session = $this->getSession();
        $session->openSession('', '');
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
        $context = Coroutine::getContext();
        return $context['_view'];
    }

    private $_request;

    public function getRequest()
    {
        $context = Coroutine::getContext();
        return $context['_request'];
    }

    private $_response;

    public function getResponse()
    {
        $context = Coroutine::getContext();
        return $context['_response'];
    }

    private $_session;

    public function getSession()
    {
        $context = Coroutine::getContext();
        return $context['_session'];
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