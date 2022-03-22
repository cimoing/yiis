<?php

namespace app\yiis\di;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Swoole\Coroutine;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\di\NotInstantiableException;

/**
 * 重写container
 * @property Coroutine\Context $context
 */
class Container extends \yii\di\Container
{
    public function get($class, $params = [], $config = [])
    {
        $context = $this->getContext();

        if ($class instanceof Instance) {
            $class = $class->id;
        }
        if (isset($context['container']['_singletons'][$class])) {
            // singleton
            return $context['container']['_singletons'][$class];
        } elseif (!isset($context['container']['_definitions'][$class])) {
            return $this->build($class, $params, $config);
        }

        $definition = $context['container']['_definitions'][$class];

        if (is_callable($definition, true)) {
            $params = $this->resolveDependencies($this->mergeParams($class, $params));
            $object = call_user_func($definition, $this, $params, $config);
        } elseif (is_array($definition)) {
            $concrete = $definition['class'];
            unset($definition['class']);

            $config = array_merge($definition, $config);
            $params = $this->mergeParams($class, $params);

            if ($concrete === $class) {
                $object = $this->build($class, $params, $config);
            } else {
                $object = $this->get($concrete, $params, $config);
            }
        } elseif (is_object($definition)) {
            return $context['container']['_singletons'][$class] = $definition;
        } else {
            throw new InvalidConfigException('Unexpected object definition type: ' . gettype($definition));
        }

        if (array_key_exists($class, $context['container']['_singletons'])) {
            // singleton
            $context['container']['_singletons'][$class] = $object;
        }

        return $object;
    }

    public function set($class, $definition = [], array $params = [])
    {
        $context = $this->getContext();
        $context['container']['_definitions'][$class] = $this->normalizeDefinition($class, $definition);
        $context['container']['_params'][$class] = $params;
        unset($context['container']['_singletons'][$class]);
        return $this;
    }

    public function setSingleton($class, $definition = [], array $params = [])
    {
        $context = $this->getContext();
        $context['container']['_definitions'][$class] = $this->normalizeDefinition($class, $definition);
        $context['container']['_params'][$class] = $params;
        $context['container']['_singletons'][$class] = null;
        return $this;
    }

    public function has($class)
    {
        $context = $this->getContext();
        return isset($context['container']['_definitions'][$class]);
    }

    public function hasSingleton($class, $checkInstance = false)
    {
        $context = $this->getContext();
        return $checkInstance ? isset($context['container']['_singletons'][$class]) : array_key_exists($class, $context['container']['_singletons']);
    }

    public function clear($class)
    {
        $context = $this->getContext();
        unset($context['container']['_definitions'][$class], $context['container']['_singletons'][$class]);
    }

    protected function normalizeDefinition($class, $definition)
    {
        if (empty($definition)) {
            return ['class' => $class];
        } elseif (is_string($definition)) {
            return ['class' => $definition];
        } elseif ($definition instanceof Instance) {
            return ['class' => $definition->id];
        } elseif (is_callable($definition, true) || is_object($definition)) {
            return $definition;
        } elseif (is_array($definition)) {
            if (!isset($definition['class']) && isset($definition['__class'])) {
                $definition['class'] = $definition['__class'];
                unset($definition['__class']);
            }
            if (!isset($definition['class'])) {
                if (strpos($class, '\\') !== false) {
                    $definition['class'] = $class;
                } else {
                    throw new InvalidConfigException('A class definition requires a "class" member.');
                }
            }

            return $definition;
        }

        throw new InvalidConfigException("Unsupported definition type for \"$class\": " . gettype($definition));
    }

    public function getDefinitions()
    {
        $context = $this->getContext();
        return $context['container']['_definitions'];
    }

    protected function mergeParams($class, $params)
    {
        if (empty($context['container']['_params'][$class])) {
            return $params;
        } elseif (empty($params)) {
            return $context['container']['_params'][$class];
        }

        $ps = $context['container']['_params'][$class];
        foreach ($params as $index => $value) {
            $ps[$index] = $value;
        }

        return $ps;
    }

    protected function getDependencies($class)
    {
        $context = $this->getContext();

        if (isset($context['container']['_reflections'][$class])) {
            return [$context['container']['_reflections'][$class], $context['container']['_dependencies'][$class]];
        }

        $dependencies = [];
        try {
            $reflection = new ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new NotInstantiableException(
                $class,
                'Failed to instantiate component or class "' . $class . '".',
                0,
                $e
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if (PHP_VERSION_ID >= 50600 && $param->isVariadic()) {
                    break;
                }

                if (PHP_VERSION_ID >= 80000) {
                    $c = $param->getType();
                    $isClass = false;
                    if ($c instanceof ReflectionNamedType) {
                        $isClass = !$c->isBuiltin();
                    }
                } else {
                    try {
                        $c = $param->getClass();
                    } catch (ReflectionException $e) {
                        if (!$this->isNulledParam($param)) {
                            $notInstantiableClass = null;
                            if (PHP_VERSION_ID >= 70000) {
                                $type = $param->getType();
                                if ($type instanceof ReflectionNamedType) {
                                    $notInstantiableClass = $type->getName();
                                }
                            }
                            throw new NotInstantiableException(
                                $notInstantiableClass,
                                $notInstantiableClass === null ? 'Can not instantiate unknown class.' : null
                            );
                        } else {
                            $c = null;
                        }
                    }
                    $isClass = $c !== null;
                }
                $className = $isClass ? $c->getName() : null;

                if ($className !== null) {
                    $dependencies[$param->getName()] = Instance::of($className, $this->isNulledParam($param));
                } else {
                    $dependencies[$param->getName()] = $param->isDefaultValueAvailable()
                        ? $param->getDefaultValue()
                        : null;
                }
            }
        }

        $context['container']['_reflections'][$class] = $reflection;
        $context['container']['_dependencies'][$class] = $dependencies;

        return [$reflection, $dependencies];
    }

    protected function resolveDependencies($dependencies, $reflection = null)
    {
        $context = $this->getContext();
        foreach ($dependencies as $index => $dependency) {
            if ($dependency instanceof Instance) {
                if ($dependency->id !== null) {
                    $dependencies[$index] = $dependency->get($this);
                } elseif ($reflection !== null) {
                    $name = $reflection->getConstructor()->getParameters()[$index]->getName();
                    $class = $reflection->getName();
                    throw new InvalidConfigException("Missing required parameter \"$name\" when instantiating \"$class\".");
                }
            } elseif ($context['container']['_resolveArrays'] && is_array($dependency)) {
                $dependencies[$index] = $this->resolveDependencies($dependency, $reflection);
            }
        }

        return $dependencies;
    }

    public function setResolveArrays($value)
    {
        $context = $this->getContext();
        $context['container']['_resolveArrays'] = (bool) $value;
    }

    /**
     * @return Coroutine\Context
     */
    public function getContext()
    {
        $context = Coroutine::getContext();

        if (!isset($context['contaienr'])) {
            $context['container'] = [
                '_singletons' => [],
                '_definitions' => [],
                '_params' => [],
                '_reflections' => [],
                '_dependencies' => [],
                '_resolveArrays' => false,
            ];
        }

        return $context;
    }
}