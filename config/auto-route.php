<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default namespace of the Controllers
    |--------------------------------------------------------------------------
    | Default namespace of the Controllers which will be used by AutoRoute.
    */
    'namespace' => 'App\\Http\\Controllers',

    /*
    |--------------------------------------------------------------------------
    | Main Method
    |--------------------------------------------------------------------------
    | Main method for the Controllers. This method name will be used to
    | define main endpoint for the Controllers.
    */
    'main_method' => 'index',

    /*
    |--------------------------------------------------------------------------
    | Default HTTP Method(s)
    |--------------------------------------------------------------------------
    | Default HTTP methods for the routes which will be generated from
    | method name which not specified the HTTP method.
    | You can define multiple methods by using an array.
    |
    | type: array
    | null or '*': all methods that supported by Laravel
    */
    'http_methods' => '*',

    /*
    |--------------------------------------------------------------------------
    | Parameter Patterns
    |--------------------------------------------------------------------------
    | You can define more new patterns for the all parameters that
    | you'll use at methods of the Controllers. Parameters that do not match
    | any pattern will accept all values.
    |
    | Format: $variable => pattern
    | Example: 'id' => '(\d+)'
    */
    'patterns' => [
        'slug' => '([\w\-_]+)',
        'uuid' => '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})',
        'date' => '([0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1]))',
    ],

    /*
    |--------------------------------------------------------------------------
    | AJAX Middleware Class
    |--------------------------------------------------------------------------
    | The middleware class that check AJAX request for your methods
    | which starts with 'x' char in your Controller file.
    | For example: xgetFoo, xpostBar, xanyBaz.
    | If you have any method in your controller like above, this middleware
    | will be triggered while trying to access your route.
    |
    | Default: \Buki\AutoRoute\Middleware\AjaxRequestMiddleware::class
    */
    // 'ajax_middleware' => App\\Http\\Middleware\\YourMiddleware::class,

];
