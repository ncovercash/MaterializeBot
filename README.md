# MaterializeBot

Bot for Dogfalo/materialize that helps validate issues and other general tasks.

A `config.json` file is needed with keys `username` and `password` in order to run this bot.

Additionally, the `jshint` CLI program, and all composer requirements are needed to run this.

If you want to use this on a different project, here are the things you need to change:

* config.php - contains repo information
* jshint_header.php - contains javascript files to be included (excluding jquery) and jshint parameters
* MaterializeBot.php Bot::tidy_config - tidy configuration options
* MaterializeBot.php Bot::jshint_header_length - length of the jshint header
* MaterializeBot.php Bot::specific_pair_checks - checks errors specific to materialize, uses an array where if a key exists the value must too
* MaterializeBot.php Bot::getUnfilledTemplate - checks errors specific to the issue template
