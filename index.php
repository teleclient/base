<?php

// Ask your questions in the MadelineProto [FA] support group.

declare(strict_types=1);

namespace teleclient\base;

$scriptStartTime = hrtime(true);

require_once 'functions.php';
include_once 'config.php';

\date_default_timezone_set('Asia/Tehran');
ignore_user_abort(true);
error_reporting(E_ALL);                                 // always TRUE
ini_set('ignore_repeated_errors', '1');                 // always TRUE
ini_set('display_startup_errors', '1');
ini_set('display_errors',         '1');                 // FALSE only in production or real server
ini_set('log_errors',             '1');                 // Error logging engine
ini_set('error_log',              'MadelineProto.log'); // Logging file path
set_include_path(\get_include_path() . PATH_SEPARATOR . dirname(__DIR__, 1));

define("SCRIPT_NAME",       'Base');
define("SCRIPT_VERSION",    'V2.0.0');
define('SESSION_FILE',      'session.madeline');
define("SCRIPT_START_TIME", $scriptStartTime);
define("DATA_DIRECTORY",    \realpath('data'));
define("STARTUPS_FILE",     \realpath('data/startups.txt'));
define("LAUNCHES_FILE",     \realpath('data/launches.txt'));
define("MEMORY_LIMIT",      ini_get('memory_limit'));

if (PHP_SAPI !== 'cli') {
    error_log("Robot's URL: '" . \getURL() . "' SERVER_NAME: '" . (getWebServerName() ?? '') . "'");
    if (!getWebServerName()) {
        echo ("To enable the restart, the constant SERVER_NAME must be defined!" . PHP_EOL);
        //\setWebServerName('');
    }
}
define('SERVER_NAME', getWebServerName() ?? '');

if (file_exists(DATA_DIRECTORY)) {
    if (!is_dir(DATA_DIRECTORY)) {
        throw new \ErrorException('data folder already exists as a file');
    }
} else {
    mkdir(DATA_DIRECTORY);
}
if (!file_exists(LAUNCHES_FILE)) {
    \touch(LAUNCHES_FILE);
    //$handle = fopen(LAUNCHES_FILE, "w");
    //fclose($handle);
}
if (!file_exists(STARTUPS_FILE)) {
    $handle = fopen(STARTUPS_FILE, "w");
    fclose($handle);
}

$launch = appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME);
error_log(toJSON($launch));
unset($launch);

if (\file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
} else {
    if (!\file_exists('madeline.php')) {
        \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    require_once 'madeline.php';
}
require_once 'EventHandler.php';

use teleclient\base\EventHandler;
use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use function \Amp\File\{get, put, exists, getSize};

$tempId = Shutdown::addCallback(
    static function (): void {
    },
    'duration'
);

//$settings['app_info']['api_id']   = 6;                                  // <== Use your own, or let MadelineProto ask you.
//$settings['app_info']['api_hash'] = "eb06d4abfb49dc3eeb1aeb98ae0f581e"; // <== Use your own, or let MadelineProto ask you.
$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger'] = Logger::FILE_LOGGER;
$settings['peer']['full_info_cache_time'] = 60;
$settings['serialization']['cleanup_before_serialization'] = true;
$settings['serialization']['serialization_interval'] = 60;
$settings['app_info']['app_version']    = SCRIPT_NAME . ' ' . SCRIPT_VERSION;
$settings['app_info']['system_version'] =  \hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB";

$apiCreationBegin = \hrtime(true);

$config['session_file']       = SESSION_FILE;
$config['script_start_time']  = SCRIPT_START_TIME;
$config['memory_limit']       = MEMORY_LIMIT;
$config['script_name']        = SCRIPT_NAME;
$config['script_version']     = SCRIPT_VERSION;
$config['os_family']          = PHP_OS_FAMILY;
$config['server_name']        = SERVER_NAME;
$config['session_file']       = SESSION_FILE;
$config['startups_file']      = STARTUPS_FILE;
$config['launches_file']      = LAUNCHES_FILE;
$config['api_creation_begin'] = $apiCreationBegin;

$MadelineProto = new API(SESSION_FILE, $settings);

if (!$MadelineProto) {
    error_log("config: " . toJSON($config));
    Logger::log("Strange! MadelineProto object is null.",      Logger::ERROR);
    Logger::log("Unsuccessful MadelineProto Object creation.", Logger::ERROR);
    throw new ErrorException("Strange! MadelineProto object is null.");
}
$apiCreationEnd = \hrtime(true);
$config['api_creation_end']    = $apiCreationEnd;
$config['authorization_state'] = getAuthorized(authorized($MadelineProto));
$MadelineProto->logger("config: " . toJSON($config), Logger::ERROR);

$MadelineProto->async(true);

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
        $delay = secondsToNexMinute();
        return $delay; // Repeat at the very begining of the next minute, sharp.
    },
    'Repeating Loop'
);

$tempId = Shutdown::addCallback(
    function () use ($MadelineProto) {
        $scrintEndTime = \hrtime(true);
        $now           = time();
        //$reason = $MadelineProto->__get('stop_reason');
        //$stopReason = $MadelineProto->__get('stop_reason') ?? 'UNKNOWN';
        $stopReason = $MadelineProto->getEventHandler()->getStopReason();
        //$test = $eh->getRobotId();
        //echo ($test . PHP_EOL);
        //var_dump($stopReason);
        //$lastError     = error_get_last();
        //echo (toJSON($lastError));
        $duration = formatDuration($scrintEndTime - SCRIPT_START_TIME);
        $record   = updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scrintEndTime,  $stopReason);
        echo ('Shutting down ....<br>' . PHP_EOL);
        $msg = SCRIPT_NAME . ' ' . SCRIPT_VERSION . " stopped at " . date("d H:i:s", $now) . "!  Execution duration: " . $duration;
        if ($MadelineProto) {
            try {
                $MadelineProto->logger(toJSON($record), Logger::ERROR);
                $MadelineProto->logger($msg, Logger::ERROR);
            } catch (\Exception $e) {
                Logger::log('Hurray!', Logger::ERROR);
                Logger::log(toJSON($record));
                Logger::log($msg,      Logger::ERROR);
                Logger::log($e,        Logger::ERROR);
            }
        } else {
            try {
                Logger::log(toJSON($record), Logger::ERROR);
                Logger::log($msg, Logger::ERROR);
            } catch (\Exception $e) {
                echo ('Oh, no!' . PHP_EOL);
                var_dump($e->getMessage());
            }
        }
    },
    'duration'
);

$maxRecycles = 5;
safeStartAndLoop($MadelineProto, \teleclient\base\EventHandler::class,  $genLoop, $maxRecycles);

exit(PHP_EOL . 'Update-Loop Exited' . PHP_EOL);

/*
function scriptShutdown(string $launchesFile, int $scriptStartTime, string  $stopReason, string $scriptName): void
{
    $scrintEndTime = \hrtime(true);
    $now    = time();
    $duration = formatDuration($scrintEndTime - SCRIPT_START_TIME);
    $record = updateLaunchRecord($launchesFile, $scriptStartTime, $scrintEndTime,  $stopReason);
    if ($record !== null) {
        Logger::log(toJSON($record));
    }
    echo ('Shutting down ....<br>' . PHP_EOL);
    $msg = $scriptName . " stopped at " . date("d H:i:s", $now) . "!  Execution duration:" . $duration;
    if ($MadelineProto) {
        try {
            Logger::log($msg, Logger::ERROR);
        } catch (\Exception $e) {
            Logger::log('Hurray!', Logger::ERROR);
            Logger::log($msg,      Logger::ERROR);
            Logger::log($e,        Logger::ERROR);
        }
    } else {
        try {
            Logger::log($msg, Logger::ERROR);
        } catch (\Exception $e) {
            echo ('Oh, no!' . PHP_EOL);
            var_dump($e->getMessage());
        }
    }
}
*/