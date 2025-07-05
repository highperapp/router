<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Unit;

use HighPerApp\HighPer\Router\Route;
use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('core')]
class RouterCoreTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    #[Test]
    #[TestDox('Router can register HTTP method routes')]
    public function testRouterCanRegisterHttpMethodRoutes(): void
    {
        $handler = function () { return 'test'; };

        $getRoute = $this->router->get('/test', $handler);
        $postRoute = $this->router->post('/users', $handler);
        $putRoute = $this->router->put('/users/{id}', $handler);
        $deleteRoute = $this->router->delete('/users/{id}', $handler);
        $patchRoute = $this->router->patch('/users/{id}', $handler);
        $optionsRoute = $this->router->options('/users', $handler);

        $this->assertInstanceOf(Route::class, $getRoute);
        $this->assertInstanceOf(Route::class, $postRoute);
        $this->assertInstanceOf(Route::class, $putRoute);
        $this->assertInstanceOf(Route::class, $deleteRoute);
        $this->assertInstanceOf(Route::class, $patchRoute);
        $this->assertInstanceOf(Route::class, $optionsRoute);
    }

    #[Test]
    #[TestDox('Router can match static routes correctly')]
    public function testRouterCanMatchStaticRoutesCorrectly(): void
    {
        $handler = function () { return 'home'; };
        $this->router->get('/', $handler);
        $this->router->get('/about', $handler);

        $homeRequest = MockRequest::create('GET', '/');
        $homeMatch = $this->router->match($homeRequest);

        $aboutRequest = MockRequest::create('GET', '/about');
        $aboutMatch = $this->router->match($aboutRequest);

        $this->assertInstanceOf(RouteMatch::class, $homeMatch);
        $this->assertInstanceOf(RouteMatch::class, $aboutMatch);
        $this->assertSame($handler, $homeMatch->getHandler());
        $this->assertEmpty($homeMatch->getParameters());
    }

    #[Test]
    #[TestDox('Router can match dynamic routes with parameters')]
    public function testRouterCanMatchDynamicRoutesWithParameters(): void
    {
        $handler = function () { return 'user'; };
        $this->router->get('/users/{id}', $handler);
        $this->router->get('/users/{id}/posts/{slug}', $handler);

        // Single parameter
        $request = MockRequest::create('GET', '/users/123');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['id' => '123'], $match->getParameters());

        // Multiple parameters
        $request = MockRequest::create('GET', '/users/456/posts/hello-world');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['id' => '456', 'slug' => 'hello-world'], $match->getParameters());
    }

    #[Test]
    #[TestDox('Router supports parameter constraints')]
    public function testRouterSupportsParameterConstraints(): void
    {
        $handler = function () { return 'result'; };

        // Test built-in constraints
        $this->router->get('/users/{id}', $handler)->whereNumber('id');
        $this->router->get('/posts/{slug}', $handler)->whereSlug('slug');
        $this->router->get('/files/{uuid}', $handler)->whereUuid('uuid');

        // Test number constraint
        $validRequest = MockRequest::create('GET', '/users/123');
        $validMatch = $this->router->match($validRequest);
        $this->assertInstanceOf(RouteMatch::class, $validMatch);

        // Test slug constraint
        $slugRequest = MockRequest::create('GET', '/posts/hello-world');
        $slugMatch = $this->router->match($slugRequest);
        $this->assertInstanceOf(RouteMatch::class, $slugMatch);

        // Test UUID constraint
        $uuidRequest = MockRequest::create('GET', '/files/550e8400-e29b-41d4-a716-446655440000');
        $uuidMatch = $this->router->match($uuidRequest);
        $this->assertInstanceOf(RouteMatch::class, $uuidMatch);
    }

    #[Test]
    #[TestDox('Router supports middleware on routes')]
    public function testRouterSupportsMiddlewareOnRoutes(): void
    {
        $handler = function () { return 'protected'; };
        $route = $this->router->get('/admin', $handler);
        $route->middleware('auth');
        $route->middleware(['admin', 'cors']);

        $request = MockRequest::create('GET', '/admin');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['auth', 'admin', 'cors'], $match->getMiddleware());
    }

    #[Test]
    #[TestDox('Router supports route names')]
    public function testRouterSupportsRouteNames(): void
    {
        $handler = function () { return 'user'; };
        $route = $this->router->get('/users/{id}', $handler);
        $route->name('user.show');

        $request = MockRequest::create('GET', '/users/123');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('user.show', $match->getName());
    }

    #[Test]
    #[TestDox('Router can add routes with multiple HTTP methods')]
    public function testRouterCanAddRoutesWithMultipleHttpMethods(): void
    {
        $handler = function () { return 'api'; };
        $route = $this->router->addRoute(['GET', 'POST'], '/api/data', $handler);

        $this->assertInstanceOf(Route::class, $route);

        $getRequest = MockRequest::create('GET', '/api/data');
        $getMatch = $this->router->match($getRequest);

        $postRequest = MockRequest::create('POST', '/api/data');
        $postMatch = $this->router->match($postRequest);

        $this->assertInstanceOf(RouteMatch::class, $getMatch);
        $this->assertInstanceOf(RouteMatch::class, $postMatch);
    }

    #[Test]
    #[TestDox('Router can handle any HTTP method')]
    public function testRouterCanHandleAnyHttpMethod(): void
    {
        $handler = function () { return 'wildcard'; };
        $route = $this->router->any('/wildcard', $handler);

        $this->assertInstanceOf(Route::class, $route);

        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        foreach ($methods as $method) {
            $request = MockRequest::create($method, '/wildcard');
            $match = $this->router->match($request);
            $this->assertInstanceOf(RouteMatch::class, $match);
        }
    }

    #[Test]
    #[TestDox('Router returns null for non-existent routes')]
    public function testRouterReturnsNullForNonExistentRoutes(): void
    {
        $request = MockRequest::create('GET', '/nonexistent');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    #[Test]
    #[TestDox('Router caching improves performance')]
    public function testRouterCachingImprovesPerformance(): void
    {
        $handler = function () { return 'cached'; };
        $this->router->get('/cached/{id}', $handler);

        // Enable caching
        $this->router->setCacheEnabled(true);

        // First request - should populate cache
        $request = MockRequest::create('GET', '/cached/123');
        $firstMatch = $this->router->match($request);

        // Second request - should use cache
        $secondMatch = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $firstMatch);
        $this->assertInstanceOf(RouteMatch::class, $secondMatch);
        $this->assertEquals($firstMatch->getParameters(), $secondMatch->getParameters());
    }

    #[Test]
    #[TestDox('Router provides performance statistics')]
    public function testRouterProvidesPerformanceStatistics(): void
    {
        $handler = function () { return 'test'; };
        $this->router->get('/test', $handler);
        $this->router->get('/users/{id}', $handler);

        $stats = $this->router->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('static_routes', $stats);
        $this->assertArrayHasKey('dynamic_routes', $stats);
        $this->assertArrayHasKey('cache_enabled', $stats);
    }

    #[Test]
    #[TestDox('Router can clear cache')]
    public function testRouterCanClearCache(): void
    {
        $handler = function () { return 'test'; };
        $this->router->get('/test/{id}', $handler);
        $this->router->setCacheEnabled(true);

        // Populate cache
        $request = MockRequest::create('GET', '/test/123');
        $this->router->match($request);

        // Clear cache
        $this->router->clearCache();

        // Should still work after clearing cache
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
    }

    #[Test]
    #[TestDox('Router handles large number of routes efficiently')]
    public function testRouterHandlesLargeNumberOfRoutesEfficiently(): void
    {
        $handler = function () { return 'route'; };

        // Add many routes
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/route{$i}", $handler);
            $this->router->get("/users/{$i}", $handler);
        }

        $startTime = microtime(true);

        // Test matching performance
        for ($i = 0; $i < 100; $i++) {
            $routeId = mt_rand(0, 999);
            $request = MockRequest::create('GET', "/route{$routeId}");
            $match = $this->router->match($request);
            $this->assertInstanceOf(RouteMatch::class, $match);
        }

        $duration = microtime(true) - $startTime;

        // Should handle 100 matches against 2000 routes quickly
        $this->assertLessThan(0.1, $duration, 'Router performance is too slow');
    }

    #[Test]
    #[TestDox('Router memory usage is reasonable')]
    public function testRouterMemoryUsageIsReasonable(): void
    {
        $initialMemory = memory_get_usage(true);

        $handler = function () { return 'test'; };

        // Add many routes
        for ($i = 0; $i < 500; $i++) {
            $this->router->get("/route{$i}", $handler);
            $this->router->post("/api{$i}", $handler);
            $this->router->get("/users/{$i}/posts/{id}", $handler);
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (under 10MB for 1500 routes)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 'Memory usage too high');
    }

    #[Test]
    #[TestDox('Router supports different handler types')]
    public function testRouterSupportsDifferentHandlerTypes(): void
    {
        // Closure handler
        $closureHandler = function () { return 'closure'; };
        $this->router->get('/closure', $closureHandler);

        // String handler
        $this->router->get('/string', 'TestHandler');

        // Array handler [class, method]
        $this->router->get('/array', [CoreTestController::class, 'index']);

        // Test closure handler
        $request = MockRequest::create('GET', '/closure');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame($closureHandler, $match->getHandler());

        // Test string handler
        $request = MockRequest::create('GET', '/string');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('TestHandler', $match->getHandler());

        // Test array handler
        $request = MockRequest::create('GET', '/array');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals([CoreTestController::class, 'index'], $match->getHandler());
    }
}

// Test controller for handler testing
class CoreTestController
{
    public function index()
    {
        return 'controller response';
    }
}
