<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

/**
 * EARouter-Inspired Array-Based Route Definition Support.
 *
 * Adds convenient array-based route configuration while maintaining
 * HighPer Router's performance advantages over regex-based routers.
 */
trait ArrayRouteDefinition
{
    // Query configuration storage (minimal performance impact)
    private array $routeQueryConfigs = [];

    /**
     * Define routes using EARouter-style array configuration.
     *
     * @param array $routeConfigs Array of route configurations
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
        $optionalQuery = $config['optional_query'] ?? false;

        if ($handler === null) {
            throw new RouterException("Handler is required for route: {$routeName}");
        }

        // Create the route using existing fluent API
        $route = $this->addRoute($methods, $path, $handler);

        // Apply constraints
        if (!empty($constraints)) {
            if (is_array($constraints)) {
                foreach ($constraints as $param => $constraint) {
                    $route->where($param, $constraint);
                }
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
        if (!empty($queryParams) || $optionalQuery) {
            $this->setRouteQueryConfig($route, $queryParams, $optionalQuery);
        }

        return $route;
    }

    /**
     * Enhanced route matching with optional query string support.
     */
    public function matchWithQuery($request): ?RouteMatch
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
            $match = $this->validateQueryParams($match, $queryParams, $queryConfig);
        }

        return $match;
    }

    /**
     * Extract query parameters from request.
     */
    private function extractQueryParams($request): array
    {
        if (method_exists($request, 'getQueryParams')) {
            return $request->getQueryParams();
        }

        if (method_exists($request, 'getUri')) {
            $query = $request->getUri()->getQuery();
            parse_str($query, $params);

            return $params;
        }

        return $_GET ?? [];
    }

    /**
     * Validate query parameters against route configuration.
     */
    private function validateQueryParams(RouteMatch $match, array $queryParams, array $config): ?RouteMatch
    {
        $requiredParams = $config['required'] ?? [];
        $optionalParams = $config['optional'] ?? [];
        $validation = $config['validation'] ?? [];

        // Check required query parameters
        foreach ($requiredParams as $param) {
            if (!isset($queryParams[$param])) {
                return null; // Required query param missing
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
    private function validateQueryParam(string $value, $validator): bool
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

    private function setRouteQueryConfig(Route $route, array $queryParams, bool $optional): void
    {
        $routeId = spl_object_id($route);
        $this->routeQueryConfigs[$routeId] = [
            'required'       => [],
            'optional'       => $queryParams,
            'validation'     => [],
            'optional_query' => $optional,
        ];
    }

    private function getRouteQueryConfig(Route $route): ?array
    {
        $routeId = spl_object_id($route);

        return $this->routeQueryConfigs[$routeId] ?? null;
    }
}
