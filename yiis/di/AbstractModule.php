<?php

namespace app\yiis\di;

use Closure;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Module;

trait AbstractModule
{
    public function get($id, $throwException = true)
    {
        $context = self::getContext();
        $component = null;
        if (isset($context['service']['_components'][$id])) {
            $component = $context['service']['_components'][$id];
        }

        if (isset($context['service']['_definitions'][$id])) {
            $definition = $context['service']['_definitions'][$id];
            if (is_object($definition) && !$definition instanceof Closure) {
                $component = $context['service']['_components'][$id] = $definition;
            }

            $component = $context['service']['_components'][$id] = Yii::createObject($definition);
        } elseif ($throwException) {
            throw new InvalidConfigException("Unknown component ID: $id");
        }


        if (!isset($this->module)) {
            return $component;
        }

        if ($component === null) {
            $component = $this->module->get($id, $throwException);
        }

        return $component;

    }

    public function set($id, $definition)
    {
        $context = self::getContext();
        unset($context['service']['_components'][$id]);

        if ($definition === null) {
            unset($context['service']['_definitions'][$id]);
            return;
        }

        if (is_object($definition) || is_callable($definition, true)) {
            // an object, a class name, or a PHP callable
            $context['service']['_definitions'][$id] = $definition;
        } elseif (is_array($definition)) {
            // a configuration array
            if (isset($definition['__class'])) {
                $context['service']['_definitions'][$id] = $definition;
                $context['service']['_definitions'][$id]['class'] = $definition['__class'];
                unset($context['service']['_definitions'][$id]['__class']);
            } elseif (isset($definition['class'])) {
                $context['service']['_definitions'][$id] = $definition;
            } else {
                throw new InvalidConfigException("The configuration for the \"$id\" component must contain a \"class\" element.");
            }
        } else {
            throw new InvalidConfigException("Unexpected configuration type for the \"$id\" component: " . gettype($definition));
        }
    }

    public function has($id, $checkInstance = false)
    {
        $context = self::getContext();

        return $checkInstance ? isset($context['service']['_components'][$id]) : isset($context['service']['_definitions'][$id]);
    }

    public function clear($id)
    {
        $context = self::getContext();
        unset($context['service']['_definitions'][$id], $context['service']['_components'][$id]);
    }

    public function getComponents($returnDefinitions = true)
    {
        $context = self::getContext();
        return $returnDefinitions ? $context['service']['_definitions'] : $context['service']['_components'];
    }

    public static function getContext()
    {
        $context =  Coroutine::getContext();
        if (!isset($context['service'])) {
            $context['service'] = [
                '_components' => [],
                '_definitions' => [],
            ];
        }

        return $context;
    }
}