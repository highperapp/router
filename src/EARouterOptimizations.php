<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

/**
 * EARouter-Inspired Internal Optimizations.
 *
 * These optimizations maintain the existing fluent API while adding
 * EARouter's array-based performance concepts internally.
 */
trait EARouterOptimizations
{
    private array $optimizedStaticLookup = [];

    private array $optimizedLengthIndex = [];

    private array $optimizedParameterCache = [];

    private array $optimizedConstraintCache = [];

    /**
     * Demonstrate performance improvement with your nested routes.
     */
    public function demonstrateNestedRouteOptimization(): array
    {
        // Your typical nested routes
        $this->get('/api/users/{userId}/posts/{postId}/comments', 'CommentController@index')
             ->whereNumber('userId')
             ->whereNumber('postId');

        $this->get('/api/categories/{slug}/products/{productId}/reviews', 'ReviewController@index')
             ->whereSlug('slug')
             ->whereNumber('productId');

        // Compile with EARouter optimizations
        $this->compileRoutesEARouterStyle();

        // Performance comparison
        $testPaths = [
            '/api/users/123/posts/456/comments',
            '/api/categories/electronics/products/789/reviews',
        ];

        $results = [];
        foreach ($testPaths as $path) {
            $pathSegments = array_filter(explode('/', $path));
            $segmentCount = count($pathSegments);

            // Show how EARouter optimization works
            $results[] = [
                'path'                   => $path,
                'segment_count'          => $segmentCount,
                'routes_to_check_before' => count($this->dynamicRoutes['GET'] ?? []),
                'routes_to_check_after'  => count($this->optimizedLengthIndex['GET'][$segmentCount] ?? []),
                'optimization'           => 'Reduced search space by segment count grouping',
            ];
        }

        return $results;
    }

    /**
     * Compile routes using EARouter-inspired optimizations.
     */
    private function compileRoutesEARouterStyle(): void
    {
        $this->optimizedStaticLookup = [];
        $this->optimizedLengthIndex = [];
        $this->optimizedParameterCache = [];
        $this->optimizedConstraintCache = [];

        // Optimize static routes - EARouter approach
        foreach ($this->staticRoutes as $method => $routes) {
            foreach ($routes as $path => $route) {
                $key = "{$method}:{$path}";
                $this->optimizedStaticLookup[$key] = [
                    'route'      => $route,
                    'handler'    => $route->getHandler(),
                    'middleware' => $route->getMiddleware(),
                    'compiled'   => true, // EARouter-style pre-compilation flag
                ];
            }
        }

        // Optimize dynamic routes - segment count indexing (EARouter's key innovation)
        foreach ($this->dynamicRoutes as $method => $routes) {
            foreach ($routes as $routeData) {
                $segments = $routeData['segments'];
                $segmentCount = count($segments);
                $route = $routeData['route'];
                $routeId = spl_object_id($route);

                // Group by segment count (EARouter approach)
                $this->optimizedLengthIndex[$method][$segmentCount][] = [
                    'route'          => $route,
                    'segments'       => $segments,
                    'routeId'        => $routeId,
                    'pattern'        => $route->getPath(),
                    'handler'        => $route->getHandler(),
                    'middleware'     => $route->getMiddleware(),
                    'regex_compiled' => false, // NO REGEX - pure array approach
                ];

                // Pre-cache parameter positions (performance optimization)
                $paramPositions = [];
                $constraints = [];
                foreach ($segments as $i => $segment) {
                    if ($segment['type'] === 'param') {
                        $paramPositions[$i] = $segment['name'];
                        if (isset($segment['constraint'])) {
                            $constraints[$segment['name']] = $segment['constraint'];
                        }
                    }
                }

                // Add route-level constraints
                $routeConstraints = $route->getConstraints();
                $constraints = array_merge($constraints, $routeConstraints);

                $this->optimizedParameterCache[$routeId] = $paramPositions;
                $this->optimizedConstraintCache[$routeId] = $constraints;
            }
        }
    }

    /**
     * Optimized route matching using EARouter principles.
     */
    private function performEARouterOptimizedMatch(string $method, string $path): ?RouteMatch
    {
        // 1. Try static routes first (O(1) hash lookup - EARouter style)
        $staticKey = "{$method}:{$path}";
        if (isset($this->optimizedStaticLookup[$staticKey])) {
            $staticData = $this->optimizedStaticLookup[$staticKey];

            return new RouteMatch($staticData['route'], []);
        }

        // 2. Try dynamic routes by segment count (EARouter's key optimization)
        $pathSegments = array_filter(explode('/', $path));
        $segmentCount = count($pathSegments);

        if (!isset($this->optimizedLengthIndex[$method][$segmentCount])) {
            return null; // No routes with this segment count
        }

        // 3. Check only routes with matching segment count
        foreach ($this->optimizedLengthIndex[$method][$segmentCount] as $routeData) {
            $match = $this->matchSegmentsEARouterStyle($pathSegments, $routeData);
            if ($match !== null) {
                return new RouteMatch($routeData['route'], $match);
            }
        }

        return null;
    }

    /**
     * EARouter-style segment matching with pre-cached parameters.
     */
    private function matchSegmentsEARouterStyle(array $pathSegments, array $routeData): ?array
    {
        $routeId = $routeData['routeId'];
        $segments = $routeData['segments'];

        // Use pre-cached parameter positions (EARouter optimization)
        $paramPositions = $this->optimizedParameterCache[$routeId] ?? [];
        $constraints = $this->optimizedConstraintCache[$routeId] ?? [];

        $parameters = [];

        // Fast segment matching with minimal branching
        for ($i = 0, $count = count($pathSegments); $i < $count; $i++) {
            $segment = $segments[$i];

            if ($segment['type'] === 'static') {
                // Direct string comparison (fastest path)
                if ($pathSegments[$i] !== $segment['value']) {
                    return null;
                }
            } else {
                // Parameter segment - use cached position
                $paramName = $paramPositions[$i];
                $value = $pathSegments[$i];

                // Apply constraints if any (optimized validation)
                if (isset($constraints[$paramName])) {
                    if (!$this->validateConstraintFast($value, $constraints[$paramName])) {
                        return null;
                    }
                }

                $parameters[$paramName] = $value;
            }
        }

        return $parameters;
    }
}
