<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Integration;

use Exception;
use HighPerApp\HighPer\Router\Router;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[Group('integration')]
#[Group('router')]
class RouterIntegrationTest extends TestCase
{
    private Router $router;

    private Container $container;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->container = new Container();
    }

    #[Test]
    #[TestDox('Router integrates with dependency injection container')]
    public function testRouterIntegratesWithDependencyInjectionContainer(): void
    {
        // Register services in container
        $this->container->bind('user.service', function () {
            return new class () {
                public function getUsers(): array
                {
                    return [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']];
                }

                public function getUser(int $id): ?array
                {
                    return ['id' => $id, 'name' => "User {$id}"];
                }
            };
        });

        // Register route that uses container
        $this->router->get('/api/users', function ($request) {
            $userService = $this->container->get('user.service');

            return ['users' => $userService->getUsers()];
        });

        $this->router->get('/api/users/{id}', function ($request) {
            $id = (int) $request->getAttribute('id');
            $userService = $this->container->get('user.service');

            return ['user' => $userService->getUser($id)];
        });

        // Test route execution with container integration
        $request = $this->createMockRequest('GET', '/api/users');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $result = $match->getHandler()($request);

        $this->assertArrayHasKey('users', $result);
        $this->assertCount(2, $result['users']);
    }

    #[Test]
    #[TestDox('Router handles complete middleware pipeline')]
    public function testRouterHandlesCompleteMiddlewarePipeline(): void
    {
        $executionOrder = [];

        // Global middleware
        $this->router->middleware(function ($request, $next) use (&$executionOrder) {
            $executionOrder[] = 'global-before';
            $response = $next($request);
            $executionOrder[] = 'global-after';

            return $response;
        });

        // Route-specific middleware
        $authMiddleware = function ($request, $next) use (&$executionOrder) {
            $executionOrder[] = 'auth-before';
            $response = $next($request);
            $executionOrder[] = 'auth-after';

            return $response;
        };

        $loggingMiddleware = function ($request, $next) use (&$executionOrder) {
            $executionOrder[] = 'logging-before';
            $response = $next($request);
            $executionOrder[] = 'logging-after';

            return $response;
        };

        $route = $this->router->get('/protected', function ($request) use (&$executionOrder) {
            $executionOrder[] = 'handler';

            return ['status' => 'success'];
        });

        $route->middleware([$authMiddleware, $loggingMiddleware]);

        // Execute request through middleware pipeline
        $request = $this->createMockRequest('GET', '/protected');
        $match = $this->router->match($request);

        $this->assertNotNull($match);

        // Simulate middleware execution
        $response = $this->executeMiddlewarePipeline($match, $request);

        $expectedOrder = [
            'global-before',
            'auth-before',
            'logging-before',
            'handler',
            'logging-after',
            'auth-after',
            'global-after',
        ];

        $this->assertEquals($expectedOrder, $executionOrder);
    }

    #[Test]
    #[TestDox('Router handles complex API routing scenarios')]
    public function testRouterHandlesComplexApiRoutingScenarios(): void
    {
        // Setup complex API routes
        $this->setupApiRoutes();

        // Test user listing
        $usersRequest = $this->createMockRequest('GET', '/api/v1/users');
        $usersMatch = $this->router->match($usersRequest);
        $this->assertNotNull($usersMatch);

        $usersResponse = $usersMatch->getHandler()($usersRequest);
        $this->assertArrayHasKey('users', $usersResponse);

        // Test user creation
        $createRequest = $this->createJsonRequest('POST', '/api/v1/users', [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $createMatch = $this->router->match($createRequest);
        $this->assertNotNull($createMatch);

        $createResponse = $createMatch->getHandler()($createRequest);
        $this->assertArrayHasKey('user', $createResponse);
        $this->assertEquals('John Doe', $createResponse['user']['name']);

        // Test user update
        $updateRequest = $this->createJsonRequest('PUT', '/api/v1/users/123', [
            'name' => 'Jane Doe',
        ]);
        $updateMatch = $this->router->match($updateRequest);
        $this->assertNotNull($updateMatch);
        $this->assertEquals(['id' => '123'], $updateMatch->getParameters());

        // Test nested resource
        $postsRequest = $this->createMockRequest('GET', '/api/v1/users/123/posts');
        $postsMatch = $this->router->match($postsRequest);
        $this->assertNotNull($postsMatch);
        $this->assertEquals(['userId' => '123'], $postsMatch->getParameters());
    }

    #[Test]
    #[TestDox('Router handles versioned API with backwards compatibility')]
    public function testRouterHandlesVersionedApiWithBackwardsCompatibility(): void
    {
        // Version 1 API
        $this->router->group('/api/v1', function ($router) {
            $router->get('/users', function ($request) {
                return [
                    'users' => [
                        ['id' => 1, 'name' => 'John'],
                        ['id' => 2, 'name' => 'Jane'],
                    ],
                    'version' => 'v1',
                ];
            });
        });

        // Version 2 API with enhanced response
        $this->router->group('/api/v2', function ($router) {
            $router->get('/users', function ($request) {
                return [
                    'data' => [
                        'users' => [
                            ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'],
                            ['id' => 2, 'name' => 'Jane', 'email' => 'jane@example.com'],
                        ],
                    ],
                    'meta' => [
                        'version' => 'v2',
                        'total'   => 2,
                    ],
                ];
            });
        });

        // Test v1 API
        $v1Request = $this->createMockRequest('GET', '/api/v1/users');
        $v1Match = $this->router->match($v1Request);
        $this->assertNotNull($v1Match);

        $v1Response = $v1Match->getHandler()($v1Request);
        $this->assertEquals('v1', $v1Response['version']);
        $this->assertArrayHasKey('users', $v1Response);

        // Test v2 API
        $v2Request = $this->createMockRequest('GET', '/api/v2/users');
        $v2Match = $this->router->match($v2Request);
        $this->assertNotNull($v2Match);

        $v2Response = $v2Match->getHandler()($v2Request);
        $this->assertEquals('v2', $v2Response['meta']['version']);
        $this->assertArrayHasKey('data', $v2Response);
    }

    #[Test]
    #[TestDox('Router handles content negotiation correctly')]
    public function testRouterHandlesContentNegotiationCorrectly(): void
    {
        $this->router->get('/api/data', function ($request) {
            $accept = $request->getHeaderLine('Accept');
            $data = ['message' => 'Hello World', 'timestamp' => time()];

            return match ($accept) {
                'application/xml' => $this->arrayToXml($data),
                'text/csv'        => $this->arrayToCsv($data),
                default           => $data // JSON
            };
        });

        // Test JSON response (default)
        $jsonRequest = $this->createMockRequest('GET', '/api/data', [
            'Accept' => 'application/json',
        ]);
        $jsonMatch = $this->router->match($jsonRequest);
        $jsonResponse = $jsonMatch->getHandler()($jsonRequest);
        $this->assertIsArray($jsonResponse);
        $this->assertArrayHasKey('message', $jsonResponse);

        // Test XML response
        $xmlRequest = $this->createMockRequest('GET', '/api/data', [
            'Accept' => 'application/xml',
        ]);
        $xmlMatch = $this->router->match($xmlRequest);
        $xmlResponse = $xmlMatch->getHandler()($xmlRequest);
        $this->assertStringContainsString('<message>', $xmlResponse);

        // Test CSV response
        $csvRequest = $this->createMockRequest('GET', '/api/data', [
            'Accept' => 'text/csv',
        ]);
        $csvMatch = $this->router->match($csvRequest);
        $csvResponse = $csvMatch->getHandler()($csvRequest);
        $this->assertStringContainsString('Hello World', $csvResponse);
    }

    #[Test]
    #[TestDox('Router handles rate limiting integration')]
    public function testRouterHandlesRateLimitingIntegration(): void
    {
        $rateLimiter = new class () {
            private array $requests = [];

            public function isAllowed(string $key, int $limit): bool
            {
                $now = time();
                $this->requests[$key] = array_filter(
                    $this->requests[$key] ?? [],
                    fn ($time) => $now - $time < 60 // 1 minute window
                );

                if (count($this->requests[$key]) >= $limit) {
                    return false;
                }

                $this->requests[$key][] = $now;

                return true;
            }
        };

        $this->container->instance('rate.limiter', $rateLimiter);

        $rateLimitMiddleware = function ($request, $next) {
            $rateLimiter = $this->container->get('rate.limiter');
            $clientIp = $request->getAttribute('client_ip', '127.0.0.1');

            if (!$rateLimiter->isAllowed($clientIp, 5)) { // 5 requests per minute
                throw new RuntimeException('Rate limit exceeded', 429);
            }

            return $next($request);
        };

        $route = $this->router->get('/api/limited', function ($request) {
            return ['message' => 'Success'];
        });
        $route->middleware($rateLimitMiddleware);

        // Test successful requests within limit
        for ($i = 0; $i < 5; $i++) {
            $request = $this->createMockRequest('GET', '/api/limited');
            $request->method('getAttribute')->willReturn('127.0.0.1');

            $match = $this->router->match($request);
            $this->assertNotNull($match);

            $response = $this->executeMiddlewarePipeline($match, $request);
            $this->assertArrayHasKey('message', $response);
        }

        // Test rate limit exceeded
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $request = $this->createMockRequest('GET', '/api/limited');
        $request->method('getAttribute')->willReturn('127.0.0.1');

        $match = $this->router->match($request);
        $this->executeMiddlewarePipeline($match, $request);
    }

    #[Test]
    #[TestDox('Router handles caching integration correctly')]
    public function testRouterHandlesCachingIntegrationCorrectly(): void
    {
        $cache = new class () {
            private array $data = [];

            public function get(string $key, $default = null)
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, $value, int $ttl = 3600): void
            {
                $this->data[$key] = $value;
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }
        };

        $this->container->instance('cache', $cache);

        $this->router->get('/api/cached-data', function ($request) {
            $cache = $this->container->get('cache');
            $cacheKey = 'expensive-data';

            if ($cache->has($cacheKey)) {
                return [
                    'data'   => $cache->get($cacheKey),
                    'cached' => true,
                ];
            }

            // Simulate expensive operation
            $expensiveData = ['result' => 'computed-' . time()];
            $cache->set($cacheKey, $expensiveData);

            return [
                'data'   => $expensiveData,
                'cached' => false,
            ];
        });

        // First request - should compute and cache
        $firstRequest = $this->createMockRequest('GET', '/api/cached-data');
        $firstMatch = $this->router->match($firstRequest);
        $firstResponse = $firstMatch->getHandler()($firstRequest);

        $this->assertFalse($firstResponse['cached']);
        $this->assertArrayHasKey('result', $firstResponse['data']);

        // Second request - should return cached result
        $secondRequest = $this->createMockRequest('GET', '/api/cached-data');
        $secondMatch = $this->router->match($secondRequest);
        $secondResponse = $secondMatch->getHandler()($secondRequest);

        $this->assertTrue($secondResponse['cached']);
        $this->assertEquals($firstResponse['data'], $secondResponse['data']);
    }

    #[Test]
    #[TestDox('Router handles database integration with transactions')]
    public function testRouterHandlesDatabaseIntegrationWithTransactions(): void
    {
        $database = new class () {
            private array $data = [];

            private bool $inTransaction = false;

            private array $transactionData = [];

            public function beginTransaction(): void
            {
                $this->inTransaction = true;
                $this->transactionData = $this->data;
            }

            public function commit(): void
            {
                $this->inTransaction = false;
                $this->transactionData = [];
            }

            public function rollback(): void
            {
                $this->data = $this->transactionData;
                $this->inTransaction = false;
            }

            public function insert(string $table, array $data): array
            {
                $id = uniqid();
                $record = array_merge(['id' => $id], $data);
                $this->data[$table][$id] = $record;

                return $record;
            }

            public function find(string $table, string $id): ?array
            {
                return $this->data[$table][$id] ?? null;
            }
        };

        $this->container->instance('database', $database);

        $this->router->post('/api/users-with-profile', function ($request) {
            $database = $this->container->get('database');
            $data = json_decode($request->getBody()->getContents(), true);

            try {
                $database->beginTransaction();

                // Create user
                $user = $database->insert('users', [
                    'name'  => $data['name'],
                    'email' => $data['email'],
                ]);

                // Create profile
                $profile = $database->insert('profiles', [
                    'user_id' => $user['id'],
                    'bio'     => $data['bio'] ?? '',
                ]);

                $database->commit();

                return [
                    'user'    => $user,
                    'profile' => $profile,
                ];
            } catch (Exception $e) {
                $database->rollback();

                throw $e;
            }
        });

        $request = $this->createJsonRequest('POST', '/api/users-with-profile', [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
            'bio'   => 'Software Developer',
        ]);

        $match = $this->router->match($request);
        $this->assertNotNull($match);

        $response = $match->getHandler()($request);

        $this->assertArrayHasKey('user', $response);
        $this->assertArrayHasKey('profile', $response);
        $this->assertEquals('John Doe', $response['user']['name']);
        $this->assertEquals('Software Developer', $response['profile']['bio']);
    }

    #[Test]
    #[TestDox('Router handles real-world application flow')]
    public function testRouterHandlesRealWorldApplicationFlow(): void
    {
        $this->setupRealWorldApplication();

        // Test authentication flow
        $loginRequest = $this->createJsonRequest('POST', '/auth/login', [
            'email'    => 'admin@example.com',
            'password' => 'password123',
        ]);

        $loginMatch = $this->router->match($loginRequest);
        $loginResponse = $loginMatch->getHandler()($loginRequest);

        $this->assertArrayHasKey('token', $loginResponse);
        $token = $loginResponse['token'];

        // Test protected resource access
        $dashboardRequest = $this->createMockRequest('GET', '/dashboard', [
            'Authorization' => "Bearer {$token}",
        ]);

        $dashboardMatch = $this->router->match($dashboardRequest);
        $dashboardResponse = $this->executeMiddlewarePipeline($dashboardMatch, $dashboardRequest);

        $this->assertArrayHasKey('data', $dashboardResponse);
        $this->assertEquals('dashboard-data', $dashboardResponse['data']);

        // Test file upload
        $uploadRequest = $this->createMockRequest('POST', '/api/files/upload', [
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'multipart/form-data',
        ]);

        $uploadMatch = $this->router->match($uploadRequest);
        $uploadResponse = $this->executeMiddlewarePipeline($uploadMatch, $uploadRequest);

        $this->assertArrayHasKey('file', $uploadResponse);
        $this->assertArrayHasKey('id', $uploadResponse['file']);
    }

    private function setupApiRoutes(): void
    {
        $this->router->group('/api/v1', function ($router) {
            // Users resource
            $router->get('/users', function ($request) {
                return ['users' => [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]];
            });

            $router->post('/users', function ($request) {
                $data = json_decode($request->getBody()->getContents(), true);

                return ['user' => array_merge(['id' => uniqid()], $data)];
            });

            $router->get('/users/{id}', function ($request) {
                $id = $request->getAttribute('id');

                return ['user' => ['id' => $id, 'name' => "User {$id}"]];
            });

            $router->put('/users/{id}', function ($request) {
                $id = $request->getAttribute('id');
                $data = json_decode($request->getBody()->getContents(), true);

                return ['user' => array_merge(['id' => $id], $data)];
            });

            $router->delete('/users/{id}', function ($request) {
                return ['deleted' => true];
            });

            // Nested resource - User posts
            $router->get('/users/{userId}/posts', function ($request) {
                $userId = $request->getAttribute('userId');

                return ['posts' => [['id' => 1, 'user_id' => $userId, 'title' => 'Post 1']]];
            });
        });
    }

    private function setupRealWorldApplication(): void
    {
        // Authentication routes
        $this->router->post('/auth/login', function ($request) {
            $data = json_decode($request->getBody()->getContents(), true);
            if ($data['email'] === 'admin@example.com' && $data['password'] === 'password123') {
                return ['token' => 'mock-jwt-token-' . time(), 'user' => ['id' => 1, 'email' => $data['email']]];
            }

            throw new RuntimeException('Invalid credentials', 401);
        });

        // Protected dashboard
        $authMiddleware = function ($request, $next) {
            $auth = $request->getHeaderLine('Authorization');
            if (!str_starts_with($auth, 'Bearer mock-jwt-token-')) {
                throw new RuntimeException('Unauthorized', 401);
            }

            return $next($request);
        };

        $dashboardRoute = $this->router->get('/dashboard', function ($request) {
            return ['data' => 'dashboard-data', 'user' => ['id' => 1, 'role' => 'admin']];
        });
        $dashboardRoute->middleware($authMiddleware);

        // File upload
        $uploadRoute = $this->router->post('/api/files/upload', function ($request) {
            return ['file' => ['id' => uniqid(), 'name' => 'uploaded-file.jpg', 'size' => 1024]];
        });
        $uploadRoute->middleware($authMiddleware);
    }

    private function executeMiddlewarePipeline($match, $request)
    {
        // Simple middleware execution simulation
        $handler = $match->getHandler();

        // Execute any middleware
        foreach ($match->route->middleware ?? [] as $middleware) {
            if (is_callable($middleware)) {
                $handler = function ($req) use ($middleware, $handler) {
                    return $middleware($req, $handler);
                };
            }
        }

        return $handler($request);
    }

    private function createMockRequest(string $method, string $uri, array $headers = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getMethod')->willReturn($method);

        $uriMock = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uriMock->method('getPath')->willReturn($uri);
        $request->method('getUri')->willReturn($uriMock);

        $request->method('getHeaderLine')
                ->willReturnCallback(function ($name) use ($headers) {
                    return $headers[$name] ?? '';
                });

        $request->method('getHeaders')->willReturn($headers);

        $bodyMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('');
        $request->method('getBody')->willReturn($bodyMock);

        $request->method('getAttribute')
                ->willReturnCallback(function ($name, $default = null) use ($uri) {
                    if (preg_match('#/(\w+)$#', $uri, $matches)) {
                        if ($name === 'id' || $name === 'userId' || $name === 'postId') {
                            return $matches[1];
                        }
                    }

                    return $default;
                });

        return $request;
    }

    private function createJsonRequest(string $method, string $uri, array $data): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getMethod')->willReturn($method);

        $uriMock = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uriMock->method('getPath')->willReturn($uri);
        $request->method('getUri')->willReturn($uriMock);

        $bodyMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $bodyMock->method('getContents')->willReturn(json_encode($data));
        $request->method('getBody')->willReturn($bodyMock);

        $request->method('getHeaderLine')
                ->willReturnCallback(function ($name) {
                    return $name === 'Content-Type' ? 'application/json' : '';
                });

        return $request;
    }

    private function arrayToXml(array $data): string
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n<response>\n";
        foreach ($data as $key => $value) {
            $xml .= "  <{$key}>{$value}</{$key}>\n";
        }
        $xml .= '</response>';

        return $xml;
    }

    private function arrayToCsv(array $data): string
    {
        $csv = implode(',', array_keys($data)) . "\n";
        $csv .= implode(',', array_values($data));

        return $csv;
    }
}

/**
 * Simple container class for testing purposes.
 */
class Container
{
    private array $bindings = [];

    private array $instances = [];

    public function bind(string $key, callable $factory): void
    {
        $this->bindings[$key] = $factory;
    }

    public function get(string $key): mixed
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->bindings[$key])) {
            return $this->bindings[$key]();
        }

        throw new RuntimeException("Service not found: {$key}");
    }

    public function instance(string $key, mixed $instance): void
    {
        $this->instances[$key] = $instance;
    }
}
