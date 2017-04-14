<?php

define("DEBUG", true);

if (DEBUG) {
	error_reporting(E_ALL);
}

// load libraries
require_once("vendor/autoload.php");
use \Github\Client;

if(!is_file("config.json")) {
	die("You have not created a config.json file yet.\n");
}

require_once("config.php");

class MaterialBot {
    private $client;
	private $repository;
    private $openIssues, $closedIssues;

	public function __construct($repository) {
        $this->repository = $repository;

		$this->client = new Client();

		// login
		$authentication = json_decode(file_get_contents("config.json"), true);
		$this->client->authenticate($authentication["username"], $authentication["password"], Client::AUTH_HTTP_PASSWORD);

		$this->refreshIssues();

        if (DEBUG) {
            echo "\$this->client at ".__LINE__." in ".__FILE__."\n";
            var_dump($this->client);
        }
	}

	protected function refreshIssues(){
        $this->closedIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "closed"));
        $this->openIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "open"));
	}
}

$bot = new MaterialBot($repository);
