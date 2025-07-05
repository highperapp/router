<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Engines;

use FFI;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Rust FFI Router Engine.
 *
 * Ultra-high performance routing using Rust radix tree implementation
 * Provides microsecond-level route matching for high-throughput applications
 */
class RustFFIEngine
{
    private array $config;

    private LoggerInterface $logger;

    private ?FFI $ffi = null;

    private bool $available = false;

    private array $routeHandlers = [];

    private int $nextHandlerId = 1;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'rust_library_path' => null,
            'cache_enabled'     => true,
            'max_cache_size'    => 10000,
            'preload_routes'    => true,
        ], $config);

        $this->logger = $logger ?? new NullLogger();
        $this->initializeFFI();
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function addRoute(array $methods, string $path, mixed $handler): string
    {
        if (!$this->available) {
            throw new RuntimeException('Rust FFI router engine not available');
        }

        $handlerId = (string) $this->nextHandlerId++;
        $this->routeHandlers[$handlerId] = $handler;

        foreach ($methods as $method) {
            $result = $this->ffi->router_add_route($method, $path, $handlerId);

            if ($result->status !== 0) {
                $this->ffi->free_router_result($result);

                throw new RuntimeException("Failed to add route: {$method} {$path}");
            }

            $this->ffi->free_router_result($result);
        }

        return $handlerId;
    }

    public function match(string $method, string $path): ?array
    {
        if (!$this->available) {
            throw new RuntimeException('Rust FFI router engine not available');
        }

        $result = $this->ffi->router_match($method, $path);

        try {
            if ($result->status === -404) {
                return null; // No match found
            }

            if ($result->status !== 0) {
                throw new RuntimeException("Route matching failed with status: {$result->status}");
            }

            $jsonData = FFI::string($result->data, $result->len);
            $matchData = json_decode($jsonData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode match result: ' . json_last_error_msg());
            }

            $handlerId = $matchData['handler'] ?? null;
            if (!$handlerId || !isset($this->routeHandlers[$handlerId])) {
                throw new RuntimeException("Handler not found for ID: {$handlerId}");
            }

            return [
                'handler'    => $this->routeHandlers[$handlerId],
                'params'     => $matchData['params'] ?? [],
                'handler_id' => $handlerId,
            ];
        } finally {
            $this->ffi->free_router_result($result);
        }
    }

    public function batchAddRoutes(array $routes): array
    {
        if (!$this->available) {
            throw new RuntimeException('Rust FFI router engine not available');
        }

        $rustRoutes = [];
        $handlerMap = [];

        foreach ($routes as $route) {
            $handlerId = (string) $this->nextHandlerId++;
            $this->routeHandlers[$handlerId] = $route['handler'];
            $handlerMap[$handlerId] = $route['handler'];

            foreach ($route['methods'] as $method) {
                $rustRoutes[] = [
                    'method'  => $method,
                    'path'    => $route['path'],
                    'handler' => $handlerId,
                ];
            }
        }

        $jsonData = json_encode($rustRoutes);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode routes to JSON');
        }

        $result = $this->ffi->router_batch_add_routes($jsonData, strlen($jsonData));

        try {
            if ($result->status !== 0) {
                throw new RuntimeException("Batch add routes failed with status: {$result->status}");
            }

            $responseData = FFI::string($result->data, $result->len);
            $response = json_decode($responseData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode batch result: ' . json_last_error_msg());
            }

            return [
                'added'    => $response['added'] ?? 0,
                'total'    => $response['total'] ?? 0,
                'handlers' => $handlerMap,
            ];
        } finally {
            $this->ffi->free_router_result($result);
        }
    }

    public function clearCache(): void
    {
        if (!$this->available) {
            throw new RuntimeException('Rust FFI router engine not available');
        }

        $result = $this->ffi->router_clear_cache();
        $this->ffi->free_router_result($result);
    }

    public function getStats(): array
    {
        if (!$this->available) {
            return [
                'available' => false,
                'engine'    => 'rust_ffi',
            ];
        }

        $result = $this->ffi->router_get_stats();

        try {
            if ($result->status !== 0) {
                throw new RuntimeException("Failed to get stats with status: {$result->status}");
            }

            $jsonData = FFI::string($result->data, $result->len);
            $stats = json_decode($jsonData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode stats: ' . json_last_error_msg());
            }

            return array_merge($stats, [
                'available'      => true,
                'engine'         => 'rust_ffi',
                'handlers_count' => count($this->routeHandlers),
                'capabilities'   => $this->getCapabilities(),
            ]);
        } finally {
            $this->ffi->free_router_result($result);
        }
    }

    public function getCapabilities(): array
    {
        if (!$this->available) {
            return [];
        }

        try {
            $caps = $this->ffi->get_router_capabilities();

            return [
                'radix_tree'       => ($caps & 1) !== 0,
                'caching'          => ($caps & 2) !== 0,
                'batch_operations' => ($caps & 4) !== 0,
                'statistics'       => ($caps & 8) !== 0,
                'engine'           => 'rust_ffi',
            ];
        } catch (Throwable $e) {
            $this->logger->error('Failed to get Rust FFI router capabilities', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function benchmark(int $iterations = 10000): array
    {
        if (!$this->available) {
            throw new RuntimeException('Rust FFI router engine not available');
        }

        // Add test routes
        $testRoutes = [
            ['methods' => ['GET'], 'path' => '/api/users', 'handler' => 'users_list'],
            ['methods' => ['GET'], 'path' => '/api/users/{id}', 'handler' => 'users_show'],
            ['methods' => ['POST'], 'path' => '/api/users', 'handler' => 'users_create'],
            ['methods' => ['PUT'], 'path' => '/api/users/{id}', 'handler' => 'users_update'],
            ['methods' => ['DELETE'], 'path' => '/api/users/{id}', 'handler' => 'users_delete'],
        ];

        $this->batchAddRoutes($testRoutes);

        $testPaths = [
            ['GET', '/api/users'],
            ['GET', '/api/users/123'],
            ['POST', '/api/users'],
            ['PUT', '/api/users/456'],
            ['DELETE', '/api/users/789'],
        ];

        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $testPath = $testPaths[$i % count($testPaths)];

            $start = microtime(true);
            $this->match($testPath[0], $testPath[1]);
            $times[] = (microtime(true) - $start) * 1000000; // microseconds
        }

        return [
            'avg_time_us'        => array_sum($times) / count($times),
            'min_time_us'        => min($times),
            'max_time_us'        => max($times),
            'operations_per_sec' => 1000000 / (array_sum($times) / count($times)),
            'iterations'         => $iterations,
            'engine'             => 'rust_ffi',
        ];
    }

    private function initializeFFI(): void
    {
        try {
            if (!extension_loaded('ffi')) {
                $this->logger->warning('FFI extension not loaded');

                return;
            }

            $libraryPath = $this->config['rust_library_path'] ?? $this->findRustLibrary();
            if (!$libraryPath || !file_exists($libraryPath)) {
                $this->logger->warning('Rust router library not found', ['path' => $libraryPath]);

                return;
            }

            $this->ffi = FFI::cdef('
                typedef struct {
                    char* data;
                    size_t len;
                    int status;
                } RouterResult;
                
                RouterResult* router_create();
                RouterResult* router_add_route(char* method, char* path, char* handler_id);
                RouterResult* router_match(char* method, char* path);
                RouterResult* router_clear_cache();
                RouterResult* router_get_stats();
                RouterResult* router_batch_add_routes(char* routes_json, size_t len);
                void free_router_result(RouterResult* result);
                int get_router_capabilities();
            ', $libraryPath);

            // Initialize router
            $result = $this->ffi->router_create();
            if ($result->status === 0) {
                $this->available = true;
                $this->logger->info('Rust FFI router engine initialized successfully');
            }

            $this->ffi->free_router_result($result);
        } catch (Throwable $e) {
            $this->logger->error('Failed to initialize Rust FFI router engine', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findRustLibrary(): ?string
    {
        $possiblePaths = [
            __DIR__ . '/../../rust/target/release/libhighper_router.so',
            __DIR__ . '/../../rust/target/release/libhighper_router.dylib',
            __DIR__ . '/../../rust/target/release/highper_router.dll',
            getcwd() . '/lib/highper_router.so',
            getcwd() . '/lib/highper_router.dylib',
            getcwd() . '/lib/highper_router.dll',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
