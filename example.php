<?php
namespace Solido\Example;

require_once(__DIR__.'/src/Solido/Reflection.php');
require_once(__DIR__.'/src/Solido/ObjectManager.php');

class DemoClass
{
    public function getPow2($value)
    {
        return $value * $value;
    }

    public function getId($param)
    {
        return $param;
    }

    public function getMessage($to = 'World', $upperCase = false)
    {
        $string = 'Hello ' . $to . '!';
        if ($upperCase) {
            $string = strtoupper($string);
        }

        return $string;
    }
}

class RewrittenDemoClass extends DemoClass
{
    public function getId($param)
    {
        echo "[rewritten getId()]";
        $result = parent::getId($param);
        echo "[/rewritten getId()]";
        return $result;
    }
}

class DemoPlugin
{
    public function beforeGetPow2($subject, $value)
    {
        if ($value === 4) {
            return array(8);
        }
    }
}

class DemoOtherPlugin
{
    public function afterGetPow2($subject, $result)
    {
        return "999.999";
    }

    public function aroundGetMessage($subject, $next, $thing, $should_lc = false)
    {
        return $next();
        return 'xxx';
    }
}

$om = new \Solido\ObjectManager();

$om->intercept('Solido\Example\DemoClass', 'Solido\Example\DemoPlugin');
$om->intercept('Solido\Example\DemoClass', 'Solido\Example\DemoOtherPlugin');
$om->rewrite('Solido\Example\DemoClass', 'Solido\Example\RewrittenDemoClass');

$demoObject = $om->get('Solido\Example\DemoClass');

// method get returns a singleton
$sameDemoObject = $om->get('Solido\Example\DemoClass');

// method create returns a new instance
$anotherDemoObject = $om->create('Solido\Example\DemoClass');

var_dump($demoObject === $sameDemoObject);
// boolean true

var_dump($demoObject === $anotherDemoObject);
// boolean false

var_dump($demoObject->getMessage('Sara'));
// string 'Hello Sara!' (length=11)

var_dump($demoObject->getPow2(4));
// string '999.999' (length=7)
