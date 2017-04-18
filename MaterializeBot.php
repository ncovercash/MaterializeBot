<?php

define("DEBUG", true);

if (DEBUG) {
    error_reporting(E_ALL);
}

// load libraries
require_once "vendor/autoload.php";

if(!is_file("config.json")) {
    die("You have not created a config.json file yet.\n");
}

require_once "config.php";

class Bot {
    public const MAIN = 0;
    public const HAS_PR = 1;
    public const REANALYZE = 2;

    protected const TIDY_CONFIG = Array();
    protected const ISSUE_KEYEWORDS = Array("select", "input", "modal", "button", "dropdown", "navbar", "page", "tabs", "icon", "after", "sidenav", "side-nav", "menu", "meteor", "form", "color", "card", "collapsible", "image", "nav", "slider", "datepicker", "carousel", "parallax", "grid", "font", "table", "fab", "checkbox", "container", "overlay", "footer", "waves");
    public const PROJECT_NAME = "materialize";
    protected const REQUIRED_JS_FILE = "/materialize\.(min\.)?js/";
    protected const REQUIRED_CSS_FILE = "/materialize\.(min\.)?css/";
    protected const LABEL_SUFFIX = " (bot)";
    protected const JS_HEADER_LOC = "jshint_header.js";
    protected const SLEEP_TIME = 10; // seconds
    // protected const SLEEP_TIME = 20; // seconds
    protected const SLEEP_TIME_PRS = 100; // seconds
    // protected const SLEEP_TIME_PRS = 300; // seconds
    protected const SLEEP_TIME_REANALYZE = 60; // seconds
    // protected const SLEEP_TIME_REANALYZE = 120; // seconds
    protected const SPECIFIC_PAIR_CHECKS = Array(
        "chips" => ".material_chip(",
        "carousel" => ".carousel(",
        "tap-target" => ".tapTarget(",
        "parallax-container" => ".parallax(",
        "scrollspy" => ".scrollSpy(",
        "side-nav" => ".sideNav("
        );
    protected const CODEDOWN_LOCATION = "/usr/local/bin/codedown";
    protected const JSHINT_LOCATION = "/usr/local/bin/jshint";
    protected const STREAM_CONTEXT = Array("http" => Array("method" => "GET", "timeout" => 10));
    protected const UNFILLED_TEMPLATE_REGEX = "/(Add a detailed description of your issue|Layout the steps to reproduce your issue.|Use this Codepen to demonstrate your issue.|xbzPQV|Add supplemental screenshots or code examples. Look for a codepen template in our Contributing Guidelines.)/";
    protected const BOT_ISSUE_FOOTER = "_I'm a bot, bleep, bloop. If there was an error, please let us know._  \n_Bot code at [smileytechguy/MaterializeBot](https://github.com/smileytechguy/MaterializeBot)_.  \n";
    
    public $alive=true;
    protected $githubClient, $githubPaginator, $seleniumDriver;
    public $repository;
    public $username;
    protected $openIssues, $closedIssues;
    protected $openPRs, $closedPRs, $prNumbers;
    protected $imageRepo, $imageRepoPath, $imageRepoName;

    protected $highestAnalyzedIssueNumber=0;

    // initialization functions
    public function __construct($repository, int $mode) {
        $this->repository = $repository;

        $this->githubClient = new \Github\Client();
        
        $this->loadConfig();

        $this->refreshIssues();

        $this->updateIssueCounter();

        echo "Bot mode ".$mode." started...running as of issue ".$this->highestAnalyzedIssueNumber."\n";

        switch ($mode) {
            case self::MAIN:
                $this->initSelenium();
                $this->runMain();
                break;
            case self::HAS_PR:
                $this->runPRLabel();
                break;
            case self::REANALYZE:
                $this->initSelenium();
                $this->runReanalyze();
        }
    }

    protected function loadConfig() {
        $config = json_decode(file_get_contents("config.json"), true);

        $this->username = $config["gh_username"];
        $this->githubClient->authenticate($config["gh_username"], $config["gh_password"], \Github\Client::AUTH_HTTP_PASSWORD);
        $this->githubPaginator = new Github\ResultPager($this->githubClient);

        $this->imageRepo = new \GitElephant\Repository($config["image_repo_path"]);
        $this->imageRepoName = $config["image_repo"];
        $this->imageRepoPath = $config["image_repo_path"];
    }

    protected function initSelenium() {
        if (isset($this->seleniumDriver)) {
            $this->seleniumDriver->quit();
        }

        $options = new \Facebook\WebDriver\Chrome\ChromeOptions();

        $prefs = Array(
            "profile.default_content_setting_values.background_sync" => 2,
            "profile.default_content_setting_values.cookies" => 2,
            "profile.default_content_setting_values.geolocation" => 2,
            "profile.default_content_setting_values.media_stream_camera" => 2,
            "profile.default_content_setting_values.media_stream_mic" => 2,
            "profile.default_content_setting_values.notifications" => 2,
            "profile.default_content_setting_values.plugins" => 2
        );

        $options->setExperimentalOption('prefs', $prefs);

        $desiredCapabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
        $desiredCapabilities->setCapability(\Facebook\WebDriver\Chrome\ChromeOptions::CAPABILITY, $options);

        $this->seleniumDriver = \Facebook\WebDriver\Remote\RemoteWebDriver::create("http://localhost:4444/wd/hub", $desiredCapabilities, 3000);
    }

    // reload data functions
    protected function refreshIssues() {
        $this->closedIssues = $this->githubPaginator->fetchAll($this->githubClient->api("issue"), "all", Array($this->repository[0], $this->repository[1], Array("state" => "closed")));
        $this->openIssues = $this->githubPaginator->fetchAll($this->githubClient->api("issue"), "all", Array($this->repository[0], $this->repository[1], Array("state" => "open")));

        $this->openIssues = array_filter($this->openIssues, function(array $issue) {
            return !isset($issue["pull_request"]);
        });
        $this->closedIssues = array_filter($this->closedIssues, function(array $issue) {
            return !isset($issue["pull_request"]);
        });
    }

    protected function updatePRs() {
        $this->closedPRs = $this->githubPaginator->fetchAll($this->githubClient->api("pull_request"), "all", Array($this->repository[0], $this->repository[1], Array("state" => "closed")));
        $this->openPRs = $this->githubPaginator->fetchAll($this->githubClient->api("pull_request"), "all", Array($this->repository[0], $this->repository[1], Array("state" => "open")));

        $this->prNumbers = Array();

        foreach ($this->closedPRs as $pr) {
            $this->prNumbers[] = "#".$pr["number"];
        }
        foreach ($this->openPRs as $pr) {
            $this->prNumbers[] = "#".$pr["number"];
        }
    }

    // issue array wrapper functions
    public function getUnanalyzedIssues() : array {
        // TODO: I know there is a way better way to do this
        // that uses a for/while loop instead
        return array_filter($this->openIssues, function(array $issue) {
            return $this->highestAnalyzedIssueNumber < $issue["number"];
        });
    }

    // core run functions
    public function runMain() {
        while ($this->alive) {
            $this->refreshIssues();

            $this->analyzeUnanalyzedIssues();

            $this->removeOldImages();
            
            sleep(self::SLEEP_TIME);
        }
    }

    public function runPRLabel() {
        while ($this->alive) {
            $this->refreshIssues();

            $this->updatePRs();

            $this->updateOpenPRLabels();
            
            sleep(self::SLEEP_TIME_PRS);
        }
    }

    public function runReanalyze() {
        while ($this->alive) {
            $this->refreshIssues();

            $this->reanalyzeIssues();
            
            sleep(self::SLEEP_TIME_REANALYZE);
        }
    }

    // issue analysis functions
    protected function analyzeIssue(array $issue) {
        $start = microtime(true);

        $hasIssues = false;

        $statement  = "@".$issue["user"]["login"].",  \n";
        $statement .= "Thank you for creating an issue!  \n\n";

        $statement .= $this->getEmptyBody($issue, $hasIssues);
        $statement .= $this->getUnfilledTemplate($issue, $hasIssues);
        $statement .= $this->getCodepenStatement($issue, $hasIssues);
        $statement .= $this->getJSFiddleStatement($issue, $hasIssues);
        $statement .= $this->getMarkdownStatement($issue, $hasIssues);
        $statement .= $this->getJSBinStatement($issue, $hasIssues);

        if ($hasIssues) {
            $statement .= "If the screenshot or log is extremely different from your version, it may be due to a missing dependency (jquery?) on either side.  \n";
            $statement .= "  \n";
            $statement .= "Please fix the above issues and re-write your example so we at ".self::PROJECT_NAME." can verify it’s a problem with the library and not with your code, and further proceed fixing your issue.  \n";
            $statement .= "Once you have done so, please mention me with the reanalyze keyword: `@".$this->username." reanalyze`.  (It may take a while for this to happen.)  \n";

            $this->githubClient->api("issue")->labels()->add($this->repository[0], $this->repository[1], $issue["number"], "awaiting reply".self::LABEL_SUFFIX);
        } else {
            foreach ($issue["labels"] as $label) {
                if ($label["name"] == "awaiting reply".self::LABEL_SUFFIX) {
                    $this->githubClient->api("issue")->labels()->remove($this->repository[0], $this->repository[1], $issue["number"], "awaiting reply".self::LABEL_SUFFIX);
                    return;
                }
            }
        }

        $statement .= $this->checkFeatureLabel($issue);
        
        $statement .= $this->getSimilarIssues($issue);
        
        $statement .= "  \n";
        $statement .= self::BOT_ISSUE_FOOTER;

        // reset browser
        $this->seleniumDriver->get("about:blank");

        $this->githubClient->api("issue")->comments()->create($this->repository[0], $this->repository[1], $issue["number"], array("body" => htmlspecialchars($statement)));

        echo "Issue ".$issue["number"]." has been analyzed and commented on in ".(microtime(true)-$start)."s.\n";

        $this->imageRepo->push();
    }

    // issue analysis wrapper functions
    protected function analyzeUnanalyzedIssues() {
        $issues = $this->getUnanalyzedIssues();

        foreach ($issues as $issue) {
            $this->analyzeIssue($issue);
        }

        $this->updateIssueCounter();
    }

    // issue reanalysis functions
    protected function reanalyzeIssue(array $issue) {
        $start = microtime(true);

        $comments = $this->githubPaginator->fetchAll($this->githubClient->api("issue")->comments(), "all", Array($this->repository[0], $this->repository[1], $issue["number"]));

        $owner = $issue["user"]["login"];

        $comments = array_reverse($comments);

        for ($i=0; $i < count($comments); $i++) { 
            if ($comments[$i]["user"]["login"] == $this->username) {
                return;
            }
            if ($comments[$i]["user"]["login"] == $owner) {
                if (preg_match("/@".strtolower($this->username)." reanalyze/", strtolower($comments[$i]["body"]))) {
                    foreach ($comments as $comment) {
                        if ($comment["user"]["login"] == $this->username) {
                            if (preg_match("/(reanalyze|Thank you for creating an issue)/", $comment["body"])) {
                                $this->githubClient->api('issue')->comments()->update($this->repository[0], $this->repository[1], $comment["id"], array('body' => "This comment is out of date.  See below for an updated analyzation."));
                            }
                        }
                    }

                    $hasIssues = false;

                    $statement  = "@".$issue["user"]["login"].",  \n";
                    $statement .= "Your code has been reanalyzed!  \n\n";

                    $statement .= $this->getEmptyBody($issue, $hasIssues);
                    $statement .= $this->getUnfilledTemplate($issue, $hasIssues);
                    $statement .= $this->getCodepenStatement($issue, $hasIssues);
                    $statement .= $this->getJSFiddleStatement($issue, $hasIssues);
                    $statement .= $this->getMarkdownStatement($issue, $hasIssues);
                    $statement .= $this->getJSBinStatement($issue, $hasIssues);

                    if ($hasIssues) {
                        $statement .= "If the screenshot or log is extremely different from your version, it may be due to a missing dependency (jquery?) on either side.  \n";
                        $statement .= "  \n";
                        $statement .= "Please fix the above issues and re-write your example so we at ".self::PROJECT_NAME." can verify it’s a problem with the library and not with your code, and further proceed fixing your issue.  \n";
                        $statement .= "Once you have done so, please mention me with the reanalyze keyword: `@".$this->username." reanalyze`.  (It may take a while for this to happen.)  \n";

                        $hasAwaitingLabel = false;
                        foreach ($issue["labels"] as $label) {
                            if ($label["name"] == "awaiting reply".self::LABEL_SUFFIX) {
                                $hasAwaitingLabel = true;
                            }
                        }
                        if (!$hasAwaitingLabel) {
                            $this->githubClient->api("issue")->labels()->add($this->repository[0], $this->repository[1], $issue["number"], "awaiting reply".self::LABEL_SUFFIX);
                        }
                    } else {
                        $statement .= "No issues were found!  \n  \n";
                        foreach ($issue["labels"] as $label) {
                            if ($label["name"] == "awaiting reply".self::LABEL_SUFFIX) {
                                $this->githubClient->api("issue")->labels()->remove($this->repository[0], $this->repository[1], $issue["number"], "awaiting reply".self::LABEL_SUFFIX);
                            }
                        }
                    }

                    $statement .= $this->getSimilarIssues($issue);
                    
                    $statement .= "  \n";
                    $statement .= self::BOT_ISSUE_FOOTER;

                    // reset browser
                    $this->seleniumDriver->get("about:blank");

                    $this->githubClient->api("issue")->comments()->create($this->repository[0], $this->repository[1], $issue["number"], array("body" => htmlspecialchars($statement)));

                    echo "Issue ".$issue["number"]." has been reanalyzed in ".(microtime(true)-$start)."s.\n";

                    $this->imageRepo->push();
                    return;
                }
            }
        }
    }

    // issue reanalysis wrapper functions
    protected function reanalyzeIssues() {
        foreach ($this->openIssues as $issue) {
            $this->reanalyzeIssue($issue);
        }
    }

    // generic functions
    protected function reviewAllIssues() {
        $this->highestAnalyzedIssueNumber = 0;
        $this->updatePRs();
        $this->updateAllPRLabels();
        $this->analyzeUnanalyzedIssues();
    }

    protected function updateIssueCounter() {
        if (count($this->openIssues) != 0) {
            $this->highestAnalyzedIssueNumber = $this->openIssues[0]["number"];
        }
    }

    // static analysis core functions
    public static function getHTMLErrors(string $in) : array {
        $tidy = new Tidy();
        $tidy->parseString($in, self::TIDY_CONFIG);
        return explode("\n", $tidy->errorBuffer);
    }

    public static function getJSErrors(string $in) : array {
        $js_header = file_get_contents(self::JS_HEADER_LOC);

        $js_header_length = count(file(self::JS_HEADER_LOC)) - 1;

        file_put_contents("tmp/tmp.js", $js_header.$in);

        exec(self::JSHINT_LOCATION." tmp/tmp.js", $errors);

        $errors = array_filter($errors, function($in) {
            return preg_match("/tmp\/tmp.js/", $in);
        });

        $returnArr = Array();

        foreach ($errors as $error) {
            $error = preg_replace("/tmp\/tmp.js\: /", "", $error);
            preg_match("/\d+/", $error, $matches); 
            $line = $matches[0];
            $line -= $js_header_length;
            $error = preg_replace("/\d+/", $line, $error, 1);
            $returnArr[] = $error;
        }

        return $returnArr;
    }

    public function specificProjectErrors(string $html, string $js, bool &$hasIssues) : array {
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

    // static analysis wrapper functions
    public static function getHTMLBodyErrors(string $in) : array {
        return self::getHTMLErrors("<!DOCTYPE html><head><title>null</title></head><body>".$in."</body></html>");
    }

    // selenium core functions
    public function getSeleniumErrors(string $html) : array {
        // make sure we arent running bad stuff
        $html = preg_replace("/<applet[^>]+>.*?<\/applet>/is", "", $html);
        $html = preg_replace("/<frame[^>]+>.*?<\/frame>/is", "", $html);
        $html = preg_replace("/<frameset[^>]+>.*?<\/frameset>/is", "", $html);
        $html = preg_replace("/<embed[^>]+>.*?<\/embed>/is", "", $html);
        $html = preg_replace("/<iframe[^>]+>.*?<\/iframe>/is", "", $html);
        $html = preg_replace("/<noframes[^>]+>.*?<\/noframes>/is", "", $html);
        $html = preg_replace("/<object[^>]+>.*?<\/object>/is", "", $html);

        file_put_contents("tmp/tmp.html", $html);

        $path = "file://".__DIR__."/tmp/tmp.html";

        $this->seleniumDriver->get($path);

        return $this->seleniumDriver->manage()->getLog("browser");
    }

    protected function getSeleniumImage() : string {
        if ($this->seleniumDriver->getCurrentUrl() != "file://".__DIR__."/tmp/tmp.html") {
            throw new Exception("Unexpected redirect");
        }

        $this->seleniumDriver->takeScreenshot("tmp/tmp.png");

        return $this->uploadImage("tmp/tmp.png");
    }

    // selenium wrapper functions
    public function getSeleniumErrorsFromBodyJSandCSS(string $body, string $js, string $css) : array {
        $html = file_get_contents("html_header.html");

        $html .= $body;

        $html .= file_get_contents("html_footer.html");

        return $this->getSeleniumErrorsFromHTMLJSandCSS($html, $js, $css);
    }

    public function getSeleniumErrorsFromHTMLJSandCSS(string $html, string $js, string $css) : array {
        $html .= "<script>".$js."</script>";

        $html .= "<style>".$css."</style>";

        return $this->getSeleniumErrors($html);
    }

    // selenium processing functions
    public static function processSeleniumErrors(array $errors, bool &$hasIssues, string $prefix="") {
        $path = "file://".__DIR__."/tmp/tmp.html";

        $statement = "";

        foreach ($errors as $error) {
            if (strpos($error["message"], " - Failed to load resource: net::ERR_FILE_NOT_FOUND") !== false) {
                $error["message"] = basename(substr($error["message"], 0, strpos($error["message"], " - Failed to load resource: net::ERR_FILE_NOT_FOUND")))." - Failed to load resource: net::ERR_FILE_NOT_FOUND";
            }
            if ($error["level"] === "WARNING") {
                $error = preg_replace("/".preg_quote($path, "/")."\s\d+\:\d+\s/", "", $error);
                $statement .= "* ".$prefix." Console warning ".$error["message"]."  \n";
            } else if ($error["level"] === "SEVERE") {
                $error = preg_replace("/".preg_quote($path, "/")."\s\d+\:\d+\s/", "", $error);
                $statement .= "* ".$prefix." Console error ".$error["message"]."  \n";
            }
            $hasIssues = true;
        }
        return $statement;
    }

    protected function uploadImage(string $file) : string {
        $fname = time().basename($file);
        $newname = $this->imageRepoPath.$fname;
        if (rename($file, $newname)) {
            $this->imageRepo->stage();
            $this->imageRepo->commit(time(), true);
            return "https://github.com/".$this->imageRepoName."/blob/master/".$fname;
        } else {
            return "error";
        }
    }

    // issue code processing functions
    public function getCodepenStatement(array $issue, bool &$hasIssues) : string {
        $statement = "";
        if (preg_match_all("/http(s|)\:\/\/(www\.|)codepen\.io\/[a-zA-Z0-9\-]+\/(pen|details|full|pres)\/[a-zA-Z0-9]+/", $issue["body"], $codepens) && !preg_match("/xbzPQV/", $issue["body"])) {
            $links = $codepens[0];
            $links = array_unique($links);
            if (count($links) > 10) {
                $statement .= "Only the first 10 Codepens will be analyzed.  \n";
            }
            $links = array_slice($links, 0, 10);
            if (count($links) === 1) {
                $link = $links[0];

                $statement .= "Your codepen at ".$link." is greatly appreciated!  \n";
                $statement .= "If there are any issues below, please fix them:  \n";

                $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                if ($page === false) {
                    $statement .= "* The codepen does not exist or could not be found  \n";
                    $hasIssues = true;
                    return $statement;
                }

                $html = file_get_contents($link.".html", false, stream_context_create(self::STREAM_CONTEXT));
                $js = file_get_contents($link.".js", false, stream_context_create(self::STREAM_CONTEXT));
                $css = file_get_contents($link.".css", false, stream_context_create(self::STREAM_CONTEXT));

                if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                    !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                    $statement .= "* The codepen may not correctly include ".self::PROJECT_NAME."  \n";
                    $hasIssues = true;
                }

                $errors = self::getHTMLBodyErrors($html);

                foreach ($errors as $error) {
                    if (strlen($error) != 0) {
                        $statement .= "* HTML ".$error."  \n";
                        $hasIssues = true;
                    }
                }

                // JS
                $errors = self::getJSErrors($js);

                foreach ($errors as $error) {
                    $statement .= "* JS ".$error."  \n";
                    $hasIssues = true;
                }

                // Specific
                $errors = self::specificProjectErrors($html, $js, $hasIssues);

                foreach ($errors as $error) {
                    $statement .= "* ".$error."  \n";
                    $hasIssues = true;
                }

                try {
                    // Console
                    $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                    $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues);
    
                    // Image
                    $image = $this->getSeleniumImage();
                    $statement .= "  \n  \nImage rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                } catch (Exception $e) {
                    $statement .= "* Unable to render content with selenium  \n";
                    $hasIssues = true;

                    $this->initSelenium();
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
                    $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                    if ($page === false) {
                        $statement .= "* Codepen [".$i."](".$link.") does not exist or could not be found  \n";
                        $hasIssues = true;
                        $i++;
                        continue;
                    }

                    $html = file_get_contents($link.".html", false, stream_context_create(self::STREAM_CONTEXT));
                    $js = file_get_contents($link.".js", false, stream_context_create(self::STREAM_CONTEXT));
                    $css = file_get_contents($link.".css", false, stream_context_create(self::STREAM_CONTEXT));

                    if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                        !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                        $statement .= "* Codepen [".$i."](".$link.") may not correctly include ".self::PROJECT_NAME."  \n";
                        $hasIssues = true;
                    }

                    $errors = self::getHTMLBodyErrors($html);

                    foreach ($errors as $error) {
                        if (strlen($error) != 0) {
                            $statement .= "* Codepen [".$i."](".$link.") HTML ".$error."  \n";
                            $hasIssues = true;
                        }
                    }

                    $errors = self::getJSErrors($js);

                    foreach ($errors as $error) {
                        $statement .= "* Codepen [".$i."](".$link.") JS ".$error."  \n";
                        $hasIssues = true;
                    }

                    $errors = self::specificProjectErrors($html, $js, $hasIssues);

                    foreach ($errors as $error) {
                        $statement .= "* Codepen [".$i."](".$link.") ".$error."  \n";
                        $hasIssues = true;
                    }

                    try {
                        // Console
                        $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                        $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues, "Codepen [".$i."](".$link.") ");
        
                        // Image
                        $image = $this->getSeleniumImage();
                        $statement .= "  \n  \nCodepen [".$i."](".$link.") image rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                    } catch (Exception $e) {
                        $statement .= "* Codepen [".$i."](".$link.") Unable to render content with selenium  \n";
                        $hasIssues = true;

                        $this->initSelenium();
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

    public function getJSFiddleStatement(array $issue, bool &$hasIssues) : string {
        $statement = "";
        if (preg_match_all("/http(s|)\:\/\/(www\.|)jsfiddle\.net\/[a-zA-Z0-9\-]+\/[a-zA-Z0-9]+/", $issue["body"], $fiddles)) {
            $links = $fiddles[0];
            $links = array_unique($links);
            if (count($links) > 10) {
                $statement .= "Only the first 10 JSFiddles will be analyzed.  \n";
            }
            $links = array_slice($links, 0, 10);
            if (count($links) === 1) {
                $link = $links[0];

                $statement .= "Your fiddle at ".$link." is greatly appreciated!  \n";
                $statement .= "If there are any issues below, please fix them:  \n";

                $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                if ($page === false) {
                    $statement .= "* The fiddle does not exist or could not be found  \n";
                    $hasIssues = true;
                    return $statement;
                }

                $dom = pQuery::parseStr($page);
                $html = htmlspecialchars_decode($dom->query("textarea#id_code_html")[0]->html());
                $js = htmlspecialchars_decode($dom->query("textarea#id_code_js")[0]->html());
                $css = htmlspecialchars_decode($dom->query("textarea#id_code_css")[0]->html());

                if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                    !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                    $statement .= "* The fiddle may not correctly include ".self::PROJECT_NAME."  \n";
                    $hasIssues = true;
                }

                $errors = self::getHTMLBodyErrors($html);

                foreach ($errors as $error) {
                    if (strlen($error) != 0) {
                        $statement .= "* HTML ".$error."  \n";
                        $hasIssues = true;
                    }
                }

                // JS
                $errors = self::getJSErrors($js);

                foreach ($errors as $error) {
                    $statement .= "* JS ".$error."  \n";
                    $hasIssues = true;
                }

                $errors = self::specificProjectErrors($html, $js, $hasIssues);

                foreach ($errors as $error) {
                    $statement .= "* ".$error."  \n";
                    $hasIssues = true;
                }

                try {
                    // Console
                    $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                    $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues);
    
                    // Image
                    $image = $this->getSeleniumImage();
                    $statement .= "  \n  \nImage rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                } catch (Exception $e) {
                    $statement .= "* Unable to render content with selenium  \n";
                    $hasIssues = true;

                    $this->initSelenium();
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
                    $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                    if ($page === false) {
                        $statement .= "* Fiddle [".$i."](".$link.") does not exist or could not be found  \n";
                        $hasIssues = true;
                        $i++;
                        continue;
                    }

                    if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                        !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                        $statement .= "* Fiddle [".$i."](".$link.") may not correctly include ".self::PROJECT_NAME."  \n";
                        $hasIssues = true;
                    }

                    $dom = pQuery::parseStr($page);
                    $html = htmlspecialchars_decode($dom->query("textarea#id_code_html")[0]->html());
                    $js = htmlspecialchars_decode($dom->query("textarea#id_code_js")[0]->html());
                    $css = htmlspecialchars_decode($dom->query("textarea#id_code_css")[0]->html());

                    $errors = self::getHTMLBodyErrors($html);

                    foreach ($errors as $error) {
                        if (strlen($error) != 0) {
                            $statement .= "* Fiddle [".$i."](".$link.") HTML ".$error."  \n";
                            $hasIssues = true;
                        }
                    }

                    $errors = self::getJSErrors($js);

                    foreach ($errors as $error) {
                        $statement .= "* Fiddle [".$i."](".$link.") JS ".$error."  \n";
                        $hasIssues = true;
                    }

                    $errors = self::specificProjectErrors($html, $js, $hasIssues);

                    foreach ($errors as $error) {
                        $statement .= "* Fiddle [".$i."](".$link.") ".$error."  \n";
                        $hasIssues = true;
                    }

                    try {
                        // Console
                        $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                        $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues, "Fiddle [".$i."](".$link.") ");
        
                        // Image
                        $image = $this->getSeleniumImage();
                        $statement .= "  \n  \nFiddle [".$i."](".$link.") image rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                    } catch (Exception $e) {
                        $statement .= "* Fiddle [".$i."](".$link.") Unable to render content with selenium  \n";
                        $hasIssues = true;

                        $this->initSelenium();
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

    public function getMarkdownStatement(array $issue, bool &$hasIssues) : string {
        $issue["body"] .= "\n"; // regex issue
        $numCodeBlocks = preg_match_all("/```/", $issue["body"]);
        if ($numCodeBlocks > 0) {
            $statement = "";
            $statement .= "Your markdown code block(s) are greatly appreciated!  \n";
            $statement .= "If there are any issues below, please fix them:  \n";

            if (preg_match_all("/```(\s+|\n)/", $issue["body"])*2 > $numCodeBlocks) {
                $statement .= "One or more markdown codeblocks do not have language descriptors, and cannot be parsed by this bot.  \nPlease add them per the [GFM guide](https://guides.github.com/features/mastering-markdown/)  \n";
            }

            file_put_contents("tmp/tmp.md", $issue["body"]."\n");

            exec(self::CODEDOWN_LOCATION." html < tmp/tmp.md", $html);
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

            exec(self::CODEDOWN_LOCATION." javascript < tmp/tmp.md", $js);
            exec(self::CODEDOWN_LOCATION." js < tmp/tmp.md", $js);
            $js = implode("\n", $js);

            $errors = self::getJSErrors($js);
            
            foreach ($errors as $error) {
                if (strlen($error) != 0) {
                    $statement .= "* JS ".$error."  \n";
                    $hasIssues = true;
                }
            }

            $errors = self::specificProjectErrors($html, $js, $hasIssues);
            
            foreach ($errors as $error) {
                if (strlen($error) != 0) {
                    $statement .= "* ".$error."  \n";
                    $hasIssues = true;
                }
            }

            exec(self::CODEDOWN_LOCATION." css < tmp/tmp.md", $css);
            $css = implode("\n", $css);

            try {
                // Console
                if (preg_match("/<body>/", $html)) {
                    $seleniumErrors = $this->getSeleniumErrorsFromHTMLJSandCSS($html, $js, $css);
                } else {
                    $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                }
                $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues);

                // Image
                $image = $this->getSeleniumImage();
                $statement .= "  \n  \nImage rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
            } catch (Exception $e) {
                $statement .= "* Unable to render content with selenium  \n";
                $hasIssues = true;

                $this->initSelenium();
            }

            return $statement."  \n";
        }
        return "";
    }

    public function getJSBinStatement(array $issue, bool &$hasIssues) : string {
        $statement = "";
        if (preg_match_all("/http(s|)\:\/\/(www\.|)jsbin\.com\/[a-zA-Z0-9\-]+(\/[0-9]+|\/latest|\/)/", $issue["body"], $bins)) {
            $links = $bins[0];
            $links = array_unique($links);
            if (count($links) > 10) {
                $statement .= "Only the first 10 JSBins will be analyzed.  \n";
            }
            $links = array_slice($links, 0, 10);
            if (count($links) === 1) {
                $link = $links[0];

                $statement .= "Your bin at ".$link." is greatly appreciated!  \n";
                $statement .= "If there are any issues below, please fix them:  \n";

                $link = $link."edit?html,css,js";

                $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                if ($page === false) {
                    $statement .= "* The bin does not exist or could not be found  \n";
                    $hasIssues = true;
                    return $statement;
                }

                preg_match_all("/http\:\/\/jsbin.com\/bin\/start\.js\?[a-zA-Z0-9]+/", $page, $subpatterns);

                $realPage = $subpatterns[0][0];

                $opts = array(
                    "http"=>array(
                        "header"=>"Referer: ".$link."\r\n"
                  )
                );

                $page = file_get_contents($realPage, false, stream_context_create(array_merge($opts, self::STREAM_CONTEXT)));

                if ($page === false) {
                    $statement .= "* The bin does not exist or could not be found  \n";
                    $hasIssues = true;
                    return $statement;
                }

                $page = preg_replace("/start\(/", "", $page, 1);

                $page = substr($page, 0, strpos($page, ",\"url\":\"http"));

                $json = json_decode($page."}", true);

                $html = $json["html"];
                $js = $json["javascript"];
                $css = $json["css"];

                if (!preg_match(self::REQUIRED_JS_FILE, $html) ||
                    !preg_match(self::REQUIRED_CSS_FILE, $html)) {
                    $statement .= "* The bin may not correctly include ".self::PROJECT_NAME."  \n";
                    $hasIssues = true;
                }

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

                // JS
                $errors = self::getJSErrors($js);

                foreach ($errors as $error) {
                    $statement .= "* JS ".$error."  \n";
                    $hasIssues = true;
                }

                $errors = self::specificProjectErrors($html, $js, $hasIssues);

                foreach ($errors as $error) {
                    $statement .= "* ".$error."  \n";
                    $hasIssues = true;
                }

                try {
                    // Console
                    if (preg_match("/<body>/", $html)) {
                        $seleniumErrors = $this->getSeleniumErrorsFromHTMLJSandCSS($html, $js, $css);
                    } else {
                        $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                    }
                    
                    $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues);
    
                    // Image
                    $image = $this->getSeleniumImage();
                    $statement .= "  \n  \nImage rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                } catch (Exception $e) {
                    $statement .= "* Unable to render content with selenium  \n";
                    $hasIssues = true;

                    $this->initSelenium();
                }

                if ($hasIssues) {
                    $statement .= "  \n  \nPlease note, if you preprocess HTML or JS, the line and column numbers are for the processed code.  \n";
                    $statement .= "Additionally, any added libraries will be omitted in the above check.  \n";
                    $hasIssues = true;
                }
            } else {
                $statement .= "Your bins at ";
                foreach ($links as $link) {
                    $statement .= $link." ";
                }
                $statement .= "are greatly appreciated!  \n";
                $i = 1;

                $statement .= "If there are any issues below, please fix them:  \n";

                foreach ($links as $link) {
                    $link = $link."edit?html,css,js";

                    $page = file_get_contents($link, false, stream_context_create(self::STREAM_CONTEXT));

                    if ($page === false) {
                        $statement .= "* Bin [".$i."](".$link.") does not exist or could not be found  \n";
                        $hasIssues = true;
                        $i++;
                        continue;
                    }

                    preg_match_all("/htt(p|ps)\:\/\/(www\.j|j)sbin.com\/bin\/start\.js\?[a-zA-Z0-9]+/", $page, $subpatterns);

                    $realPage = $subpatterns[0][0];

                    $opts = array(
                        "http"=>array(
                            "header"=>"Referer: ".$link."\r\n"
                      )
                    );

                    $page = file_get_contents($realPage, false, stream_context_create(array_merge($opts, self::STREAM_CONTEXT)));

                    if ($page === false) {
                        $statement .= "* Bin [".$i."](".$link.") does not exist or could not be found  \n";
                        $hasIssues = true;
                        $i++;
                        continue;
                    }

                    $page = preg_replace("/start\(/", "", $page, 1);

                    $page = substr($page, 0, strpos($page, ",\"url\":\"http"));

                    $json = json_decode($page."}", true);

                    $html = $json["html"];
                    $js = $json["javascript"];
                    $css = $json["css"];

                    if (!preg_match(self::REQUIRED_JS_FILE, $page) ||
                        !preg_match(self::REQUIRED_CSS_FILE, $page)) {
                        $statement .= "* Bin [".$i."](".$link.") may not correctly include ".self::PROJECT_NAME."  \n";
                        $hasIssues = true;
                    }

                    if (preg_match("/<body>/", $html)) {
                        $errors = self::getHTMLErrors($html);
                    } else {
                        $errors = self::getHTMLBodyErrors($html);
                    }

                    foreach ($errors as $error) {
                        if (strlen($error) != 0) {
                            $statement .= "* Bin [".$i."](".$link.") HTML ".$error."  \n";
                            $hasIssues = true;
                        }
                    }

                    $errors = self::getJSErrors($js);

                    foreach ($errors as $error) {
                        $statement .= "* Bin [".$i."](".$link.") JS ".$error."  \n";
                        $hasIssues = true;
                    }

                    $errors = self::specificProjectErrors($html, $js, $hasIssues);

                    foreach ($errors as $error) {
                        $statement .= "* Bin [".$i."](".$link.") ".$error."  \n";
                        $hasIssues = true;
                    }

                    try {
                        // Console
                        if (preg_match("/<body>/", $html)) {
                            $seleniumErrors = $this->getSeleniumErrorsFromHTMLJSandCSS($html, $js, $css);
                        } else {
                            $seleniumErrors = $this->getSeleniumErrorsFromBodyJSandCSS($html, $js, $css);
                        }
                        $statement .= self::processSeleniumErrors($seleniumErrors, $hasIssues, "Bin [".$i."](".$link.") ");
        
                        // Image
                        $image = $this->getSeleniumImage();
                        $statement .= "  \n  \nBin [".$i."](".$link.") image rendered with ".$this->seleniumDriver->getCapabilities()->getBrowserName()." v".$this->seleniumDriver->getCapabilities()->getVersion().": [".$image."](".$image.")  \n";
                    } catch (Exception $e) {
                        $statement .= "* Bin [".$i."](".$link.") Unable to render content with selenium  \n";
                        $hasIssues = true;

                        $this->initSelenium();
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

    // issue description analysis functions
    public function getEmptyBody(array $issue, bool &$hasIssues) : string {
        if (strlen($issue["body"]) === 0) {
            $statement  = "Your issue body is empty.  \n";
            $statement .= "Please add more information in order to allow us to quickly categorize and resolve the issue.  \n  \n";
            $hasIssues = true;
            return $statement;
        }
        return "";
    }

    public function getUnfilledTemplate(array $issue, bool &$hasIssues) : string {
        if (preg_match(self::UNFILLED_TEMPLATE_REGEX, $issue["body"])) {
            $statement  = "You have not filled out part of our issue template.  \n";
            $statement .= "Please fill in the template in order to allow us to quickly categorize and resolve the issue.  \n  \n";
            $hasIssues = true;
            return $statement;
        }
        return "";
    }

    // issue label functions
    public function checkFeatureLabel(array $issue) : string {
        $text = $issue["title"].$issue["body"];
        $text = strtolower($text);

        if (preg_match("/new feature/", $text) || preg_match("/feature request/", $text) || preg_match("/feature suggestion/", $text)) {
            $this->githubClient->api("issue")->labels()->add($this->repository[0], $this->repository[1], $issue["number"], "enhancement".self::LABEL_SUFFIX);
            return "I have detected a feature request.  If this is wrong, please remove the `enhancement ".self::LABEL_SUFFIX."` label.  \n\n";
        }
        return "";
    }

    protected function updatePRLabel(array $issue) {
        $comments = $this->githubPaginator->fetchAll($this->githubClient->api("issue")->comments(), "all", Array($this->repository[0], $this->repository[1], $issue["number"]));

        foreach ($comments as $comment) {
            $lowercaseComment = strtolower($comment["body"]);

            // creator has asked us to ignore the PR
            if (preg_match("/@".strtolower($this->username)." ignore\-pr/", $lowercaseComment) && $issue["user"]["login"] == $comment["user"]["login"]) {
                foreach ($issue["labels"] as $label) {
                    if ($label["name"] == "has-pr".self::LABEL_SUFFIX) {
                        $this->githubClient->api("issue")->labels()->remove($this->repository[0], $this->repository[1], $issue["number"], "has-pr".self::LABEL_SUFFIX);
                        return;
                    }
                }
                return;
            }
        }

        foreach ($issue["labels"] as $label) {
            if ($label["name"] == "has-pr".self::LABEL_SUFFIX || $label["name"] == "has-pr") {
                // skip
                return;
            }
        }

        foreach ($comments as $comment) {
            preg_match_all("/#\d+/", $comment["body"], $issueAndPRs);

            $issueAndPRs = $issueAndPRs[0];

            foreach ($issueAndPRs as $value) {
                if (in_array($value, $this->prNumbers)) {
                    echo "Adding has-pr to #".$issue["number"]."\n";
                    $this->githubClient->api("issue")->labels()->add($this->repository[0], $this->repository[1], $issue["number"], "has-pr".self::LABEL_SUFFIX);

                    $statement  = "Hi,  \n";
                    $statement .= "I auto-detected a PR commented on your issue: ".$value.".  \n";
                    $statement .= "As a result, I've added a `has-pr".self::LABEL_SUFFIX."` label to your issue.  \n";
                    $statement .= "  \n";
                    $statement .= "If this was in error, the issue creator can comment `@".$this->username." ignore-pr` and I will remove it on my next cycle.  \n";
                    $statement .= "  \n";
                    $statement .= self::BOT_ISSUE_FOOTER;

                    $this->githubClient->api("issue")->comments()->create($this->repository[0], $this->repository[1], $issue["number"], array("body" => htmlspecialchars($statement)));
                    return;
                }
            }
        }
    }

    // label PR wrapper functions
    protected function updateAllPRLabels() {
        foreach ($this->openIssues as $issue) {
            $this->updatePRLabel($issue);
        }
        foreach ($this->openIssues as $issue) {
            $this->updatePRLabel($issue);
        }
    }

    protected function updateOpenPRLabels() {
        foreach ($this->openIssues as $issue) {
            $this->updatePRLabel($issue);
            sleep(1);
        }
    }

    // other issue functions
    public function getSimilarIssues(array $issue) : string {
        $issue["title"] = strtolower($issue["title"]." ".$issue["body"]);
        foreach (self::ISSUE_KEYEWORDS as $keyword) {
            if (preg_match("/".preg_quote($keyword, "/")."/", $issue["title"])) {
                $statement  = "Similar issues related to `".$keyword."`:  \n";
                $similar = 0;
                foreach ($this->closedIssues as $tmpIssue) {
                    if (preg_match("/".preg_quote($keyword, "/")."/", $tmpIssue["title"]) && $similar < 15) {
                        if ($tmpIssue["number"] != $issue["number"]) {
                            $statement .= "* #".$tmpIssue["number"]." ".$tmpIssue["title"]."  \n";
                            $similar++;
                        }
                    }
                }
                foreach ($this->openIssues as $tmpIssue) {
                    if (preg_match("/".preg_quote($keyword, "/")."/", $tmpIssue["title"]) && $similar < 15) {
                        if ($tmpIssue["number"] != $issue["number"]) {
                            $statement .= "* #".$tmpIssue["number"]." ".$tmpIssue["title"]."  \n";
                            $similar++;
                        }
                    }
                }
                return $statement;
            }
        }
        return "No similar issues were found.  Please check that your issue has not yet been resolved elsewhere.  \n\n";
    }

    // maintenance functions
    protected function removeOldImages() {
        // DESTRUCTIVE!!!
        $files = glob($this->imageRepoPath."*tmp.png");
        $now = time();

        foreach ($files as $file) {
            if ($now - filemtime($file) >= 60 * 60 * 24 * 10) { // 10 days
                unlink($file);
            }
        }

        if (count($this->imageRepo->getStatus()->deleted()) != 0) {
            $this->imageRepo->stage();
            $this->imageRepo->commit("remove old images");
            $this->imageRepo->push();

            exec("cd ".$this->imageRepoPath.";
            git checkout --orphan newBranch;
            git add -A;  # Add all files and commit them
            git commit -m \"clear old files\";
            git branch -D master;  # Deletes the master branch
            git branch -m master;  # Rename the current branch to master
            git push -f origin master;  # Force push master branch to github
            git gc --aggressive --prune=all;     # remove the old files
            git push --set-upstream origin master;  # fix upstream", $out);
        }
    }

    // deconstruction functions
    public function __destruct() {
        $this->seleniumDriver->quit();
    }

    public function kill() {
        $this->seleniumDriver->quit();
        $this->alive = false;
    }
}

if (isset($argv[1])) {
    while (true) {
        try {
            switch ($argv[1]) {
                case 'main':
                    new Bot($repository, Bot::MAIN);
                    break;
                case 'pr':
                    new Bot($repository, Bot::HAS_PR);
                    break;
                case 'reanalyze':
                    new Bot($repository, Bot::REANALYZE);
                    break;
                default:
                    echo "Usage: php ".basename(__FILE__)." mode\n";
                    echo "\n";
                    echo "Mode can be:\n";
                    echo "  main - main thread, for initial issue analyzation.\n";
                    echo "  pr - pr thread, for has-pr label.\n";
                    echo "  reanalyze - reanalyze issues.\n";
                    break 2;
            }
        } catch (Exception $e) {
            echo "THREAD QUIT WITH ERROR ".var_dump($e, true)."...restarting\n";
        }
    }
} else {
    echo "Usage: php ".basename(__FILE__)." mode\n";
    echo "\n";
    echo "Mode can be:\n";
    echo "  main - main thread, for initial issue analyzation.\n";
    echo "  pr - pr thread, for has-pr label.\n";
    echo "  reanalyze - reanalyze issues.\n";
}
