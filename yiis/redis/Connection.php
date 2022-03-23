<?php

namespace app\yiis\redis;

use Swoole\Coroutine;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;

/**
 * @property-read false|\Redis $socket
 */
class Connection extends \yii\redis\Connection
{
    const CONTEXT_KEY = 'redis_con';

    /**
     * @var RedisPool
     */
    private $_sPool;

    public function init()
    {
        parent::init();
        $this->initPool();
    }

    private function initPool()
    {
        $config = new RedisConfig();
        $config->withHost($this->hostname)
            ->withPort($this->port)
            ->withAuth((string) $this->password)
            ->withDbIndex($this->database)
            ->withTimeout($this->dataTimeout ?? 5.0)
            ->withReadTimeout(1.0)
            ->withRetryInterval(3);

        $this->_sPool = new RedisPool($config);
    }

    public function getIsActive()
    {
        return $this->socket !== false;
    }

    public function open()
    {
        $context = Coroutine::getContext();
        if (!isset($context[self::CONTEXT_KEY])) {
            $redis = $this->_sPool->get();

            if (!$redis->isConnected()) {
                \Yii::error("redis 连接丢失");
            } else {
                $context[self::CONTEXT_KEY] = $redis;
                $this->initConnection();
            }
        }
    }

    public function close()
    {
        $redis = $this->socket;
        if ($redis) {
            $this->_sPool->put($redis);
        }
    }

    /**
     * @return false|mixed|resource|\Redis
     */
    public function getSocket()
    {
        $context = Coroutine::getContext();

        return $context[self::CONTEXT_KEY] ?? false;
    }

    public function executeCommand($name, $params = [])
    {
        $this->open();

        $params = $this->transParams($name, $params);
        \Yii::debug("Executing Redis Command: {$name}", __METHOD__);
        if ($this->retries > 0) {
            $tries = $this->retries;
            while ($tries-- > 0) {
                try {
                    return call_user_func_array([$this->socket, $name], $params);
                } catch (\RedisException $e) {
                    \Yii::error($e, __METHOD__);
                    // backup retries, fail on commands that fail inside here
                    $retries = $this->retries;
                    $this->retries = 0;
                    $this->close();
                    if ($this->retryInterval > 0) {
                        usleep($this->retryInterval);
                    }
                    try {
                        $this->open();
                    } catch (\RedisException $exception) {
                        // Fail to run initial commands, skip current try
                        \Yii::error($exception, __METHOD__);
                        $this->close();
                    } catch (\Exception $exception) {
                        $this->close();
                    }

                    $this->retries = $retries;
                }
            }
        }
        return call_user_func_array([$this->socket, $name], $params);
    }

    private function transParams($name, $params)
    {
        $name = strtolower($name);
        if ($name != 'set') {
            return $params;
        }
        $result = [array_shift($params), array_shift($params)];
        $options = [];

        $skip = null;
        foreach ($params as $k => $param) {

            if (!is_null($skip)) {
                $options[$skip] = $param;
                $skip = null;
                continue;
            }

            switch ($param) {
                case 'EX':
                case 'PX':
                case 'EXAT':
                case 'PXAT':
                    $skip = $param;
                    break;
                default:
                    $options[] = $param;
                    break;
            }
        }

        $result[] = $options;
        return $result;
    }
}