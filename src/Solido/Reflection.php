<?php

namespace Solido;

class Reflection
{
    public static function getParameterSignature($parameter)
    {
        $string = $parameter->__toString();

        $className = null;
        $isDefaultValue = false;
        $defaultValue = null;
        if ($parameter->isDefaultValueAvailable()) {
            $isDefaultValue = true;
            $defaultValue = $parameter->getDefaultValue();
        }

        preg_match('/\[\s\<\w+?>\s([\w\\\\]+)/s', $string, $matches);
        $className = isset($matches[1]) ? $matches[1] : null;

        $dv = print_r($defaultValue, true);

        $parameterName = $parameter->getName();

        $out = array();
        if ($className) {
            $out[] = $className;
        }
        $out[] = '$'.$parameterName;

        if ($isDefaultValue) {
            $out[] = '=';

            if (is_array($defaultValue) && empty($defaultValue)) {
                $defaultValueStr = 'array()';
            } else {
                $defaultValueStr = var_export($defaultValue, true);
            }
            $out[] = $defaultValueStr;
        }

        $out = implode(' ', $out);

        return $out;
    }

    public static function getMethodSignature($class, $method = null)
    {
        if ($class instanceof \ReflectionMethod) {
            $reflectionMethod = $class;
        }

        $methodName = $reflectionMethod->getName();
        $parameters = $reflectionMethod->getParameters();

        $parametersSignature = array();
        foreach ($parameters as $parameter) {
            $parametersSignature[] = self::getParameterSignature($parameter);
        }

        $signature = implode(', ', $parametersSignature);

        $access = array();
        if ($reflectionMethod->isPublic()) {
            $access[] = 'public';
        } elseif ($reflectionMethod->isProtected()) {
            $access[] = 'protected';
        } elseif ($reflectionMethod->isPrivate()) {
            $access[] = 'private';
        }
        if ($reflectionMethod->isStatic()) {
            $access[] = 'static';
        }
        if ($reflectionMethod->isAbstract()) {
            $access[] = 'abstract';
        }
        if ($reflectionMethod->isFinal()) {
            $access[] = 'final';
        }

        $access = implode(' ', $access);

        $methodSignature = "$access function $methodName($signature)";

        return $methodSignature;
    }
}
