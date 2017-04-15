<?php

define("DEBUG", true);

if (DEBUG) {
    error_reporting(E_ALL);
}

// load libraries
require_once 'vendor/autoload.php';
use \Github\Client;

if(!is_file("config.json")) {
    die("You have not created a config.json file yet.\n");
}

require_once 'config.php';

class Bot {
    protected const TIDY_CONFIG = Array();
    protected const REQUIRED_JS_FILE = "/materialize\.(min\.)?js)/";
    protected const REQUIRED_CSS_FILE = "/materialize\.(min\.)?css/";
    public const PROJECT_NAME = "materialize";
    protected const JSHINT_HEADER_LENGTH = 15;
    protected const JS_HEADER_LOC = "jshint_header.js";
    protected const SPECIFIC_PAIR_CHECKS = Array(
        "chips" => ".material_chip(",
        "carousel" => ".carousel(",
        "tap-target" => ".tapTarget(",
        "parallax-container" => ".parallax(",
        "scrollspy" => ".scrollSpy(",
        "side-nav" => ".sideNav("
        );
    protected $client;
    public $repository;
    public $username;
    protected $openIssues, $closedIssues;

    protected $highestAnalyzedIssueNumber=0;

    public function __construct($repository) {
        $this->repository = $repository;

        $this->client = new Client();

        $this->login();

        $this->refreshIssues();

        $this->updateIssueCounter();

        echo "Bot started...running as of issue ".$this->highestAnalyzedIssueNumber."\n";

        $this->highestAnalyzedIssueNumber = 38;

        $this->run();
    }

    protected function login() {
        // login
        $authentication = json_decode(file_get_contents("config.json"), true);
        $this->username = $authentication["username"];
        $this->client->authenticate($authentication["username"], $authentication["password"], Client::AUTH_HTTP_PASSWORD);
    }

    protected function refreshIssues() {
        $this->closedIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "closed"));
        $this->openIssues = $this->client->api("issue")->all($this->repository[0], $this->repository[1], Array("state" => "open"));
    }

    protected function run() {
        while (true) {
            $this->refreshIssues();

            $this->analyzeOpenIssues();
            
            sleep(2);
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
        $tidy->parseString($in, self::TIDY_CONFIG);
        return explode("\n", $tidy->errorBuffer);
    }

    public static function getHTMLBodyErrors(string $in) : array {
        return self::getHTMLErrors("<!DOCTYPE html><head><title>null</title></head><body>".$in."</body></html>");
    }

    public static function getCodepenStatement(array $issue, bool &$hasIssues) : string {
        $statement = "";
        if (preg_match_all("/http(s|)\:\/\/(www\.|)codepen\.io\/[a-zA-Z0-9\-]+\/(pen|details|full|pres)\/[a-zA-Z0-9]+/", $issue["body"], $codepens) && !preg_match("/xbzPQV/", $issue["body"])) {
            $links = $codepens[0];
            if (count($links) == 1) {
                $link = $links[0];

                $statement .= "Your codepen at ".$link." is greatly appreciated!  \n";
                $statement .= "If there are any issues below, please fix them:  \n";

                $page = file_get_contents($link);

                if ($page === false) {
                    $statement .= "* The codepen does not exist or could not be found  \n";
                }

                $html = file_get_contents($link.".html");
                $js = file_get_contents($link.".js");

                if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                    !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                    $statement .= "* The codepen may not correctly include ".self::PROJECT_NAME."  \n";
                }

                $errors = self::getHTMLBodyErrors($html);

                foreach ($errors as $error) {
                    if (strlen($error) != 0) {
                        $statement .= "* HTML ".$error."  \n";
                    }
                }

                // JS
                $errors = self::getJSErrors($js);

                foreach ($errors as $error) {
                    $statement .= "* JS ".$error."  \n";
                }

                $errors = self::specificPlatformErrors($html, $js, $hasIssues);

                foreach ($errors as $error) {
                    $statement .= "* ".$error."  \n";
                }

                if ($hasIssues) {
                    $statement .= "  \n  \nPlease note, if you preprocess HTML or JS, the line and column numbers are for the processed code.  \n";
                    $statement .= "Additionally, any added libraries will be omitted in the above check.  \n";
                    $hasIssues = true;
                }
            } else {
                $statement .= "Your codepens at ";
                foreach ($links as $link) {
                    $statement .= $link." ";
                }
                $statement .= "are greatly appreciated!  \n";
                $i = 1;

                $statement .= "If there are any issues below, please fix them:  \n";

                foreach ($links as $link) {
                    $page = file_get_contents($link);

                    if ($page === false) {
                        $statement .= "* The codepen does not exist or could not be found  \n";
                    }
                    $html = file_get_contents($link.".html");
                    $js = file_get_contents($link.".js");

                    if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                        !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                        $statement .= "* The codepen may not correctly include ".self::PROJECT_NAME."  \n";
                    }

                    $errors = self::getHTMLBodyErrors($html);

                    foreach ($errors as $error) {
                        if (strlen($error) != 0) {
                            $statement .= "* Codepen [".$i."](".$link.") HTML ".$error."  \n";
                        }
                    }

                    $errors = self::getJSErrors($js);

                    foreach ($errors as $error) {
                        $statement .= "* Codepen [".$i."](".$link.") JS ".$error."  \n";
                    }

                    $errors = self::specificPlatformErrors($html, $js, $hasIssues);

                    foreach ($errors as $error) {
                        $statement .= "* Codepen [".$i."](".$link.") ".$error."  \n";
                    }

                    $i++;
                    $statement .= "  \n";
                }

                if ($hasIssues) {
                    $statement .= "  \n  \nPlease note, if you preprocess HTML or JS, the line and column numbers are for the processed code.  \n";
                    $statement .= "Additionally, any added libraries will be omitted in the above check.  \n";
                    $hasIssues = true;
                }
            }
        }
        return $statement."  \n";
    }

    public static function getJSFiddleStatement(array $issue, bool &$hasIssues) : string {
        $statement = "";
        if (preg_match_all("/http(s|)\:\/\/(www\.|)jsfiddle\.net\/[a-zA-Z0-9\-]+\/[a-zA-Z0-9]+/", $issue["body"], $fiddles)) {
            $links = $fiddles[0];
            if (count($links) == 1) {
                $link = $links[0];

                $statement .= "Your fiddle at ".$link." is greatly appreciated!  \n";
                $statement .= "If there are any issues below, please fix them:  \n";

                $page = file_get_contents($link);

                if ($page === false) {
                    $statement .= "* The fiddle does not exist or could not be found  \n";
                }

                $dom = pQuery::parseStr($page);
                $html = htmlspecialchars_decode($dom->query("textarea#id_code_html")[0]->html());
                $js = htmlspecialchars_decode($dom->query("textarea#id_code_js")[0]->html());

                if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                    !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                    $statement .= "* The fiddle may not correctly include ".self::PROJECT_NAME."  \n";
                }

                $errors = self::getHTMLBodyErrors($html);

                foreach ($errors as $error) {
                    if (strlen($error) != 0) {
                        $statement .= "* HTML ".$error."  \n";
                    }
                }

                // JS
                $errors = self::getJSErrors($js);

                foreach ($errors as $error) {
                    $statement .= "* JS ".$error."  \n";
                }

                $errors = self::specificPlatformErrors($html, $js, $hasIssues);

                foreach ($errors as $error) {
                    $statement .= "* ".$error."  \n";
                }

                if ($hasIssues) {
                    $statement .= "  \n  \nPlease note, if you preprocess HTML or JS, the line and column numbers are for the processed code.  \n";
                    $statement .= "Additionally, any added libraries will be omitted in the above check.  \n";
                    $hasIssues = true;
                }
            } else {
                $statement .= "Your fiddles at ";
                foreach ($links as $link) {
                    $statement .= $link." ";
                }
                $statement .= "are greatly appreciated!  \n";
                $i = 1;

                $statement .= "If there are any issues below, please fix them:  \n";

                foreach ($links as $link) {
                    $page = file_get_contents($link);

                    if ($page === false) {
                        $statement .= "* The fiddle does not exist or could not be found  \n";
                    }

                    if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                        !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                        $statement .= "* The fiddle may not correctly include ".self::PROJECT_NAME."  \n";
                    }

                    $dom = pQuery::parseStr($page);
                    $html = htmlspecialchars_decode($dom->query("textarea#id_code_html")[0]->html());
                    $js = htmlspecialchars_decode($dom->query("textarea#id_code_js")[0]->html());

                    $errors = self::getHTMLBodyErrors($html);

                    foreach ($errors as $error) {
                        if (strlen($error) != 0) {
                            $statement .= "* Fiddle [".$i."](".$link.") HTML ".$error."  \n";
                        }
                    }

                    $errors = self::getJSErrors($js);

                    foreach ($errors as $error) {
                        $statement .= "* Fiddle [".$i."](".$link.") JS ".$error."  \n";
                    }

                    $errors = self::specificPlatformErrors($html, $js, $hasIssues);

                    foreach ($errors as $error) {
                        $statement .= "* Fiddle [".$i."](".$link.") ".$error."  \n";
                    }

                    $i++;
                    $statement .= "  \n";
                }

                if ($hasIssues) {
                    $statement .= "  \n  \nPlease note, if you preprocess HTML or JS, the line and column numbers are for the processed code.  \n";
                    $statement .= "Additionally, any added libraries will be omitted in the above check.  \n";
                    $hasIssues = true;
                }
            }
        }
        return $statement."  \n";
    }

    public static function getMarkdownStatement(array $issue, bool &$hasIssues) : string {
        $issue["body"] .= "\n"; // regex issue
        $numCodeBlocks = preg_match_all("/```/", $issue["body"]);
        if ($numCodeBlocks > 0) {
            $statement = "";
            $statement .= "Your markdown code block(s) are greatly appreciated!  \n";
            $statement .= "If there are any issues below, please fix them:  \n";

            if (preg_match_all("/```(\s+|\n)/", $issue["body"])*2 > $numCodeBlocks) {
                $statement .= "One or more markdown codeblocks do not have language descriptors, and cannot be parsed by this bot.  \nPlease add them per the [GFM guide](https://guides.github.com/features/mastering-markdown/)  \n";
            }

            file_put_contents("tmp.md", $issue["body"]."\n");

            exec("/usr/local/bin/codedown html < tmp.md", $html);
            $html = implode("\n", $html);

            if (preg_match("/<body>/", $html)) {
                $errors = self::getHTMLErrors($html);
            } else {
                $errors = self::getHTMLBodyErrors($html);
            }

            foreach ($errors as $error) {
                if (strlen($error) != 0) {
                    $statement .= "* HTML ".$error."  \n";
                    $hasIssues = true;
                }
            }

            exec("/usr/local/bin/codedown javascript < tmp.md", $js);
            exec("/usr/local/bin/codedown js < tmp.md", $js);
            $js = implode("\n", $js);

            $errors = self::getJSErrors($js);
            
            foreach ($errors as $error) {
                if (strlen($error) != 0) {
                    $statement .= "* JS ".$error."  \n";
                    $hasIssues = true;
                }
            }

            $errors = self::specificPlatformErrors($html, $js, $hasIssues);
            
            foreach ($errors as $error) {
                if (strlen($error) != 0) {
                    $statement .= "* ".$error."  \n";
                    $hasIssues = true;
                }
            }

            return $statement."  \n";
        }
        return "";
    }

    public static function getJSErrors(string $in) : array {
        $js_header = file_get_contents(self::JS_HEADER_LOC);
        file_put_contents("tmp.js", $js_header.$in);

        exec("/usr/local/bin/jshint tmp.js", $errors);

        $errors = array_filter($errors, function($in) {
            return preg_match("/tmp.js/", $in);
        });

        $returnArr = Array();

        foreach ($errors as $error) {
            $error = preg_replace("/tmp.js\: /", "", $error);
            preg_match('/\d+/', $error, $matches); 
            $line = $matches[0];
            $line -= self::JSHINT_HEADER_LENGTH;
            $error = preg_replace("/\d+/", $line, $error, 1);
            $returnArr[] = $error;
        }

        return $returnArr;
    }

    public static function specificPlatformErrors(string $html, string $js, bool &$hasIssues) : array {
        $errors = Array();
        $text = $html.$js;
        foreach (self::SPECIFIC_PAIR_CHECKS as $key => $value) {
            if (strpos($text, $key) !== false) {
                if (strpos($text, $value) === false) {
                    $errors[] = Array($key, $value);
                    $hasIssues = true;
                }
            }
        }

        if (count($errors) != 0) {
            $arr = Array();
            foreach ($errors as $error) {
                $arr[] = "`".$error[0]."` was found without the associated `".$error[1]."`";
            }
            return $arr;
        }
        return Array();
    }

    public static function getEmptyBody(array $issue, bool &$hasIssues) : string {
        if (strlen($issue["body"]) == 0) {
            $statement  = "Your issue body is empty.  \n";
            $statement .= "Please add more information in order to allow us to quickly categorize and resolve the issue.  \n  \n";
            $hasIssues = true;
            return $statement;
        }
        return "";
    }

    public static function getUnfilledTemplate(array $issue, bool &$hasIssues) : string {
        if (preg_match("/(Add a detailed description of your issue|Layout the steps to reproduce your issue.|Use this Codepen to demonstrate your issue.|xbzPQV|Add supplemental screenshots or code examples. Look for a codepen template in our Contributing Guidelines.)/", $issue["body"])) {
            $statement  = "You have not filled out part of our issue template.  \n";
            $statement .= "Please fill in the template in order to allow us to quickly categorize and resolve the issue.  \n  \n";
            $hasIssues = true;
            return $statement;
        }
        return "";
    }

    protected function analyzeIssue(array $issue) {
        $hasIssues = false;

        $statement  = "@".$issue["user"]["login"].",  \n";
        $statement .= "Thank you for creating an issue!  \n\n";

        $statement .= self::getEmptyBody($issue, $hasIssues);
        $statement .= self::getUnfilledTemplate($issue, $hasIssues);
        $statement .= self::getCodepenStatement($issue, $hasIssues);
        $statement .= self::getJSFiddleStatement($issue, $hasIssues);
        $statement .= self::getMarkdownStatement($issue, $hasIssues);

        if ($hasIssues) {
            $statement .= "Please fix the above issues and re-write your example so we at ".self::PROJECT_NAME." can verify it’s a problem with the library and not with your code, and further proceed fixing your issue.  \n";
            $statement .= "Once you have done so, please mention me with the reanalyze keyword: `@".$this->username." reanalyze`.  \n";
        }

        $statement .= "  \n";
        $statement .= "_I'm a bot, bleep, bloop. If there was an error, please let us know._  \n";

        $this->client->api("issue")->comments()->create($this->repository[0], $this->repository[1], $issue["number"], array("body" => htmlspecialchars($statement)));

        echo "Issue ".$issue["number"]." has been analyzed and commented on.\n";
    }

    protected function analyzeOpenIssues() {
        $issues = $this->getUnanalyzedIssues();

        foreach ($issues as $issue) {
            $this->analyzeIssue($issue);
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

$bot = new Bot($repository);
