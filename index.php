<?php

// Ask your questions in the MadelineProto [FA] support group.

declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
ignore_user_abort(true);
error_reporting(E_ALL);                               // always TRUE
ini_set('ignore_repeated_errors', '1');               // always TRUE
ini_set('display_startup_errors', '1');
ini_set('display_errors',         '1');               // FALSE only in production or real server
ini_set('log_errors',             '1');               // Error logging engine
ini_set('error_log',              'MadelineProto.log'); // Logging file path

define("SCRIPT_INFO",    'Base V1.2.4');
define('SESSION_FILE',   'session.madeline');
define('SERVER_NAME',    '');
define('SAPI_NAME', (PHP_SAPI === 'cli') ? (isset($_SERVER['TERM']) ? 'Shell' : 'Cron') : 'Web');

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use function\Amp\File\{get, put, exists, getSize};

require_once 'functions.php';
includeMadeline('phar');
require_once 'EventHandler.php';

if (PHP_SAPI !== 'cli') {
    error_log("Robot's URL: '" . getURL() . "' SERVER_NAME: '" . getWebServerName() . "'");
}
error_log("Server's Memory Limit: " . ini_get('memory_limit'));
if (PHP_SAPI !== 'cli' && !getWebServerName()) {
    if (SERVER_NAME === '') {
        throw new Exception("To enable the restart, the constant SERVER_NAME must be defined!");
    }
    setWebServerName(SERVER_NAME);
}

if (file_exists('data')) {
    if (!is_dir('data')) {
        throw new Exception('data folder already exists as a file');
    }
} else {
    mkdir('data'/*, NEEDED_ACCESS_LEVEL*/);
}
if (!file_exists('data/launches.txt')) {
    $handle = fopen("data/launches.txt", "w");
    fclose($handle);
}
if (!file_exists('data/startups.txt')) {
    $handle = fopen("data/startups.txt", "w");
    fclose($handle);
}

$robotName    = SCRIPT_NAME;
$startTime    = \time();
$launchesFile = \realpath('data/launches.txt');
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
$settings['app_info']['system_version'] =  hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB";
$MadelineProto = new API(SESSION_FILE, $settings);
if (!$MadelineProto) {
    echo ('Strange! MadelineProto object is null. exiting ....' . PHP_EOL);
    Logger::log("Strange! MadelineProto object is null. exiting ....", Logger::ERROR);
    exit("Unsuccessful MadelineProto Object creation.");
}
$MadelineProto->async(true);
$oldInstance = Tools::getVar($MadelineProto, 'oldInstance');
Logger::log($oldInstance ? ' Existing Session!' : 'New Session!',  Logger::ERROR);
Logger::log("API object created! Authorization Status: " . getAuthorized(authorized($MadelineProto)), Logger::ERROR);

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
    static function () use ($MadelineProto, $robotName, $startTime, $launchesFile) {
        $now          = time();
        $duration     = $now - $startTime;
        $launchMethod = getLaunchMethod();
        $memory       = getMemUsage(true);
        echo ('Shutting down ....<br>' . PHP_EOL);
        $msg = $robotName . " stopped at " . date("d H:i:s", $now) . "!  Execution duration:" . gmdate('H:i:s', $duration);
        if ($MadelineProto) {
            try {
                $MadelineProto->logger($msg, Logger::ERROR);
                $MadelineProto->logger("Launch Method:'$launchMethod'  Duration: $duration", Logger::ERROR);
                $launchesHandle = fopen($launchesFile, 'a');
                fwrite($launchesHandle, "\n$now $launchMethod $duration $memory");
            } catch (\Exception $e) {
                Logger::log($msg, Logger::ERROR);
                Logger::log($e,   Logger::ERROR);
            }
        } else {
            try {
                Logger::log($msg, Logger::ERROR);
            } catch (\Exception $e) {
                echo ('Oh, no!' . PHP_EOL);
                var_dump($e);
            }
        }
    },
    'duration'
);

$maxRecycles = 5;
safeStartAndLoop($MadelineProto, $genLoop, $maxRecycles);

exit(PHP_EOL . 'Finished' . PHP_EOL);
