<?php

namespace bjsmasth\Salesforce\Authentication;

use bjsmasth\Salesforce\Exception\SalesforceAuthentication;
use GuzzleHttp\Client;

class PasswordAuthentication implements AuthenticationInterface
{
    protected $client;
    protected $endPoint;
    protected $options;
    protected $access_token;
    protected $instance_url;

    public function __construct(array $options)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->endPoint = 'https://login.salesforce.com/';
        $this->options = $options;
    }

    public function authenticate()
    {
        $client = new Client();

        $request = $client->request('post', "{$this->endPoint}services/oauth2/token", ['form_params' => $this->options]);
        $response = json_decode($request->getBody(), true);

        if ($response) {
            $this->access_token = $response['access_token'];
            $this->instance_url = $response['instance_url'];

            $_SESSION['salesforce'] = $response;
        } else {
            throw new SalesforceAuthentication($request->getBody());
        }
    }

    public function setEndpoint($endPoint)
    {
        $this->endPoint = $endPoint;
    }

    public function getAccessToken()
    {
        return $this->access_token;
    }

    public function getInstanceUrl()
    {
        return $this->instance_url;
    }
}

?>
