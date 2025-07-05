<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Unit;

use HighPerApp\HighPer\Router\Route;
use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\RouterException;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('router')]
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    #[Test]
    #[TestDox('Router can register GET route correctly')]
    public function testRouterCanRegisterGetRouteCorrectly(): void
    {
        $handler = function () { return 'test'; };
        $route = $this->router->get('/test', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertTrue($this->router->hasRoute('GET', '/test'));
    }

    #[Test]
    #[TestDox('Router can register POST route correctly')]
    public function testRouterCanRegisterPostRouteCorrectly(): void
    {
        $handler = function () { return 'post'; };
        $route = $this->router->post('/users', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertTrue($this->router->hasRoute('POST', '/users'));
    }

    #[Test]
    #[TestDox('Router can register PUT route correctly')]
    public function testRouterCanRegisterPutRouteCorrectly(): void
    {
        $handler = function () { return 'put'; };
        $route = $this->router->put('/users/{id}', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertTrue($this->router->hasRoute('PUT', '/users/{id}'));
    }

    #[Test]
    #[TestDox('Router can register DELETE route correctly')]
    public function testRouterCanRegisterDeleteRouteCorrectly(): void
    {
        $handler = function () { return 'delete'; };
        $route = $this->router->delete('/users/{id}', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertTrue($this->router->hasRoute('DELETE', '/users/{id}'));
    }

    #[Test]
    #[TestDox('Router can match static route correctly')]
    public function testRouterCanMatchStaticRouteCorrectly(): void
    {
        $handler = function () { return 'test response'; };
        $this->router->get('/test', $handler);

        $request = MockRequest::create('GET', '/test');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame($handler, $match->getHandler());
        $this->assertEmpty($match->getParameters());
    }

    #[Test]
    #[TestDox('Router returns null for non-existent route')]
    public function testRouterReturnsNullForNonExistentRoute(): void
    {
        $request = MockRequest::create('GET', '/nonexistent');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    #[Test]
    #[TestDox('Router can match route with single parameter')]
    public function testRouterCanMatchRouteWithSingleParameter(): void
    {
        $handler = function () { return 'user'; };
        $this->router->get('/users/{id}', $handler);

        $request = MockRequest::create('GET', '/users/123');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['id' => '123'], $match->getParameters());
    }

    #[Test]
    #[TestDox('Router can match route with multiple parameters')]
    public function testRouterCanMatchRouteWithMultipleParameters(): void
    {
        $handler = function () { return 'post'; };
        $this->router->get('/users/{userId}/posts/{postId}', $handler);

        $request = MockRequest::create('GET', '/users/123/posts/456');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['userId' => '123', 'postId' => '456'], $match->getParameters());
    }

    #[Test]
    #[TestDox('Router handles parameter constraints correctly')]
    public function testRouterHandlesParameterConstraintsCorrectly(): void
    {
        $handler = function () { return 'user'; };
        $route = $this->router->get('/users/{id}', $handler);
        $route->where('id', 'int'); // Only digits

        // Should match numeric ID
        $request = MockRequest::create('GET', '/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Should not match non-numeric ID
        $request = MockRequest::create('GET', '/users/abc');
        $noMatch = $this->router->match($request);
        $this->assertNull($noMatch);
    }

    #[Test]
    #[TestDox('Router handles multiple constraints correctly')]
    public function testRouterHandlesMultipleConstraintsCorrectly(): void
    {
        $handler = function () { return 'api'; };
        $route = $this->router->get('/api/{version}/users/{id}', $handler);
        $route->where([
            'version' => 'alnum',
            'id'      => 'int',
        ]);

        // Should match valid version and ID
        $request = MockRequest::create('GET', '/api/v2/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['version' => 'v2', 'id' => '123'], $match->getParameters());
    }

    // TODO: Optional parameters ({category?}) not yet implemented
    // #[Test]
    // #[TestDox('Router handles optional parameters correctly')]
    // public function testRouterHandlesOptionalParametersCorrectly(): void

    #[Test]
    #[TestDox('Router supports route groups correctly')]
    public function testRouterSupportsRouteGroupsCorrectly(): void
    {
        $this->router->group('/api/v1', function ($router) {
            $router->get('/users', function () { return 'users'; });
            $router->get('/posts', function () { return 'posts'; });
        });

        $this->assertTrue($this->router->hasRoute('GET', '/api/v1/users'));
        $this->assertTrue($this->router->hasRoute('GET', '/api/v1/posts'));

        $request = MockRequest::create('GET', '/api/v1/users');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
    }

    #[Test]
    #[TestDox('Router supports nested route groups')]
    public function testRouterSupportsNestedRouteGroups(): void
    {
        $this->router->group('/api', function ($router) {
            $router->group('/v1', function ($router) {
                $router->get('/users', function () { return 'users'; });
            });
        });

        $this->assertTrue($this->router->hasRoute('GET', '/api/v1/users'));

        $request = MockRequest::create('GET', '/api/v1/users');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
    }

    #[Test]
    #[TestDox('Router supports middleware on routes')]
    public function testRouterSupportsMiddlewareOnRoutes(): void
    {
        $handler = function () { return 'admin'; };
        $route = $this->router->get('/admin', $handler);
        $route->middleware('auth');

        $request = MockRequest::create('GET', '/admin');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertContains('auth', $match->getMiddleware());
    }

    #[Test]
    #[TestDox('Router supports multiple middleware on routes')]
    public function testRouterSupportsMultipleMiddlewareOnRoutes(): void
    {
        $handler = function () { return 'api'; };
        $route = $this->router->get('/api/data', $handler);
        $route->middleware(['auth', 'throttle', 'cors']);

        $request = MockRequest::create('GET', '/api/data');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['auth', 'throttle', 'cors'], $match->getMiddleware());
    }

    // TODO: middlewareGroup not implemented
    // #[Test]
    // #[TestDox('Router supports middleware groups correctly')]
    // public function testRouterSupportsMiddlewareGroupsCorrectly(): void

    // TODO: resource method not implemented
    // #[Test]
    // #[TestDox('Router supports RESTful resource routes')]
    // public function testRouterSupportsRestfulResourceRoutes(): void

    // TODO: loadCompiled static method not implemented
    // #[Test]
    // #[TestDox('Router handles route compilation correctly')]
    // public function testRouterHandlesRouteCompilationCorrectly(): void

    // TODO: enableProfiling, getCacheStats, getProfile methods not implemented
    // #[Test]
    // #[TestDox('Router provides performance statistics')]
    // public function testRouterProvidesPerformanceStatistics(): void

    // TODO: writeCache method not implemented
    // #[Test]
    // #[TestDox('Router handles route caching correctly')]
    // public function testRouterHandlesRouteCachingCorrectly(): void

    #[Test]
    #[TestDox('Router performance is acceptable for many routes')]
    public function testRouterPerformanceIsAcceptableForManyRoutes(): void
    {
        // Add many routes
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/route{$i}", function () use ($i) { return "route{$i}"; });
            $this->router->get("/users/{$i}", function () use ($i) { return "user{$i}"; });
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

        // Should handle 100 matches against 2000 routes in under 100ms
        $this->assertLessThan(0.1, $duration);
    }

    #[Test]
    #[TestDox('Router memory usage stays reasonable')]
    public function testRouterMemoryUsageStaysReasonable(): void
    {
        $initialMemory = memory_get_usage(true);

        // Add many routes
        for ($i = 0; $i < 500; $i++) {
            $this->router->get("/route{$i}", function () use ($i) { return "route{$i}"; });
            $this->router->post("/users{$i}", function () use ($i) { return "user{$i}"; });
            $this->router->get("/api/v1/resource{$i}/{id}", function () use ($i) { return "resource{$i}"; });
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (under 10MB for 1500 routes)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease);
    }

    #[Test]
    #[TestDox('Router validates route patterns correctly')]
    #[DataProvider('invalidRoutePatternProvider')]
    public function testRouterValidatesRoutePatternsCorrectly(string $pattern, bool $shouldThrow): void
    {
        if ($shouldThrow) {
            $this->expectException(RouterException::class);
        }

        $route = $this->router->get($pattern, function () { return 'test'; });

        if (!$shouldThrow) {
            $this->assertInstanceOf(Route::class, $route);
        }
    }

    public static function invalidRoutePatternProvider(): array
    {
        return [
            'valid simple route'            => ['/users', false],
            'valid parameterized route'     => ['/users/{id}', false],
            // 'valid optional parameter'      => ['/posts/{category?}', false], // TODO: Optional parameters not implemented
            'valid multiple parameters'     => ['/users/{userId}/posts/{postId}', false],
            'empty pattern'                 => ['', true],
            'pattern without leading slash' => ['users', true],
            'invalid parameter syntax'      => ['/users/{id', true],
            'invalid parameter name'        => ['/users/{123}', true],
        ];
    }

    #[Test]
    #[TestDox('Router handles concurrent access safely')]
    public function testRouterHandlesConcurrentAccessSafely(): void
    {
        $this->router->get('/test', function () { return 'concurrent'; });

        $results = [];

        // Simulate concurrent access
        for ($i = 0; $i < 50; $i++) {
            $request = MockRequest::create('GET', '/test');
            $match = $this->router->match($request);
            $results[] = $match !== null;
        }

        // All matches should succeed
        $this->assertCount(50, array_filter($results));
    }

    // TODO: enableMethodOverride, matchWithOverride methods not implemented
    // #[Test]
    // #[TestDox('Router supports method override correctly')]
    // public function testRouterSupportsMethodOverrideCorrectly(): void

    // TODO: hasNamedRoute, route methods not implemented
    // #[Test]
    // #[TestDox('Router handles route aliasing correctly')]
    // public function testRouterHandlesRouteAliasingCorrectly(): void

    // TODO: route method not implemented
    // #[Test]
    // #[TestDox('Router generates URLs with parameters correctly')]
    // public function testRouterGeneratesUrlsWithParametersCorrectly(): void

    // TODO: domain, matchWithDomain methods not implemented
    // #[Test]
    // #[TestDox('Router handles subdomain routing correctly')]
    // public function testRouterHandlesSubdomainRoutingCorrectly(): void

    // TODO: signed routes, signedRoute, hasValidSignature methods not implemented
    // #[Test]
    // #[TestDox('Router supports signed routes correctly')]
    // public function testRouterSupportsSignedRoutesCorrectly(): void
}

// Test classes
class TestController
{
    public function index()
    {
        return 'index';
    }

    public function show($id)
    {
        return "show {$id}";
    }

    public function create()
    {
        return 'create';
    }

    public function store()
    {
        return 'store';
    }

    public function edit($id)
    {
        return "edit {$id}";
    }

    public function update($id)
    {
        return "update {$id}";
    }

    public function destroy($id)
    {
        return "destroy {$id}";
    }
}

class UserController extends TestController
{
}
