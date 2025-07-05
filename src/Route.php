<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router;

/**
 * Route Definition.
 *
 * Represents a single route with methods, path, and handler
 */
class Route
{
    private array $methods;

    private string $path;

    private mixed $handler;

    private array $middleware = [];

    private array $constraints = [];

    private ?string $name = null;

    public function __construct(array $methods, string $path, mixed $handler)
    {
        $this->methods = array_map('strtoupper', $methods);
        $this->path = $path;
        $this->handler = $handler;
    }

    /**
     * Get HTTP methods.
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get route path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get route handler.
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * Add middleware to route.
     */
    public function middleware(string|array|callable $middleware): self
    {
        if (is_string($middleware) || is_callable($middleware)) {
            $this->middleware[] = $middleware;
        } else {
            $this->middleware = array_merge($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * Get route middleware.
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Add parameter constraint.
     */
    public function where(string|array $parameter, ?string $constraint = null): self
    {
        if (is_array($parameter)) {
            $this->constraints = array_merge($this->constraints, $parameter);
        } else {
            $this->constraints[$parameter] = $constraint;
        }

        return $this;
    }

    /**
     * Add multiple parameter constraints.
     */
    public function whereArray(array $constraints): self
    {
        $this->constraints = array_merge($this->constraints, $constraints);

        return $this;
    }

    /**
     * Get parameter constraints.
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * Set route name.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get route name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Check if route matches method.
     */
    public function matchesMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Common constraint helpers.
     */
    public function whereNumber(string $parameter): self
    {
        return $this->where($parameter, 'int');
    }

    public function whereAlpha(string $parameter): self
    {
        return $this->where($parameter, 'alpha');
    }

    public function whereAlphaNumeric(string $parameter): self
    {
        return $this->where($parameter, 'alnum');
    }

    public function whereUuid(string $parameter): self
    {
        return $this->where($parameter, 'uuid');
    }

    public function whereSlug(string $parameter): self
    {
        return $this->where($parameter, 'slug');
    }
}
