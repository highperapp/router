<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Router\Tests\Unit;

use HighPerApp\HighPer\Router\RouteMatch;
use HighPerApp\HighPer\Router\Router;
use HighPerApp\HighPer\Router\Tests\MockRequest;
use PHPUnit\Framework\TestCase;

class QueryStringTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testBasicQueryStringSupport(): void
    {
        $this->router->defineRoute('search', [
            'path'         => '/search',
            'methods'      => ['GET'],
            'handler'      => 'SearchController@index',
            'query_params' => ['q', 'category', 'limit'],
        ]);

        $request = $this->createRequestWithQuery('GET', '/search', ['q' => 'test', 'category' => 'books']);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals(['q' => 'test', 'category' => 'books'], $match->getQueryParams());
    }

    public function testRequiredQueryParameters(): void
    {
        $this->router->defineRoute('api-search', [
            'path'         => '/api/search',
            'methods'      => ['GET'],
            'handler'      => 'ApiSearchController@index',
            'query_params' => [
                'required' => ['q', 'type'],
                'optional' => ['limit', 'offset'],
            ],
        ]);

        // Test with all required parameters
        $request = $this->createRequestWithQuery('GET', '/api/search', [
            'q'     => 'test',
            'type'  => 'product',
            'limit' => '10',
        ]);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Test missing required parameter
        $request = $this->createRequestWithQuery('GET', '/api/search', ['q' => 'test']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);

        // Test with empty required parameter
        $request = $this->createRequestWithQuery('GET', '/api/search', ['q' => '', 'type' => 'product']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);
    }

    public function testQueryParameterValidation(): void
    {
        $this->router->defineRoute('products', [
            'path'         => '/products',
            'methods'      => ['GET'],
            'handler'      => 'ProductController@index',
            'query_params' => [
                'optional'   => ['page', 'limit', 'sort', 'email', 'website'],
                'validation' => [
                    'page'    => 'int',
                    'limit'   => 'integer',
                    'sort'    => 'alpha',
                    'email'   => 'email',
                    'website' => 'url',
                ],
            ],
        ]);

        // Test valid parameters
        $request = $this->createRequestWithQuery('GET', '/products', [
            'page'    => '1',
            'limit'   => '20',
            'sort'    => 'name',
            'email'   => 'test@example.com',
            'website' => 'https://example.com',
        ]);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Test invalid integer
        $request = $this->createRequestWithQuery('GET', '/products', ['page' => 'invalid']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);

        // Test invalid email
        $request = $this->createRequestWithQuery('GET', '/products', ['email' => 'invalid-email']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);

        // Test invalid URL
        $request = $this->createRequestWithQuery('GET', '/products', ['website' => 'not-a-url']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);
    }

    public function testCustomQueryParameterValidation(): void
    {
        $this->router->defineRoute('custom-validation', [
            'path'         => '/custom',
            'methods'      => ['GET'],
            'handler'      => 'CustomController@index',
            'query_params' => [
                'optional'   => ['status'],
                'validation' => [
                    'status' => function ($value) {
                        return in_array($value, ['active', 'inactive', 'pending']);
                    },
                ],
            ],
        ]);

        // Test valid custom validation
        $request = $this->createRequestWithQuery('GET', '/custom', ['status' => 'active']);
        $match = $this->router->matchWithQuery($request);
        $this->assertInstanceOf(RouteMatch::class, $match);

        // Test invalid custom validation
        $request = $this->createRequestWithQuery('GET', '/custom', ['status' => 'invalid']);
        $match = $this->router->matchWithQuery($request);
        $this->assertNull($match);
    }

    public function testQueryStringWithPathParameters(): void
    {
        $this->router->defineRoute('user-posts', [
            'path'         => '/users/{userId}/posts',
            'methods'      => ['GET'],
            'handler'      => 'UserPostController@index',
            'constraints'  => ['userId' => 'int'],
            'query_params' => [
                'optional'   => ['page', 'limit'],
                'validation' => ['page' => 'int', 'limit' => 'int'],
            ],
        ]);

        $request = $this->createRequestWithQuery('GET', '/users/123/posts', [
            'page'  => '1',
            'limit' => '10',
        ]);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);

        // Get path parameters (excluding __query)
        $pathParams = $match->getParameters();
        unset($pathParams['__query']);
        $this->assertEquals(['userId' => '123'], $pathParams);
        $this->assertEquals(['page' => '1', 'limit' => '10'], $match->getQueryParams());
    }

    public function testQueryStringHelperMethods(): void
    {
        $this->router->defineRoute('test-helpers', [
            'path'         => '/test',
            'methods'      => ['GET'],
            'handler'      => 'TestController@index',
            'query_params' => ['param1', 'param2'],
        ]);

        $request = $this->createRequestWithQuery('GET', '/test', [
            'param1' => 'value1',
            'param2' => 'value2',
        ]);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);

        // Test hasQueryParam
        $this->assertTrue($match->hasQueryParam('param1'));
        $this->assertTrue($match->hasQueryParam('param2'));
        $this->assertFalse($match->hasQueryParam('nonexistent'));

        // Test getQueryParam
        $this->assertEquals('value1', $match->getQueryParam('param1'));
        $this->assertEquals('value2', $match->getQueryParam('param2'));
        $this->assertNull($match->getQueryParam('nonexistent'));
        $this->assertEquals('default', $match->getQueryParam('nonexistent', 'default'));

        // Test getQueryParams
        $this->assertEquals(['param1' => 'value1', 'param2' => 'value2'], $match->getQueryParams());
    }

    public function testMatchWithQueryFallsBackToNormalMatch(): void
    {
        // Route without query string configuration
        $this->router->get('/normal', 'NormalController@index');

        $request = $this->createRequestWithQuery('GET', '/normal', ['some' => 'param']);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals([], $match->getQueryParams());
    }

    public function testQueryStringWithArrayParameters(): void
    {
        $this->router->defineRoute('filter-test', [
            'path'         => '/filter',
            'methods'      => ['GET'],
            'handler'      => 'FilterController@index',
            'query_params' => ['tags', 'categories'],
        ]);

        // Test with array parameters (tags[]=tag1&tags[]=tag2)
        $request = $this->createRequestWithQuery('GET', '/filter', [
            'tags'       => ['tag1', 'tag2'],
            'categories' => ['cat1', 'cat2'],
        ]);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $queryParams = $match->getQueryParams();
        $this->assertEquals(['tag1', 'tag2'], $queryParams['tags']);
        $this->assertEquals(['cat1', 'cat2'], $queryParams['categories']);
    }

    public function testEmptyQueryStringHandling(): void
    {
        $this->router->defineRoute('empty-query', [
            'path'         => '/empty',
            'methods'      => ['GET'],
            'handler'      => 'EmptyController@index',
            'query_params' => ['optional' => ['param']],
        ]);

        // Test with empty query string
        $request = $this->createRequestWithQuery('GET', '/empty', []);
        $match = $this->router->matchWithQuery($request);

        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertEquals([], $match->getQueryParams());
    }

    public function testQueryStringPerformance(): void
    {
        // Routes with query string support
        $this->router->defineRoute('with-query', [
            'path'         => '/with-query',
            'methods'      => ['GET'],
            'handler'      => 'WithQueryController@index',
            'query_params' => ['param1', 'param2'],
        ]);

        // Routes without query string support
        $this->router->get('/without-query', 'WithoutQueryController@index');

        // Test performance difference is minimal
        $iterations = 1000;

        // Test with query string support
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $request = $this->createRequestWithQuery('GET', '/with-query', ['param1' => 'value1']);
            $match = $this->router->matchWithQuery($request);
        }
        $withQueryTime = microtime(true) - $startTime;

        // Test without query string support
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $request = $this->createRequestWithQuery('GET', '/without-query', ['param1' => 'value1']);
            $match = $this->router->match($request);
        }
        $withoutQueryTime = microtime(true) - $startTime;

        // Performance overhead should be minimal (less than 100% increase)
        $overhead = ($withQueryTime - $withoutQueryTime) / $withoutQueryTime;
        $this->assertLessThan(1.0, $overhead, 'Query string support should not significantly impact performance');
    }

    private function createRequestWithQuery(string $method, string $path, array $queryParams = []): MockRequest
    {
        return MockRequest::createWithQuery($method, $path, $queryParams);
    }
}
