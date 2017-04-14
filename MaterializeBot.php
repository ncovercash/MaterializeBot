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
    protected $client;
	public $repository;
    protected $openIssues, $closedIssues;

    protected $highestAnalyzedIssueNumber=0;

	public function __construct($repository) {
        $this->repository = $repository;

		$this->client = new Client();

        $this->login();

		$this->refreshIssues();

        $this->updateIssueCounter();

        $this->run();
	}

    protected function login() {
        // login
        $authentication = json_decode(file_get_contents("config.json"), true);
        $this->client->authenticate($authentication["username"], $authentication["password"], Client::AUTH_HTTP_PASSWORD);
    }

	protected function refreshIssues() {
            $this->closedIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "closed"));
            $this->openIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "open"));
	}

    protected function run() {
        while (true) {
            $this->refreshIssues();

            $this->highestAnalyzedIssueNumber = 1;

            $this->analyzeOpenIssues();
            
            sleep(20);
        }
    }

    public function getUnanalyzedIssues() : array {
        // TODO: I know there is a way better way to do this
        // that uses a for/while loop instead
        return array_filter($this->openIssues, function(array $issue) {
            return $this->highestAnalyzedIssueNumber < $issue["number"];
        });
    }

    public static function getHTMLErrors(string $in) : array {
        $tidy = new Tidy();
        $tidy->parseString($in);
        return explode("\n", $tidy->errorBuffer);
    }

    public static function getHTMLBodyErrors(string $in) : array {
        return self::getHTMLErrors("<!DOCTYPE html><head><title>null</title></head><body>".$in."</body></html>");
    }

    protected function analyzeOpenIssues() {
        $issues = $this->getUnanalyzedIssues();

        foreach ($issues as $issue) {
            $hasIssues = false;

            $statement  = "@".$issue["user"]["login"].",  \n";
            $statement .= "Thank you for creating an issue!  \n\n";

	    // TODO Make a function for each site / separate this into sites / make helper classes for each site
            if (preg_match_all("/http(s|)\:\/\/(www\.|)codepen\.io\/[a-zA-Z0-9]+\/(pen|details|full|pres)\/[a-zA-Z0-9]+/", $issue["body"], $codepens)) {
                $links = $codepens[0];

                if (count($links) == 1) {
			
		    // TODO There's no need for a message if there aren't any issues, no? 
                    $statement .= "Your codepen at ".$links[0]." is greatly appreciated!  \n";

                    $errors = self::getHTMLBodyErrors(file_get_contents($links[0].".html"));

                    if (count($errors) > 0) {
                        $statement .= "However, it has a few issues, some of which may be not applicable:  \n";
                        $hasIssues = true;
                    }
                    foreach ($errors as $error) {
                        $statement .= "* ".$error."  \n";
                    }

                    $statement .= "  \n";
                } else {
                    $statement .= "Your codepens ";
                    foreach ($links as $link) {
                        $statement .= $link." ";
                    }
                    $statement .= "are greatly appreciated!  \n";
                    $i = 1;

                    $codepensHaveErrors = false;

                    foreach ($links as $link) {
                        $errors = self::getHTMLBodyErrors(file_get_contents($link.".html"));
                        if (count($errors) > 0) {
                            $codepensHaveErrors = true;
                            $hasIssues = true;
                        }
                    }

                    if ($codepensHaveErrors) {
                        $statement .= "However, they have a few issues, some of which may be not applicable:  \n";
                    }

                    foreach ($links as $link) {
                        $errors = self::getHTMLBodyErrors(file_get_contents($link.".html"));

                        foreach ($errors as $error) {
                            $statement .= "* Codepen #".$i." ".$error."  \n";
                        }

                        $i++;
                        $statement .= "  \n";
                    }
                }
            }

            if ($hasIssues) {
                $statement .= "Please fix the above issues and re-write your example so we at Materialize can verify it’s a problem with the library and not with your code, and further proceed fixing your issue.  \n";
            }

            $statement .= "Thanks!  \n";
            $statement .= "  \n";
            $statement .= "(Note: This is a fully automated comment.)  \n";

            echo $statement;
        }

        $this->updateIssueCounter();
    }

    protected function updateIssueCounter() {
        if (count($this->openIssues) != 0) {
            $this->highestAnalyzedIssueNumber = $this->openIssues[0]["number"];
        }
    }

    protected function reviewAllIssues() {
        $this->highestAnalyzedIssueNumber = 0;
        $this->analyzeOpenIssues();
    }
}

$bot = new MaterialBot($repository);
