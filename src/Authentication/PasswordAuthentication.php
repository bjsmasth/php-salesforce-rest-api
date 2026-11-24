<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce\Authentication;

use bjsmasth\Salesforce\Exception\SalesforceAuthentication;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class PasswordAuthentication implements AuthenticationInterface
{
    protected ClientInterface $client;
    protected string $endPoint = 'https://login.salesforce.com/';
    protected ?string $access_token = null;
    protected ?string $instance_url = null;
    protected array $response = [];

    /**
     * @param array           $options    OAuth parameters (grant_type, client_id, client_secret, username, password).
     * @param ClientInterface $client     Optional HTTP client, e.g. for proxies or testing.
     * @param bool            $useSession Store the token in $_SESSION['salesforce'] (default, needed by `new CRUD()`).
     */
    public function __construct(
        protected array $options,
        ?ClientInterface $client = null,
        protected bool $useSession = true,
    ) {
        $this->client = $client ?? new Client();

        if ($this->useSession && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function authenticate(): void
    {
        $url = rtrim($this->endPoint, '/') . '/services/oauth2/token';

        try {
            $request = $this->client->request('POST', $url, [
                'form_params' => $this->options,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new SalesforceAuthentication("Authentication request to {$url} failed: {$e->getMessage()}", 0, $e);
        }

        $body = (string) $request->getBody();
        $response = json_decode($body, true);

        if (!is_array($response) || !isset($response['access_token'], $response['instance_url'])) {
            $message = is_array($response)
                ? ($response['error_description'] ?? $response['error'] ?? $body)
                : $body;

            throw new SalesforceAuthentication((string) $message, $request->getStatusCode());
        }

        $this->response = $response;
        $this->access_token = $response['access_token'];
        $this->instance_url = $response['instance_url'];

        if ($this->useSession) {
            $_SESSION['salesforce'] = $response;
        }
    }

    public function setEndpoint(string $endPoint): void
    {
        $this->endPoint = $endPoint;
    }

    public function getAccessToken(): ?string
    {
        return $this->access_token;
    }

    public function getInstanceUrl(): ?string
    {
        return $this->instance_url;
    }

    /**
     * The full token response from Salesforce (id, issued_at, signature, ...).
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}
