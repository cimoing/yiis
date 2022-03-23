<?php

namespace app\yiis\web;

use Yii;
use yii\base\InvalidArgumentException;
use yii\di\Instance;
use yii\web\Cookie;

class Session extends \yii\web\CacheSession
{
    private $_data = [];
    private $_rawData = '';

    public function init()
    {
        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }

        $this->cache = Instance::ensure($this->cache, 'yii\caching\CacheInterface');
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }

        if ($this->getUseStrictMode() && $this->_forceRegenerateId) {
            $this->regenerateID();
            $this->_forceRegenerateId = null;
        }

        if ($this->getIsActive()) {

            $this->_rawData = $this->readSession($this->getId());

            if (!empty($this->_rawData)) {
                $this->_data = \json_decode($this->_rawData);
            }

            Yii::info('Session started', __METHOD__);
            $this->updateFlashCounters();
        } else {
            $error = error_get_last();
            $message = isset($error['message']) ? \json_encode($error) : 'Failed to start session.';
            Yii::error($message, __METHOD__);
        }
    }

    public function close()
    {
        if ($this->getIsActive()) {
            $this->writeSession($this->getId(), \json_encode($this->_data));
        }
        $this->_id = null;
        $this->_forceRegenerateId = null;
    }

    public function destroy()
    {
        if ($this->getIsActive()) {
            $sessionId = $this->getId();
            $this->close();
            $this->setId($sessionId);
            $this->open();
            $this->setId($sessionId);
        }
    }

    public function getIsActive()
    {
        return !is_null($this->_id);
    }

    private $_hasSessionId;

    public function getHasSessionId()
    {
        if ($this->_hasSessionId === null) {
            $name = $this->getName();
            $request = Yii::$app->getRequest();
            if ($request->cookies->has($name) && ini_get('session.use_cookies')) {
                $this->_hasSessionId = true;
            } elseif (!ini_get('session.use_only_cookies') && ini_get('session.use_trans_sid')) {
                $this->_hasSessionId = $request->get($name) != '';
            } else {
                $this->_hasSessionId = false;
            }
        }

        return $this->_hasSessionId;
    }

    public function setHasSessionId($value)
    {
        $this->_hasSessionId = $value;
    }

    public function openSession($savePath, $sessionName)
    {
        if ($this->getUseStrictMode()) {
            $id = $this->getId();
            if (!$this->cache->exists($this->calculateKey($id))) {
                //This session id does not exist, mark it for forced regeneration
                $this->_forceRegenerateId = $id;
            }
        }

        return true;
    }

    private $_id;

    public function getId()
    {
        if (is_null($this->_id)) {
            $request = Yii::$app->getRequest();

            if ($this->useCookies && $request->getCookies()->has($this->getName())) {
                $cookie = $request->getCookies()->get($this->getName());
                $this->_id = $cookie->value;
            } elseif (!ini_get('session.use_only_cookies') && ini_get('session.use_trans_sid')) {
                $this->_id = $request->get($this->getName(), null);
            }

            if (is_null($this->_id)) {
                $this->regenerateID();
            }
        }

        return $this->_id;
    }

    public function setId($value)
    {
        $this->_id = $value;
    }

    public function regenerateID($deleteOldSession = false)
    {
        if ($deleteOldSession && $this->_id) {
            // delete old session id
            $this->cache->delete($this->calculateKey($this->_id));
        }
        $this->_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),

            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private $_name;

    public function getName()
    {
        if (is_null($this->_name)) {
            $this->_name = ini_get("session.name");
        }

        return $this->_name;
    }

    public function setName($value)
    {
        $this->_name = $value;
    }

    private $_savePath;

    public function getSavePath()
    {
        if (is_null($this->_savePath)) {
            $this->_savePath = ini_get("session.save_path");
        }

        return $this->_savePath;
    }

    public function setSavePath($value)
    {
        $path = Yii::getAlias($value);
        if (is_dir($path)) {
            $this->_savePath = $value;
        } else {
            throw new InvalidArgumentException("Session save path is not invalid");
        }
    }

    public function getCount()
    {
        $this->open();
        return count($this->_data);
    }

    public function count()
    {
        return $this->getCount();
    }

    public function get($key, $defaultValue = null)
    {
        $this->open();

        return isset($this->_data[$key]) ? $this->_data[$key] : $defaultValue;
    }

    public function set($key, $value)
    {
        $this->open();
        $this->_data[$key] = $value;
    }

    public function remove($key)
    {
        $this->open();
        if (isset($this->_data[$key])) {
            $value = $this->_data[$key];
            unset($this->_data[$key]);

            return $value;
        }

        return null;
    }

    public function removeAll()
    {
        $this->open();
        $this->_data = [];
    }

    public function has($key)
    {
        $this->open();
        return isset($this->_data[$key]);
    }

    protected function updateFlashCounters()
    {
        $counters = $this->get($this->flashParam, []);
        if (is_array($counters)) {
            foreach ($counters as $key => $count) {
                if ($count > 0) {
                    unset($counters[$key], $this->_data[$key]);
                } elseif ($count == 0) {
                    $counters[$key]++;
                }
            }
            $this->_data[$this->flashParam] = $counters;
        } else {
            // fix the unexpected problem that flashParam doesn't return an array
            unset($this->_data[$this->flashParam]);
        }
    }

    public function getFlash($key, $defaultValue = null, $delete = false)
    {
        $counters = $this->get($this->flashParam, []);
        if (isset($counters[$key])) {
            $value = $this->get($key, $defaultValue);
            if ($delete) {
                $this->removeFlash($key);
            } elseif ($counters[$key] < 0) {
                // mark for deletion in the next request
                $counters[$key] = 1;
                $this->_data[$this->flashParam] = $counters;
            }

            return $value;
        }

        return $defaultValue;
    }

    public function getAllFlashes($delete = false)
    {
        $counters = $this->get($this->flashParam, []);
        $flashes = [];
        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $this->_data)) {
                $flashes[$key] = $this->_data[$key];
                if ($delete) {
                    unset($counters[$key], $this->_data[$key]);
                } elseif ($counters[$key] < 0) {
                    // mark for deletion in the next request
                    $counters[$key] = 1;
                }
            } else {
                unset($counters[$key]);
            }
        }

        $this->_data[$this->flashParam] = $counters;

        return $flashes;
    }

    public function setFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->_data[$key] = $value;
        $this->_data[$this->flashParam] = $counters;
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true)
    {
        $counters = $this->get($this->flashParam, []);
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->_data[$this->flashParam] = $counters;
        if (empty($this->_data[$key])) {
            $this->_data[$key] = [$value];
        } elseif (is_array($this->_data[$key])) {
            $this->_data[$key][] = $value;
        } else {
            $this->_data[$key] = [$this->_data[$key], $value];
        }
    }

    public function removeFlash($key)
    {
        $counters = $this->get($this->flashParam, []);
        $value = isset($this->_data[$key], $counters[$key]) ? $this->_data[$key] : null;
        unset($counters[$key], $this->_data[$key]);
        $this->_data[$this->flashParam] = $counters;

        return $value;
    }

    public function removeAllFlashes()
    {
        $counters = $this->get($this->flashParam, []);
        $value = isset($this->_data[$key], $counters[$key]) ? $this->_data[$key] : null;
        unset($counters[$key], $this->_data[$key]);
        $this->_data[$this->flashParam] = $counters;

        return $value;
    }

    public function offsetExists($key)
    {
        $this->open();
        return isset($this->_data[$key]);
    }

    public function offsetGet($offset)
    {
        $this->open();

        return isset($this->_data[$offset]) ? $this->_data[$offset] : null;
    }

    public function offsetSet($offset, $item)
    {
        $this->open();

        $this->_data[$offset] = $item;
    }

    public function offsetUnset($offset)
    {
        $this->open();
        unset($this->_data[$offset]);
    }

    private $frozenSessionData;

    protected function freeze()
    {
        if ($this->getIsActive()) {
            if (isset($this->_data)) {
                $this->frozenSessionData = $this->_data;
            }
            $this->close();
            Yii::info('Session frozen', __METHOD__);
        }
    }

    protected function unfreeze()
    {
        if (null !== $this->frozenSessionData) {
            if ($this->getIsActive()) {
                Yii::info('Session unfrozen', __METHOD__);
            } else {
                $error = error_get_last();
                $message = isset($error['message']) ? $error['message'] : 'Failed to unfreeze session.';
                Yii::error($message, __METHOD__);
            }

            $this->_data = $this->frozenSessionData;
            $this->frozenSessionData = null;
        }
    }

    private $_cache_limiter;

    public function getCacheLimiter()
    {
        if (is_null($this->_cache_limiter)) {
            $this->_cache_limiter = ini_get("session.cache_limiter");
        }

        return $this->_cache_limiter;
    }

    public function setCacheLimiter($cacheLimiter)
    {
        $this->freeze();
        $this->_cache_limiter = $cacheLimiter;
        $this->unfreeze();
    }
}