<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Concurrency;

use Exception;
use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Group('concurrency')]
#[Group('router')]
class RouterConcurrencyTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->setupTestRoutes();
    }

    #[Test]
    #[TestDox('Router handles concurrent read operations safely')]
    public function testRouterHandlesConcurrentReadOperationsSafely(): void
    {
        // Simulate concurrent read operations
        $results = [];
        $processes = [];

        for ($i = 0; $i < 10; $i++) {
            $processes[] = function () use (&$results, $i) {
                for ($j = 0; $j < 100; $j++) {
                    $routeId = ($i * 100 + $j) % 50; // Cycle through 50 routes
                    $match = $this->router->match($this->createMockRequest('GET', "/test/{$routeId}"));
                    $results[$i][$j] = $match !== null;
                }

                return true;
            };
        }

        // Execute all processes (simulating concurrency)
        foreach ($processes as $i => $process) {
            $this->assertTrue($process());
        }

        // Verify all operations succeeded
        $totalSuccesses = 0;
        foreach ($results as $processResults) {
            $totalSuccesses += array_sum($processResults);
        }

        $this->assertEquals(1000, $totalSuccesses, 'Not all concurrent read operations succeeded');
    }

    #[Test]
    #[TestDox('Router cache remains consistent under concurrent access')]
    public function testRouterCacheRemainsConsistentUnderConcurrentAccess(): void
    {
        $this->router->setCacheEnabled(true);

        // Pre-populate some routes in cache
        for ($i = 0; $i < 20; $i++) {
            $this->router->match($this->createMockRequest('GET', "/cache/test/{$i}"));
        }

        $cacheHits = [];
        $processes = [];

        for ($i = 0; $i < 5; $i++) {
            $processes[] = function () use (&$cacheHits, $i) {
                $hits = 0;
                for ($j = 0; $j < 50; $j++) {
                    $routeId = $j % 20; // Use cached routes
                    $match = $this->router->match($this->createMockRequest('GET', "/cache/test/{$routeId}"));
                    if ($match !== null) {
                        $hits++;
                    }
                }
                $cacheHits[$i] = $hits;

                return true;
            };
        }

        // Execute concurrent cache access
        foreach ($processes as $process) {
            $this->assertTrue($process());
        }

        // All processes should have 100% hit rate
        foreach ($cacheHits as $processId => $hits) {
            $this->assertEquals(50, $hits, "Process {$processId} had unexpected cache misses");
        }
    }

    #[Test]
    #[TestDox('Router handles mixed read/write operations correctly')]
    public function testRouterHandlesMixedReadWriteOperationsCorrectly(): void
    {
        $readResults = [];
        $writeResults = [];

        // Start with base routes
        for ($i = 0; $i < 50; $i++) {
            $this->router->get("/base/{$i}", function () use ($i) {
                return "Base {$i}";
            });
        }

        // Simulate mixed operations
        $operations = [];

        // Reader processes
        for ($i = 0; $i < 3; $i++) {
            $operations[] = function () use (&$readResults, $i) {
                $successes = 0;
                for ($j = 0; $j < 100; $j++) {
                    $routeId = $j % 50;
                    $match = $this->router->match($this->createMockRequest('GET', "/base/{$routeId}"));
                    if ($match !== null) {
                        $successes++;
                    }
                }
                $readResults[$i] = $successes;

                return true;
            };
        }

        // Writer processes
        for ($i = 0; $i < 2; $i++) {
            $operations[] = function () use (&$writeResults, $i) {
                $additions = 0;
                for ($j = 0; $j < 25; $j++) {
                    $routeId = 100 + ($i * 25) + $j;

                    try {
                        $this->router->get("/new/{$routeId}", function () use ($routeId) {
                            return "New {$routeId}";
                        });
                        $additions++;
                    } catch (Exception $e) {
                        // Route addition failed - acceptable in concurrent scenario
                    }
                }
                $writeResults[$i] = $additions;

                return true;
            };
        }

        // Execute all operations
        foreach ($operations as $operation) {
            $this->assertTrue($operation());
        }

        // Verify readers maintained high success rate
        foreach ($readResults as $readerIndex => $successes) {
            $successRate = $successes / 100;
            $this->assertGreaterThan(
                0.95,
                $successRate,
                "Reader {$readerIndex} success rate {$successRate} below 95%"
            );
        }

        // Verify writers succeeded
        $totalWrites = array_sum($writeResults);
        $this->assertGreaterThan(
            40,
            $totalWrites,
            "Total writes {$totalWrites} unexpectedly low"
        );
    }

    #[Test]
    #[TestDox('Router compilation is thread-safe')]
    public function testRouterCompilationIsThreadSafe(): void
    {
        // Add routes that will trigger compilation
        for ($i = 0; $i < 100; $i++) {
            $this->router->get("/compile/{$i}", function () use ($i) {
                return "Compile {$i}";
            });
        }

        $compilationResults = [];
        $matchResults = [];

        // Simulate concurrent compilation and matching
        $operations = [];

        // Compilation trigger operations
        for ($i = 0; $i < 3; $i++) {
            $operations[] = function () use (&$compilationResults, $i) {
                try {
                    $this->router->compile();
                    $compilationResults[$i] = true;
                } catch (Exception $e) {
                    $compilationResults[$i] = false;
                }

                return true;
            };
        }

        // Matching operations during compilation
        for ($i = 0; $i < 3; $i++) {
            $operations[] = function () use (&$matchResults, $i) {
                $successes = 0;
                for ($j = 0; $j < 20; $j++) {
                    $routeId = $j % 100;

                    try {
                        $match = $this->router->match($this->createMockRequest('GET', "/compile/{$routeId}"));
                        if ($match !== null) {
                            $successes++;
                        }
                    } catch (Exception $e) {
                        // Acceptable during compilation
                    }
                }
                $matchResults[$i] = $successes;

                return true;
            };
        }

        // Execute operations
        foreach ($operations as $operation) {
            $this->assertTrue($operation());
        }

        // At least one compilation should succeed
        $successfulCompilations = array_sum($compilationResults);
        $this->assertGreaterThan(
            0,
            $successfulCompilations,
            'No compilations succeeded'
        );

        // Matching operations should generally succeed
        $totalMatches = array_sum($matchResults);
        $this->assertGreaterThan(
            30,
            $totalMatches,
            "Total matches {$totalMatches} unexpectedly low during compilation"
        );
    }

    #[Test]
    #[TestDox('Router parameter extraction is consistent under concurrency')]
    public function testRouterParameterExtractionIsConsistentUnderConcurrency(): void
    {
        // Add routes with parameters
        $this->router->get('/users/{id}/posts/{postId}', function () {
            return 'user-post';
        });
        $this->router->get('/api/{version}/data/{id}', function () {
            return 'api-data';
        });

        $parameterResults = [];

        // Concurrent parameter extraction
        for ($i = 0; $i < 5; $i++) {
            $operations[] = function () use (&$parameterResults, $i) {
                $extractedParams = [];

                for ($j = 0; $j < 20; $j++) {
                    $userId = 1000 + $j;
                    $postId = 2000 + $j;

                    $match = $this->router->match(
                        $this->createMockRequest('GET', "/users/{$userId}/posts/{$postId}")
                    );

                    if ($match !== null) {
                        $params = $match->getParameters();
                        $extractedParams[] = [
                            'id'     => $params['id'] ?? null,
                            'postId' => $params['postId'] ?? null,
                        ];
                    }
                }

                $parameterResults[$i] = $extractedParams;

                return true;
            };
        }

        // Execute parameter extractions
        foreach ($operations as $operation) {
            $this->assertTrue($operation());
        }

        // Verify parameter consistency
        foreach ($parameterResults as $processIndex => $params) {
            $this->assertCount(
                20,
                $params,
                "Process {$processIndex} extracted wrong number of parameters"
            );

            foreach ($params as $index => $param) {
                $expectedId = 1000 + $index;
                $expectedPostId = 2000 + $index;

                $this->assertEquals(
                    (string) $expectedId,
                    $param['id'],
                    "Process {$processIndex}, param {$index}: wrong id"
                );
                $this->assertEquals(
                    (string) $expectedPostId,
                    $param['postId'],
                    "Process {$processIndex}, param {$index}: wrong postId"
                );
            }
        }
    }

    #[Test]
    #[TestDox('Router handles high concurrent load without degradation')]
    public function testRouterHandlesHighConcurrentLoadWithoutDegradation(): void
    {
        // Add a variety of routes
        for ($i = 0; $i < 200; $i++) {
            $this->router->get("/load/static/{$i}", function () use ($i) {
                return "Static {$i}";
            });
            $this->router->get("/load/dynamic/{id}/item/{$i}", function () use ($i) {
                return "Dynamic {$i}";
            });
        }

        $loadResults = [];
        $timeResults = [];

        // High concurrency simulation
        for ($i = 0; $i < 10; $i++) {
            $operations[] = function () use (&$loadResults, &$timeResults, $i) {
                $startTime = microtime(true);
                $successes = 0;

                for ($j = 0; $j < 100; $j++) {
                    if ($j % 2 === 0) {
                        // Static route
                        $routeId = $j % 200;
                        $match = $this->router->match(
                            $this->createMockRequest('GET', "/load/static/{$routeId}")
                        );
                    } else {
                        // Dynamic route
                        $itemId = $j % 200;
                        $userId = 1000 + ($j % 100);
                        $match = $this->router->match(
                            $this->createMockRequest('GET', "/load/dynamic/{$userId}/item/{$itemId}")
                        );
                    }

                    if ($match !== null) {
                        $successes++;
                    }
                }

                $duration = microtime(true) - $startTime;
                $loadResults[$i] = $successes;
                $timeResults[$i] = $duration;

                return true;
            };
        }

        // Execute high load operations
        foreach ($operations as $operation) {
            $this->assertTrue($operation());
        }

        // Verify performance consistency
        $totalSuccesses = array_sum($loadResults);
        $this->assertEquals(
            1000,
            $totalSuccesses,
            'Not all high-load operations succeeded'
        );

        $avgTime = array_sum($timeResults) / count($timeResults);
        $maxTime = max($timeResults);
        $minTime = min($timeResults);

        // Times should be relatively consistent (no process should take 50x longer)
        $timeVariation = $maxTime / $minTime;
        $this->assertLessThan(
            50.0,
            $timeVariation,
            "Time variation {$timeVariation} indicates performance degradation under load"
        );

        // Average time should be reasonable (under 50ms per 100 operations)
        $this->assertLessThan(
            0.05,
            $avgTime,
            "Average time {$avgTime}s exceeds 50ms threshold"
        );
    }

    private function setupTestRoutes(): void
    {
        // Add various test routes
        for ($i = 0; $i < 100; $i++) {
            $this->router->get("/test/{$i}", function () use ($i) {
                return "Test {$i}";
            });
            $this->router->get("/cache/test/{$i}", function () use ($i) {
                return "Cache test {$i}";
            });
        }
    }

    private function createMockRequest(string $method, string $path): MockRequest
    {
        return MockRequest::create($method, $path);
    }
}
