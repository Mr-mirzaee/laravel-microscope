<?php

namespace Imanghafoori\LaravelMicroscope\Checks;

use Illuminate\Routing\Controller;
use Imanghafoori\ComposerJson\NamespaceCalculator;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\TokenAnalyzer\ClassMethods;
use Throwable;

class RoutelessActions
{
    public static function getControllerActions($methods)
    {
        $orphanMethods = [];
        foreach ($methods as $method) {
            // we exclude non-public methods
            // we exclude static methods
            // we exclude __construct
            $conditions = ($method['visibility'][0] !== T_PUBLIC or
                            $method['is_static'] or
                            $method['name'][1] == '__construct');
            if ($conditions) {
                continue;
            }

            $orphanMethods[] = $method;
        }

        return $orphanMethods;
    }

    public static function getNamespacedClassName($classFilePath, $psr4Path, $psr4Namespace)
    {
        $absFilePath = $classFilePath->getRealPath();
        $className = $classFilePath->getFilename();
        $relativePath = \str_replace(base_path(), '', $absFilePath);
        $namespace = NamespaceCalculator::calculateCorrectNamespace($relativePath, $psr4Path, $psr4Namespace);

        return $namespace.'\\'.$className;
    }

    public static function isLaravelController($fullNamespace)
    {
        try {
            return is_subclass_of($fullNamespace, Controller::class);
        } catch (Throwable $r) {
            // it means the file does not contain a class or interface.
            return false;
        }
    }

    public static function getFullNamespace($classFilePath, $psr4Path, $psr4Namespace)
    {
        $fullNamespace = self::getNamespacedClassName($classFilePath, $psr4Path, $psr4Namespace);

        return \trim($fullNamespace, '.php');
    }

    protected function findOrphanActions($tokens, $fullNamespace)
    {
        $class = ClassMethods::read($tokens);

        $methods = self::getControllerActions($class['methods']);
        $actions = [];
        foreach ($methods as $method) {
            $classAtMethod = self::classAtMethod($fullNamespace, $method['name'][1]);
            // For __invoke, we will also check to see if the route is defined like this:
            // Route::get('/', [Controller::class, '__invoke']);
            // Route::get('/', Controller::class);
            if (
                ! $this->getByAction($classAtMethod) || ($method['name'][1] === '__invoke' && ! $this->getByAction($classAtMethod.'@__invoke'))
            ) {
                $line = $method['name'][2];
                $actions[] = [$line, $classAtMethod];
            }
        }

        return $actions;
    }

    public static function check($tokens, $absFilePath, $classFilePath, $psr4Path, $psr4Namespace)
    {
        (new self())->checkControllerActionsForRoutes($classFilePath, $psr4Path, $psr4Namespace, $tokens, $absFilePath);
    }

    public function checkControllerActionsForRoutes($classFilePath, $psr4Path, $psr4Namespace, $tokens, $absFilePath)
    {
        $errorPrinter = resolve(ErrorPrinter::class);
        $fullNamespace = self::getFullNamespace($classFilePath, $psr4Path, $psr4Namespace);

        if (! self::isLaravelController($fullNamespace)) {
            return;
        }

        // exclude abstract class
        if ((new \ReflectionClass($fullNamespace))->isAbstract()) {
            return;
        }

        $actions = $this->findOrphanActions($tokens, $fullNamespace);

        foreach ($actions as $action) {
            $errorPrinter->routelessAction($absFilePath, $action[0], $action[1]);
        }
    }

    public static function classAtMethod($fullNamespace, $methodName)
    {
        ($methodName == '__invoke') ? ($methodName = '') : ($methodName = '@'.$methodName);

        return \trim($fullNamespace, '\\').$methodName;
    }

    protected function getByAction($classAtMethod)
    {
        return app('router')->getRoutes()->getByAction($classAtMethod);
    }
}
