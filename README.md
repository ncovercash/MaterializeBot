# MaterializeBot

Github issue management bot orignally designed for Dogfalo/Materialize.

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
* [Examples](#examples)
* [Dependency overview](#dependencies)
* [Method Description](#method-description)

Introduction
===

This bot is designed to automatically analyze and manage issues in a GitHub repository under a "bot" account.  It can analyze many different code hosting platforms, including Codepen, JSFiddle, and markdown snippets.

Known Issues
===

* Selenium cannot handle alert() well.  Currently, this is mitigated by providing a notice that the page could not be rendered and console tested.
* Over-verbose error/warning reporting for HTML (`alt` attribute warnings, etc)

Planned Features
===

* Issue auto-closing after a specified time (see #8)
* Support for more platforms for code analyzation (see #1)
* Dom project-specific checks

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

Preloaded with materialize definitions, these checks are able to check basic associations within code.  These are typically to ensure proper initialization.

#### Runtime Console Warnings and Errors

Additionally, the provided code is run in the latest Google Chrome build using Selenium Webdriver.  As a result, many other runtime errors can be found and returned to the issue owner.

#### Inclusion-related Error Detection

On most platforms, the bot is able to detect when Materialize, or another configurable library is not included.  This prevents errors where the library isn't properly included in the demo.

### Screenshots

Each code example posted is rendered through Selenium on the latest Google Chrome build, screenshotted, and uploaded to a temporary git repository.  This repository is configurable, and by default deletes images and the history associated, as to use as little space as possible on GitHub.

This was attempted using other APIs, such as Imgur, however the time between requests was simply too slow.

### Similar Issue Search

Based on configurable keywords, the bot is able to search and return a list of, preferably closed, issues pertaining to that category.

### Reanalyzation

Without making a new issue, the owner can request the bot re-analyze their code using a simple command `@BotName reanalyze`.  This thread runs asynchrounously to the main one, allowing for new issues to take priority.

### Pull Request Detection

The bot can detect when a PR has been mentioned in an open issue.  This allows the bot to assign a label to the issue as a response.  Furthermore, a `@BotName ignore-pr` command is available to stop this action.

### Label Assignment

The bot adds labels automatically to issues, and uses a configurable suffix to do so.  The "awaiting response" label is used when errors have been detected, and as a result is awaiting reanalyzation.  The issue owner can remove this label if this is incorrect, and it will not be assigned again.  If the issue is reanalyzed and the issues are resolved, the label will be removed.  Additionally, as mentioned in the previous section, the "has-pr" label is added or removed based on whether there is a PR mentioned, or the `ignore-pr` keyword was mentioned respectively.

### Threading

Through POSIX, this bot is able to run multiple threads simotaneously, allowing for speedy responses.  There is currently a `main` thread, which checks new issues, `reanalyze`, which reanalyzes per request, and the `has_pr` thread, which scans for and adds the `has-pr` label to issues with a PR mentioned in the comments.

Configuration
===

There are 3 files which contain configuration files, listed in order of importance:

### config.json

This file is a simple JSON file containing 4 keys:

* `gh_username` - username of the bot without the @ sign
* `gh_password` - passsword for the bot
* `image_repo` - the name of the repo to store screenshots - syntax "user/repo"
* `image_repo_path` - a relative of full path to the folder where images should be stored.  The trailing slash should be omitted.

Below is a sample:

```json
{ "gh_username": "MaterializeBot", "gh_password": "REDACTED",
  "image_repo" : "MaterializeBot/IssueImages", "image_repo_path": "IssueImages/" }
```

### config.php

Contains 1 variable, `$repository`, which is an array.

`$repository[0]` is the owner of the repository, and `$repository[1]` is the name of the repository.

### MaterializeBot.php

Inside the PHP file itself are many more in-depth configuration options.  These should be condensed into a separate file, but, here we are.

On line 3, the `DEBUG` constant is set.  Currently, this is only used for more in-depth error reporting.

Inside the `Bot` class, there are many options:

* `TIDY_CONFIG` - contains an array which is used for configuration of the Tidy utility.  [Options](tidy.sourceforge.net/docs/quickref.html)
* `PROJECT_NAME` - lowercase name of the project this bot is used on
* `REQUIRED_JS_FILE` - regex to match the JS file of the project you are using this bot on
* `REQUIRED_CSS_FILE` - regex to match the CSS file of the project you are using this bot on
* `LABEL_SUFFIX` - suffix to append to labels in order to distinguish from human applied labels at a glance
* `SLEEP_TIME` - Time to sleep between iterations of the main thread of the bot.  Should be lower than the 2 below.
* `SLEEP_TIME_PRS` - Time to sleep between iterations of the `has-pr` thread of the bot.
* `SLEEP_TIME_REANALYZE` - Time to sleep between iterations of the `reanalyze` thread of the bot.
* `SPECIFIC_PAIR_CHECKS` - Contains key/value pairs, where if the key exists the value must exist too.
* `CODEDOWN_LOCATION` - location of the codedown binary
* `JSHINT_LOCATION` - location of the jshint binary
* `STREAM_CONTEXT["http"]["timeout"]` - timeout to wait on HTTP requests.

### html_header.html

What to wrap the body section of the html in - top section.  Must contain `<html>` and `<body>` tags.  Should also include correct references to your project's JS and CSS.

### html_footer.html

What to wrap the body section of the html in - bottom section.  Must contain `</body>` and `</html>` tags.

### jshint_header.js

Contains the javascript to prepend to statically analyzed JS.  Should contain your project, and dependencies (jQuery, react, etc).  Additionally, can contain jshint magic comments to enable and disable warnings.

Installation
===



Additionally, the `jshint` and `codedown` NPM programs, selenium webdriver server on default ports, and all composer requirements are needed to run this.

If you want to use this on a different project, here are the things you need to change:

* config.php - contains repo information
* jshint_header.js - contains javascript files to be included (excluding jquery) and jshint parameters
* html_header.html - contains the top of the html document for selenium tests
* html_footer.html - contains the bottom of the html document for selenium tests
* MaterializeBot.php Bot::TIDY_CONFIG - tidy configuration options
* MaterializeBot.php Bot::SPECIFIC_PAIR_CHECKS - checks errors specific to materialize, uses an array where if a key exists the value must too
* MaterializeBot.php Bot::REQUIRED_CSS_FILE - file to check is implemented correctly in codepen/jsfiddle/etc.
* MaterializeBot.php Bot::REQUIRED_JS_FILE - file to check is implemented correctly in codepen/jsfiddle/etc.
* MaterializeBot.php Bot::PROJECT_NAME - name of project
* MaterializeBot.php Bot::getUnfilledTemplate - checks errors specific to the issue template

Any other blocks of text are generic, and can be found with a simple find command.
