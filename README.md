# Php Salesforce Rest Api

```Bijesh Shrestha``` ```bjsmasth``` ```bjsmasth@gmail.com``` ```bjsmasth``` ```php rest api```

## Install

Via Composer

``` bash
composer require bjsmasth/php-salesforce-rest-api
```

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

Password Authentication

```bash
    $options = [
        'client_id' => CONSUMERKEY,
        'client_secret' => CONSUMERSECRET,
        'username' => SALESFORCE_USERNAME,
        'password' => SALESFORCE_PASSWORD AND SECURITY_TOKEN
    ];
 
    $salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options);
    $salesforce->authenticate();
    
    $access_token = $salesforce->getAccessToken();
    $instance_url = $salesforce->getInstanceUrl();
    
    Change Endpoint
    
    $salesforce = new bjsmasth\Salesforce\Authentication\PasswordAuthentication($options);
    $salesforce->setEndpoint('https://test.salesforce.com/');
    $salesforce->authenticate();
 
    $access_token = $salesforce->getAccessToken();
    $instance_url = $salesforce->getInstanceUrl();
```
Oauth2/Web Authentication

1. Create file oauth.php and write the following code

```bash

define("LOGIN_URI", "https://login.salesforce.com");
define("CONSUMERKEY", CONSUMERKEY);
define("REDIRECT_URI", "https://login.salesforce.com");

$auth_url = LOGIN_URI
    . "/services/oauth2/authorize?response_type=code&client_id=".CONSUMERKEY."&redirect_uri=" . urlencode('".REDIRECT_URI."');

header('Location: ' . $auth_url);
```
2. Create file or route that match callback url and write the following code:
```bash
$code = $_GET['code'];

if (!isset($code) || $code == "") {
    die("Error - code parameter missing from request!");
}

$options = [
    'code' => $code,
    'client_id' => CONSUMERKEY,
    'client_secret' => CONSUMERSECRETE,
    'redirect_uri' => 'CALLBACK_URI
];

$authentication = new \bjsmasth\Salesforce\Authentication\WebAuthentication($options);

$response = $authentication->authenticate();

if ($response == 200) {
    header('Location: ' . 'someroute');
}
```

Query

```bash
    $query = 'SELECT Id,Name FROM ACCOUNT LIMIT 100';
    
    $crud = new \bjsmasth\Salesforce\CRUD();
    $crud->query($query);
```

Create

```bash
    
    $data = [
       'Name' => 'some name',
    ];
    
    $crud->create('Account', $data);  #returns id
```

Update

```bash
    $new_data = [
       'Name' => 'another name',
    ];
    
    $crud->update('Account', $id, $new_data); #returns status_code 204
    
```

Delete

```bash
    $crud->delete('Account', $id);

```