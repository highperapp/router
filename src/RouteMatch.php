<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

/**
 * Route Match Result.
 *
 * Contains the matched route and extracted parameters
 */
class RouteMatch
{
    private Route $route;

    private array $parameters;

    public function __construct(Route $route, array $parameters = [])
    {
        $this->route = $route;
        $this->parameters = $parameters;
    }

    /**
     * Get matched route.
     */
    public function getRoute(): Route
    {
        return $this->route;
    }

    /**
     * Get all parameters.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get specific parameter.
     */
    public function getParameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Check if parameter exists.
     */
    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    /**
     * Get route handler.
     */
    public function getHandler(): mixed
    {
        return $this->route->getHandler();
    }

    /**
     * Get route middleware.
     */
    public function getMiddleware(): array
    {
        return $this->route->getMiddleware();
    }

    /**
     * Get route name.
     */
    public function getName(): ?string
    {
        return $this->route->getName();
    }

    /**
     * Get route path.
     */
    public function getPath(): string
    {
        return $this->route->getPath();
    }

    /**
     * Get route methods.
     */
    public function getMethods(): array
    {
        return $this->route->getMethods();
    }

    /**
     * Get query parameters (EARouter feature).
     */
    public function getQueryParams(): array
    {
        return $this->parameters['__query'] ?? [];
    }

    /**
     * Get specific query parameter.
     */
    public function getQueryParam(string $name, mixed $default = null): mixed
    {
        return $this->getQueryParams()[$name] ?? $default;
    }

    /**
     * Check if query parameter exists.
     */
    public function hasQueryParam(string $name): bool
    {
        return isset($this->getQueryParams()[$name]);
    }
}
