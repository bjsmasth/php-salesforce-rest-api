<?php

namespace bjsmasth\Salesforce\Authentication;

use bjsmasth\Salesforce\Exception\SalesforceAuthentication;
use GuzzleHttp\Client;

class WebAuthentication extends PasswordAuthentication implements AuthenticationInterface
{
    public function __construct(array $options)
    {
        parent::__construct($options);
    }

    public function authenticate()
    {
        $this->options['grant_type'] = 'authorization_code';

        $client = new Client();

        $request = $client->request('post', $this->endPoint . 'services/oauth2/token', ['form_params' => $this->options]);
        $status_code = $request->getStatusCode();

        $response = json_decode($request->getBody(), true);

        if ($status_code == 200) {
            $this->access_token = $response['access_token'];
            $this->instance_url = $response['instance_url'];

            $_SESSION['salesforce'] = $response;
        }

        return $status_code;
    }
}

?>