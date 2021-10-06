<?php

namespace Buki\AutoRoute;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
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
    /**
     * @var Container
     */
    protected $app;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var string[] Laravel Routing Available Methods.
     */
    protected $availableMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @var string Main Method
     */
    protected $mainMethod;

    /**
     * @var string
     */
    protected $defaultHttpMethods;

    /**
     * @var string[]
     */
    protected $defaultPatterns = [
        ':any' => '([^/]+)',
        ':int' => '(\d+)',
        ':float' => '[+-]?([0-9]*[.])?[0-9]+',
        ':bool' => '(true|false|1|0)',
    ];

    /**
     * AutoRoute constructor.
     *
     * @param Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->router = $app->get('router');
    }

    /**
     * Initialize for the AutoRoute
     *
     * @param array $config
     */
    public function setConfigurations(array $config): void
    {
        $this->mainMethod           = $config['main_method'] ?? 'index';
        $this->namespace            = $config['namespace'] ?? 'App\\Http\\Controllers';
        $this->defaultPatterns      = array_merge($this->defaultPatterns, $config['patterns'] ?? []);
        $this->defaultHttpMethods   = $config['http_methods'] ?? $this->availableMethods;

        if (empty($this->defaultHttpMethods) || $this->defaultHttpMethods === '*') {
            $this->defaultHttpMethods = $this->availableMethods;
        }
    }

    /**
     * @param string $prefix
     * @param string $controller
     * @param array  $options
     *
     * @return void
     * @throws
     */
    public function auto(string $prefix, string $controller, array $options = []): void
    {
        [$class, $className] = $this->resolveControllerName($controller);
        $classRef = new ReflectionClass($class);
        foreach ($classRef->getMethods() as $method) {
            // Check the method should be added into Routes or not.
            if (!stristr($method->class, $className) || !$method->isPublic()
                || strpos($method->name, '__') === 0) {
                continue;
            }

            // Needed definitions
            $methodName = $method->name;
            $only = $options['only'] ?? [];
            $except = $options['except'] ?? [];
            $patterns = $options['patterns'] ?? [];

            if ((!empty($only) && !in_array($methodName, $only))
                || (!empty($except) && in_array($methodName, $except))) {
                continue;
            }

            // Find the HTTP method which will be used and method name.
            [$httpMethods, $methodName] = $this->getHttpMethodAndName($methodName);

            // Get endpoints and parameter patterns for Route
            [$endpoints, $routePatterns] = $this->getRouteValues($method, $patterns);
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
                function () use ($endpoints, $methodName, $method, $httpMethods, $routePatterns) {
                    $endpoints = implode('/', $endpoints);
                    $this->router->addRoute(
                        array_map(function ($method) {
                            return strtoupper($method);
                        }, $httpMethods),
                        ($methodName !== $this->mainMethod ? $methodName : '') . "/{$endpoints}",
                        [$method->class, $method->name]
                    )->where($routePatterns)->name("{$method->name}");
                }
            );
        }
    }

    /**
     * @param string $methodName
     *
     * @return array
     */
    private function getHttpMethodAndName(string $methodName): array
    {
        $httpMethods = $this->defaultHttpMethods;
        foreach ($this->availableMethods as $httpMethod) {
            if (stripos($methodName, strtolower($httpMethod), 0) === 0) {
                $httpMethods = [$httpMethod];
                $methodName = lcfirst(
                    preg_replace('/' . strtolower($httpMethod) . '_?/i', '', $methodName, 1)
                );
                break;
            }
        }

        // Convert URL from camelCase to snake-case.
        $methodName = strtolower(preg_replace('%([a-z]|[0-9])([A-Z])%', '\1-\2', $methodName));

        return [$httpMethods, $methodName];
    }

    /**
     * @param ReflectionMethod $method
     * @param array            $patterns
     *
     * @return array
     */
    private function getRouteValues(ReflectionMethod $method, array $patterns = []): array
    {
        $routePatterns = $endpoints = [];
        $patterns = array_merge($this->defaultPatterns, $patterns);
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            $typeHint = $param->hasType() ? $param->getType()->getName() : null;

            if ($typeHint !== null && class_exists($typeHint)) {
                if (!in_array($typeHint, ['int', 'float', 'string', 'bool']) && !in_array($typeHint, $patterns)
                    && !is_subclass_of($typeHint, Model::class)) {
                    continue;
                }
            }

            $routePatterns[$paramName] = $patterns[$paramName] ??
                ($this->defaultPatterns[":{$typeHint}"] ?? $this->defaultPatterns[':any']);
            $endpoints[] = $param->isOptional() ? "{{$paramName}?}" : "{{$paramName}}";
        }

        return [$endpoints, $routePatterns];
    }

    /**
     * @param string $controller
     *
     * @return array
     */
    private function resolveControllerName(string $controller): array
    {
        $controller = str_replace(['.', $this->namespace], ['\\', ''], $controller);
        return [
            $this->namespace . "\\" . trim($controller, "\\"),
            $controller,
        ];
    }
}
