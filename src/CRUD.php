<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce;

use bjsmasth\Salesforce\Authentication\AuthenticationInterface;
use bjsmasth\Salesforce\Exception\Salesforce as SalesforceException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class CRUD
{
    public const DEFAULT_API_VERSION = '39.0';

    protected string $instance_url;
    protected string $access_token;
    protected ClientInterface $client;
    protected string $apiVersion;

    /**
     * With no arguments the instance URL and access token are read from $_SESSION['salesforce'],
     * which PasswordAuthentication::authenticate() fills in.
     */
    public function __construct(
        ?string $instanceUrl = null,
        ?string $accessToken = null,
        ?ClientInterface $client = null,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ) {
        $instanceUrl ??= $_SESSION['salesforce']['instance_url'] ?? null;
        $accessToken ??= $_SESSION['salesforce']['access_token'] ?? null;

        if ($instanceUrl === null || $accessToken === null) {
            throw new SalesforceException('Access Denied', 403);
        }

        $this->instance_url = rtrim($instanceUrl, '/');
        $this->access_token = $accessToken;
        $this->client = $client ?? new Client();
        $this->setApiVersion($apiVersion);
    }

    public static function fromAuthentication(
        AuthenticationInterface $auth,
        ?ClientInterface $client = null,
        string $apiVersion = self::DEFAULT_API_VERSION,
    ): self {
        $instanceUrl = $auth->getInstanceUrl();
        $accessToken = $auth->getAccessToken();

        if ($instanceUrl === null || $accessToken === null) {
            throw new SalesforceException('Not authenticated: call authenticate() first', 403);
        }

        return new static($instanceUrl, $accessToken, $client, $apiVersion);
    }

    public function setApiVersion(string $apiVersion): void
    {
        $this->apiVersion = ltrim($apiVersion, 'vV');
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Run a SOQL query. Returns the first page; use queryMore() or fetchAllRecords() for the rest.
     */
    public function query(string $query): array
    {
        return $this->decode($this->send('GET', $this->dataPath('query'), ['query' => ['q' => $query]]));
    }

    /**
     * Like query(), but also returns deleted and archived records.
     */
    public function queryAll(string $query): array
    {
        return $this->decode($this->send('GET', $this->dataPath('queryAll'), ['query' => ['q' => $query]]));
    }

    /**
     * Fetch the next page of a query, given the nextRecordsUrl from the previous page.
     */
    public function queryMore(string $nextRecordsUrl): array
    {
        return $this->decode($this->send('GET', $nextRecordsUrl));
    }

    /**
     * Run a SOQL query and follow every page, returning all records as one list.
     */
    public function fetchAllRecords(string $query, bool $includeDeleted = false): array
    {
        $page = $includeDeleted ? $this->queryAll($query) : $this->query($query);
        $records = $page['records'] ?? [];

        while (empty($page['done']) && !empty($page['nextRecordsUrl'])) {
            $page = $this->queryMore($page['nextRecordsUrl']);
            array_push($records, ...($page['records'] ?? []));
        }

        return $records;
    }

    /**
     * Run a SOSL search, e.g. "FIND {Acme} IN NAME FIELDS RETURNING Account(Id, Name)".
     */
    public function search(string $sosl): array
    {
        return $this->decode($this->send('GET', $this->dataPath('search'), ['query' => ['q' => $sosl]]));
    }

    /**
     * Retrieve one record by ID. Pass $fields to limit which fields are returned.
     */
    public function get(string $object, string $id, array $fields = []): array
    {
        $options = $fields ? ['query' => ['fields' => implode(',', $fields)]] : [];

        return $this->decode($this->send('GET', $this->sobjectPath($object, $id), $options));
    }

    public function create(string $object, array $data): string
    {
        $url = $this->sobjectPath($object) . '/';
        $request = $this->send('POST', $url, ['json' => $data]);

        $this->assertStatus($request, $url, 201);

        return $this->decode($request)['id'];
    }

    public function update(string $object, string $id, array $data): int
    {
        $url = $this->sobjectPath($object, $id);
        $request = $this->send('PATCH', $url, ['json' => $data]);

        return $this->assertStatus($request, $url, 204);
    }

    /**
     * Insert or update a record by external ID field. Returns 201 (created) or 204 (updated).
     */
    public function upsert(string $object, string $field, string $id, array $data): int
    {
        $url = $this->sobjectPath($object, $field, $id);
        $request = $this->send('PATCH', $url, ['json' => $data]);

        return $this->assertStatus($request, $url, 201, 204);
    }

    public function delete(string $object, string $id): bool
    {
        $url = $this->sobjectPath($object, $id);
        $request = $this->send('DELETE', $url);

        $this->assertStatus($request, $url, 204);

        return true;
    }

    /**
     * Fields, record types and other metadata for one object.
     */
    public function describe(string $object): array
    {
        return $this->decode($this->send('GET', $this->sobjectPath($object, 'describe')));
    }

    /**
     * The list of objects available in the org.
     */
    public function describeGlobal(): array
    {
        return $this->decode($this->send('GET', $this->dataPath('sobjects')));
    }

    /**
     * The org's API limits and how much of each has been used.
     */
    public function limits(): array
    {
        return $this->decode($this->send('GET', $this->dataPath('limits')));
    }

    protected function dataPath(string $resource): string
    {
        return "/services/data/v{$this->apiVersion}/{$resource}";
    }

    protected function sobjectPath(string $object, string ...$segments): string
    {
        $path = $this->dataPath('sobjects/' . rawurlencode($object));

        foreach ($segments as $segment) {
            $path .= '/' . rawurlencode($segment);
        }

        return $path;
    }

    protected function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $url = $this->instance_url . $path;
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => "OAuth {$this->access_token}",
            'Accept' => 'application/json',
        ];

        try {
            return $this->client->request($method, $url, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response === null) {
                throw new SalesforceException("Error: call to URL {$url} failed: {$e->getMessage()}", 0, $e);
            }

            $status = $response->getStatusCode();
            $errors = json_decode((string) $response->getBody(), true);
            $errors = is_array($errors) && array_is_list($errors) ? $errors : [];
            $messages = array_map(
                static fn ($error) => is_array($error)
                    ? trim(($error['errorCode'] ?? '') . ': ' . ($error['message'] ?? ''), ': ')
                    : (string) $error,
                $errors,
            );
            $detail = $messages ? implode('; ', $messages) : $response->getReasonPhrase();

            throw new SalesforceException(
                "Error: call to URL {$url} failed with status {$status}, response: {$detail}",
                $status,
                $e,
                $errors,
            );
        } catch (GuzzleException $e) {
            throw new SalesforceException("Error: call to URL {$url} failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new SalesforceException('Unexpected response from Salesforce: ' . substr($body, 0, 200));
        }

        return $data;
    }

    protected function assertStatus(ResponseInterface $response, string $path, int ...$expected): int
    {
        $status = $response->getStatusCode();

        if (!in_array($status, $expected, true)) {
            throw new SalesforceException(
                "Error: call to URL {$this->instance_url}{$path} failed with status {$status}, response: {$response->getReasonPhrase()}",
                $status,
            );
        }

        return $status;
    }
}
