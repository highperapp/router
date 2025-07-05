<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Performance;

use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
#[Group('router')]
class RouterPerformanceTest extends TestCase
{
    private Router $router;

    private array $testRoutes = [];

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->generateTestRoutes();
    }

    #[Test]
    #[TestDox('Router achieves sub-millisecond performance for static routes')]
    public function testRouterAchievesSubMillisecondPerformanceForStaticRoutes(): void
    {
        // Add 1000 static routes as mentioned in README
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/static/route{$i}", function () use ($i) {
                return "Route {$i}";
            });
        }

        $startTime = microtime(true);

        // Test 100 random static route matches
        for ($i = 0; $i < 100; $i++) {
            $routeId = mt_rand(0, 999);
            $match = $this->router->match($this->createMockRequest('GET', "/static/route{$routeId}"));
            $this->assertInstanceOf(RouteMatch::class, $match);
        }

        $duration = microtime(true) - $startTime;
        $avgTimePerMatch = ($duration / 100) * 1000; // milliseconds

        // README claims <0.001ms per route - test with 0.1ms tolerance for PHP implementation
        $this->assertLessThan(
            0.1,
            $avgTimePerMatch,
            "Average match time {$avgTimePerMatch}ms exceeds 0.1ms threshold (README target: <0.001ms)"
        );
    }

    #[Test]
    #[TestDox('Router achieves sub-2ms performance for dynamic routes')]
    public function testRouterAchievesSubTwoMsPerformanceForDynamicRoutes(): void
    {
        // Add 1000 dynamic routes with parameters
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/api/v1/users/{id}/posts/{$i}", function () use ($i) {
                return "Post {$i}";
            });
        }

        $startTime = microtime(true);

        // Test 100 dynamic route matches
        for ($i = 0; $i < 100; $i++) {
            $postId = mt_rand(0, 999);
            $userId = mt_rand(1, 1000);
            $match = $this->router->match($this->createMockRequest('GET', "/api/v1/users/{$userId}/posts/{$postId}"));
            $this->assertInstanceOf(RouteMatch::class, $match);
            $this->assertEquals(['id' => (string) $userId], $match->getParameters());
        }

        $duration = microtime(true) - $startTime;
        $avgTimePerMatch = ($duration / 100) * 1000; // milliseconds

        // README claims <0.002ms for dynamic routes - test with 0.5ms tolerance for PHP implementation
        $this->assertLessThan(
            0.5,
            $avgTimePerMatch,
            "Average dynamic match time {$avgTimePerMatch}ms exceeds 0.5ms threshold (README target: <0.002ms)"
        );
    }

    #[Test]
    #[TestDox('Router cache significantly improves repeated lookups')]
    public function testRouterCacheSignificantlyImprovesRepeatedLookups(): void
    {
        // Add routes
        for ($i = 0; $i < 500; $i++) {
            $this->router->get("/cached/route/{$i}", function () use ($i) {
                return "Cached {$i}";
            });
        }

        // First pass - populate cache
        $firstPassStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $routeId = $i % 50; // Repeat same 50 routes
            $this->router->match($this->createMockRequest('GET', "/cached/route/{$routeId}"));
        }
        $firstPassDuration = microtime(true) - $firstPassStart;

        // Second pass - should use cache
        $secondPassStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $routeId = $i % 50; // Same routes as first pass
            $this->router->match($this->createMockRequest('GET', "/cached/route/{$routeId}"));
        }
        $secondPassDuration = microtime(true) - $secondPassStart;

        // Cache should provide at least 2x improvement
        $improvement = $firstPassDuration / $secondPassDuration;
        $this->assertGreaterThan(
            2.0,
            $improvement,
            "Cache improvement ratio {$improvement} is less than expected 2x"
        );
    }

    #[Test]
    #[TestDox('Router memory usage remains stable under heavy load')]
    public function testRouterMemoryUsageRemainsStableUnderHeavyLoad(): void
    {
        $initialMemory = memory_get_usage(true);

        // Add 2000 routes
        for ($i = 0; $i < 2000; $i++) {
            $this->router->get("/memory/test/{$i}", function () use ($i) {
                return "Memory test {$i}";
            });
            $this->router->post("/api/memory/{$i}", function () use ($i) {
                return "POST Memory {$i}";
            });
        }

        $afterRoutesMemory = memory_get_usage(true);

        // Execute 1000 matches
        for ($i = 0; $i < 1000; $i++) {
            $routeId = mt_rand(0, 1999);
            $method = $i % 2 === 0 ? 'GET' : 'POST';
            $path = $method === 'GET' ? "/memory/test/{$routeId}" : "/api/memory/{$routeId}";
            $this->router->match($this->createMockRequest($method, $path));
        }

        $finalMemory = memory_get_usage(true);

        $routeMemoryIncrease = $afterRoutesMemory - $initialMemory;
        $executionMemoryIncrease = $finalMemory - $afterRoutesMemory;

        // Route storage should be under 20MB for 4000 routes
        $this->assertLessThan(
            20 * 1024 * 1024,
            $routeMemoryIncrease,
            "Route memory usage {$routeMemoryIncrease} bytes exceeds 20MB limit"
        );

        // Execution memory increase should be minimal (under 5MB)
        $this->assertLessThan(
            5 * 1024 * 1024,
            $executionMemoryIncrease,
            "Execution memory increase {$executionMemoryIncrease} bytes exceeds 5MB limit"
        );
    }

    #[Test]
    #[TestDox('Router handles complex parameter constraints efficiently')]
    public function testRouterHandlesComplexParameterConstraintsEfficiently(): void
    {
        // Add routes with various constraints
        $this->router->get('/users/{id}', function () { return 'user'; })
            ->whereNumber('id');

        $this->router->get('/posts/{slug}', function () { return 'post'; })
            ->whereSlug('slug');

        $this->router->get('/files/{uuid}', function () { return 'file'; })
            ->whereUuid('uuid');

        $startTime = microtime(true);

        // Test constraint validation performance
        $validTests = [
            ['GET', '/users/123'],
            ['GET', '/posts/hello-world'],
            ['GET', '/files/550e8400-e29b-41d4-a716-446655440000'],
        ];

        $invalidTests = [
            ['GET', '/users/abc'],
            ['GET', '/posts/Hello_World!'],
            ['GET', '/files/invalid-uuid'],
        ];

        for ($i = 0; $i < 100; $i++) {
            foreach ($validTests as [$method, $path]) {
                $match = $this->router->match($this->createMockRequest($method, $path));
                $this->assertInstanceOf(RouteMatch::class, $match);
            }

            foreach ($invalidTests as [$method, $path]) {
                $match = $this->router->match($this->createMockRequest($method, $path));
                $this->assertNull($match);
            }
        }

        $duration = microtime(true) - $startTime;
        $avgTimePerConstraintCheck = ($duration / 600) * 1000000; // 6 checks * 100 iterations

        // Constraint validation should be under 10 microseconds per check
        $this->assertLessThan(
            10,
            $avgTimePerConstraintCheck,
            "Average constraint check time {$avgTimePerConstraintCheck}μs exceeds 10μs threshold"
        );
    }

    #[Test]
    #[TestDox('Router compilation provides significant performance boost')]
    public function testRouterCompilationProvidesSignificantPerformanceBoost(): void
    {
        // Add many routes
        for ($i = 0; $i < 1000; $i++) {
            $this->router->get("/compile/test/{$i}", function () use ($i) {
                return "Compiled {$i}";
            });
        }

        // Test without compilation
        $uncompiled = new Router();
        for ($i = 0; $i < 1000; $i++) {
            $uncompiled->get("/compile/test/{$i}", function () use ($i) {
                return "Uncompiled {$i}";
            });
        }

        // Benchmark uncompiled performance
        $uncompiledStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $routeId = mt_rand(0, 999);
            $uncompiled->match($this->createMockRequest('GET', "/compile/test/{$routeId}"));
        }
        $uncompiledDuration = microtime(true) - $uncompiledStart;

        // Force compilation
        $this->router->compile();

        // Benchmark compiled performance
        $compiledStart = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $routeId = mt_rand(0, 999);
            $this->router->match($this->createMockRequest('GET', "/compile/test/{$routeId}"));
        }
        $compiledDuration = microtime(true) - $compiledStart;

        // Compiled should be at least 1.5x faster
        $improvement = $uncompiledDuration / $compiledDuration;
        $this->assertGreaterThan(
            1.5,
            $improvement,
            "Compilation improvement ratio {$improvement} is less than expected 1.5x"
        );
    }

    #[Test]
    #[TestDox('Router handles mixed static and dynamic routes optimally')]
    #[DataProvider('mixedRouteScenarioProvider')]
    public function testRouterHandlesMixedStaticAndDynamicRoutesOptimally(
        int $staticCount,
        int $dynamicCount,
        float $expectedMaxTimeMs
    ): void {
        // Add static routes
        for ($i = 0; $i < $staticCount; $i++) {
            $this->router->get("/static/{$i}", function () use ($i) {
                return "Static {$i}";
            });
        }

        // Add dynamic routes
        for ($i = 0; $i < $dynamicCount; $i++) {
            $this->router->get("/dynamic/{id}/item/{$i}", function () use ($i) {
                return "Dynamic {$i}";
            });
        }

        $startTime = microtime(true);

        // Test mixed route matching
        for ($i = 0; $i < 50; $i++) {
            // Test static route
            $staticId = mt_rand(0, $staticCount - 1);
            $staticMatch = $this->router->match($this->createMockRequest('GET', "/static/{$staticId}"));
            $this->assertInstanceOf(RouteMatch::class, $staticMatch);

            // Test dynamic route
            $dynamicItem = mt_rand(0, $dynamicCount - 1);
            $userId = mt_rand(1, 1000);
            $dynamicMatch = $this->router->match($this->createMockRequest('GET', "/dynamic/{$userId}/item/{$dynamicItem}"));
            $this->assertInstanceOf(RouteMatch::class, $dynamicMatch);
        }

        $duration = (microtime(true) - $startTime) * 1000; // milliseconds

        $this->assertLessThan(
            $expectedMaxTimeMs,
            $duration,
            "Mixed route performance {$duration}ms exceeds {$expectedMaxTimeMs}ms threshold"
        );
    }

    public static function mixedRouteScenarioProvider(): array
    {
        return [
            'Small mixed set'  => [100, 100, 10.0],    // 200 routes, 10ms max
            'Medium mixed set' => [500, 500, 25.0],   // 1000 routes, 25ms max
            'Large mixed set'  => [1000, 1000, 50.0],  // 2000 routes, 50ms max
        ];
    }

    #[Test]
    #[TestDox('Router ring buffer cache performs at O(1) complexity')]
    public function testRouterRingBufferCachePerformsAtO1Complexity(): void
    {
        // Enable caching and add routes
        $this->router->setCacheEnabled(true);

        for ($i = 0; $i < 2000; $i++) {
            $this->router->get("/cache/test/{$i}", function () use ($i) {
                return "Cache {$i}";
            });
        }

        // Warm up cache with specific routes
        $cacheTestRoutes = [];
        for ($i = 0; $i < 100; $i++) {
            $routeId = mt_rand(0, 1999);
            $cacheTestRoutes[] = "/cache/test/{$routeId}";
            $this->router->match($this->createMockRequest('GET', "/cache/test/{$routeId}"));
        }

        // Test cache performance with different cache sizes
        $times = [];
        $iterations = [10, 50, 100, 200];

        foreach ($iterations as $count) {
            $startTime = microtime(true);

            for ($i = 0; $i < $count; $i++) {
                $route = $cacheTestRoutes[$i % count($cacheTestRoutes)];
                $this->router->match($this->createMockRequest('GET', $route));
            }

            $times[$count] = microtime(true) - $startTime;
        }

        // Verify O(1) complexity - time should scale linearly with iterations
        $ratio1 = $times[50] / $times[10];
        $ratio2 = $times[100] / $times[50];
        $ratio3 = $times[200] / $times[100];

        // Ratios should be close to the iteration ratio (indicating linear scaling)
        $this->assertLessThan(8, $ratio1, "50:10 ratio {$ratio1} indicates worse than linear scaling");
        $this->assertLessThan(3, $ratio2, "100:50 ratio {$ratio2} indicates worse than linear scaling");
        $this->assertLessThan(3, $ratio3, "200:100 ratio {$ratio3} indicates worse than linear scaling");
    }

    private function generateTestRoutes(): void
    {
        $this->testRoutes = [
            ['GET', '/'],
            ['GET', '/users'],
            ['GET', '/users/{id}'],
            ['POST', '/users'],
            ['PUT', '/users/{id}'],
            ['DELETE', '/users/{id}'],
            ['GET', '/api/v1/posts/{id}/comments'],
            ['POST', '/api/v1/posts/{id}/comments'],
            ['GET', '/admin/dashboard'],
            ['GET', '/files/{uuid}/download'],
        ];
    }

    private function createMockRequest(string $method, string $path): \HighPerApp\HighPer\Router\Tests\MockRequest
    {
        return \HighPerApp\HighPer\Router\Tests\MockRequest::create($method, $path);
    }
}
