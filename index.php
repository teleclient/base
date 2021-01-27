<?php

// Ask your questions in the MadelineProto [FA] support group.

declare(strict_types=1);

namespace teleclient\base;

$scriptStartTime = microtime(true);

require_once 'functions.php';
include_once 'config.php';

\date_default_timezone_set('gmt');
ignore_user_abort(true);
error_reporting(E_ALL);                                 // always TRUE
ini_set('ignore_repeated_errors', '1');                 // always TRUE
ini_set('display_startup_errors', '1');
ini_set('display_errors',         '1');                 // FALSE only in production or real server
ini_set('log_errors',             '1');                 // Error logging engine
ini_set('error_log',              'MadelineProto.log'); // Logging file path
ini_set('precision',              '18');
//set_include_path(\get_include_path() . PATH_SEPARATOR . dirname(__DIR__, 1));

define("SCRIPT_NAME",       'Base');
define("SCRIPT_VERSION",    'V2.0.0');
define('SESSION_FILE',      'session.madeline');
define("SCRIPT_START_TIME", $scriptStartTime);
define("DATA_DIRECTORY",    \makeDataDirectory('data'));
define("STARTUPS_FILE",     \makeDataFile(DATA_DIRECTORY, 'startups.txt'));
define("LAUNCHES_FILE",     \makeDataFile(DATA_DIRECTORY, 'launches.txt'));
define("MEMORY_LIMIT",      ini_get('memory_limit'));
define('SERVER_NAME',       \makeWebServerName());
define('REQUEST_URL',       \getURL() ?? '');
define('USER_AGENT',        $_SERVER['HTTP_USER_AGENT'] ?? '');
define('MAX_RECYCLES',      5);
define('MAX_RESTARTS',      5);

error_log('');
error_log('==========================================================');
error_log(SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' started at ' . date('H:i:s') . " by " . \getLaunchMethod() . " launch method  using " . \getPeakMemory() . ' memory.');
error_log('==========================================================');

if (\file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
} else {
    if (!\file_exists('madeline.php')) {
        \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    require_once 'madeline.php';
}
require_once 'EventHandler.php';

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use Amp\Loop;
use function\Amp\File\{get, put, exists, getSize};

Shutdown::addCallback(
    static function (): void {
    },
    'duration'
);

$restartsCount = checkTooManyRestarts(LAUNCHES_FILE);
$nowstr = readableMilli(SCRIPT_START_TIME, new \DateTimeZone('Asia/Tehran'));
if ($restartsCount > MAX_RESTARTS) {
    $text = 'More than ' . MAX_RESTARTS . ' times restarted within a minute. Permanently shutting down ....';
    Logger::log($text, Logger::ERROR);
    Logger::log(SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' on ' . hostname() . ' is stopping at ' . $nowstr, Logger::ERROR);
    exit($text . PHP_EOL);
}

$signal  = null;
Loop::run(function () use (&$signal) {
    if (\defined('SIGINT')) {
        $siginit = Loop::onSignal(SIGINT, static function () use (&$signal) {
            $signal = 'sigint';
            Logger::log('Got sigint', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        });
        Loop::unreference($siginit);

        $sigterm = Loop::onSignal(SIGTERM, static function () use (&$signal) {
            $signal = 'sigterm';
            Logger::log('Got sigterm', Logger::FATAL_ERROR);
            Magic::shutdown(1);
        });
        Loop::unreference($sigterm);
    }
});

$settings['app_info']['api_id']   = 904912; //6;                                  // <== Use your own, or let MadelineProto ask you.
$settings['app_info']['api_hash'] = '8208f08eefc502bedea8b5d437be898e'; // "eb06d4abfb49dc3eeb1aeb98ae0f581e"; // <== Use your own, or let MadelineProto ask you.
$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger'] = Logger::FILE_LOGGER;
$settings['peer']['full_info_cache_time'] = 60;
$settings['serialization']['cleanup_before_serialization'] = true;
$settings['app_info']['app_version']    = SCRIPT_NAME . ' ' . SCRIPT_VERSION;
$settings['app_info']['system_version'] =  \hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB";

$apiCreationStart = \hrtime(true);
$MadelineProto = new API(SESSION_FILE, $settings);
$apiCreationEnd = \hrtime(true);
\sanityCheck($MadelineProto, $apiCreationStart, $apiCreationEnd);

Shutdown::addCallback(
    function () use ($MadelineProto, &$signal) {
        echo (PHP_EOL . 'Shutting down ....<br>' . PHP_EOL);
        $scriptEndTime = \microTime(true);
        $stopReason = 'nullapi';
        if ($signal !== null) {
            $stopReason = $signal;
        } elseif ($MadelineProto) {
            try {
                $stopReason = $MadelineProto->getEventHandler()->getStopReason();
                if (false && $stopReason === 'UNKNOWN') {
                    $error = \error_get_last();
                    $stopReason = isset($error) ? 'error' : $stopReason;
                }
            } catch (\TypeError $e) {
                $stopReason = 'sigterm';
            }
        }
        $duration = \formatDuration($scriptEndTime - SCRIPT_START_TIME);
        $peakMemory = \getPeakMemory();
        $record   = \updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scriptEndTime, $stopReason, $peakMemory);
        Logger::log(toJSON($record), Logger::ERROR);
        $msg = SCRIPT_NAME . ' ' . SCRIPT_VERSION . " stopped due to $stopReason!  Execution duration: " . $duration;
        error_log($msg);
    },
    'duration'
);

$genLoop = new GenericLoop(
    $MadelineProto,
    function () use ($MadelineProto) {
        $eventHandler = $MadelineProto->getEventHandler();
        $now = time();
        if ($eventHandler->getLoopState() && $now % 60 === 0) {
            $msg = 'Time is ' . date('H:i:s', $now) . '!';
            yield $MadelineProto->logger($msg, Logger::ERROR);
            if (false) {
                yield $MadelineProto->account->updateProfile([
                    'about' => date('H:i:s', $now)
                ]);
            }
            if (false) {
                $robotId = $eventHandler->getRobotID();
                yield $MadelineProto->messages->sendMessage([
                    'peer'    => $robotId,
                    'message' => $msg
                ]);
            }
        }
        yield $MadelineProto->sleep(1);
        $delay = \secondsToNexMinute();
        return $delay; // Repeat at the very begining of the next minute, sharp.
    },
    'Repeating Loop'
);

\safeStartAndLoop($MadelineProto, \teleclient\base\EventHandler::class,  [$genLoop], MAX_RECYCLES);

exit;
