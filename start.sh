echo "" > logs/bot.log

brew services start selenium-server-standalone >> logs/bot.log 2>&1 || brew services restart selenium-server-standalone >> logs/bot.log 2>&1 

php MaterializeBot.php main >> logs/bot.log 2>&1 &
sleep 2
php MaterializeBot.php pr >> logs/bot.log 2>&1 &
sleep 2
php MaterializeBot.php reanalyze >> logs/bot.log 2>&1 &

trap ctrl_c INT

function ctrl_c() {
	kill -2 -$PGID
	brew services restart selenium-server-standalone >> logs/bot.log 2>&1
	exit 130
}

tail -f logs/bot.log
