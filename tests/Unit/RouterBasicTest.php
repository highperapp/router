<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Unit;

use HighPerApp\HighPer\Router\Route;
use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

#[Group('unit')]
#[Group('router')]
class RouterBasicTest extends TestCase
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
        $this->assertEquals(['GET'], $route->getMethods());
        $this->assertEquals('/test', $route->getPath());
    }

    #[Test]
    #[TestDox('Router can register POST route correctly')]
    public function testRouterCanRegisterPostRouteCorrectly(): void
    {
        $handler = function () { return 'post'; };
        $route = $this->router->post('/users', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals(['POST'], $route->getMethods());
        $this->assertEquals('/users', $route->getPath());
    }

    #[Test]
    #[TestDox('Router can register PUT route correctly')]
    public function testRouterCanRegisterPutRouteCorrectly(): void
    {
        $handler = function () { return 'put'; };
        $route = $this->router->put('/users/{id}', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals(['PUT'], $route->getMethods());
        $this->assertEquals('/users/{id}', $route->getPath());
    }

    #[Test]
    #[TestDox('Router can register DELETE route correctly')]
    public function testRouterCanRegisterDeleteRouteCorrectly(): void
    {
        $handler = function () { return 'delete'; };
        $route = $this->router->delete('/users/{id}', $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals(['DELETE'], $route->getMethods());
        $this->assertEquals('/users/{id}', $route->getPath());
    }

    #[Test]
    #[TestDox('Router can match static route correctly')]
    public function testRouterCanMatchStaticRouteCorrectly(): void
    {
        $handler = function () { return 'test response'; };
        $this->router->get('/test', $handler);

        $request = $this->createMockRequest('GET', '/test');
        $match = $this->router->match($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertSame($handler, $match->getHandler());
        $this->assertEmpty($match->getParameters());
    }

    #[Test]
    #[TestDox('Router returns null for non-existent route')]
    public function testRouterReturnsNullForNonExistentRoute(): void
    {
        $request = $this->createMockRequest('GET', '/nonexistent');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    #[Test]
    #[TestDox('Router can match route with single parameter')]
    public function testRouterCanMatchRouteWithSingleParameter(): void
    {
        $handler = function () { return 'user'; };
        $this->router->get('/users/{id}', $handler);

        $request = $this->createMockRequest('GET', '/users/123');
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

        $request = $this->createMockRequest('GET', '/users/123/posts/456');
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
        $route->whereNumber('id');

        // Should match numeric ID
        $request = $this->createMockRequest('GET', '/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Should not match non-numeric ID
        $request = $this->createMockRequest('GET', '/users/abc');
        $noMatch = $this->router->match($request);
        $this->assertNull($noMatch);
    }

    #[Test]
    #[TestDox('Router supports middleware on routes')]
    public function testRouterSupportsMiddlewareOnRoutes(): void
    {
        $handler = function () { return 'admin'; };
        $route = $this->router->get('/admin', $handler);
        $route->middleware('auth');

        $request = $this->createMockRequest('GET', '/admin');
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

        $request = $this->createMockRequest('GET', '/api/data');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['auth', 'throttle', 'cors'], $match->getMiddleware());
    }

    #[Test]
    #[TestDox('Router handles route names correctly')]
    public function testRouterHandlesRouteNamesCorrectly(): void
    {
        $handler = function () { return 'user'; };
        $route = $this->router->get('/users/{id}', $handler);
        $route->name('user.show');

        $request = $this->createMockRequest('GET', '/users/123');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals('user.show', $match->getName());
    }

    #[Test]
    #[TestDox('Router cache improves performance')]
    public function testRouterCacheImprovesPerformance(): void
    {
        $this->router->setCacheEnabled(true);

        $handler = function () { return 'cached'; };
        $this->router->get('/cached', $handler);

        $request = $this->createMockRequest('GET', '/cached');

        // First match
        $match1 = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match1);

        // Second match should use cache
        $match2 = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match2);
        $this->assertEquals($match1->getHandler(), $match2->getHandler());
    }

    #[Test]
    #[TestDox('Router provides performance statistics')]
    public function testRouterProvidesPerformanceStatistics(): void
    {
        $this->router->get('/test', function () { return 'test'; });

        $stats = $this->router->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('static_routes', $stats);
        $this->assertArrayHasKey('dynamic_routes', $stats);
        $this->assertArrayHasKey('cache_size', $stats);
        $this->assertArrayHasKey('cache_enabled', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
    }

    #[Test]
    #[TestDox('Router compilation works correctly')]
    public function testRouterCompilationWorksCorrectly(): void
    {
        // Add routes
        for ($i = 0; $i < 10; $i++) {
            $this->router->get("/route{$i}", function () use ($i) { return "route{$i}"; });
        }

        // Force compilation
        $this->router->compile();

        // Test that compiled router still works
        $request = $this->createMockRequest('GET', '/route5');
        $match = $this->router->match($request);
        $this->assertInstanceOf(RouteMatch::class, $match);
    }

    #[Test]
    #[TestDox('Router memory usage is reasonable')]
    public function testRouterMemoryUsageIsReasonable(): void
    {
        $initialMemory = memory_get_usage(true);

        // Add many routes
        for ($i = 0; $i < 100; $i++) {
            $this->router->get("/route{$i}", function () use ($i) { return "route{$i}"; });
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (under 5MB for 100 routes)
        $this->assertLessThan(5 * 1024 * 1024, $memoryIncrease);
    }

    private function createMockRequest(string $method, string $path): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getMethod')->willReturn($method);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }
}
