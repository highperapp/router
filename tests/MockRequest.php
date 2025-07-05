<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Simple PSR-7 mock request for testing.
 */
class MockRequest implements ServerRequestInterface
{
    private string $method;

    private UriInterface $uri;

    private array $queryParams = [];

    public function __construct(string $method, string $path)
    {
        $this->method = $method;
        $this->uri = new MockUri($path);

        // Parse query parameters from URI if present
        $queryString = $this->uri->getQuery();
        if ($queryString) {
            parse_str($queryString, $this->queryParams);
        }
    }

    /**
     * Create a mock request quickly.
     */
    public static function create(string $method, string $path): self
    {
        return new self($method, $path);
    }

    /**
     * Create a mock request with query parameters.
     */
    public static function createWithQuery(string $method, string $path, array $queryParams): self
    {
        $queryString = http_build_query($queryParams);
        $pathWithQuery = $path . ($queryString ? '?' . $queryString : '');

        return new self($method, $pathWithQuery);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    // Minimal implementations for required methods
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): static
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): static
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): static
    {
        return $this;
    }

    public function withoutHeader(string $name): static
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return new MockStream();
    }

    public function withBody(StreamInterface $body): static
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return $this->uri->getPath();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }

    public function withMethod(string $method): static
    {
        return new self($method, $this->uri->getPath());
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this;
    }

    public function getServerParams(): array
    {
        return [];
    }

    public function getCookieParams(): array
    {
        return [];
    }

    public function withCookieParams(array $cookies): static
    {
        return $this;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): static
    {
        return $this;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        return $this;
    }

    public function getParsedBody()
    {
    }

    public function withParsedBody($data): static
    {
        return $this;
    }

    public function getAttributes(): array
    {
        return [];
    }

    public function getAttribute(string $name, $default = null)
    {
        return $default;
    }

    public function withAttribute(string $name, $value): static
    {
        return $this;
    }

    public function withoutAttribute(string $name): static
    {
        return $this;
    }
}

class MockUri implements UriInterface
{
    private string $path;

    private string $query;

    public function __construct(string $pathWithQuery)
    {
        // Properly separate path from query string
        $parts = explode('?', $pathWithQuery, 2);
        $this->path = $parts[0];
        $this->query = $parts[1] ?? '';
    }

    public function __toString(): string
    {
        return "http://localhost{$this->path}";
    }

    public function getPath(): string
    {
        return $this->path;
    }

    // Minimal implementations
    public function getScheme(): string
    {
        return 'http';
    }

    public function getAuthority(): string
    {
        return '';
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return 'localhost';
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): static
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        return $this;
    }

    public function withHost(string $host): static
    {
        return $this;
    }

    public function withPort(?int $port): static
    {
        return $this;
    }

    public function withPath(string $path): static
    {
        return new self($path);
    }

    public function withQuery(string $query): static
    {
        return $this;
    }

    public function withFragment(string $fragment): static
    {
        return $this;
    }
}

class MockStream implements StreamInterface
{
    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
    }

    public function getSize(): ?int
    {
        return 0;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
