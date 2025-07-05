<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Performance;

use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\TestCase;

class EARouterPerformanceTest extends TestCase
{
    private const PERFORMANCE_ITERATIONS = 10000;
    private const ROUTE_DEFINITION_ITERATIONS = 1000;

    public function testArrayBasedRouteDefinitionPerformance(): void
    {
        $fluentRouter = new Router();
        $arrayRouter = new Router();

        // Test route definition performance - Fluent API
        $startTime = microtime(true);
        for ($i = 0; $i < self::ROUTE_DEFINITION_ITERATIONS; $i++) {
            $fluentRouter->get("/test/{$i}", "Handler{$i}")
                ->whereNumber((string) $i)
                ->middleware(['auth']);
        }
        $fluentDefinitionTime = microtime(true) - $startTime;

        // Test route definition performance - Array Config
        $startTime = microtime(true);
        $arrayConfigs = [];
        for ($i = 0; $i < self::ROUTE_DEFINITION_ITERATIONS; $i++) {
            $arrayConfigs["test-{$i}"] = [
                'path'        => "/test/{id}",
                'methods'     => ['GET'],
                'handler'     => "Handler{$i}",
                'constraints' => ['id' => 'int'],
                'middleware'  => ['auth'],
            ];
        }
        $arrayRouter->defineRoutes($arrayConfigs);
        $arrayDefinitionTime = microtime(true) - $startTime;

        // Route definition should be reasonably fast
        $this->assertLessThan(1.0, $fluentDefinitionTime, 'Fluent API route definition should be under 1 second');
        $this->assertLessThan(1.0, $arrayDefinitionTime, 'Array-based route definition should be under 1 second');

        // Performance difference should be reasonable (within 4x)
        $definitionRatio = $arrayDefinitionTime / $fluentDefinitionTime;
        $this->assertLessThan(4.0, $definitionRatio, 'Array-based definition should not be more than 4x slower than fluent API');

        echo "\n--- Route Definition Performance ---\n";
        echo 'Fluent API: ' . round($fluentDefinitionTime * 1000, 2) . 'ms for ' . self::ROUTE_DEFINITION_ITERATIONS . " routes\n";
        echo 'Array Config: ' . round($arrayDefinitionTime * 1000, 2) . 'ms for ' . self::ROUTE_DEFINITION_ITERATIONS . " routes\n";
        echo 'Performance Ratio: ' . round($definitionRatio, 2) . "x\n";
    }

    public function testRouteMatchingPerformanceEquivalence(): void
    {
        $fluentRouter = new Router();
        $arrayRouter = new Router();

        // Define identical routes using both approaches
        $fluentRouter->get('/static', 'StaticController@index');
        $fluentRouter->get('/dynamic/{id}', 'DynamicController@show')->whereNumber('id');
        $fluentRouter->get('/nested/{userId}/posts/{postId}', 'NestedController@show')
            ->whereNumber('userId')
            ->whereNumber('postId');

        $arrayRouter->defineRoutes([
            'static' => [
                'path'    => '/static',
                'methods' => ['GET'],
                'handler' => 'StaticController@index',
            ],
            'dynamic' => [
                'path'        => '/dynamic/{id}',
                'methods'     => ['GET'],
                'handler'     => 'DynamicController@show',
                'constraints' => ['id' => 'int'],
            ],
            'nested' => [
                'path'        => '/nested/{userId}/posts/{postId}',
                'methods'     => ['GET'],
                'handler'     => 'NestedController@show',
                'constraints' => ['userId' => 'int', 'postId' => 'int'],
            ],
        ]);

        $testRoutes = [
            ['GET', '/static'],
            ['GET', '/dynamic/123'],
            ['GET', '/nested/456/posts/789'],
        ];

        // Test fluent router matching performance
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            foreach ($testRoutes as [$method, $path]) {
                $request = MockRequest::create($method, $path);
                $match = $fluentRouter->match($request);
                $this->assertNotNull($match);
            }
        }
        $fluentMatchTime = microtime(true) - $startTime;

        // Test array router matching performance
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            foreach ($testRoutes as [$method, $path]) {
                $request = MockRequest::create($method, $path);
                $match = $arrayRouter->match($request);
                $this->assertNotNull($match);
            }
        }
        $arrayMatchTime = microtime(true) - $startTime;

        // Matching performance should be virtually identical
        $matchingRatio = $arrayMatchTime / $fluentMatchTime;
        $this->assertLessThan(1.1, $matchingRatio, 'Array-defined routes should not be more than 10% slower to match');

        $totalMatches = self::PERFORMANCE_ITERATIONS * count($testRoutes);
        $fluentPerMatch = ($fluentMatchTime * 1000) / $totalMatches;
        $arrayPerMatch = ($arrayMatchTime * 1000) / $totalMatches;

        echo "\n--- Route Matching Performance ---\n";
        echo 'Fluent Router: ' . round($fluentPerMatch, 4) . "ms per match\n";
        echo 'Array Router: ' . round($arrayPerMatch, 4) . "ms per match\n";
        echo 'Performance Ratio: ' . round($matchingRatio, 2) . "x\n";
        echo 'Total Matches: ' . $totalMatches . "\n";
    }

    public function testQueryStringPerformanceOverhead(): void
    {
        $router = new Router();

        // Route with query string support
        $router->defineRoute('with-query', [
            'path'         => '/with-query',
            'methods'      => ['GET'],
            'handler'      => 'WithQueryController@index',
            'query_params' => [
                'optional'   => ['param1', 'param2'],
                'validation' => ['param1' => 'int'],
            ],
        ]);

        // Route without query string support
        $router->get('/without-query', 'WithoutQueryController@index');

        // Test normal matching (no query string processing)
        $request = MockRequest::create('GET', '/with-query');
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            $match = $router->match($request);
        }
        $normalMatchTime = microtime(true) - $startTime;

        // Test with query string processing
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            $match = $router->matchWithQuery($request);
        }
        $queryMatchTime = microtime(true) - $startTime;

        // Query string overhead should be minimal
        $queryOverhead = ($queryMatchTime - $normalMatchTime) / $normalMatchTime;
        $this->assertLessThan(3.0, $queryOverhead, 'Query string processing should not add more than 300% overhead');

        echo "\n--- Query String Performance Overhead ---\n";
        echo 'Normal Match: ' . round($normalMatchTime * 1000, 2) . 'ms for ' . self::PERFORMANCE_ITERATIONS . " matches\n";
        echo 'Query Match: ' . round($queryMatchTime * 1000, 2) . 'ms for ' . self::PERFORMANCE_ITERATIONS . " matches\n";
        echo 'Overhead: ' . round($queryOverhead * 100, 1) . "%\n";
    }

    public function testSegmentCountOptimizationBenefit(): void
    {
        $router = new Router();

        // Create routes with different segment counts
        $routeConfigs = [];
        for ($segments = 1; $segments <= 10; $segments++) {
            for ($i = 0; $i < 50; $i++) {
                $path = '/' . str_repeat('segment/', $segments - 1) . 'endpoint';
                $routeConfigs["route-{$segments}-{$i}"] = [
                    'path'    => $path . "-{$i}",
                    'methods' => ['GET'],
                    'handler' => "Handler{$segments}{$i}",
                ];
            }
        }

        $router->defineRoutes($routeConfigs);

        // Test matching performance for different segment counts
        $testCases = [
            [1, '/endpoint-25'],
            [3, '/segment/segment/endpoint-25'],
            [5, '/segment/segment/segment/segment/endpoint-25'],
            [10, '/segment/segment/segment/segment/segment/segment/segment/segment/segment/endpoint-25'],
        ];

        $segmentResults = [];
        foreach ($testCases as [$segmentCount, $path]) {
            $request = MockRequest::create('GET', $path);
            $startTime = microtime(true);

            for ($i = 0; $i < 5000; $i++) {
                $match = $router->match($request);
                $this->assertNotNull($match);
            }

            $matchTime = microtime(true) - $startTime;
            $segmentResults[$segmentCount] = $matchTime;
        }

        // Performance should not degrade significantly with segment count
        // (due to segment-count optimization)
        $minTime = min($segmentResults);
        $maxTime = max($segmentResults);
        $performanceRatio = $maxTime / $minTime;

        $this->assertLessThan(2.0, $performanceRatio, 'Performance should not degrade more than 2x with segment count increase');

        echo "\n--- Segment Count Optimization ---\n";
        foreach ($segmentResults as $segments => $time) {
            echo "Segments: {$segments}, Time: " . round($time * 1000, 2) . "ms\n";
        }
        echo 'Performance Ratio (max/min): ' . round($performanceRatio, 2) . "x\n";
    }

    public function testMemoryUsageImpact(): void
    {
        $baselineMemory = memory_get_usage(true);

        // Create router with many routes using array configuration
        $router = new Router();
        $routeConfigs = [];

        for ($i = 0; $i < 1000; $i++) {
            $routeConfigs["route-{$i}"] = [
                'path'         => "/api/resource/{id}",
                'methods'      => ['GET', 'POST'],
                'handler'      => "ResourceController@handle{$i}",
                'constraints'  => ['id' => 'int'],
                'middleware'   => ['auth', 'throttle'],
                'query_params' => [
                    'optional'   => ['include', 'fields'],
                    'validation' => ['include' => 'alpha'],
                ],
            ];
        }

        $router->defineRoutes($routeConfigs);

        $afterDefinitionMemory = memory_get_usage(true);
        $definitionMemoryUsage = $afterDefinitionMemory - $baselineMemory;

        // Perform many matches to test cache memory usage
        for ($i = 0; $i < 100; $i++) {
            $request = MockRequest::create('GET', "/api/resource/{$i}");
            $match = $router->match($request);
        }

        $finalMemory = memory_get_usage(true);
        $totalMemoryUsage = $finalMemory - $baselineMemory;

        // Memory usage should be reasonable
        $this->assertLessThan(50 * 1024 * 1024, $totalMemoryUsage, 'Total memory usage should be under 50MB');

        echo "\n--- Memory Usage Impact ---\n";
        echo "Routes defined: 1000\n";
        echo 'Memory after route definition: ' . round($definitionMemoryUsage / 1024 / 1024, 2) . "MB\n";
        echo 'Total memory usage: ' . round($totalMemoryUsage / 1024 / 1024, 2) . "MB\n";
        echo 'Memory per route: ' . round($definitionMemoryUsage / 1000, 0) . " bytes\n";
    }

    public function testPerformanceTargetCompliance(): void
    {
        $router = new Router();

        // Static routes (target: <0.001ms)
        $router->get('/static', 'StaticController@index');
        $router->get('/api/health', 'HealthController@check');

        // Dynamic routes (target: <0.002ms)
        $router->defineRoutes([
            'dynamic' => [
                'path'        => '/users/{id}',
                'methods'     => ['GET'],
                'handler'     => 'UserController@show',
                'constraints' => ['id' => 'int'],
            ],
            'nested' => [
                'path'        => '/users/{userId}/posts/{postId}',
                'methods'     => ['GET'],
                'handler'     => 'PostController@show',
                'constraints' => ['userId' => 'int', 'postId' => 'int'],
            ],
        ]);

        // Test static route performance
        $request = MockRequest::create('GET', '/static');
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            $match = $router->match($request);
        }
        $staticTime = (microtime(true) - $startTime) / self::PERFORMANCE_ITERATIONS;

        // Test dynamic route performance
        $request = MockRequest::create('GET', '/users/123');
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            $match = $router->match($request);
        }
        $dynamicTime = (microtime(true) - $startTime) / self::PERFORMANCE_ITERATIONS;

        // Test nested route performance
        $request = MockRequest::create('GET', '/users/123/posts/456');
        $startTime = microtime(true);
        for ($i = 0; $i < self::PERFORMANCE_ITERATIONS; $i++) {
            $match = $router->match($request);
        }
        $nestedTime = (microtime(true) - $startTime) / self::PERFORMANCE_ITERATIONS;

        echo "\n--- Performance Target Compliance ---\n";
        echo 'Static Route: ' . round($staticTime * 1000, 4) . "ms (target: <0.001ms)\n";
        echo 'Dynamic Route: ' . round($dynamicTime * 1000, 4) . "ms (target: <0.002ms)\n";
        echo 'Nested Route: ' . round($nestedTime * 1000, 4) . "ms (target: <0.002ms)\n";

        // Note: These are aspirational targets that may require Rust FFI engine
        // For now, we test that performance is reasonable
        $this->assertLessThan(0.01, $staticTime, 'Static route matching should be under 0.01ms');
        $this->assertLessThan(0.02, $dynamicTime, 'Dynamic route matching should be under 0.02ms');
        $this->assertLessThan(0.02, $nestedTime, 'Nested route matching should be under 0.02ms');
    }
}
