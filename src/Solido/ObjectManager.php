<?php

namespace Solido;

class ObjectManager
{
    private $declaredInterceptors = array();
    private $declaredRewrites = array();

    private $interceptors = array();
    private $instances = array();

    public function create($class, $args = array())
    {
        $class = $this->getRewrittenClass($class);
        $exists = class_exists($class);

        if (!$exists) {
            // Try custom autoloading
        }

        if (!$exists) {
            throw new \Exception("Class $class not found");
        }

        $declaredInterceptors = $this->getDeclaredInterceptorsForClass($class);

        if (!empty($declaredInterceptors)) {
            $interceptedClass = $this->createInterceptors($class, $declaredInterceptors);

            $instance = $this->construct($interceptedClass, $args);
            $instance->__setPlugins($this->interceptors[$class]);
            $instance->om = $this;
        } else {
            $instance = $this->construct($class, $args);
        }

        return $instance;
    }

    public function get($class, $args = array())
    {
        if (!isset($this->instances[$class])) {
            $instance = $this->create($class, $args);
            $this->instances[$class] = $instance;
        }

        return $this->instances[$class];
    }

    public function intercept($class, $plugin)
    {
        if (!isset($this->declaredInterceptors[$class])) {
            $this->declaredInterceptors[$class] = array();
        }
        $this->declaredInterceptors[$class][] = array(
            'plugin' => $plugin,
        );
    }

    public function rewrite($class, $rewrittenClass)
    {
        if ($class[0] != '\\') {
            $class = '\\'.$class;
        }

        if (!isset($this->declaredRewrites[$class])) {
            $this->declaredRewrites[$class] = array();
        }
        $this->declaredRewrites[$class][] = $rewrittenClass;
    }

    protected function construct($class, $args = array())
    {
        $refClass = new \ReflectionClass($class);
        $args1 = array();

        if ($refClass->hasMethod('__construct')) {
            $refMethod = new \ReflectionMethod($class, '__construct');
            $params = $refMethod->getParameters();

            $i = 0;
            foreach ($params as $key => $param) {
                if (!isset($args[$key])) {
                    continue;
                }

                if ($param->isPassedByReference()) {
                    $args1[$key] = &$args[$key];
                } else {
                    $args1[$key] = $args[$key];
                }
            }
        }

        $instance = $refClass->newInstanceArgs((array) $args1);

        return $instance;
    }

    protected function getDeclaredInterceptorsForClass($class)
    {
        $result = array();
        $aux = $class;
        while ($aux) {
            if (isset($this->declaredInterceptors[$aux])) {
                $result = array_merge($result, $this->declaredInterceptors[$aux]);
            }
            $aux = get_parent_class($aux);
        }

        return $result;
    }

    protected function createInterceptors($class, $declaredInterceptors)
    {
        if (!isset($this->interceptors[$class])) {
            foreach ($declaredInterceptors as $interceptorInfo) {
                $this->createInterceptor($class, $interceptorInfo['plugin']);
            }
        }

        // Ensure interceptor class exists
        $interceptedClass = $class.'Interceptor';
        if (!class_exists($interceptedClass)) {
            $this->createInterceptorClass($class, $interceptedClass);
        }

        return $interceptedClass;
    }

    protected function createInterceptor($class, $plugin)
    {
        $classMethods = get_class_methods($class);
        $pluginMethods = get_class_methods($plugin);

        $methods = array();
        foreach ($pluginMethods as $pluginMethod) {
            preg_match_all('/^(before|after|around)(.*)$/', $pluginMethod, $m);
            if (!isset($m[2])) {
                continue;
            }
            $on = $m[1][0];
            $method = lcfirst($m[2][0]);
            if (!isset($methods[$method])) {
                $methods[$method] = array();
            }
            if (!isset($methods[$method][$on])) {
                $methods[$method][$on] = array();
            }
            $methods[$method][$on][] = $plugin;
        }

        if (!isset($this->interceptors[$class])) {
            $this->interceptors[$class] = array(
                'methods' => array(),
                'plugins' => array(),
            );
        }

        $this->interceptors[$class]['methods'] = array_merge_recursive($this->interceptors[$class]['methods'], $methods);
        $this->interceptors[$class]['plugins'][] = array(
            'class' => $plugin,
            'methods' => $methods,
        );
    }

    protected function createInterceptorClass($class, $interceptedClass)
    {
        $rclass = new \ReflectionClass($class);
        $interceptorInfo = $this->interceptors[$class];

        $nsName = $rclass->getNamespaceName();
        $shortName = $rclass->getShortName();

        $methodsPHPCode = array();
        foreach ($interceptorInfo['methods'] as $method => $methodInfo) {
            if (!$rclass->hasMethod($method)) {
                continue;
            }

            $rmethod = $rclass->getMethod($method);
            $methodSignature = \Solido\Reflection::getMethodSignature($rmethod);

            $methodBodyPHPCode = $this->getInterceptedMethodBodyPHPCode();

            $methodPHPCode = <<<EOT
    $methodSignature {
$methodBodyPHPCode
    }
EOT;
            $methodsPHPCodeArray[] = $methodPHPCode;
        }

        $methodsPHPCode = implode("\n\n", $methodsPHPCodeArray);
        $interceptorMethodsPHPCode = $this->getInterceptorMethodsPHPCode();

        $classPHPCode = <<<EOT
namespace $nsName;
class {$shortName}Interceptor extends \\$class
{
$interceptorMethodsPHPCode

$methodsPHPCode
}
EOT;

        eval($classPHPCode);
    }

    protected function getRewrittenClass($class)
    {
        $result = $class;
        while (isset($this->declaredRewrites[$result])) {
            $result = $this->declaredRewrites[$result][0];
        }

        return $result;
    }

/******************************************************************************
*
*   METHODS RETURNING PHP CODE TO BE EVALUATED
*
******************************************************************************/

    protected function getInterceptedMethodBodyPHPCode()
    {
        ob_start();
        ?>
        return $this->__callPlugins(__FUNCTION__, func_get_args());
        <?php
        return ob_get_clean();
    }

    protected function getInterceptorMethodsPHPCode()
    {
        ob_start();
        ?>
    protected $__plugins = null;
    protected $__chained = array();

    public function __callPlugins($method, $args)
    {
        $methods = $this->__plugins['methods'];
        $suffix = ucfirst($method);

        // before
        if (isset($methods[$method]) && isset($methods[$method]['before'])) {
            foreach($methods[$method]['before'] as $plugin) {
                $pluginObject = $this->om->get($plugin);

                $beforeResult = call_user_func_array(array($pluginObject, 'before' . $suffix), array_merge(array($this), $args));

                if ($beforeResult) {
                    $args = $beforeResult;
                }
            }
        }

        // around
        if (isset($methods[$method]) && isset($methods[$method]['around'])) {

            $subject = $this;
            $type = get_parent_class($this);

            $this->__chained = $methods[$method]['around'];

            $next = function() use ($type, $method, $subject, $args) {
                return $this->invokeNext($type, $method, $subject, $args);
            };

            $currentPlugin = array_shift($this->__chained);
            $pluginObject = $this->om->get($currentPlugin);

            $result = call_user_func_array(array($pluginObject, 'around' . $suffix), array_merge(array($this, $next), $args)); 
        } else {
            $result = call_user_func_array(array('parent', $method), $args);
        }

        // after
        if (isset($methods[$method]) && isset($methods[$method]['after'])) {
            foreach($methods[$method]['after'] as $plugin) {
                $pluginObject = $this->om->get($plugin);
                $result = call_user_func_array(array($pluginObject, 'after' . $suffix), array_merge(array($this, $result), $args));
            }
        }

        return $result;
    }

    public function invokeNext($type, $method, $subject, $args)
    {
        $result = null;
        $suffix = ucfirst($method);

        $next = function() use ($method, $subject, $args) {
            return $this->invokeNext($method, $subject, $args);
        };

        $currentPlugin = array_shift($this->__chained);

        if ($currentPlugin) {
            $pluginObject = $this->om->get($currentPlugin);
            $result = call_user_func_array(array($pluginObject, 'around' . $suffix), array_merge(array($this, $next), $args));
        } else {
            $result = call_user_func_array(array('parent', $method), $args);
        }
        return $result;
    }

    public function __setPlugins($plugins)
    {
        $this->__plugins = $plugins;
    }
        <?php
        return ob_get_clean();
    }
}
