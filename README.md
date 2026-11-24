# Php Salesforce Rest Api

```Bijesh Shrestha``` ```bjsmasth``` ```bjsmasth@gmail.com``` ```bjsmasth``` ```php rest api```

## Install

Via Composer

``` bash
composer require bjsmasth/php-salesforce-rest-api
```

## Requirements

- PHP 8.2 or newer
- Guzzle 7 (`guzzlehttp/guzzle` ^7.8, installed automatically)

Need PHP 5.5–8.1 or Guzzle 6? Stay on the 0.x releases (`composer require bjsmasth/php-salesforce-rest-api:^0.2`).

### Upgrading from 0.x

- The library now uses `declare(strict_types=1)` and typed signatures. Pass record IDs, object names and field names as strings.
- If you implement `AuthenticationInterface` yourself, `getAccessToken()` and `getInstanceUrl()` must now return `?string`.
- Failed API calls now throw `bjsmasth\Salesforce\Exception\Salesforce` (with Salesforce's error message) instead of Guzzle's `ClientException`/`ServerException`. Failed logins throw `SalesforceAuthentication`.

# Getting Started

Setting up a Connected App

1. Log into to your Salesforce org
2. Click on Setup in the upper right-hand menu
3. Under Build click ```Create > Apps ```
4. Scroll to the bottom and click ```New``` under Connected Apps.
5. Enter the following details for the remote application:
    - Connected App Name
    - API Name
    - Contact Email
    - Enable OAuth Settings under the API dropdown
    - Callback URL
    - Select access scope (If you need a refresh token, specify it here)
6. Click Save

After saving, you will now be given a Consumer Key and Consumer Secret. Update your config file with values for ```consumerKey``` and ```consumerSecret```

# Setup

Authentication

```php
$options = [
'grant_type' => 'password',
'client_id' => 'CONSUMERKEY',
'client_secret' => 'CONSUMERSECRET',
'username' => 'SALESFORCE_USERNAME',
'password' => 'SALESFORCE_PASSWORD AND SECURITY_TOKEN'
];

$salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options);
$salesforce->authenticate();

$access_token = $salesforce->getAccessToken();
$instance_url = $salesforce->getInstanceUrl();
```

Change Endpoint (e.g. for a sandbox)

```php
$salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options);
$salesforce->setEndpoint('https://test.salesforce.com/');
$salesforce->authenticate();

$access_token = $salesforce->getAccessToken();
$instance_url = $salesforce->getInstanceUrl();
```

Without sessions

`new CRUD()` reads the token from `$_SESSION`. To skip the session (CLI scripts, queues, APIs), pass the credentials directly:

```php
$salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options, useSession: false);
$salesforce->authenticate();

$crud = bjsmasth\Salesforce\CRUD::fromAuthentication($salesforce);

// or, with a token you already have
$crud = new bjsmasth\Salesforce\CRUD($instance_url, $access_token);
```

API version

Requests use API version `39.0` by default. To use a newer one:

```php
$crud = new bjsmasth\Salesforce\CRUD($instance_url, $access_token, apiVersion: '62.0');
// or
$crud->setApiVersion('62.0');
```

Query

```php
$query = 'SELECT Id,Name FROM ACCOUNT LIMIT 100';

$crud = new \bjsmasth\Salesforce\CRUD();
$result = $crud->query($query);
```

Create

```php
$data = [
    'Name' => 'some name',
];

$crud->create('Account', $data);  // returns id
```

Update

```php
$new_data = [
    'Name' => 'another name',
];

$crud->update('Account', $id, $new_data); // returns status_code 204
```

Upsert

```php
$new_data = [
    'Name' => 'another name',
];

$crud->upsert('Account', 'API Name/ Field Name', 'value', $new_data); // returns status_code 204 or 201
```

Delete

```php
$crud->delete('Account', $id);
```

Get a record

```php
$account = $crud->get('Account', $id);                   // all fields
$account = $crud->get('Account', $id, ['Id', 'Name']);   // only these fields
```

Query: more pages, deleted records

```php
// query() returns the first page (up to 2000 records)
$page = $crud->query('SELECT Id, Name FROM Account');

while (!$page['done']) {
    $page = $crud->queryMore($page['nextRecordsUrl']);
}

// or get every record in one call
$records = $crud->fetchAllRecords('SELECT Id, Name FROM Account');

// include deleted and archived records
$result = $crud->queryAll('SELECT Id FROM Account WHERE IsDeleted = true');
$records = $crud->fetchAllRecords('SELECT Id FROM Account', includeDeleted: true);
```

Search (SOSL)

```php
$result = $crud->search('FIND {Acme} IN NAME FIELDS RETURNING Account(Id, Name)');
```

Describe and limits

```php
$objects = $crud->describeGlobal();    // all objects in the org
$account = $crud->describe('Account'); // fields, record types, ...
$limits  = $crud->limits();            // API usage, e.g. $limits['DailyApiRequests']['Remaining']
```

Error handling

```php
use bjsmasth\Salesforce\Exception\Salesforce as SalesforceException;

try {
    $crud->get('Account', $id);
} catch (SalesforceException $e) {
    $e->getCode();    // HTTP status, e.g. 404
    $e->getMessage(); // includes Salesforce's error code and message
    $e->getErrors();  // raw errors, e.g. [['errorCode' => 'NOT_FOUND', 'message' => '...']]
}
```

Custom HTTP client

Both `PasswordAuthentication` and `CRUD` accept any Guzzle `ClientInterface`, e.g. to set a proxy or timeout:

```php
$client = new GuzzleHttp\Client(['timeout' => 30]);

$salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options, $client);
$crud = new bjsmasth\Salesforce\CRUD($instance_url, $access_token, $client);
```

## Testing

```bash
composer install
composer test
```
