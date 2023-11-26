<?php

namespace Buki\AutoRoute;

use Buki\AutoRoute\Middleware\AjaxRequestMiddleware;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionMethod;

// for Livewire support
use Livewire\{Volt\Volt, Component};

/**
 * Class AutoRoute
 *
 * @package Buki\AutoRoute
 * @author  İzni Burak Demirtaş <info@burakdemirtas.org>
 * @web     https://buki.dev
 */
class AutoRoute
{
    protected Router $router;

    protected string $namespace;

    protected array $availableMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    protected array $customMethods = ['XGET', 'XPOST', 'XPUT', 'XPATCH', 'XDELETE', 'XOPTIONS', 'XANY', 'VOLT', 'WIRE'];

    protected string $mainMethod;

    protected string|array $defaultHttpMethods;

    protected string $ajaxMiddleware;

    protected array $defaultPatterns = [
        ':any' => '([^/]+)',
        ':int' => '(\d+)',
        ':float' => '[+-]?([0-9]*[.])?[0-9]+',
        ':bool' => '(true|false|1|0)',
    ];

    /**
     * AutoRoute constructor.
     * @throws
     */
    public function __construct(protected Container $app)
    {
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
                        || str_starts_with($method->name, '__')) {
                        continue;
                    }

                    // Needed definitions
                    $methodName = $method->name;

                    if ((!empty($only) && !in_array($methodName, $only))
                        || (!empty($except) && in_array($methodName, $except))) {
                        continue;
                    }

                    // Find the HTTP method which will be used and method name.
                    [$httpMethods, $path, $middleware] = $this->getHttpMethodAndName($methodName);

                    // Get endpoints and parameter patterns for Route
                    [$endpoints, $routePatterns] = $this->getRouteValues($method, $patterns);

                    $endpoint = implode('/', $endpoints);

                    $handler = [$classRef->getName(), $method->name];
                    $routePath = ($path !== $this->mainMethod ? $path : '') . "/{$endpoint}";

                    // for volt
                    if (str_starts_with($method->name, 'volt')) {
                        if (class_exists(Volt::class) && $method->getReturnType()?->getName() === 'string') {
                            Volt::route($routePath, $method->invoke(new ($classRef->getName()), ...$endpoints))
                                ->where($routePatterns)->name("{$method->name}")->middleware($middleware);
                        }

                        continue;
                    }

                    // for livewire
                    if (str_starts_with($method->name, 'wire')) {
                        if (!(class_exists(Component::class) && $method->getReturnType()?->getName() === 'string')) {
                            continue;
                        }

                        $handler = $method->invoke(new ($classRef->getName()), ...$endpoints);
                        if (!is_subclass_of($handler, Component::class)) {
                            continue;
                        }
                    }

                    $this->router
                        ->addRoute(array_map(fn ($method) => strtoupper($method), $httpMethods), $routePath, $handler)
                        ->where($routePatterns)->name("{$method->name}")->middleware($middleware);
                }
            }
        );
    }

    private function getHttpMethodAndName(string $controllerMethod): array
    {
        $httpMethods = $this->defaultHttpMethods;
        $middleware = null;
        foreach (array_merge($this->availableMethods, $this->customMethods) as $method) {
            $method = strtolower($method);
            if (stripos($controllerMethod, $method, 0) === 0) {
                if (in_array($method, ['volt', 'wire'])) {
                    $httpMethods = ['GET', 'HEAD'];
                } elseif ($method !== 'xany') {
                    $httpMethods = [ltrim($method, 'x')];
                }

                $middleware = str_starts_with($method, 'x') ? $this->ajaxMiddleware : null;
                $controllerMethod = lcfirst(
                    preg_replace('/' . $method . '_?/i', '', $controllerMethod, 1)
                );
                break;
            }
        }

        // Convert URL from camelCase to snake-case.
        $controllerMethod = strtolower(preg_replace('%([a-z]|[0-9])([A-Z])%', '\1-\2', $controllerMethod));

        return [$httpMethods, $controllerMethod, $middleware];
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
