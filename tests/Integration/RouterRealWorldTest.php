<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Integration;

use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('realworld')]
class RouterRealWorldTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    #[Test]
    #[TestDox('Router works as standalone library in any PHP project')]
    public function testRouterWorksAsStandaloneLibrary(): void
    {
        // Simple REST API setup
        $this->router->get('/api/health', function () {
            return ['status' => 'healthy', 'timestamp' => time()];
        });

        $this->router->get('/api/users', function () {
            return ['users' => [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]];
        });

        $this->router->get('/api/users/{id}', function ($request) {
            $match = $this->router->match($request);
            $id = $match->getParameters()['id'];

            return ['user' => ['id' => $id, 'name' => "User {$id}"]];
        })->whereNumber('id');

        $this->router->post('/api/users', function () {
            return ['message' => 'User created', 'id' => 123];
        });

        // Test health endpoint
        $healthRequest = MockRequest::create('GET', '/api/health');
        $healthMatch = $this->router->match($healthRequest);
        $this->assertNotNull($healthMatch);
        $response = $healthMatch->getHandler()();
        $this->assertEquals('healthy', $response['status']);

        // Test users list
        $usersRequest = MockRequest::create('GET', '/api/users');
        $usersMatch = $this->router->match($usersRequest);
        $this->assertNotNull($usersMatch);
        $response = $usersMatch->getHandler()();
        $this->assertCount(2, $response['users']);

        // Test single user with parameter constraint
        $userRequest = MockRequest::create('GET', '/api/users/123');
        $userMatch = $this->router->match($userRequest);
        $this->assertNotNull($userMatch);
        $this->assertEquals(['id' => '123'], $userMatch->getParameters());

        // Test POST route
        $createRequest = MockRequest::create('POST', '/api/users');
        $createMatch = $this->router->match($createRequest);
        $this->assertNotNull($createMatch);
        $response = $createMatch->getHandler()();
        $this->assertEquals('User created', $response['message']);
    }

    #[Test]
    #[TestDox('Router integrates with PSR-7 middleware pattern')]
    public function testRouterIntegratesWithPsr7MiddlewarePattern(): void
    {
        // Setup routes with middleware
        $this->router->get('/public', function () {
            return 'public content';
        });

        $this->router->get('/protected', function () {
            return 'protected content';
        })->middleware('auth');

        $this->router->get('/admin', function () {
            return 'admin content';
        })->middleware(['auth', 'admin']);

        // Test public route
        $publicRequest = MockRequest::create('GET', '/public');
        $publicMatch = $this->router->match($publicRequest);
        $this->assertNotNull($publicMatch);
        $this->assertEmpty($publicMatch->getMiddleware());

        // Test protected route with auth middleware
        $protectedRequest = MockRequest::create('GET', '/protected');
        $protectedMatch = $this->router->match($protectedRequest);
        $this->assertNotNull($protectedMatch);
        $this->assertEquals(['auth'], $protectedMatch->getMiddleware());

        // Test admin route with multiple middleware
        $adminRequest = MockRequest::create('GET', '/admin');
        $adminMatch = $this->router->match($adminRequest);
        $this->assertNotNull($adminMatch);
        $this->assertEquals(['auth', 'admin'], $adminMatch->getMiddleware());
    }

    #[Test]
    #[TestDox('Router handles complex parameter constraints for real APIs')]
    public function testRouterHandlesComplexParameterConstraintsForRealApis(): void
    {
        // E-commerce style routes with different parameter types
        $this->router->get('/products/{id}', function () {
            return 'product';
        })->whereNumber('id');

        $this->router->get('/categories/{slug}', function () {
            return 'category';
        })->whereSlug('slug');

        $this->router->get('/files/{uuid}/download', function () {
            return 'download';
        })->whereUuid('uuid');

        $this->router->get('/api/{version}/products/{id}', function () {
            return 'versioned api';
        })->where(['version' => 'v[1-3]', 'id' => 'int']);

        // Test numeric product ID
        $productRequest = MockRequest::create('GET', '/products/12345');
        $productMatch = $this->router->match($productRequest);
        $this->assertNotNull($productMatch);
        $this->assertEquals(['id' => '12345'], $productMatch->getParameters());

        // Test slug category
        $categoryRequest = MockRequest::create('GET', '/categories/electronics');
        $categoryMatch = $this->router->match($categoryRequest);
        $this->assertNotNull($categoryMatch);
        $this->assertEquals(['slug' => 'electronics'], $categoryMatch->getParameters());

        // Test UUID file download
        $fileRequest = MockRequest::create('GET', '/files/550e8400-e29b-41d4-a716-446655440000/download');
        $fileMatch = $this->router->match($fileRequest);
        $this->assertNotNull($fileMatch);
        $this->assertEquals(['uuid' => '550e8400-e29b-41d4-a716-446655440000'], $fileMatch->getParameters());

        // Test versioned API with multiple constraints
        $apiRequest = MockRequest::create('GET', '/api/v2/products/789');
        $apiMatch = $this->router->match($apiRequest);
        $this->assertNotNull($apiMatch);
        $this->assertEquals(['version' => 'v2', 'id' => '789'], $apiMatch->getParameters());
    }

    #[Test]
    #[TestDox('Router performs O(1) lookups with ring buffer cache')]
    public function testRouterPerformsO1LookupsWithRingBufferCache(): void
    {
        // Enable caching for performance testing
        $this->router->setCacheEnabled(true);

        // Add many routes to test cache performance
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/static/route{$i}", function () use ($i) {
                return "static route {$i}";
            });
            $this->router->get("/dynamic/users/{$i}/posts/{id}", function () use ($i) {
                return "dynamic route {$i}";
            });
        }

        // Warm up cache with popular routes
        $popularRoutes = [];
        for ($i = 0; $i < 50; $i++) {
            $route = "/static/route{$i}";
            $popularRoutes[] = $route;
            $request = MockRequest::create('GET', $route);
            $this->router->match($request);
        }

        // Test O(1) performance - cached lookups should be constant time
        $iterations = [10, 50, 100];
        $times = [];

        foreach ($iterations as $count) {
            $startTime = microtime(true);

            for ($i = 0; $i < $count; $i++) {
                $route = $popularRoutes[$i % count($popularRoutes)];
                $request = MockRequest::create('GET', $route);
                $match = $this->router->match($request);
                $this->assertNotNull($match);
            }

            $times[$count] = microtime(true) - $startTime;
        }

        // Verify performance scales linearly (indicating O(1) per operation)
        $ratio1 = $times[50] / $times[10]; // Should be ~5x
        $ratio2 = $times[100] / $times[50]; // Should be ~2x

        $this->assertLessThan(8, $ratio1, "Cache performance not O(1): 50:10 ratio is {$ratio1}");
        $this->assertLessThan(3, $ratio2, "Cache performance not O(1): 100:50 ratio is {$ratio2}");
    }

    #[Test]
    #[TestDox('Router with Rust FFI engine provides transparent fallback')]
    public function testRouterWithRustFfiEngineProvidestransparentFallback(): void
    {
        // Test engine configuration through environment variables
        putenv('ROUTER_ENGINE=auto');
        $autoRouter = new Router();

        putenv('ROUTER_ENGINE=php');
        $phpRouter = new Router();

        putenv('ROUTER_ENGINE=rust');
        $rustRouter = new Router();

        // Add the same routes to all routers
        $handler = function () { return 'test response'; };

        foreach ([$autoRouter, $phpRouter, $rustRouter] as $router) {
            $router->get('/test', $handler);
            $router->get('/users/{id}', $handler)->whereNumber('id');
            $router->post('/api/data', $handler);
        }

        // Test that all engines produce the same results
        $testCases = [
            ['GET', '/test'],
            ['GET', '/users/123'],
            ['POST', '/api/data'],
            ['GET', '/nonexistent'], // should return null
        ];

        foreach ($testCases as [$method, $path]) {
            $request = MockRequest::create($method, $path);

            $autoMatch = $autoRouter->match($request);
            $phpMatch = $phpRouter->match($request);
            $rustMatch = $rustRouter->match($request);

            // All engines should produce the same result
            if ($autoMatch === null) {
                $this->assertNull($phpMatch);
                $this->assertNull($rustMatch);
            } else {
                $this->assertNotNull($phpMatch);
                $this->assertNotNull($rustMatch);
                $this->assertEquals($autoMatch->getParameters(), $phpMatch->getParameters());
                $this->assertEquals($autoMatch->getParameters(), $rustMatch->getParameters());
            }
        }

        // Clean up environment
        putenv('ROUTER_ENGINE');
    }

    #[Test]
    #[TestDox('Router handles high performance scenarios for C10M applications')]
    public function testRouterHandlesHighPerformanceScenariosForC10mApplications(): void
    {
        // Simulate high-traffic application with many routes
        $this->router->setCacheEnabled(true);

        // Add typical web application routes
        $this->router->get('/', function () { return 'home'; });
        $this->router->get('/about', function () { return 'about'; });
        $this->router->get('/contact', function () { return 'contact'; });

        // API routes
        for ($i = 1; $i <= 100; $i++) {
            $this->router->get("/api/v1/resource{$i}", function () use ($i) {
                return "resource {$i}";
            });
            $this->router->get("/api/v1/resource{$i}/{id}", function () use ($i) {
                return "resource {$i} item";
            })->whereNumber('id');
        }

        // User-generated content routes
        for ($i = 1; $i <= 50; $i++) {
            $this->router->get("/users/{id}/posts/{$i}", function () use ($i) {
                return "user post {$i}";
            })->whereNumber('id');
        }

        // Simulate high load - many concurrent route lookups
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        for ($i = 0; $i < 1000; $i++) {
            // Mix of static and dynamic routes
            if ($i % 3 === 0) {
                // Static routes
                $paths = ['/', '/about', '/contact'];
                $path = $paths[$i % 3];
                $request = MockRequest::create('GET', $path);
            } elseif ($i % 3 === 1) {
                // API routes
                $resourceId = ($i % 100) + 1;
                $request = MockRequest::create('GET', "/api/v1/resource{$resourceId}");
            } else {
                // Dynamic routes with parameters
                $userId = ($i % 1000) + 1;
                $postId = ($i % 50) + 1;
                $request = MockRequest::create('GET', "/users/{$userId}/posts/{$postId}");
            }

            $match = $this->router->match($request);
            $this->assertNotNull($match);
        }

        $duration = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;

        // Performance assertions for C10M capability
        $this->assertLessThan(0.5, $duration, "1000 route lookups took {$duration}s - too slow for C10M");
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed, "Memory usage {$memoryUsed} bytes too high");

        // Verify cache effectiveness
        $stats = $this->router->getStats();
        $this->assertTrue($stats['cache_enabled']);
        $this->assertGreaterThan(0, $stats['static_routes']);
        $this->assertGreaterThan(0, $stats['dynamic_routes']);
    }

    #[Test]
    #[TestDox('Router memory usage remains stable under continuous load')]
    public function testRouterMemoryUsageRemainsStableUnderContinuousLoad(): void
    {
        $this->router->setCacheEnabled(true);

        // Add baseline routes
        for ($i = 0; $i < 200; $i++) {
            $this->router->get("/route{$i}", function () use ($i) { return "route {$i}"; });
            $this->router->get("/users/{id}/item{$i}", function () use ($i) { return "item {$i}"; });
        }

        $initialMemory = memory_get_usage(true);

        // Simulate continuous operation with many requests
        for ($batch = 0; $batch < 10; $batch++) {
            for ($i = 0; $i < 100; $i++) {
                $routeId = mt_rand(0, 199);
                $userId = mt_rand(1, 1000);

                if ($i % 2 === 0) {
                    $request = MockRequest::create('GET', "/route{$routeId}");
                } else {
                    $request = MockRequest::create('GET', "/users/{$userId}/item{$routeId}");
                }

                $match = $this->router->match($request);
                $this->assertNotNull($match);
            }

            // Check memory hasn't grown significantly during this batch
            $currentMemory = memory_get_usage(true);
            $memoryIncrease = $currentMemory - $initialMemory;

            // Memory shouldn't grow beyond reasonable limits during operation
            $this->assertLessThan(
                10 * 1024 * 1024,
                $memoryIncrease,
                "Memory grew too much during batch {$batch}: {$memoryIncrease} bytes"
            );
        }

        $finalMemory = memory_get_usage(true);
        $totalIncrease = $finalMemory - $initialMemory;

        // Total memory increase should be minimal (under 5MB for 1000 operations)
        $this->assertLessThan(
            5 * 1024 * 1024,
            $totalIncrease,
            "Total memory increase {$totalIncrease} bytes too high - possible memory leak"
        );
    }
}
