<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * A Guzzle client that answers with the given responses in order and records each request.
     */
    protected function mockClient(Response|\Throwable ...$responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    protected function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    protected function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    protected function queryParams(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $params);

        return $params;
    }
}
