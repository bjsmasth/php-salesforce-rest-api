<?php

declare(strict_types=1);

namespace bjsmasth\Salesforce\Tests;

use bjsmasth\Salesforce\Authentication\AuthenticationInterface;
use bjsmasth\Salesforce\CRUD;
use bjsmasth\Salesforce\Exception\Salesforce as SalesforceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class CRUDTest extends TestCase
{
    private const URL = 'https://example.my.salesforce.com';
    private const TOKEN = 'token123';

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    private function crud(Response|\Throwable ...$responses): CRUD
    {
        return new CRUD(self::URL, self::TOKEN, $this->mockClient(...$responses));
    }

    public function testConstructorThrowsWithoutCredentials(): void
    {
        unset($_SESSION);

        $this->expectException(SalesforceException::class);
        $this->expectExceptionCode(403);

        new CRUD();
    }

    public function testConstructorReadsCredentialsFromSession(): void
    {
        $_SESSION = ['salesforce' => ['instance_url' => self::URL . '/', 'access_token' => 'from-session']];

        $crud = new CRUD(client: $this->mockClient($this->json(200, ['records' => []])));
        $crud->query('SELECT Id FROM Account');

        $this->assertSame('OAuth from-session', $this->request()->getHeaderLine('Authorization'));
        $this->assertSame('example.my.salesforce.com', $this->request()->getUri()->getHost());
        $this->assertSame('/services/data/v39.0/query', $this->request()->getUri()->getPath());
    }

    public function testFromAuthentication(): void
    {
        $auth = new class implements AuthenticationInterface {
            public function getAccessToken(): ?string
            {
                return 'auth-token';
            }

            public function getInstanceUrl(): ?string
            {
                return 'https://auth.my.salesforce.com';
            }
        };

        $crud = CRUD::fromAuthentication($auth, $this->mockClient($this->json(200, [])), '60.0');
        $crud->limits();

        $this->assertSame('OAuth auth-token', $this->request()->getHeaderLine('Authorization'));
        $this->assertSame('https://auth.my.salesforce.com/services/data/v60.0/limits', (string) $this->request()->getUri());
    }

    public function testFromAuthenticationRequiresToken(): void
    {
        $auth = new class implements AuthenticationInterface {
            public function getAccessToken(): ?string
            {
                return null;
            }

            public function getInstanceUrl(): ?string
            {
                return null;
            }
        };

        $this->expectException(SalesforceException::class);

        CRUD::fromAuthentication($auth);
    }

    public function testApiVersionAcceptsLeadingV(): void
    {
        $crud = $this->crud($this->json(200, []));
        $crud->setApiVersion('v62.0');
        $crud->describeGlobal();

        $this->assertSame('62.0', $crud->getApiVersion());
        $this->assertSame('/services/data/v62.0/sobjects', $this->request()->getUri()->getPath());
    }

    public function testQuery(): void
    {
        $result = ['totalSize' => 1, 'done' => true, 'records' => [['Id' => '001']]];

        $crud = $this->crud($this->json(200, $result));

        $this->assertSame($result, $crud->query('SELECT Id FROM Account'));
        $this->assertSame('GET', $this->request()->getMethod());
        $this->assertSame('/services/data/v39.0/query', $this->request()->getUri()->getPath());
        $this->assertSame(['q' => 'SELECT Id FROM Account'], $this->queryParams($this->request()));
        $this->assertSame('OAuth ' . self::TOKEN, $this->request()->getHeaderLine('Authorization'));
    }

    public function testQueryAllUsesQueryAllEndpoint(): void
    {
        $crud = $this->crud($this->json(200, ['records' => []]));
        $crud->queryAll('SELECT Id FROM Account');

        $this->assertSame('/services/data/v39.0/queryAll', $this->request()->getUri()->getPath());
    }

    public function testQueryMore(): void
    {
        $crud = $this->crud($this->json(200, ['done' => true, 'records' => []]));
        $crud->queryMore('/services/data/v39.0/query/01gD0000002HU6KIAW-2000');

        $this->assertSame(
            self::URL . '/services/data/v39.0/query/01gD0000002HU6KIAW-2000',
            (string) $this->request()->getUri(),
        );
    }

    public function testFetchAllRecordsFollowsEveryPage(): void
    {
        $crud = $this->crud(
            $this->json(200, ['done' => false, 'nextRecordsUrl' => '/next-1', 'records' => [['Id' => '1'], ['Id' => '2']]]),
            $this->json(200, ['done' => false, 'nextRecordsUrl' => '/next-2', 'records' => [['Id' => '3']]]),
            $this->json(200, ['done' => true, 'records' => [['Id' => '4']]]),
        );

        $records = $crud->fetchAllRecords('SELECT Id FROM Account');

        $this->assertSame(['1', '2', '3', '4'], array_column($records, 'Id'));
        $this->assertCount(3, $this->history);
        $this->assertSame('/next-1', $this->request(1)->getUri()->getPath());
        $this->assertSame('/next-2', $this->request(2)->getUri()->getPath());
    }

    public function testFetchAllRecordsCanIncludeDeleted(): void
    {
        $crud = $this->crud($this->json(200, ['done' => true, 'records' => []]));
        $crud->fetchAllRecords('SELECT Id FROM Account', includeDeleted: true);

        $this->assertSame('/services/data/v39.0/queryAll', $this->request()->getUri()->getPath());
    }

    public function testSearch(): void
    {
        $crud = $this->crud($this->json(200, ['searchRecords' => []]));
        $crud->search('FIND {Acme}');

        $this->assertSame('/services/data/v39.0/search', $this->request()->getUri()->getPath());
        $this->assertSame(['q' => 'FIND {Acme}'], $this->queryParams($this->request()));
    }

    public function testGet(): void
    {
        $record = ['Id' => '001', 'Name' => 'Acme'];
        $crud = $this->crud($this->json(200, $record));

        $this->assertSame($record, $crud->get('Account', '001', ['Id', 'Name']));
        $this->assertSame('/services/data/v39.0/sobjects/Account/001', $this->request()->getUri()->getPath());
        $this->assertSame(['fields' => 'Id,Name'], $this->queryParams($this->request()));
    }

    public function testGetWithoutFieldsSendsNoQueryString(): void
    {
        $crud = $this->crud($this->json(200, ['Id' => '001']));
        $crud->get('Account', '001');

        $this->assertSame('', $this->request()->getUri()->getQuery());
    }

    public function testCreate(): void
    {
        $crud = $this->crud($this->json(201, ['id' => '001NEW', 'success' => true, 'errors' => []]));

        $this->assertSame('001NEW', $crud->create('Account', ['Name' => 'Acme']));
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/services/data/v39.0/sobjects/Account/', $this->request()->getUri()->getPath());
        $this->assertSame(['Name' => 'Acme'], json_decode((string) $this->request()->getBody(), true));
        $this->assertSame('application/json', $this->request()->getHeaderLine('Content-Type'));
    }

    public function testCreateThrowsOnUnexpectedStatus(): void
    {
        $crud = $this->crud($this->json(200, ['id' => '001']));

        $this->expectException(SalesforceException::class);
        $this->expectExceptionCode(200);

        $crud->create('Account', ['Name' => 'Acme']);
    }

    public function testUpdate(): void
    {
        $crud = $this->crud(new Response(204));

        $this->assertSame(204, $crud->update('Account', '001', ['Name' => 'New']));
        $this->assertSame('PATCH', $this->request()->getMethod());
        $this->assertSame('/services/data/v39.0/sobjects/Account/001', $this->request()->getUri()->getPath());
        $this->assertSame(['Name' => 'New'], json_decode((string) $this->request()->getBody(), true));
    }

    public function testUpsertReturnsCreatedOrUpdated(): void
    {
        $crud = $this->crud($this->json(201, ['id' => '001']), new Response(204));

        $this->assertSame(201, $crud->upsert('Account', 'External_Id__c', 'ABC', ['Name' => 'Acme']));
        $this->assertSame(204, $crud->upsert('Account', 'External_Id__c', 'ABC', ['Name' => 'Acme']));
        $this->assertSame(
            '/services/data/v39.0/sobjects/Account/External_Id__c/ABC',
            $this->request()->getUri()->getPath(),
        );
    }

    public function testPathSegmentsAreEncoded(): void
    {
        $crud = $this->crud(new Response(204));
        $crud->upsert('Account', 'External_Id__c', 'A B/C', []);

        $this->assertSame(
            '/services/data/v39.0/sobjects/Account/External_Id__c/A%20B%2FC',
            $this->request()->getUri()->getPath(),
        );
    }

    public function testDelete(): void
    {
        $crud = $this->crud(new Response(204));

        $this->assertTrue($crud->delete('Account', '001'));
        $this->assertSame('DELETE', $this->request()->getMethod());
        $this->assertSame('/services/data/v39.0/sobjects/Account/001', $this->request()->getUri()->getPath());
    }

    public function testDescribe(): void
    {
        $crud = $this->crud($this->json(200, ['name' => 'Account', 'fields' => []]));

        $this->assertSame('Account', $crud->describe('Account')['name']);
        $this->assertSame('/services/data/v39.0/sobjects/Account/describe', $this->request()->getUri()->getPath());
    }

    public function testDescribeGlobal(): void
    {
        $crud = $this->crud($this->json(200, ['sobjects' => [['name' => 'Account']]]));

        $this->assertSame('Account', $crud->describeGlobal()['sobjects'][0]['name']);
        $this->assertSame('/services/data/v39.0/sobjects', $this->request()->getUri()->getPath());
    }

    public function testLimits(): void
    {
        $crud = $this->crud($this->json(200, ['DailyApiRequests' => ['Max' => 15000, 'Remaining' => 14998]]));

        $this->assertSame(15000, $crud->limits()['DailyApiRequests']['Max']);
        $this->assertSame('/services/data/v39.0/limits', $this->request()->getUri()->getPath());
    }

    public function testSalesforceErrorsAreWrapped(): void
    {
        $errors = [['message' => 'The requested resource does not exist', 'errorCode' => 'NOT_FOUND']];
        $crud = $this->crud($this->json(404, $errors));

        try {
            $crud->get('Account', 'missing');
            $this->fail('Expected exception');
        } catch (SalesforceException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame($errors, $e->getErrors());
            $this->assertStringContainsString('NOT_FOUND: The requested resource does not exist', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testErrorWithNonJsonBodyUsesReasonPhrase(): void
    {
        $crud = $this->crud(new Response(500, [], 'oops'));

        try {
            $crud->limits();
            $this->fail('Expected exception');
        } catch (SalesforceException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame([], $e->getErrors());
            $this->assertStringContainsString('Internal Server Error', $e->getMessage());
        }
    }

    public function testConnectionErrorsAreWrapped(): void
    {
        $crud = $this->crud(new ConnectException('Connection refused', new Request('GET', self::URL)));

        $this->expectException(SalesforceException::class);
        $this->expectExceptionMessage('Connection refused');

        $crud->limits();
    }

    public function testInvalidJsonThrows(): void
    {
        $crud = $this->crud(new Response(200, [], 'not json'));

        $this->expectException(SalesforceException::class);
        $this->expectExceptionMessage('Unexpected response');

        $crud->query('SELECT Id FROM Account');
    }
}
