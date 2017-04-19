# GitHubBot

Github issue management bot originally designed for Dogfalo/Materialize, but available for open use on any GitHub repository.  If you want to use it, see the [credit](#credit) section.

Table of Contents
===

* [Introduction](#introduction)
* [Known Issues](#known-issues)
* [Planned Features](#planned-features)
* [Capabilities](#capabilities)
* [Configuration](#configuration)
* [Installation](#installation)
* [Usage](#usage)
  * [Main thread](#main-thread)
  * [PR thread](#pr-thread)
  * [Reanalyzation thread](#reanalyzation-thread)
  * [Autoclose thread](#autoclose-thread)
* [Examples](#examples)
* [Dependency overview](#dependency-overview)
* [Method and Class Description](#method-and-class-description)
  * [Bot class](#bot_class)
  * [PlatformCheck extensions](#platformcheck_extensions)
* [Asynchronous running and start script](#asynchronous-running-and-start-script)
* [Credit](#credit)

Introduction
===

This bot is designed to automatically analyze and manage issues in a GitHub repository under a "bot" account.  It can analyze many different code hosting platforms, including Codepen, JSFiddle, and markdown snippets.  Additionally, it tests the code in a browser with selenium.

Known Issues
===

* Selenium cannot handle alert() well.  Currently, this is mitigated by providing a notice that the page could not be rendered and console tested.
* Over-verbose error/warning reporting for HTML (`alt` attribute warnings, etc)

Planned Features
===

* Support for more platforms for code analyzation (see #1)

Capabilities
===

### Issue analyzation

Issues are gathered from a growing list of sources based on the contents of the issue body.  
Currently supported platforms:

* Codepen.io
* JSFiddle.net
* Raw markdown blocks
* JSBin.com
* More coming soon!

The code garnered is analyzed in the following ways:

#### HTML Static Analysis (Tidy utility)

Using the Tidy HTML clean and repair utility, this bot is able to easily detect document tree errors such as unclosed elements, `<li>` outside of lists, and much more.  [More info](http://php.net/manual/en/tidy.intro.php)

#### JavaScript Static Analysis (jshint utility)

JSHint is a popular static analysis tool for the javascript language.  It can detect errors in syntax, as well as more advanced errors such as variable typing.  [More info](https://github.com/jshint/jshint)

#### Specific Project Checks

Preloaded with materialize definitions, but fully customizable, these checks are able to check advanced associations within code.  These are typical to ensure proper initialization and HTML structure.  These can be specified through the `PlatformCheck class`.

#### Runtime Console Warnings and Errors

Additionally, the provided code is run in the latest Google Chrome build using Selenium Webdriver.  As a result, many other runtime errors can be found and returned to the issue owner.

#### Inclusion-related Error Detection

On most platforms, the bot is able to detect when a configurable library is not included.  This prevents errors where the library isn't properly included in the demo.

### Screenshots

Each code example posted is rendered through Selenium on the latest Google Chrome build, screenshotted, and uploaded to a temporary git repository.  This repository is configurable, and by default deletes images and the history associated, as to use as little space as possible on GitHub.

This was attempted using other APIs, such as Imgur; however, the time between requests was simply too slow.

### Similar Issue Search

Based on configurable keywords, the bot is able to search and return a list of, preferably closed, issues pertaining to that category.

### Reanalyzation

Without making a new issue, the owner can request the bot re-analyze their code using a simple command `@BotName reanalyze`.  This thread runs asynchronously to the main one, allowing for new issues to take priority.  Additionally, it edits its previous analyzation(s) to say "This comment is out of date. See below for an updated analyzation."

### Auto closure

The bot can automatically close issues after a certain period of inactivity has elapsed.

### Pull Request Detection

The bot can detect when a PR has been mentioned in an open issue.  This allows the bot to assign a label to the issue as a response.  Furthermore, a `@BotName ignore-pr` command is available to stop this action.

### Label Assignment

The bot adds labels automatically to issues and uses a configurable suffix to do so.  The "awaiting response" label is used when errors have been detected and, as a result, is awaiting reanalyzation.  The issue owner can remove this label if this is incorrect, and it will not be assigned again.  If the issue is reanalyzed and the issues are resolved, the label will be removed.  Additionally, as mentioned in the previous section, the "has-pr" label is added or removed based on whether there is a PR mentioned, or the `ignore-pr` keyword was mentioned respectively.

### Threading

Through POSIX, this bot is able to run multiple threads simultaneously, allowing for speedy responses.  There is currently a `main` thread, which checks new issues, `reanalyze`, which reanalyzes per request, and the `has_pr` thread, which scans for and adds the `has-pr` label to issues with a PR mentioned in the comments.

Configuration
===

There are 3 main files which contain configuration information, listed in order of importance:

### config.json

This file is a simple JSON file containing 4 keys:

* `gh_username` - username of the bot without the @ sign
* `gh_password` - password for the bot
* `image_repo` - the name of the repo to store screenshots - syntax "user/repo"
* `image_repo_path` - a relative or full path to the folder where images should be stored.  The trailing slash should be omitted.

Below is a sample:

```json
{ "gh_username": "GHBot", "gh_password": "REDACTED",
  "image_repo" : "GHBot/IssueImages", "image_repo_path": "IssueImages/" }
```

### config.php

Contains 1 variable, `$repository`, which is an array.

`$repository[0]` is the owner of the repository, and `$repository[1]` is the name of the repository.

### Bot.php

Inside the main PHP file, ther are many more in-depth configuration options.  These should be condensed into a separate file, but, here we are.

On line 3, the `DEBUG` constant is set.  Currently, this is only used for more in-depth error reporting.

Inside the `Bot` class, there are many options:

* `TIDY_CONFIG` - contains an array which is used for configuration of the Tidy utility.  [Options](tidy.sourceforge.net/docs/quickref.html)
* `ISSUE_KEYEWORDS` - keywords to look for when searching for similar issues
* `PROJECT_NAME` - lowercase name of the project this bot is used on
* `REQUIRED_JS_FILE` - regex to match the JS file of the project you are using this bot on
* `REQUIRED_CSS_FILE` - regex to match the CSS file of the project you are using this bot on
* `LABEL_SUFFIX` - suffix to append to labels in order to distinguish from human applied labels at a glance
* `SLEEP_TIME` - Time to sleep between iterations of the `main` thread of the bot.  Should be lower than the others.
* `SLEEP_TIME_PRS` - Time to sleep between iterations of the `has-pr` thread of the bot.
* `SLEEP_TIME_REANALYZE` - Time to sleep between iterations of the `reanalyze` thread of the bot.
* `SLEEP_TIME_AUTOCLOSE` - Time to sleep between iterations of the `autoclose` thread of the bot.
* `CODEDOWN_LOCATION` - location of the codedown binary
* `JSHINT_LOCATION` - location of the jshint binary
* `STREAM_CONTEXT["http"]["timeout"]` - timeout to wait on HTTP requests.
* `UNFILLED_TEMPLATE_REGEX` - regex to match an unfilled issue template
* `BOT_ISSUE_FOOTER` - footer left at the bottom of each issue.  Default contains attribution to me and a bot notice.
* `DAYS_INACTIVE_TILL_CLOSE` - Number of days till an issue is autoclosed.
* `DAYS_INACTIVE_WARNING` - Number of days to wait after the bot has issued a warning
* `$checks` - Contains instances of the PlatformCheck class for advanced checks.  Information about these checks is [here](#checks)

Any other blocks of text can be modified with a basic find command.

## Additional files:

### html_header.html

What to wrap the body section of the HTML in - top section.  Must contain `<html>` and `<body>` tags.  Should also include correct references to your project's JS and CSS.

### html_footer.html

What to wrap the body section of the HTML in - bottom section.  Must contain `</body>` and `</html>` tags.

### jshint_header.js

Contains the javascript to prepend to statically analyzed JS.  Should contain your project, and dependencies (jQuery, react, etc).  Additionally, can contain jshint magic comments to enable and disable warnings.

Installation
===

In order to install this bot, you must use a Linux based or Unix based system.  It may work on Windows, however additional modifications may be required.

### Requirements

* PHP >= 7.1 with tidy extension
* composer
* composer requirements
  * knplabs/github-api
  * php-http/guzzle6-adapter
  * tburry/pquery
  * facebook/webdriver
  * cypresslab/gitelephant
* jshint
* codedown
* Selenium webdriver server on port 4444
* Google Chrome
* git
* GitHub repository for screenshots

Usage
===

1. Install the above dependencies
  * use `composer install` for composer dependencies
2. Clone the GitHub repository for screenshots
3. Properly fill out the configuration files to suit your bot
4. Run `./start.sh` in a terminal
5. Done!

The bot will log to logs/bot.log, and output to the terminal.

## Main Thread

The main thread of the bot checks, by default, every 10 seconds if there are any newly opened issues.  If so, it iterates through them, extracts code snippets, analyzes them per the above information, and replies/labels the issue.  This is the highest priority thread and has the shortest sleep time.

It can be run manually with the argument `main` or by creating an instance of `Bot` with `Bot::MAIN` as the second argument for the constructor.

## PR Thread

The PR thread of the bot checks, by default, every 100 seconds if there are any issues with PR numbers commented on them.  It does this by iterating over each issue's comments and checks for numbers with regex.  This is the lowest priority thread and has the longest sleep time.

It can be run manually with the argument `pr` or by creating an instance of `Bot` with `Bot::HAS_PR` as the second argument to the constructor.

## Reanalyzation Thread

The reanalyzation thread of the bot checks, by default, every 60 seconds if there are any issues where the owner wishes to reanalyze the code.  If so, it analyzes the code per the above checks.  It does this by iterating over each issue's comments.

It can be run manually with the argument `reanalyze` or by creating an instance of `Bot` with `Bot::REANALYZE` as the second argument to the constructor.

## Autoclose Thread

The autoclose thread of the bot checks, by default, once an hour for inactive issues.  If it finds one which has not been updated in a configurable time, then it will close it.

It can be run manually with the argument `autoclose` or by creating an instance of `Bot` with `Bot::AUTOCLOSE` as the second argument for the constructor.

Checks
===

The `PlatformCheck` abstract class has many extensions and is easy to add additional functionality.

Examples
===

Below are some issues which demonstrate the bot's usefulness:

* https://github.com/smileytechguy/issueTesting/issues/82
* https://github.com/smileytechguy/issueTesting/issues/80

Dependency Overview
===

Here is what each dependency is used for:

* tidy extension - HTML static analysis
* composer - package management
* composer requirements
  * knplabs/github-api - connect to github for issues, comments, labels, etc
  * php-http/guzzle6-adapter - helper for github-api
  * tburry/pquery - static DOM analysis
  * facebook/webdriver - selenium controller
  * cypresslab/gitelephant - local git repo management
* jshint - static JS analysis
* codedown - extract markdown codeblocks
* Selenium webdriver server on port 4444 - control chrome from PHP for runtime errors and screenshots
* Google Chrome - used to render screenshots and get runtime errors
* git - manage the screenshot repository 
* GitHub repository for screenshots - hold screenshots from the bot

Method and class description
===

## Bot class

### Initialization functions

* `public function __construct($repository, int $mode)` - constuct bot
* `protected function loadConfig()` - authenticate, etc
* `protected function initSelenium()` - kill selenium if running, and create new instance

### Data reloading functions

* `protected function refreshIssues()` - get new issues
* `protected function updatePRs()` - get new PRs

### Issue array wrapper functions

* `public function getUnanalyzedIssues() : array` - returns array of unanalyzed issues

### Core run functions

* `public function runMain()` - loop for main thread
* `public function runPRLabel()` - loop for pr thread
* `public function runReanalyze()` - loop for reanalyze thread
* `public function runAutoclose()` - loop for autoclose thread

### Issue analysis functions

* `protected function analyzeIssue(array $issue)` - analyze issue

### Issue analysis wrapper functions

* `protected function analyzeUnanalyzedIssues()` - analyze all open unanalyzed issues

### Issue reanalysis functions

* `protected function reanalyzeIssue(array $issue)` - check if issue needs reanalyzing and if so reanalyze

### Issue reanalysis wrapper functions

* `protected function reanalyzeIssues()` - check if reanalyzing is needed on all open issues

### Issue autoclose functions

* `protected function autocloseIssue(array $issue)` - attempt to autoclose issue if needed

### Issue autoclose wrapper functions

* `protected function autocloseIssues()` - attempt to autoclose all open issues

### Generic functions

* `protected function reviewAllIssues()` - review all open issues for everything, not recommended
* `protected function updateIssueCounter()` - set the highest analyzed issue to the latest issue

### Static analysis functions

* `public static function getHTMLErrors(string $in) : array - gets html errors based off input
* `public static function getJSErrors(string $in) : array` - get errors from JS (static)
* `public function specificProjectErrors(string $html, string $js, bool &$hasIssues) : array` - get errors specific to the project

### Static analysis wrapper functions

* `public static function getHTMLBodyErrors(string $in) : array` - gets html errors based off input that is only the `<body>` element

### Selenium core functions

* `public function getSeleniumErrors(string $html) : array` - get errors through selenium based on input html
* `string protected function getSeleniumImage()` - take screenshot of selenium browser

### Selenium wrapper functions

* `public function getSeleniumErrorsFromBodyJSandCSS(string $body, string $js, string $css) : array` - get errors through selenium based on input body, js, and css
* `public function getSeleniumErrorsFromHTMLJSandCSS(string $html, string $js, string $css) : array` - get errors through selenium based on input html, js, and css

### Selenium processing funtions

* `public static function processSeleniumErrors(array $errors, bool &$hasIssues, string $prefix="")` - formats selenium's error console format
* `string protected function uploadImage(string $file) : string` - upload image to git repo from filename

### Issue code processing functions

* `string public function getCodepenStatement(array $issue, bool &$hasIssues) : string` - find and get issues from codepen
* `string public function getJSFiddleStatement(array $issue, bool &$hasIssues) : string` - find and get issues from jsfiddle
* `string public function getMarkdownStatement(array $issue, bool &$hasIssues) : string` - find and get issues from markdown snippets
* `string public function getJSBinStatement(array $issue, bool &$hasIssues) : string` - find and get issues from jsbin

### Issue description analysis functions

* `string public function getEmptyBody(array $issue, bool &$hasIssues) : string` - get errors if issue body is empty
* `string public function getUnfilledTemplate(array $issue, bool &$hasIssues) : string` - get errors if issue template is unfilled

### Issue label functions

* `string public function checkFeatureLabel(array $issue) : string - get whether the issue is likely a feature request, and apply label if needed
* `protected function updatePRLabel(array $issue)` - check has-pr status of issue

### Issue PR label wrapper functions

* `protected function updateAllPRLabels()` - apply has-pr to all issues if they qualify
* `protected function updateOpenPRLabels()` - apply has-pr to all open issues if they qualify

### Other issue analysis functions

* `string public function getSimilarIssues(array $issue) : string` - get issues similar to this one based on keywords

### Maintenance functions

* `protected function removeOldImages()` - removes images a week old from the image repository

### Deconstruction functions

* `public function __destruct()` - deconstructor, kills selenium
* `public function kill()` - kills selenium, deconstructs

## PlatformCheck extensions

Create these with the format `(string $explaination, string ...$checks)` unless otherwise specified

* `ExistsDomCheck` - returns true if the check is in the DOM at least once
* `SingularOrNonexistentDomCheck` - returns true if the element appears once or not at all
* `SingularDomCheck` - returns true if the check is in the DOM exactly once
* `MultipleDomCheck` - returns true if the same number of elements are in each check (e.g. input in wrappers, etc)
* `DomJSCheck` - returns true if the DOM check doesn't exist or the DOM check exists and the JS check also exists
* `JSDomCheck` - returns true if the JS check doesn't exist or the JS check exists and the DOM check also exists

Asynchronous running and start script
===

The contents of start.sh allow for the asynchronous running of all threads independently:

Additionally, it logs to logs/bot.log and will restart automatically on error. 

```shell
echo "" > logs/bot.log

brew services start selenium-server-standalone >> logs/bot.log 2>&1 || brew services restart selenium-server-standalone >> logs/bot.log 2>&1 

php Bot.php main >> logs/bot.log 2>&1 &
sleep 2
php Bot.php pr >> logs/bot.log 2>&1 &
sleep 2
php Bot.php reanalyze >> logs/bot.log 2>&1 &
sleep 2
php Bot.php inactive >> logs/bot.log 2>&1 &

trap ctrl_c INT

function ctrl_c() {
    kill -2 -$PGID
    brew services restart selenium-server-standalone >> logs/bot.log 2>&1
    exit 130
}

tail -f logs/bot.log
```

This script is designed to easily start the bot.  Looking at it line by line:

:1 - `echo "" > logs/bot.log` - empty the log file  
:3 - `brew services...` - using homebrew, start or restart the selenium server.  This needs to be adapted for non-macOS environments  
:5-11 - start the bot's threads in order, all asynchronously, and all logging to logs/bot.log, with 2s delay between each  
:13 - `trap ctrl_c INT` - trap ^C and run the ctrl_c function  
:15 - `function ctrl_c() {` define ctrl_c function  
:16 - `kill -2 -$PGID` - kill the php scripts with ^C  
:17 - `brew services restart...` - restart selenium (close any straggling browsers left from the bot)  
:18 - `exit 130` - exit with ^C exit code  
:21 - `tail -f logs/bot.log` - output log infinitely until ^C

Credit
===

Any repository is welcome to use this bot, I just ask that you let me know so I know how it's being used.  
If you really need to, you can remove the string that says it was made by me.  The string is under `Bot::BOT_ISSUE_FOOTER`.

Also, I couldn't have made this without the help of those in the materialize-devs chatroom, specifically @NonameSLdev, @fega, @tomscholz, and more.

All the projects in the [dependency overview](#dependency-overview) were also invaluable in the development of this bot, they did a lot of the heavy lifting.
