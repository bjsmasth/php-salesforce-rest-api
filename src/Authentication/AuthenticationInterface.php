<?php

namespace bjsmasth\Salesforce\Authentication;

interface AuthenticationInterface
{
    public function getAccessToken();

    public function getInstanceUrl();
}

?>