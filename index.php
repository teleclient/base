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
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}
define("SCRIPT_NAME",       'Base');
define("SCRIPT_VERSION",    'V2.0.0');
define('SESSION_FILE',      'session.madeline');
define("SCRIPT_START_TIME", $scriptStartTime);
define("DATA_DIRECTORY",    makeDataDirectory('data'));
define("STARTUPS_FILE",     makeDataFile(DATA_DIRECTORY, 'startups.txt'));
define("LAUNCHES_FILE",     makeDataFile(DATA_DIRECTORY, 'launches.txt'));
define("MEMORY_LIMIT",      ini_get('memory_limit'));
define('SERVER_NAME',       makeWebServerName());

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

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use function\Amp\File\{get, put, exists, getSize};

Shutdown::addCallback(
    static function (): void {
    },
    'duration'
);

$settings['app_info']['api_id']   = 6;                                  // <== Use your own, or let MadelineProto ask you.
$settings['app_info']['api_hash'] = "eb06d4abfb49dc3eeb1aeb98ae0f581e"; // <== Use your own, or let MadelineProto ask you.
$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger'] = Logger::FILE_LOGGER;
$settings['peer']['full_info_cache_time'] = 60;
$settings['serialization']['cleanup_before_serialization'] = true;
$settings['app_info']['app_version']    = SCRIPT_NAME . ' ' . SCRIPT_VERSION;
$settings['app_info']['system_version'] =  \hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB";

$apiCreationStart = \hrtime(true);
$MadelineProto = new API(SESSION_FILE, $settings);
$apiCreationEnd = \hrtime(true);
sanityCheck($MadelineProto, $apiCreationStart, $apiCreationEnd);
$MadelineProto->async(true);

Shutdown::addCallback(
    function () use ($MadelineProto) {
        echo (PHP_EOL . 'Shutting down ....<br>' . PHP_EOL);
        $scrintEndTime = \hrtime(true);
        $stopReason = 'nullapi';
        if ($MadelineProto) {
            try {
                $stopReason = $MadelineProto->getEventHandler()->getStopReason();
                if ($stopReason === 'UNKNOWN') {
                    $error = \error_get_last();
                    $stopReason = isset($error) ? 'error' : $stopReason;
                }
            } catch (\TypeError $e) {
                $stopReason = 'sigterm';
            }
        }
        $duration = formatDuration($scrintEndTime - SCRIPT_START_TIME);
        $record   = updateLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $scrintEndTime,  $stopReason);
        Logger::log(toJSON($record), Logger::ERROR);
        $msg = SCRIPT_NAME . ' ' . SCRIPT_VERSION . " stopped due to $stopReason!  Execution duration: " . $duration;
        error_log($msg);
        //Magic::shutdown(1);
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
        $delay = secondsToNexMinute();
        return $delay; // Repeat at the very begining of the next minute, sharp.
    },
    'Repeating Loop'
);

//$msgIds = Tools::getVar($MadelineProto->API, 'msg_ids');
//Logger::log(toJSON($msgIds), Logger::ERROR);

$maxRecycles = 5;
safeStartAndLoop($MadelineProto, \teleclient\base\EventHandler::class,  $genLoop, $maxRecycles);

exit(PHP_EOL . 'Update-Loop Exited' . PHP_EOL);

function makeDataDirectory($directory): string
{
    if (file_exists($directory)) {
        if (!is_dir($directory)) {
            throw new \ErrorException('data folder already exists as a file');
        }
    } else {
        mkdir($directory);
    }
    $dataDirectory = realpath($directory);
    return $dataDirectory;
}

function makeDataFile($dataDirectory, $dataFile): string
{
    $fullPath = $dataDirectory . '/' . $dataFile;
    if (!file_exists($fullPath)) {
        \touch($fullPath);
    }
    $real = realpath('data/' . $dataFile);
    return $fullPath;
}

function makeWebServerName(): ?string
{
    $webServerName = null;
    if (PHP_SAPI !== 'cli') {
        $webServerName = getWebServerName();
        if (!$webServerName()) {
            echo ("To enable the restart, the constant SERVER_NAME must be defined!" . PHP_EOL);
            $webServerName = '';
        }
    }
    return $webServerName;
}

function sanityCheck(API $MadelineProto, int $apiCreationStart, int $apiCreationEnd): void
{
    $variables['session_file']        = SESSION_FILE;
    $variables['script_start_time']   = SCRIPT_START_TIME;
    $variables['memory_limit']        = MEMORY_LIMIT;
    $variables['script_name']         = SCRIPT_NAME;
    $variables['script_version']      = SCRIPT_VERSION;
    $variables['os_family']           = PHP_OS_FAMILY;
    $variables['server_name']         = SERVER_NAME;
    $variables['session_file']        = SESSION_FILE;
    $variables['startups_file']       = STARTUPS_FILE;
    $variables['launches_file']       = LAUNCHES_FILE;
    $variables['php_version']         = PHP_VERSION;
    $variables['api_creation_start']  = $apiCreationStart;
    $variables['api_creation_end']    = $apiCreationEnd;
    $variables['authorization_state'] = getAuthorized(authorized($MadelineProto));

    if (!$MadelineProto) {
        error_log("variables: " . toJSON($variables));
        Logger::log("Strange! MadelineProto object is null.",      Logger::ERROR);
        Logger::log("Unsuccessful MadelineProto Object creation.", Logger::ERROR);
        throw new \ErrorException("Strange! MadelineProto object is null.");
    } else {
        $MadelineProto->logger("variables: " . toJSON($variables), Logger::ERROR);
        unset($variables);
    }
}




function startAndLoop(object $eh, string $eventHandler)
{
    $eh->async(true);
    $errors = [];
    while (true) {
        try {
            $started = false;
            yield $eh->start();
            yield $eh->setEventHandler($eventHandler);
            $started = true;
            Tools::wait(yield from $eh->API->loop());
            break;
        } catch (\Throwable $e) {
            $errors = [\time() => $errors[\time()] ?? 0];
            $errors[\time()]++;
            if ($errors[\time()] > 10 && (!$eh->inited() || !$started)) {
                // Display Errors
                $eh->logger->logger("More than 10 errors in a second and not inited, exiting!", Logger::FATAL_ERROR);
                break;
            }
            echo $e;
            $eh->logger->logger((string) $e, Logger::FATAL_ERROR);
            $eh->report("Surfaced: $e");
        }
    }
}



function startAndLoop_ORIG(object $eh, string $eventHandler): void
{
    while (true) {
        try {
            Tools::wait($eh->startAndLoopAsync_ORIG($eventHandler));
            return;
        } catch (\Throwable $e) {
            $eh->logger->logger((string) $e, Logger::FATAL_ERROR);
            $eh->report("Surfaced: $e");
        }
    }
}

function startAndLoopAsync_ORIG(object $eh, string $eventHandler): \Generator
{
    $eh->async(true);

    $started = false;
    $errors = [];
    while (true) {
        try {
            yield $this->start();
            yield $this->setEventHandler($eventHandler);
            $started = true;
            return yield from $this->API->loop();
        } catch (\Throwable $e) {
            $errors = [\time() => $errors[\time()] ?? 0];
            $errors[\time()]++;
            if ($errors[\time()] > 10 && (!$this->inited() || !$started)) {
                $this->logger->logger("More than 10 errors in a second and not inited, exiting!", Logger::FATAL_ERROR);
                return;
            }
            echo $e;
            $this->logger->logger((string) $e, Logger::FATAL_ERROR);
            $this->report("Surfaced: $e");
        }
    }
}
