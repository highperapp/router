<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Unit;

use HighPerApp\HighPer\Router\Route;
use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\RouterException;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\TestCase;

class EARouterFeaturesTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testArrayBasedRouteDefinition(): void
    {
        $routeConfigs = [
            'api-health' => [
                'path'    => '/api/health',
                'methods' => ['GET'],
                'handler' => 'HealthController@check',
                'name'    => 'api.health',
            ],
            'user-show' => [
                'path'        => '/api/users/{id}',
                'methods'     => ['GET'],
                'handler'     => 'UserController@show',
                'constraints' => ['id' => 'int'],
                'middleware'  => ['auth'],
            ],
            'user-posts' => [
                'path'        => '/api/users/{userId}/posts/{postId}',
                'methods'     => ['GET'],
                'handler'     => 'PostController@show',
                'constraints' => [
                    'userId' => 'int',
                    'postId' => 'int',
                ],
                'middleware' => ['auth', 'throttle'],
            ],
        ];

        $this->router->defineRoutes($routeConfigs);

        // Test static route
        $request = MockRequest::create('GET', '/api/health');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('/api/health', $match->getPath());
        $this->assertEquals('HealthController@check', $match->getHandler());

        // Test dynamic route with constraints
        $request = MockRequest::create('GET', '/api/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('/api/users/{id}', $match->getPath());
        $this->assertEquals('UserController@show', $match->getHandler());
        $this->assertEquals(['id' => '123'], $match->getParameters());

        // Test nested dynamic route
        $request = MockRequest::create('GET', '/api/users/123/posts/456');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('/api/users/{userId}/posts/{postId}', $match->getPath());
        $this->assertEquals('PostController@show', $match->getHandler());
        $this->assertEquals(['userId' => '123', 'postId' => '456'], $match->getParameters());
    }

    public function testDefineRouteWithConstraints(): void
    {
        $route = $this->router->defineRoute('user-show', [
            'path'        => '/users/{id}',
            'methods'     => ['GET'],
            'handler'     => 'UserController@show',
            'constraints' => ['id' => 'int'],
        ]);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals('/users/{id}', $route->getPath());
        $this->assertEquals('UserController@show', $route->getHandler());
        $this->assertEquals(['id' => 'int'], $route->getConstraints());

        // Test valid integer parameter
        $request = MockRequest::create('GET', '/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['id' => '123'], $match->getParameters());

        // Test invalid non-integer parameter
        $request = MockRequest::create('GET', '/users/abc');
        $match = $this->router->match($request);
        $this->assertNull($match);
    }

    public function testDefineRouteWithMiddleware(): void
    {
        $route = $this->router->defineRoute('protected-route', [
            'path'       => '/protected',
            'methods'    => ['GET'],
            'handler'    => 'ProtectedController@index',
            'middleware' => ['auth', 'admin'],
        ]);

        $this->assertEquals(['auth', 'admin'], $route->getMiddleware());
    }

    public function testDefineRouteWithName(): void
    {
        $route = $this->router->defineRoute('custom-name', [
            'path'    => '/test',
            'methods' => ['GET'],
            'handler' => 'TestController@index',
            'name'    => 'test.index',
        ]);

        $this->assertEquals('test.index', $route->getName());
    }

    public function testDefineRouteRequiresHandler(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Handler is required for route: missing-handler');

        $this->router->defineRoute('missing-handler', [
            'path'    => '/test',
            'methods' => ['GET'],
        ]);
    }

    public function testQueryStringSupport(): void
    {
        // Define route with query string configuration
        $this->router->defineRoute('search', [
            'path'         => '/search',
            'methods'      => ['GET'],
            'handler'      => 'SearchController@index',
            'query_params' => [
                'required'   => ['q'],
                'optional'   => ['category', 'limit'],
                'validation' => ['limit' => 'int'],
            ],
        ]);

        // Test with required query parameter
        $request = MockRequest::createWithQuery('GET', '/search', [
            'q'        => 'test',
            'category' => 'books',
            'limit'    => '10',
        ]);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        $queryParams = $match->getQueryParams();
        $this->assertEquals('test', $queryParams['q']);
        $this->assertEquals('books', $queryParams['category']);
        $this->assertEquals('10', $queryParams['limit']);

        // Test missing required parameter
        $request = MockRequest::createWithQuery('GET', '/search', ['category' => 'books']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);

        // Test invalid validation
        $request = MockRequest::createWithQuery('GET', '/search', ['q' => 'test', 'limit' => 'invalid']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);
    }

    public function testQueryStringSimpleConfiguration(): void
    {
        // Define route with simple query parameter list
        $this->router->defineRoute('products', [
            'path'         => '/products',
            'methods'      => ['GET'],
            'handler'      => 'ProductController@index',
            'query_params' => ['page', 'limit', 'sort'],
        ]);

        $request = MockRequest::createWithQuery('GET', '/products', [
            'page'  => '1',
            'limit' => '20',
            'sort'  => 'name',
        ]);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        $queryParams = $match->getQueryParams();
        $this->assertEquals('1', $queryParams['page']);
        $this->assertEquals('20', $queryParams['limit']);
        $this->assertEquals('name', $queryParams['sort']);
    }

    public function testRouteMatchQueryHelperMethods(): void
    {
        $this->router->defineRoute('test-route', [
            'path'         => '/test/{id}',
            'methods'      => ['GET'],
            'handler'      => 'TestController@show',
            'query_params' => ['include', 'format'],
        ]);

        $request = MockRequest::createWithQuery('GET', '/test/123', [
            'include' => 'comments',
            'format'  => 'json',
        ]);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Test query parameter helper methods
        $this->assertTrue($match->hasQueryParam('include'));
        $this->assertTrue($match->hasQueryParam('format'));
        $this->assertFalse($match->hasQueryParam('nonexistent'));

        $this->assertEquals('comments', $match->getQueryParam('include'));
        $this->assertEquals('json', $match->getQueryParam('format'));
        $this->assertEquals('default', $match->getQueryParam('nonexistent', 'default'));

        $this->assertEquals(['include' => 'comments', 'format' => 'json'], $match->getQueryParams());
    }

    public function testFileBasedRouteConfiguration(): void
    {
        // Create a temporary configuration file
        $configPath = sys_get_temp_dir() . '/test_routes.php';
        $configContent = '<?php
return [
    "api-users" => [
        "path" => "/api/users",
        "methods" => ["GET"],
        "handler" => "UserController@index"
    ],
    "api-user-show" => [
        "path" => "/api/users/{id}",
        "methods" => ["GET"],
        "handler" => "UserController@show",
        "constraints" => ["id" => "int"]
    ]
];';
        file_put_contents($configPath, $configContent);

        // Load routes from file
        $this->router->defineRoutesFromFile($configPath);

        // Test routes were loaded correctly
        $request = MockRequest::create('GET', '/api/users');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('UserController@index', $match->getHandler());

        $request = MockRequest::create('GET', '/api/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('UserController@show', $match->getHandler());
        $this->assertEquals(['id' => '123'], $match->getParameters());

        // Clean up
        unlink($configPath);
    }

    public function testFileBasedConfigurationFileNotFound(): void
    {
        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Route configuration file not found: /nonexistent/file.php');

        $this->router->defineRoutesFromFile('/nonexistent/file.php');
    }

    public function testFileBasedConfigurationInvalidFormat(): void
    {
        $configPath = sys_get_temp_dir() . '/invalid_routes.php';
        file_put_contents($configPath, '<?php return "invalid";');

        $this->expectException(RouterException::class);
        $this->expectExceptionMessage('Route configuration file must return an array');

        $this->router->defineRoutesFromFile($configPath);

        unlink($configPath);
    }

    public function testBackwardCompatibilityWithFluentAPI(): void
    {
        // Mix fluent API with array-based configuration
        $this->router->get('/fluent', 'FluentController@index');

        $this->router->defineRoutes([
            'array-route' => [
                'path'    => '/array',
                'methods' => ['GET'],
                'handler' => 'ArrayController@index',
            ],
        ]);

        // Test both approaches work
        $request = MockRequest::create('GET', '/fluent');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('FluentController@index', $match->getHandler());

        $request = MockRequest::create('GET', '/array');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('ArrayController@index', $match->getHandler());
    }

    public function testPerformanceImpactMinimal(): void
    {
        // Test that array-based configuration doesn't impact matching performance
        $fluentRouter = new Router();
        $arrayRouter = new Router();

        // Define 100 routes using fluent API
        for ($i = 0; $i < 100; $i++) {
            $fluentRouter->get("/fluent/test/{$i}", "Handler{$i}");
        }

        // Define 100 routes using array configuration
        $arrayConfigs = [];
        for ($i = 0; $i < 100; $i++) {
            $arrayConfigs["array-test-{$i}"] = [
                'path'    => "/array/test/{$i}",
                'methods' => ['GET'],
                'handler' => "Handler{$i}",
            ];
        }
        $arrayRouter->defineRoutes($arrayConfigs);

        // Test matching performance is similar
        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $request = MockRequest::create('GET', '/fluent/test/50');
            $match = $fluentRouter->match($request);
        }
        $fluentTime = microtime(true) - $startTime;

        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $request = MockRequest::create('GET', '/array/test/50');
            $match = $arrayRouter->match($request);
        }
        $arrayTime = microtime(true) - $startTime;

        // Performance difference should be minimal (within 20%)
        $difference = abs($arrayTime - $fluentTime) / $fluentTime;
        $this->assertLessThan(0.2, $difference, 'Array-based configuration should not significantly impact performance');
    }

    public function testEARouterAlternativeConfigurationFormats(): void
    {
        // Test EARouter-style alternative configuration keys
        $this->router->defineRoutes([
            'earouter-style' => [
                'route_value'             => '/earouter/test',
                'allowed_request_methods' => ['GET', 'POST'],
                'controller'              => 'EARouterController@test',
                'where'                   => ['id' => 'int'],
            ],
        ]);

        $request = MockRequest::create('GET', '/earouter/test');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('EARouterController@test', $match->getHandler());
    }
}
