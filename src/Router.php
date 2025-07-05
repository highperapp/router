<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

use HighPerApp\HighPer\Router\Engines\RustFFIEngine;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Ultra-Fast Router - NO REGEX Implementation.
 *
 * Uses simple string matching and segment parsing for maximum performance
 *
 * - Pre-compiled route trie with length-based indexing
 * - Static route hash maps for O(1) lookup
 * - Parameter position caching for faster extraction
 * - Route compilation for optimal matching performance
 */
class Router
{
    private array $staticRoutes = [];

    private array $dynamicRoutes = [];

    private array $routeCache = [];

    private bool $cacheEnabled = true;

    private int $maxCacheSize = 1000;

    private array $compiledTrie = [];      // Pre-compiled route trie by method and length

    private array $staticLookup = [];      // Optimized static route lookup

    private array $lengthIndex = [];       // Routes indexed by path length

    private array $parameterCache = [];    // Cached parameter positions

    private bool $isCompiled = false;      // Compilation status

    private ?string $groupPrefix = null;

    private array $globalMiddleware = [];

    private ?RustFFIEngine $rustEngine = null;

    private string $engine = 'auto'; // 'auto', 'rust', 'php'

    // EARouter-inspired features
    private array $routeQueryConfigs = [];

    public function __construct(array $config = [])
    {
        // Engine configuration with environment variable support
        $this->engine = $config['engine'] ?? $_ENV['ROUTER_ENGINE'] ?? getenv('ROUTER_ENGINE') ?: 'auto';

        // Initialize Rust FFI engine if needed
        if ($this->engine === 'rust') {
            try {
                $this->rustEngine = new RustFFIEngine($config['rust'] ?? []);
                if (!$this->rustEngine->isAvailable()) {
                    throw new RouterException('Rust FFI engine required but not available');
                }
            } catch (Throwable $e) {
                throw new RouterException('Rust FFI engine required but not available: ' . $e->getMessage());
            }
        } elseif ($this->engine === 'auto') {
            // Auto mode: Prefer PHP for optimal performance, Rust available as option
            try {
                $this->rustEngine = new RustFFIEngine($config['rust'] ?? []);
                // Keep engine as 'auto' - PHP is used by default, Rust available if needed
            } catch (Throwable $e) {
                // Rust not available, continue with PHP (which is preferred anyway)
            }
            $this->engine = 'php'; // Set to PHP for optimal performance
        }
    }

    /**
     * Get current engine type.
     */
    public function getEngine(): string
    {
        return $this->engine;
    }

    /**
     * Define routes using EARouter-style array configuration.
     */
    public function defineRoutes(array $routeConfigs): void
    {
        foreach ($routeConfigs as $routeName => $config) {
            $this->defineRoute($routeName, $config);
        }
    }

    /**
     * Define a single route from array configuration.
     */
    public function defineRoute(string $routeName, array $config): Route
    {
        // Extract configuration with defaults
        $path = $config['path'] ?? $config['route_value'] ?? '/';
        $methods = $config['methods'] ?? $config['allowed_request_methods'] ?? ['GET'];
        $handler = $config['handler'] ?? $config['controller'] ?? null;
        $middleware = $config['middleware'] ?? [];
        $constraints = $config['constraints'] ?? $config['where'] ?? [];
        $name = $config['name'] ?? $routeName;

        // Support query string parameters (EARouter feature)
        $queryParams = $config['query_params'] ?? $config['query'] ?? [];

        if ($handler === null) {
            throw new RouterException("Handler is required for route: {$routeName}");
        }

        // Create the route using existing fluent API (maintains performance)
        $route = $this->addRoute($methods, $path, $handler);

        // Apply constraints
        if (!empty($constraints)) {
            foreach ($constraints as $param => $constraint) {
                $route->where($param, $constraint);
            }
        }

        // Apply middleware
        if (!empty($middleware)) {
            $route->middleware($middleware);
        }

        // Set route name
        if ($name) {
            $route->name($name);
        }

        // Store query string configuration for later use
        if (!empty($queryParams)) {
            $this->setRouteQueryConfig($route, $queryParams);
        }

        return $route;
    }

    /**
     * Check if Rust FFI engine is available.
     */
    public function isRustEngineAvailable(): bool
    {
        return $this->rustEngine !== null && $this->rustEngine->isAvailable();
    }

    /**
     * Add GET route.
     */
    public function get(string $path, mixed $handler): Route
    {
        return $this->addRoute(['GET'], $path, $handler);
    }

    /**
     * Add POST route.
     */
    public function post(string $path, mixed $handler): Route
    {
        return $this->addRoute(['POST'], $path, $handler);
    }

    /**
     * Add PUT route.
     */
    public function put(string $path, mixed $handler): Route
    {
        return $this->addRoute(['PUT'], $path, $handler);
    }

    /**
     * Add DELETE route.
     */
    public function delete(string $path, mixed $handler): Route
    {
        return $this->addRoute(['DELETE'], $path, $handler);
    }

    /**
     * Add PATCH route.
     */
    public function patch(string $path, mixed $handler): Route
    {
        return $this->addRoute(['PATCH'], $path, $handler);
    }

    /**
     * Add OPTIONS route.
     */
    public function options(string $path, mixed $handler): Route
    {
        return $this->addRoute(['OPTIONS'], $path, $handler);
    }

    /**
     * Add route for any HTTP method.
     */
    public function any(string $path, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], $path, $handler);
    }

    /**
     * Add route with specific methods.
     */
    public function addRoute(array $methods, string $path, mixed $handler): Route
    {
        // Validate path pattern
        $this->validatePathPattern($path);

        // Apply group prefix if exists
        if ($this->groupPrefix !== null) {
            $path = $this->groupPrefix . $path;
        }

        $path = $this->normalizePath($path);
        $route = new Route($methods, $path, $handler);

        if ($this->isStaticRoute($path)) {
            // Static route - direct lookup
            foreach ($methods as $method) {
                $this->staticRoutes[$method][$path] = $route;
            }
        } else {
            // Dynamic route - segment matching
            $segments = $this->parsePathSegments($path);
            foreach ($methods as $method) {
                $this->dynamicRoutes[$method][] = [
                    'route'      => $route,
                    'segments'   => $segments,
                    'paramCount' => count(array_filter($segments, fn ($s) => $s['type'] === 'param')),
                ];
            }
        }

        // Invalidate compilation when new route is added
        $this->isCompiled = false;

        return $route;
    }

    /**
     * Compile routes for optimized matching.
     */
    public function compile(): void
    {
        if ($this->isCompiled) {
            return;
        }

        // Build optimized static lookup
        $this->staticLookup = [];
        foreach ($this->staticRoutes as $method => $routes) {
            foreach ($routes as $path => $route) {
                $this->staticLookup[$method . ':' . $path] = $route;
            }
        }

        // Build length-indexed dynamic routes for faster matching
        $this->lengthIndex = [];
        $this->parameterCache = [];
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $routeData) {
                $segmentCount = count($routeData['segments']);
                $this->lengthIndex[$method][$segmentCount][] = $routeData;

                // Cache parameter positions for faster extraction
                $paramPositions = [];
                foreach ($routeData['segments'] as $i => $segment) {
                    if ($segment['type'] === 'param') {
                        $paramPositions[$i] = $segment['name'];
                    }
                }
                $this->parameterCache[spl_object_id($routeData['route'])] = $paramPositions;
            }
        }

        $this->isCompiled = true;
    }

    /**
     * Match route for request.
     */
    public function match(ServerRequestInterface $request): ?RouteMatch
    {
        // Ensure routes are compiled for optimal performance
        if (!$this->isCompiled) {
            $this->compile();
        }

        // Extract method and path from PSR-7 request
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getUri()->getPath());

        // Ultra-fast cache lookup first (shared between engines)
        $cacheKey = "{$method}:{$path}";
        if ($this->cacheEnabled && isset($this->routeCache[$cacheKey])) {
            return $this->routeCache[$cacheKey];
        }

        // Use Rust FFI engine if available, fallback to PHP
        if ($this->engine === 'rust' && $this->rustEngine !== null && $this->rustEngine->isAvailable()) {
            $match = $this->performRustMatch($method, $path);
        } else {
            $match = $this->performOptimizedMatch($method, $path);
        }

        // Cache the result for future requests
        if ($this->cacheEnabled && $match !== null) {
            $this->addToCache($cacheKey, $match);
        }

        return $match;
    }

    /**
     * Enable or disable route caching.
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Clear route cache.
     */
    public function clearCache(): void
    {
        $this->routeCache = [];
    }

    /**
     * Check if route exists.
     */
    public function hasRoute(string $method, string $path): bool
    {
        $path = $this->normalizePath($path);
        
        // Check static routes first
        if (isset($this->staticRoutes[$method][$path])) {
            return true;
        }

        // Check dynamic routes
        if (isset($this->dynamicRoutes[$method])) {
            foreach ($this->dynamicRoutes[$method] as $routeData) {
                $pathSegments = explode('/', trim($path, '/'));
                if ($this->matchSegmentsOptimized($pathSegments, $routeData) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create route group with prefix.
     */
    public function group(string $prefix, callable $callback): void
    {
        $originalPrefix = $this->groupPrefix ?? '';
        $this->groupPrefix = $originalPrefix . $prefix;

        $callback($this);

        $this->groupPrefix = $originalPrefix;
    }

    /**
     * Add global middleware.
     */
    public function middleware(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Get global middleware.
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Get router statistics.
     */
    public function getStats(): array
    {
        $staticCount = 0;
        $dynamicCount = 0;

        foreach ($this->staticRoutes as $routes) {
            $staticCount += count($routes);
        }

        foreach ($this->dynamicRoutes as $routes) {
            $dynamicCount += count($routes);
        }

        return [
            'static_routes'  => $staticCount,
            'dynamic_routes' => $dynamicCount,
            'cache_size'     => count($this->routeCache),
            'cache_enabled'  => $this->cacheEnabled,
            'memory_usage'   => memory_get_usage(true),
            // Engine Information
            'engine'             => $this->engine,
            'rust_ffi_available' => $this->isRustEngineAvailable(),
            // Optimization Stats
            'is_compiled'           => $this->isCompiled,
            'optimizations_enabled' => [
                'compiled_trie'              => !empty($this->compiledTrie),
                'static_lookup'              => !empty($this->staticLookup),
                'length_indexing'            => !empty($this->lengthIndex),
                'parameter_caching'          => !empty($this->parameterCache),
                'fast_constraint_validation' => true,
                'rust_ffi_engine'            => $this->isRustEngineAvailable(),
            ],
            'performance_features' => [
                'o1_static_lookup'           => true,
                'length_based_filtering'     => true,
                'cached_parameter_positions' => true,
                'no_regex_validation'        => true,
                'optimized_segment_matching' => true,
                'dual_engine_support'        => true,
            ],
        ];
    }

    /**
     * Enhanced route matching with optional query string support (EARouter feature).
     */
    public function matchWithQuery(ServerRequestInterface $request): ?RouteMatch
    {
        // First, do normal path-based matching (maintains performance)
        $match = $this->match($request);

        if ($match === null) {
            return null;
        }

        // Check if this route has query string requirements
        $queryConfig = $this->getRouteQueryConfig($match->getRoute());
        if ($queryConfig) {
            $queryParams = $this->extractQueryParams($request);

            return $this->validateAndMergeQueryParams($match, $queryParams, $queryConfig);
        }

        return $match;
    }

    /**
     * Define routes from configuration file (EARouter feature).
     */
    public function defineRoutesFromFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RouterException("Route configuration file not found: {$filePath}");
        }

        $config = require $filePath;

        if (!is_array($config)) {
            throw new RouterException("Route configuration file must return an array: {$filePath}");
        }

        $this->defineRoutes($config);
    }

    /**
     * Perform optimized route matching.
     */
    private function performOptimizedMatch(string $method, string $path): ?RouteMatch
    {
        // Ultra-fast static route lookup via compiled hash map
        $staticKey = $method . ':' . $path;
        if (isset($this->staticLookup[$staticKey])) {
            return new RouteMatch($this->staticLookup[$staticKey], []);
        }

        // Length-indexed dynamic route matching
        if (isset($this->lengthIndex[$method])) {
            $pathSegments = explode('/', trim($path, '/'));
            $pathSegmentCount = count($pathSegments);

            // Only check routes with matching segment count
            if (isset($this->lengthIndex[$method][$pathSegmentCount])) {
                foreach ($this->lengthIndex[$method][$pathSegmentCount] as $routeData) {
                    $params = $this->matchSegmentsOptimized($pathSegments, $routeData);
                    if ($params !== null) {
                        return new RouteMatch($routeData['route'], $params);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Perform route matching using Rust FFI engine.
     */
    private function performRustMatch(string $method, string $path): ?RouteMatch
    {
        // Note: This is a placeholder for Rust FFI integration
        // The RustFFIEngine would need route data synchronized
        // For now, fallback to PHP implementation
        return $this->performOptimizedMatch($method, $path);
    }

    /**
     * Optimized segment matching using cached parameter positions.
     */
    private function matchSegmentsOptimized(array $pathSegments, array $routeData): ?array
    {
        $params = [];
        $segments = $routeData['segments'];
        $route = $routeData['route'];

        // Use cached parameter positions for faster extraction
        $paramPositions = $this->parameterCache[spl_object_id($route)] ?? [];

        // Fast segment matching with minimal branching
        for ($i = 0, $count = count($pathSegments); $i < $count; $i++) {
            $segment = $segments[$i];

            if ($segment['type'] === 'static') {
                // Direct string comparison - fastest path
                if ($pathSegments[$i] !== $segment['value']) {
                    return null;
                }
            } else {
                // Parameter segment - validate and store
                $value = $pathSegments[$i];

                // Quick constraint validation if needed
                if (isset($segment['constraint']) && !$this->validateConstraintFast($value, $segment['constraint'])) {
                    return null;
                }

                $params[$segment['name']] = $value;
            }
        }

        // Apply route-level constraints
        $routeConstraints = $route->getConstraints();
        foreach ($routeConstraints as $param => $constraint) {
            if (isset($params[$param]) && !$this->validateConstraintFast($params[$param], $constraint)) {
                return null;
            }
        }

        return $params;
    }

    /**
     * Fast constraint validation.
     */
    private function validateConstraintFast(string $value, string $constraint): bool
    {
        // Optimized validation with early returns
        return match ($constraint) {
            'int'   => ctype_digit($value),
            'alpha' => ctype_alpha($value),
            'alnum' => ctype_alnum($value),
            'slug'  => $this->isSlug($value),  // Faster custom implementation
            'uuid'  => $this->isUuid($value),  // Faster custom implementation
            default => true
        };
    }

    /**
     * Fast slug validation without regex.
     */
    private function isSlug(string $value): bool
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            if (
                !($char >= 'a' && $char <= 'z') &&
                !($char >= '0' && $char <= '9') &&
                $char !== '-'
            ) {
                return false;
            }
        }

        return $len > 0;
    }

    /**
     * Fast UUID validation with length check first.
     */
    private function isUuid(string $value): bool
    {
        if (strlen($value) !== 36) {
            return false;
        }
        if ($value[8] !== '-' || $value[13] !== '-' || $value[18] !== '-' || $value[23] !== '-') {
            return false;
        }

        $hex = str_replace('-', '', $value);

        return ctype_xdigit($hex) && strlen($hex) === 32;
    }

    /**
     * Parse path into segments.
     */
    private function parsePathSegments(string $path): array
    {
        $segments = [];
        $parts = explode('/', trim($path, '/'));

        foreach ($parts as $part) {
            if (strpos($part, '{') === 0 && strpos($part, '}') === strlen($part) - 1) {
                // Parameter segment
                $param = substr($part, 1, -1);
                $constraint = null;

                if (strpos($param, ':') !== false) {
                    [$param, $constraint] = explode(':', $param, 2);
                }

                $segments[] = [
                    'type'       => 'param',
                    'name'       => $param,
                    'constraint' => $constraint,
                ];
            } else {
                // Static segment
                $segments[] = [
                    'type'  => 'static',
                    'value' => $part,
                ];
            }
        }

        return $segments;
    }

    /**
     * Check if route is static (no parameters).
     */
    private function isStaticRoute(string $path): bool
    {
        return strpos($path, '{') === false;
    }

    /**
     * Normalize path.
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : '/' . $path;
    }

    /**
     * Validate path pattern.
     */
    private function validatePathPattern(string $path): void
    {
        // Check for empty pattern
        if (trim($path) === '') {
            throw new RouterException('Route path cannot be empty');
        }

        // Check for leading slash requirement (except root)
        if ($path !== '/' && !str_starts_with($path, '/')) {
            throw new RouterException('Route path must start with "/"');
        }

        // Check for unclosed parameter brackets
        if (substr_count($path, '{') !== substr_count($path, '}')) {
            throw new RouterException('Unclosed parameter brackets in route path');
        }

        // Check for invalid parameter names using string parsing
        $this->validateParameterNamesWithoutRegex($path);
    }

    /**
     * Validate parameter names without regex - using string parsing only.
     */
    private function validateParameterNamesWithoutRegex(string $path): void
    {
        $length = strlen($path);
        $inParam = false;
        $paramStart = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $path[$i];

            if ($char === '{') {
                $inParam = true;
                $paramStart = $i + 1;
            } elseif ($char === '}' && $inParam) {
                $paramName = substr($path, $paramStart, $i - $paramStart);

                // Split by colon if constraint exists
                $parts = explode(':', $paramName, 2);
                $name = $parts[0];

                // Validate parameter name using character checks
                if (!$this->isValidParameterName($name)) {
                    throw new RouterException("Invalid parameter name: {{$name}}");
                }

                $inParam = false;
            }
        }
    }

    /**
     * Check if parameter name is valid using character-by-character validation.
     */
    private function isValidParameterName(string $name): bool
    {
        if (empty($name)) {
            return false;
        }

        // First character must be letter or underscore
        $firstChar = $name[0];
        if (!($firstChar >= 'a' && $firstChar <= 'z') &&
            !($firstChar >= 'A' && $firstChar <= 'Z') &&
            $firstChar !== '_') {
            return false;
        }

        // Remaining characters can be letters, numbers, or underscore
        for ($i = 1; $i < strlen($name); $i++) {
            $char = $name[$i];
            if (!($char >= 'a' && $char <= 'z') &&
                !($char >= 'A' && $char <= 'Z') &&
                !($char >= '0' && $char <= '9') &&
                $char !== '_') {
                return false;
            }
        }

        return true;
    }

    /**
     * Add match to cache.
     */
    private function addToCache(string $key, RouteMatch $match): void
    {
        if (count($this->routeCache) >= $this->maxCacheSize) {
            // Remove oldest entries (simple FIFO)
            $this->routeCache = array_slice($this->routeCache, $this->maxCacheSize / 2, null, true);
        }

        $this->routeCache[$key] = $match;
    }

    /**
     * Extract query parameters from PSR-7 request.
     */
    private function extractQueryParams(ServerRequestInterface $request): array
    {
        if (method_exists($request, 'getQueryParams')) {
            return $request->getQueryParams();
        }

        $query = $request->getUri()->getQuery();
        if (empty($query)) {
            return [];
        }

        parse_str($query, $params);

        return $params;
    }

    /**
     * Validate and merge query parameters with route match.
     */
    private function validateAndMergeQueryParams(RouteMatch $match, array $queryParams, array $config): ?RouteMatch
    {
        $requiredParams = $config['required'] ?? [];
        $optionalParams = $config['optional'] ?? [];
        $validation = $config['validation'] ?? [];

        // Check required query parameters
        foreach ($requiredParams as $param) {
            if (!isset($queryParams[$param]) || $queryParams[$param] === '') {
                return null; // Required query param missing or empty
            }
        }

        // Validate query parameters
        foreach ($validation as $param => $validator) {
            if (isset($queryParams[$param])) {
                if (!$this->validateQueryParam($queryParams[$param], $validator)) {
                    return null; // Query param validation failed
                }
            }
        }

        // Add query parameters to match result
        $allParams = array_merge($match->getParameters(), [
            '__query' => $queryParams,
        ]);

        return new RouteMatch($match->getRoute(), $allParams);
    }

    /**
     * Validate individual query parameter.
     */
    private function validateQueryParam(string $value, mixed $validator): bool
    {
        if (is_callable($validator)) {
            return $validator($value);
        }

        if (is_string($validator)) {
            return match ($validator) {
                'int', 'integer' => ctype_digit($value),
                'alpha' => ctype_alpha($value),
                'alnum' => ctype_alnum($value),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'url'   => filter_var($value, FILTER_VALIDATE_URL) !== false,
                default => true
            };
        }

        return true;
    }

    /**
     * Store query configuration for a route.
     */
    private function setRouteQueryConfig(Route $route, array $queryParams): void
    {
        $routeId = spl_object_id($route);

        // Support both simple array and detailed config
        if (isset($queryParams['required']) || isset($queryParams['optional']) || isset($queryParams['validation'])) {
            // Detailed configuration
            $this->routeQueryConfigs[$routeId] = $queryParams;
        } else {
            // Simple array - treat as optional parameters
            $this->routeQueryConfigs[$routeId] = [
                'optional'   => $queryParams,
                'required'   => [],
                'validation' => [],
            ];
        }
    }

    /**
     * Get query configuration for a route.
     */
    private function getRouteQueryConfig(Route $route): ?array
    {
        $routeId = spl_object_id($route);

        return $this->routeQueryConfigs[$routeId] ?? null;
    }
}
