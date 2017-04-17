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

Planned Features
===

* Issue auto-closing after a specified time (see smileytechguy/MaterializeBot#8)
* Support for more platforms for code analyzation (see smileytechguy/MaterializeBot#1)

Capabilities
===



A `config.json` file is needed with keys `gh_username` - bot GH username, `gh_password` - bot GH password, `image_repo` - name of repo to store images (user/repo), and `image_repo_path` - the path to the image repository, can be relative in order to run this bot.

The imgur api is used to upload images of the code given by the user once run.

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
