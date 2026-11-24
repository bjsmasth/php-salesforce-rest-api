<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce\Tests;

use bjsmasth\Salesforce\Authentication\PasswordAuthentication;
use bjsmasth\Salesforce\Exception\Salesforce as SalesforceException;
use bjsmasth\Salesforce\Exception\SalesforceAuthentication;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class PasswordAuthenticationTest extends TestCase
{
    private const OPTIONS = [
        'grant_type' => 'password',
        'client_id' => 'key',
        'client_secret' => 'secret',
        'username' => 'user@example.com',
        'password' => 'passTOKEN',
    ];

    private const TOKEN_RESPONSE = [
        'access_token' => 'access123',
        'instance_url' => 'https://example.my.salesforce.com',
        'id' => 'https://login.salesforce.com/id/00D/005',
        'token_type' => 'Bearer',
    ];

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    private function auth(Response|\Throwable $response): PasswordAuthentication
    {
        return new PasswordAuthentication(self::OPTIONS, $this->mockClient($response), useSession: false);
    }

    public function testAuthenticate(): void
    {
        $auth = $this->auth($this->json(200, self::TOKEN_RESPONSE));
        $auth->authenticate();

        $this->assertSame('access123', $auth->getAccessToken());
        $this->assertSame('https://example.my.salesforce.com', $auth->getInstanceUrl());
        $this->assertSame(self::TOKEN_RESPONSE, $auth->getResponse());

        $request = $this->request();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://login.salesforce.com/services/oauth2/token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $sent);
        $this->assertSame(self::OPTIONS, $sent);
    }

    public function testCustomEndpoint(): void
    {
        $auth = $this->auth($this->json(200, self::TOKEN_RESPONSE));
        $auth->setEndpoint('https://test.salesforce.com');
        $auth->authenticate();

        $this->assertSame('https://test.salesforce.com/services/oauth2/token', (string) $this->request()->getUri());
    }

    public function testSessionIsNotTouchedWhenDisabled(): void
    {
        unset($_SESSION);

        $this->auth($this->json(200, self::TOKEN_RESPONSE))->authenticate();

        $this->assertFalse(isset($_SESSION));
    }

    public function testFailedLoginThrowsWithSalesforceMessage(): void
    {
        $auth = $this->auth($this->json(400, [
            'error' => 'invalid_grant',
            'error_description' => 'authentication failure',
        ]));

        try {
            $auth->authenticate();
            $this->fail('Expected exception');
        } catch (SalesforceAuthentication $e) {
            $this->assertSame('authentication failure', $e->getMessage());
            $this->assertSame(400, $e->getCode());
            $this->assertInstanceOf(SalesforceException::class, $e);
        }

        $this->assertNull($auth->getAccessToken());
    }

    public function testNonJsonResponseThrows(): void
    {
        $auth = $this->auth(new Response(503, [], 'Service Unavailable'));

        $this->expectException(SalesforceAuthentication::class);
        $this->expectExceptionMessage('Service Unavailable');

        $auth->authenticate();
    }

    public function testConnectionErrorThrows(): void
    {
        $auth = $this->auth(new ConnectException('Could not resolve host', new Request('POST', 'https://login.salesforce.com')));

        $this->expectException(SalesforceAuthentication::class);
        $this->expectExceptionMessage('Could not resolve host');

        $auth->authenticate();
    }
}
