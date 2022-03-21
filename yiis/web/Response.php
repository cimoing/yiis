<?php

namespace app\yiis\web;

use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;

class Response extends \yii\web\Response
{
    /**
     * @var \Swoole\Http\Response
     */
    private $_r;

    public function __construct($config = [])
    {
        parent::__construct($config);
    }

    public function setSwooleResponse($value)
    {
        $this->_r = $value;
    }

    protected function sendContent()
    {
        if ($this->stream === null) {
            $this->_r->end($this->content);
            return;
        }

        if (is_callable($this->stream)) {
            $data = call_user_func($this->stream);
            foreach ($data as $datum) {
                $this->_r->write($datum);
            }
            $this->_r->end();
            return;
        }

        $chunkSize = 8 * 1024 * 1024; // 8MB per chunk

        if (is_array($this->stream)) {
            list($handle, $begin, $end) = $this->stream;

            // only seek if stream is seekable
            if ($this->isSeekable($handle)) {
                fseek($handle, $begin);
            }

            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                if ($pos + $chunkSize > $end) {
                    $chunkSize = $end - $pos + 1;
                }
                $this->_r->write(fread($handle, $chunkSize));
                flush();
            }
            fclose($handle);
        } else {
            while (!feof($this->stream)) {
                $this->_r->write(fread($this->stream, $chunkSize));
                flush();
            }
            fclose($this->stream);
        }
    }

    protected function sendHeaders()
    {
        $headers = $this->getHeaders();
        if ($headers) {
            foreach ($headers as $name => $values) {
                foreach ($values as $value) {
                    $this->_r->header($name, $value);
                }
            }
        }
        $this->_r->setStatusCode($this->getStatusCode());

        $this->sendCookies();
    }

    protected function sendCookies()
    {
        $cookies = $this->getCookies();
        if ($cookies->count() === 0) {
            return;
        }

        $request = Yii::$app->getRequest();
        if ($request->enableCookieValidation) {
            if ($request->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($request) . '::cookieValidationKey must be configured with a secret key.');
            }
            $validationKey = $request->cookieValidationKey;
        }
        foreach ($cookies as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && isset($validationKey)) {
                $value = Yii::$app->getSecurity()->hashData(serialize([$cookie->name, $value]), $validationKey);
            }

            $this->_r->setCookie(
                $cookie->name,
                $value,
                $cookie->expire,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httpOnly,
                !empty($cookie->sameSite) ? $cookie->sameSite : null
            );
        }
    }
}