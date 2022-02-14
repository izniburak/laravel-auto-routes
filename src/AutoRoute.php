<?php

namespace Buki\AutoRoute;

use Buki\AutoRoute\Middleware\AjaxRequestMiddleware;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionMethod;

/**
 * Class AutoRoute
 *
 * @package Buki\AutoRoute
 * @author  İzni Burak Demirtaş <info@burakdemirtasorg>
 */
class AutoRoute
{
    /** @var Container */
    protected $app;

    /** @var Router */
    protected $router;

    /** @var string */
    protected $namespace;

    /** @var string[] Laravel Routing Available Methods. */
    protected $availableMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var string[] Custom methods for the package */
    protected $customMethods = ['XGET', 'XPOST', 'XPUT', 'XPATCH', 'XDELETE', 'XOPTIONS', 'XANY'];

    /** @var string Main Method */
    protected $mainMethod;

    /** @var string */
    protected $defaultHttpMethods;

    /** @var string Ajax Middleware class for the Requests */
    protected $ajaxMiddleware;

    /** @var string[] */
    protected $defaultPatterns = [
        ':any' => '([^/]+)',
        ':int' => '(\d+)',
        ':float' => '[+-]?([0-9]*[.])?[0-9]+',
        ':bool' => '(true|false|1|0)',
    ];

    /**
     * AutoRoute constructor.
     * @throws
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->router = $app->get('router');
    }

    /**
     * Initialize for the AutoRoute
     */
    public function setConfigurations(array $config): void
    {
        $this->mainMethod = $config['main_method'] ?? 'index';
        $this->namespace = $config['namespace'] ?? 'App\\Http\\Controllers';
        $this->ajaxMiddleware = $config['ajax_middleware'] ?? AjaxRequestMiddleware::class;
        $this->defaultPatterns = array_merge($this->defaultPatterns, $config['patterns'] ?? []);
        $this->defaultHttpMethods = $config['http_methods'] ?? $this->availableMethods;

        if (empty($this->defaultHttpMethods) || $this->defaultHttpMethods === '*') {
            $this->defaultHttpMethods = $this->availableMethods;
        }
    }

    /**
     * @throws
     */
    public function auto(string $prefix, string $controller, array $options = []): void
    {
        $only = $options['only'] ?? [];
        $except = $options['except'] ?? [];
        $patterns = $options['patterns'] ?? [];

        $this->router->group(
            array_merge($options, [
                'prefix' => $prefix,
                'as' => isset($options['as'])
                    ? "{$options['as']}."
                    : (isset($options['name'])
                        ? "{$options['name']}."
                        : trim($prefix, '/') . '.'
                    ),
            ]),
            function () use ($controller, $only, $except, $patterns) {
                [$class, $className] = $this->resolveControllerName($controller);
                $classRef = new ReflectionClass($class);
                foreach ($classRef->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    // Check the method should be added into Routes or not.
                    if (in_array($method->class, [BaseController::class, "{$this->namespace}\\Controller"])
                        || $method->getDeclaringClass()->getParentClass()->getName() === BaseController::class
                        || !$method->isPublic()
                        || strpos($method->name, '__') === 0) {
                        continue;
                    }

                    // Needed definitions
                    $methodName = $method->name;

                    if ((!empty($only) && !in_array($methodName, $only))
                        || (!empty($except) && in_array($methodName, $except))) {
                        continue;
                    }

                    // Find the HTTP method which will be used and method name.
                    [$httpMethods, $methodName, $middleware] = $this->getHttpMethodAndName($methodName);

                    // Get endpoints and parameter patterns for Route
                    [$endpoints, $routePatterns] = $this->getRouteValues($method, $patterns);

                    $endpoints = implode('/', $endpoints);
                    $this->router->addRoute(
                        array_map(function ($method) {
                            return strtoupper($method);
                        }, $httpMethods),
                        ($methodName !== $this->mainMethod ? $methodName : '') . "/{$endpoints}",
                        [$classRef->getName(), $method->name]
                    )->where($routePatterns)->name("{$method->name}")->middleware($middleware);
                }
            }
        );
    }

    private function getHttpMethodAndName(string $methodName): array
    {
        $httpMethods = $this->defaultHttpMethods;
        $middleware = null;
        foreach (array_merge($this->availableMethods, $this->customMethods) as $httpMethod) {
            $httpMethod = strtolower($httpMethod);
            if (stripos($methodName, $httpMethod, 0) === 0) {
                if ($httpMethod !== 'xany') {
                    $httpMethods = [ltrim($httpMethod, 'x')];
                }
                $middleware = strpos($httpMethod, 'x') === 0 ? $this->ajaxMiddleware : null;
                $methodName = lcfirst(
                    preg_replace('/' . $httpMethod . '_?/i', '', $methodName, 1)
                );
                break;
            }
        }

        // Convert URL from camelCase to snake-case.
        $methodName = strtolower(preg_replace('%([a-z]|[0-9])([A-Z])%', '\1-\2', $methodName));

        return [$httpMethods, $methodName, $middleware];
    }

    private function getRouteValues(ReflectionMethod $method, array $patterns = []): array
    {
        $routePatterns = $endpoints = [];
        $patterns = array_merge($this->defaultPatterns, $patterns);
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            $typeHint = $param->hasType() ? $param->getType()->getName() : null;

            if (!$this->isValidRouteParam($typeHint)) {
                continue;
            }

            $routePatterns[$paramName] = $patterns[$paramName] ??
                ($this->defaultPatterns[":{$typeHint}"] ?? $this->defaultPatterns[':any']);
            $endpoints[] = $param->isOptional() ? "{{$paramName}?}" : "{{$paramName}}";
        }

        return [$endpoints, $routePatterns];
    }

    private function resolveControllerName(string $controller): array
    {
        $controller = str_replace(['.', $this->namespace], ['\\', ''], $controller);
        return [
            $this->namespace . "\\" . trim($controller, "\\"),
            $controller,
        ];
    }

    private function isValidRouteParam(?string $type): bool
    {
        if (is_null($type) || in_array($type, ['int', 'float', 'string', 'bool', 'mixed'])) {
            return true;
        }

        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            return true;
        }

        if (function_exists('enum_exists') && enum_exists($type)) {
            return true;
        }

        return false;
    }
}
