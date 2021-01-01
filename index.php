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

if (\file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
} else {
    if (!\file_exists('madeline.php')) {
        \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    require_once 'madeline.php';
}

define("SCRIPT_NAME",    'Base');
define("SCRIPT_VERSION", 'V1.2.2');
define('SESSION_FILE',   'session.madeline');
define('SERVER_NAME',    '');
define('SAPI_NAME', (PHP_SAPI === 'cli') ? (isset($_SERVER['TERM']) ? 'Shell' : 'Cron') : 'Web');

use \danog\MadelineProto\EventHandler as MadelineEventHandler;
use \danog\MadelineProto\Logger;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\API;
use \danog\MadelineProto\APIWrapper;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use \danog\MadelineProto\MTProto;
use \danog\MadelineProto\RPCErrorException;
use function\Amp\File\{get, put, exists, getSize};

function toJSON($var, bool $pretty = true): string
{
    if (isset($var['request'])) {
        unset($var['request']);
    }
    $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty ? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '') ? $json : var_export($var, true);
    return $json;
}

//function errHandle($errNo, $errStr, $errFile, $errLine)
//{
//    $msg = "$errStr in $errFile on line $errLine";
//    if ($errNo == E_NOTICE || $errNo == E_WARNING) {
//        throw new ErrorException($msg, $errNo);
//    } else {
//        echo $msg;
//    }
//}

function nowMilli()
{
    $mt = explode(' ', microtime());
    return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
}

function getUptime(int $start, int $end = 0): string
{
    $end = $end !== 0 ? $end : time();
    $age     = $end - $start;
    $days    = floor($age  / 86400);
    $hours   = floor(($age / 3600) % 3600);
    $minutes = floor(($age / 60) % 60);
    $seconds = $age % 60;
    $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
    return $ageStr;
}

function getMemUsage(bool $peak = false): int
{
    $memUsage = $peak ? memory_get_peak_usage(true) : memory_get_usage(true);
    return $memUsage;
}

function getFileSize($file)
{
    $size = filesize($file);
    if ($size < 0) {
        if (!(strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'))
            $size = trim(`stat -c%s $file`);
        else {
            $fsobj = new COM("Scripting.FileSystemObject");
            $f = $fsobj->GetFile($file);
            $size = $file->Size;
        }
    }
    return $size;
}

function getSizeString(int $size): string
{
    $unit = array('Bytes', 'KB', 'MB', 'GB', 'TB', 'PB');
    $mem  = $size !== 0 ? round($size / pow(1024, ($x = floor(log($size, 1024)))), 2) . ' ' . $unit[$x] : 'UNAVAILABLE';
    return $mem;
    /*
    if ($memUsage === 0) {
        $memUsage = '_UNAVAILABLE_';
    } elseif ($memUsage < 1024) {
        $memUsage .= 'B';
    } elseif ($memUsage < 1048576) {
        $memUsage = round($memUsage / 1024, 2) . 'KB';
    } else {
        $memUsage = round($memUsage / 1048576, 2) . 'MB';
    }
    */
}

function getSessionSize(string $sessionFile): int
{
    clearstatcache(true, $sessionFile);
    $size = filesize($sessionFile);
    return $size !== false ? $size : 0;

    if ($size === false) {
        $sessionSize = '_UNAVAILABLE_';
    } elseif ($size < 1024) {
        $sessionSize = $size . ' B';
    } elseif ($size < 1048576) {
        $sessionSize = round($size / 1024, 0) . ' KB';
    } else {
        $sessionSize = round($size / 1048576, 0) . ' MB';
    }
    return $sessionSize;
}

function getCpuUsage(): string
{
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return $load[0] . '%';
    } else {
        return 'UNAVAILABLE';
    }
}

function hostName(bool $full = false): string
{
    $name = getHostname();
    if (!$full && $name && strpos($name, '.') !== false) {
        $name = substr($name, 0, strpos($name, '.'));
    }
    return $name;
}

function strStartsWith($haystack, $needle, $caseSensitive = true)
{
    $length = strlen($needle);
    $startOfHaystack = substr($haystack, 0, $length);
    if ($caseSensitive) {
        if ($startOfHaystack === $needle) {
            return true;
        }
    } else {
        if (strcasecmp($startOfHaystack, $needle) == 0) {
            return true;
        }
    }
    return false;
}

function secondsToNexMinute($now = null): int
{
    $now   = $now ?? time();
    $next  = (int)ceil($now / 60) * 60;
    $delay = $next - $now;
    return $delay > 0 ? $delay : 60;
}

class ArrayInt64
{
    private $_backing = '';

    public function __construct()
    {
        assert(PHP_INT_SIZE === 8, 'Class requires 64-bit integer support');
    }

    public function append(int $item): void
    {
        $this->_backing .= pack('P', $item);
    }

    public function count(): int
    {
        return $this->_binary_strlen($this->_backing) / PHP_INT_SIZE;
    }

    public function get(int $index): ?int
    {
        if (!is_numeric($index)) {
            return null;
        }
        if ($index >= $this->count()) {
            return null;
        }

        $packed = $this->_binary_substr($this->_backing, $index * PHP_INT_SIZE, PHP_INT_SIZE);
        $unpacked = unpack('P', $packed);
        if (is_array($unpacked)) {
            return array_shift($unpacked);
        }
        return null;
    }

    protected function _binary_strlen(string $str): int
    {
        if (function_exists('mb_internal_encoding') && (ini_get('mbstring.func_overload') & 2)) {
            return mb_strlen($str, '8bit');
        }
        return strlen($str);
    }

    protected function _binary_substr(string $str, int $start, int $length = null): string
    {
        if (function_exists('mb_internal_encoding') && (ini_get('mbstring.func_overload') & 2)) {
            return mb_substr($str, $start, $length, '8bit');
        }
        return substr($str, $start, $length);
    }
}

function parseCommand(string $msg, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => null, 'params' => []];
    $msg = trim($msg);
    if ($msg && strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
        $verb = strtolower(substr($msg, 1, strpos($msg . ' ', ' ') - 1));
        if (ctype_alnum($verb)) {
            $command['prefix'] = $msg[0];
            $command['verb']   = $verb;
            $tokens = explode(' ', $msg, $maxParams + 1);
            for ($i = 1; $i < count($tokens); $i++) {
                $command['params'][$i - 1] = trim($tokens[$i]);
            }
        }
    }
    return $command;
}

function sendAndDelete(EventHandler $mp, int $dest, string $text, int $delaysecs = 30, bool $delmsg = true): Generator
{
    $result = yield $mp->messages->sendMessage([
        'peer'    => $dest,
        'message' => $text
    ]);
    if ($delmsg) {
        $msgid = $result['updates'][1]['message']['id'];
        $mp->callFork((function () use ($mp, $msgid, $delaysecs) {
            try {
                yield $mp->sleep($delaysecs);
                yield $mp->messages->deleteMessages([
                    'revoke' => true,
                    'id'     => [$msgid]
                ]);
                yield $mp->logger('Robot\'s startup message is deleted at ' . time() . '!', Logger::ERROR);
            } catch (\Exception $e) {
                yield $mp->logger($e, Logger::ERROR);
            }
        })());
    }
}

function getLastLaunch(EventHandler $eh): Generator
{
    $content = yield get('data/launches.txt');
    if ($content === '') {
        return null;
    }
    $content  = substr($content, 1);
    $launches = explode("\n", $content);
    yield $eh->logger("Launches Count:" . count($launches), Logger::ERROR);
    $values = explode(' ', trim(end($launches)));
    if (count($values) !== 4) {
        throw new Exception("Invalid launch information .");
    }
    $launch['stop']     = intval($values[0]);
    $launch['method']   = $values[1];
    $launch['duration'] = intval($values[2]);
    $launch['memory']   = intval($values[3]);
    return $launch;
}

function newVisitor($fromId): bool
{
    return true;
}

function getWebServerName(): ?string
{
    return $_SERVER['SERVER_NAME'] ?? null;
}
function setWebServerName(string $serverName): void
{
    $_SERVER['SERVER_NAME'] = $serverName;
}

function getLaunchMethod2(): string
{
    if (PHP_OS_FAMILY === "Windows") {
        return PHP_SAPI === 'cli' ? (isset($_SERVER['TERM']) ? 'manual' : 'cron') : 'web';
    } elseif (PHP_OS_FAMILY === "Linux") {
        return PHP_SAPI === 'cli' ? (isset($_SERVER['TERM']) ? 'manual' : 'cron') : 'web';
    }
}
/*
  Interface, LaunchMethod
1) Web, Manual
2) Web, Cron
3) Web, Restart
4) CLI, Manual
5) CLI, Cron
*/
function getLaunchMethod(): string
{
    if (PHP_SAPI === 'cli') {
        $interface = 'cli';
        if (PHP_OS_FAMILY === "Linux") {
            if ($_SERVER['TERM']) {
                $launchMethod = 'manual';
            } else {
                $launchMethod = 'cron';
            }
        } elseif (PHP_OS_FAMILY === "Windows") {
            $launchMethod = 'manual';
        } else {
            throw new Exception('Unknown OS!');
        }
    } else {
        $interface    = 'web';
        $launchMethod = 'UNKNOWN';
        if (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
            if (stripos($requestUri, '?MadelineSelfRestart=') !== false) {
                $launchMethod = 'restart';
            } else if (stripos($requestUri, 'cron') !== false) {
                $launchMethod = 'cron';
            } else {
                $launchMethod = 'manual';
            }
        }
    }
    return $launchMethod;
}

function getHostTimeout($mp): int
{
    $duration = $mp->__get('duration');
    $reason   = $mp->__get('shutdow_reason');
    if ($duration /*&& $reason && $reason !== 'stop' && $reason !== 'restart'*/) {
        return $duration;
    }
    return -1;
}

function getURL(): ?string
{
    //$_SERVER['REQUEST_URI'] => '/base/?MadelineSelfRestart=1755455420394943907'
    $url = null;
    if (PHP_SAPI === 'cli') {
        $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    return $url;
}

function safeStartAndLoop(API $MadelineProto, GenericLoop $genLoop = null, int $maxRecycles = 10): void
{
    $recycleTimes = [];
    while (true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler('\EventHandler');
                if ($genLoop !== null) {
                    $genLoop->start(); // Do NOT use yield.
                }

                // Synchronously wait for the update loop to exit normally.
                // The update loop exits either on ->stop or ->restart (which also calls ->stop).
                Tools::wait(yield from $MadelineProto->API->loop());
                yield $MadelineProto->logger("Update loop exited!");
            });
            sleep(5);
            break;
        } catch (\Throwable $e) {
            try {
                $MadelineProto->logger->logger((string) $e, Logger::FATAL_ERROR);
                // quit recycling if more than $maxRecycles happened within the last minutes.
                $now = time();
                foreach ($recycleTimes as $index => $restartTime) {
                    if ($restartTime > $now - 1 * 60) {
                        break;
                    }
                    unset($recycleTimes[$index]);
                }
                if (count($recycleTimes) > $maxRecycles) {
                    // quit for good
                    Shutdown::removeCallback('restarter');
                    Magic::shutdown(1);
                    break;
                }
                $recycleTimes[] = $now;
                $MadelineProto->report("Surfaced: $e");
            } catch (\Throwable $e) {
            }
        }
    };
}

function checkTooManyRestarts(EventHandler $eh): Generator
{
    $startups = [];
    if (yield exists('data/startups.txt')) {
        $startupsText = yield get('data/startups.txt');
        $startups = explode('\n', $startupsText);
    } else {
        // Create the file
    }
    $startupsCount0 = count($startups);

    $nowMilli = nowMilli();
    $aMinuteAgo = $nowMilli - 60 * 1000;
    foreach ($startups as $index => $startupstr) {
        $startup = intval($startupstr);
        if ($startup < $aMinuteAgo) {
            unset($startups[$index]);
        }
    }
    $startups[] = strval($nowMilli);
    $startupsText = implode('\n', $startups);
    yield put('data/startups.txt', $startupsText);
    $restartsCount = count($startups);
    yield $eh->logger("startups: {now:$nowMilli, count0:$startupsCount0, count1:$restartsCount}", Logger::ERROR);
    return $restartsCount;
}

function telegram2dialogSlices($mp, ?array $params, Closure $sliceCallback = null): \Generator
{
    foreach ($params as $key => $param) {
        switch ($key) {
            case 'limit':
            case 'max_dialogs':
            case 'pause_min':
            case 'pause_max':
                break;
            default:
                throw new Exception("Unknown Parameter: $key");
        }
    }
    $limit      = $params['limit']       ?? 100;
    $maxDialogs = $params['max_dialogs'] ?? 100000;
    $pauseMin   = $params['pause_min']   ?? 0;
    $pauseMax   = $params['pause_max']   ?? 0;
    $pauseMax   = $pauseMax < $pauseMin ? $pauseMin : $pauseMax;
    $json = toJSON([
        'limit'       => $limit,
        'max_dialogs' => $maxDialogs,
        'pause_min'   => $pauseMin,
        'pause_max'   => $pauseMax
    ]);
    yield $mp->logger($json, Logger::ERROR);

    //yield $mp->echo('Entering telegram2dialogSlices!' . PHP_EOL);
    //yield $mp->logger('Entering telegram2dialogSlices! ' . toJSON($params, false), Logger::ERROR);

    $params = [
        'offset_date' => 0,
        'offset_id'   => 0,
        'offset_peer' => ['_' => 'inputPeerEmpty'],
        'limit'       => $limit,
        'hash'        => 0,
    ];
    $res = ['count' => 1];
    $fetched = 0;
    $sentDialogs  = 0;
    while ($fetched < $res['count']) {
        //yield $mp->echo(PHP_EOL . 'Request: ' . toJSON($params, false) . PHP_EOL);
        yield $mp->logger('Request: ' . toJSON($params, false), Logger::ERROR);

        //==============================================
        try {
            //$res = yield from $mp->methodCallAsyncRead($method[$cdn], $basic_param + $offset, ['heavy' => true, 'file' => true, 'FloodWaitLimit' => 0, 'datacenter' => &$datacenter, 'postpone' => $postpone]);
            $res = yield $mp->messages->getDialogs($params);
        } catch (RPCErrorException $e) {
            if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                throw new Exception('FLOOD');
            }
        }
        //==============================================

        $sliceSize    = count($res['dialogs']);
        $totalDialogs = isset($res['count']) ? $res['count'] : $sliceSize;

        $messageCount = count($res['messages']);
        $chatCount    = count($res['chats']);
        $userCount    = count($res['users']);
        $fetchedSofar = $fetched + $sliceSize;
        $countMsg     = "Result: {dialogs:$sliceSize, messages:$messageCount, chats:$chatCount, users:$userCount " .
            "total:$totalDialogs fetched:$fetchedSofar}";
        //yield $mp->echo($countMsg. PHP_EOL . PHP_EOL);
        yield $mp->logger($countMsg, Logger::ERROR);
        if (count($res['messages']) !== $sliceSize) {
            throw new Exception('Unequal slice size.');
        }

        if ($sliceCallback !== null) {
            //===================================================================================================
            yield $sliceCallback($totalDialogs, $res['dialogs'], $res['messages'], $res['chats'], $res['users']);
            //===================================================================================================
            $sentDialogs += count($res['dialogs']);
            yield $mp->logger("Sent Dialogs:$sentDialogs,  Max Dialogs:$maxDialogs, Slice Size:$sliceSize", Logger::ERROR);
            if ($sentDialogs >= $maxDialogs) {
                break;
            }
        }

        $lastPeer = 0;
        $lastDate = 0;
        $lastId   = 0;
        $res['messages'] = \array_reverse($res['messages'] ?? []);
        foreach (\array_reverse($res['dialogs'] ?? []) as $dialog) {
            $fetched += 1;
            $id = yield $mp->getId($dialog['peer']);
            if (!$lastDate) {
                if (!$lastPeer) {
                    $lastPeer = $id;
                }
                if (!$lastId) {
                    $lastId = $dialog['top_message'];
                }
                foreach ($res['messages'] as $message) {
                    $idBot = yield $mp->getId($message);
                    if (
                        $message['_'] !== 'messageEmpty' &&
                        $idBot  === $lastPeer            &&
                        $lastId  == $message['id']
                    ) {
                        $lastDate = $message['date'];
                        break;
                    }
                }
            }
        }
        if ($lastDate) {
            $params['offset_date'] = $lastDate;
            $params['offset_peer'] = $lastPeer;
            $params['offset_id']   = $lastId;
        } else {
            //yield $mp->echo('*** NO LAST-DATE EXISTED'.PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if (!isset($res['count'])) {
            //yield $mp->echo('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...'.PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if ($pauseMin > 0 || $pauseMax > 0) {
            $pause = $pauseMax <= $pauseMin ? $pauseMin : rand($pauseMin, $pauseMax);
            //yield $mp->echo("Pausing for $pause seconds. ...".PHP_EOL);
            yield $mp->logger("Pausing for $pause seconds. ...", Logger::ERROR);
            yield $mp->logger(" ", Logger::ERROR);
            yield $mp->sleep($pause);
        } else {
            yield $mp->logger(" ", Logger::ERROR);
        }
    } // end of while/for
    //echo('Exiting telegram2dialogSlices!'.PHP_EOL);
    //yield $mp->logger('Exiting telegram2dialogSlices!', Logger::ERROR);
}


function visitDialogs($mp, array $params, callable $callback): \Generator
{
    //yield $mp->logger("Entered VisitDialogs:" . toJSON($params, false), Logger::ERROR);
    yield telegram2dialogSlices(
        $mp,
        $params,
        function (
            int   $totalDialogs,
            array $dialogs,
            array $messages,
            array $chats,
            array $users
        )
        use ($callback, $mp): Generator {
            //yield $mp->logger("Entered telegram2dialogSlices.", Logger::ERROR);
            //yield $mp->echo("Entered telegram2dialogSlices." . PHP_EOL);
            $index = 0;
            foreach ($dialogs as $idx => $dialog) {
                $peer    = $dialog['peer'];
                $message = $messages[$idx] ?? null;
                switch ($peer['_']) {
                    case 'peerUser':
                        $peerId = $peer['user_id'];
                        foreach ($users as $user) {
                            if ($peerId === $user['id']) {
                                $subtype = ($user['bot'] ?? false) ? 'bot' : 'user';
                                $peerval = $user;
                                if (isset($user['username'])) {
                                    $name = '@' . $user['username'];
                                } elseif (($user['first_name'] ?? '') !== '' || ($user['last_name'] ?? '') !== '') {
                                    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                                } elseif (isset($chat['id'])) {
                                    $name = strval($user['id']);
                                } else {
                                    $name = '';
                                }
                                break 2;
                            }
                        }
                        throw new Exception("Missing user: '$peerId'");
                    case 'peerChat':
                    case 'peerChannel':
                        $peerId = $peer['_'] === 'peerChat' ? $peer['chat_id'] : $peer['channel_id'];
                        foreach ($chats as $chat) {
                            if ($chat['id'] === $peerId) {
                                $peerval = $chat;
                                if (isset($chat['username'])) {
                                    $name = $chat['username'];
                                } elseif (($chat['title'] ?? '') !== '') {
                                    $name = $chat['title'];
                                } elseif (isset($chat['id'])) {
                                    $name = strval($chat['id']);
                                } else {
                                    $name = '';
                                }
                                switch ($chat['_']) {
                                    case 'chatEmpty':
                                        $subtype = $chat['_'];
                                        break;
                                    case 'chat':
                                        $subtype = 'basicgroup';
                                        break;
                                    case 'chatForbidden':
                                        $subtype = $chat['_'];
                                        break;
                                    case 'channel':
                                        $subtype = ($chat['megagroup'] ?? false) ? 'supergroup' : 'channel';
                                        break;
                                    case 'channelForbidden':
                                        $subtype = $chat['_'];
                                        break;
                                    default:
                                        throw new Exception("Unknown subtype: '$peerId'  '" . $chat['_'] . "'");
                                }
                                break 2;
                            }
                        }
                        throw new Exception("Missing chat: '$peerId'");
                    default:
                        throw new Exception("Invalid peer type: '" . $peer['_'] . "'");
                }
                yield $callback($totalDialogs, $index, $peerId, $subtype, $name, $peerval, $message);
                $index += 1;
            }
        }
    );
}

class EventHandler extends MadelineEventHandler
{
    private $startTime;
    private $stopTime;

    private $robotId;     // id of this worker.

    private $owner;       // id or username of the owner of the workers.
    private $officeId;    // id of the office channel;
    private $admins;      // ids of the users which have admin rights to issue commands.
    private $workers;     // ids of the userbots which take orders from admins to execute commands.
    private $reportPeers; // ids of the support people who will receive the errors.

    //private $notifState;  // true: Notify; false: Never notify.
    //private $notifAge;    // 30 => Delete the notifications after 30 seconds;  0 => Never Delete.

    private $oldAge = 2;

    public function __construct(?APIWrapper $API)
    {
        parent::__construct($API);

        $this->startTime = time();
        $this->stopTime  = 0;

        $this->officeId  = 1373853876;
        //$this->ownerId   =  157887279;
        //$this->admins = [906097988, 157887279, 1087968824];

        //$this->notifState = false;
        //$this->notifAge   = 0;
    }

    public function onStart(): \Generator
    {
        yield $this->logger("Event Handler instatiated at " . date('d H:i:s', $this->startTime) . "!", Logger::ERROR);
        yield $this->logger("Event Handler started at " . date('d H:i:s') . "!", Logger::ERROR);

        $robot = yield $this->getSelf();
        $this->robotId = $robot['id'];
        if (isset($robot['username'])) {
            $this->account = $robot['username'];
        } elseif (isset($robot['first_name'])) {
            $this->account = $robot['first_name'];
        } else {
            $this->account = strval($robot['id']);
        }

        $this->ownerId     = $this->robotId;
        $this->admins      = [$this->robotId];
        $this->reportPeers = [$this->robotId];

        $this->processCommands  = false;
        $this->updatesProcessed = 0;

        $maxRestart = 5;
        $eh = $this;
        $restartsCount = yield checkTooManyRestarts($eh);
        $nowstr = date('d H:i:s', $this->startTime);
        if ($restartsCount > $maxRestart) {
            $text = 'More than ' . $maxRestart . ' times restarted within a minute. Permanently shutting down ....';
            yield $this->logger($text, Logger::ERROR);
            yield $this->messages->sendMessage([
                'peer'    => $this->robotId,
                'message' => $text
            ]);
            if (Shutdown::removeCallback('restarter')) {
                yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $this->logger(SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' on ' . hostname() . ' is stopping at ' . $nowstr, Logger::ERROR);
            yield $this->stop();
            return;
        }
        $text = SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $this->account . ' account.';

        $notif      = $this->getNotif();
        $notifState = $notif['state'];
        $notifAge   = $notif['age'];
        $dest       = $this->robotId;
        if ($notifState) {
            $result = yield $eh->messages->sendMessage([
                'peer'    => $dest,
                'message' => $text
            ]);
            yield $eh->logger($text, Logger::ERROR);
            if ($notifAge > 0) {
                $msgid = $result['updates'][1]['message']['id'];
                $eh->callFork((function () use ($eh, $msgid, $notifAge) {
                    try {
                        yield $eh->sleep($notifAge);
                        yield $eh->messages->deleteMessages([
                            'revoke' => true,
                            'id'     => [$msgid]
                        ]);
                        yield $eh->logger('Robot\'s startup message is deleted.', Logger::ERROR);
                    } catch (\Exception $e) {
                        yield $eh->logger($e, Logger::ERROR);
                    }
                })());
            }
        }

        $launch = yield getLastLaunch($this);
        $lastStopTime       = $launch['stop']    ?? 0;
        $lastLaunchMethod   = $launch['method']  ?? null;
        $lastLaunchDuration = $launch['duration'] ?? 0;
        $lastPeakMemory     = $launch['memory']  ?? 0;

        $totalDialogsOut = 0;
        $peerCounts   = [
            'user' => 0, 'bot' => 0, 'basicgroup' => 0, 'supergroup' => 0, 'channel' => 0,
            'chatForbidden' => 0, 'channelForbidden' => 0
        ];
        $mp = $this;
        $currentUserIds = [];
        $lastMessages = [];
        yield visitDialogs(
            $this,
            ['max_dialogs' => 20],
            function (int $totalDialogs, int $index, int $peerId, string $subtype, string $name, ?array $peerval, ?array $message)
            use ($mp, &$totalDialogsOut, &$peerCounts, &$currentUserIds, &$lastMessages): void {
                $totalDialogsOut = $totalDialogs;
                $peerCounts[$subtype] += 1;
                switch ($subtype) {
                    case 'bot':
                    case 'basicgroup':
                    case 'supergroup':
                    case 'channel':
                    case 'chatForbidden':
                    case 'channelForbidden':
                        break;
                    case 'user':
                        if (!$peerval['self'] && $peerId !== 777000) {
                            //$out = ['peer_id' => $peerId, 'name' => $name, 'peerval' => $peerval, 'message' => $message];
                            //$mp->logger(toJSON($out), Logger::ERROR);
                            $currentUserIds[] = $peerId;
                            $lastMessages[]   = $message;
                        }
                        break;
                }
            }
        );
        yield $this->logger("Count of users: " . count($currentUserIds), Logger::ERROR);

        foreach ($lastMessages as $idx => $message) {
            //$message = $lastMessages[$idx] ?? null;
            if ($message && ($message['from_id']) !== $this->getRobotId()) {
                //yield $this->logger("Last Message: " . toJSON($message, false), Logger::ERROR);
                $res = yield $this->messages->getHistory([
                    'peer'        => $message,
                    'limit'       => 4,
                    'offset_id'   => 0,
                    'offset_date' => 0,
                    'add_offset'  => 0,
                    'max_id'      => 0,
                    'min_id'      => 0,
                ]);
                //yield $this->logger("getHistory Result: " . toJSON($res), Logger::ERROR);
                $messages = $res['messages'];
                $chats    = $res['chats'];
                $users    = $res['users'];

                $isNew = true;
                foreach ($res['messages'] as $msg) {
                    if ($msg['from_id'] === $this->getRobotId()) {
                        $isNew = false;
                    }
                }
                if ($isNew) {
                    yield $this->logger("New User: " . toJSON($message), Logger::ERROR);
                } else {
                    //yield $this->logger("Old User: " . toJSON($message), Logger::ERROR);
                }
            }
        }
        yield $this->logger(" ", Logger::ERROR);

        $this->setReportPeers($this->reportPeers);
    }

    public function getRobotID(): int
    {
        return $this->robotId;
    }

    public function getLoopState(): bool
    {
        $state = $this->__get('loop_state');
        return $state ?? false;
    }
    public function setLoopState(bool $loopState): void
    {
        $this->__set('loop_state', $loopState);
    }

    public function getNotif(): array
    {
        $notif['state'] = $this->__get('notif_state');
        $notif['age']   = $this->__get('notif_age');
        return $notif;
    }
    public function setNotif($state, $age = null): void
    {
        $this->__set('notif_state', $state);
        $this->__set('notif_age',   $age);
    }

    public function onUpdateEditMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if (
            $update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty'
        ) {
            return;
        }
        if (!isset($update['message']['message'])) {
            yield $this->echo("Empty message-text:<br>" . PHP_EOL);
            yield $this->echo(toJSON($update) . '<br>' . PHP_EOL);
            exit;
        }
        $msgType      = $update['_'];
        $msgOrig      = $update['message']['message'] ?? null;
        $msgDate      = $update['message']['date'] ?? null;
        $msg          = $msgOrig ? strtolower($msgOrig) : null;
        $messageId    = $update['message']['id'] ?? 0;
        $fromId       = $update['message']['from_id'] ?? 0;
        $replyToId    = $update['message']['reply_to_msg_id'] ?? null;
        $isOutward    = $update['message']['out'] ?? false;
        $peerType     = $update['message']['to_id']['_'] ?? '';
        $peer         = $update['message']['to_id'] ?? null;
        $byRobot      = $fromId    === $this->robotId && $msg;
        $toRobot      = $peerType  === 'peerUser' && $peer['user_id'] === $this->robotId && $msg;
        $byVisitor    = !$byRobot && $toRobot;
        $replyToRobot = $replyToId === $this->robotId && $msg;
        $this->updatesProcessed += 1;
        $moment = time();
        $msgAge = $moment - $msgDate;

        $command = parseCommand($msgOrig);
        $verb    = $command['verb'];
        $params  = $command['params'];

        $toOffice  = $peerType === 'peerChannel' && $peer['channel_id'] === $this->officeId;
        $fromAdmin = in_array($fromId, $this->admins);
        if ($fromAdmin || $toOffice) {
            yield $this->logger("admins: [{$this->admins[0]}]", Logger::ERROR);
            yield $this->logger("officeId:$this->officeId  robotId:$this->robotId", Logger::ERROR);
            yield $this->logger("fromId: $fromId, toOffice:" . ($toOffice ? 'true' : 'false'), Logger::ERROR);
            yield $this->logger(toJSON($update), Logger::ERROR);
        }

        switch ($fromAdmin && $toOffice && $verb ? $verb : '') {
            case '':
                // Not a verb and/or not sent by an admin.
                break;
            case 'ping':
                yield $this->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $messageId,
                    'message'         => 'Pong'
                ]);
                yield $this->logger("Command '/ping' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                break;
            default:
                yield $this->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $messageId,
                    'message'         => "Invalid command: '$msgOrig'"
                ]);
                yield $this->logger("Invalid Command '$msgOrig' rejected at " . date('d H:i:s!'), Logger::ERROR);
                break;
        }

        if ($byVisitor) {
            yield $this->logger(toJSON($update), Logger::ERROR);
            if (newVisitor($fromId)) {
                /*
                yield $this->messages->sendMessage([
                    'peer'            => $fromId,
                    'reply_to_msg_id' => $messageId,
                    'message'         => "Hello! My name is Sara. What's your name?. '"
                ]);
                */
                yield $this->logger("Replied to a new visitor", Logger::ERROR);
            } else {
                /*
                yield $this->messages->sendMessage([
                    'peer'            => $fromId,
                    'reply_to_msg_id' => $messageId,
                    'message'         => "Hi, John! What's new?'"
                ]);
                */
                yield $this->logger("Replied to an existing visitor", Logger::ERROR);
            }
        }

        // Recognize and log old or new commands and reactions.
        if ($byRobot && $toRobot && $msgType === 'updateNewMessage') {
            $new = $msgAge <= $this->oldAge;
            if ($verb) {
                $age = $new ? 'New' : 'Old';
                yield $this->logger(
                    "$age Command:{verb:'$verb', time:" . date('H:i:s', $msgDate) .
                        ", now:" . date('H:i:s', $moment) . ", age:$msgAge}",
                    Logger::ERROR
                );
            }
        }

        // Start the Command Processing Engine
        if (
            !$this->processCommands &&
            $byRobot && $toRobot &&
            $msgType === 'updateNewMessage' &&
            //strStartsWith($msgOrig, SCRIPT_NAME . ' started at ') &&
            $msgAge <= $this->oldAge
        ) {
            $this->processCommands = true;
            yield $this->logger('Command-Processing engine started at ' . date('H:i:s', $moment), Logger::ERROR);
        }

        // Log some information for debugging
        if ($byRobot || $toRobot) {
            $criteria = ['by_robot' => $byRobot, 'to_robot' => $toRobot, 'process' => $this->processCommands];
            $criteria[$verb ? 'action' : 'reaction'] = mb_substr($msgOrig, 0, 40);
            $this->logger(toJSON($criteria, false), Logger::ERROR);
        }

        if ($byRobot && $toRobot && $verb && $this->processCommands && $msgType === 'updateNewMessage') {
            switch ($verb) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'parse_mode' => 'HTML',
                        'message'    => '' .
                            '<b>Robot Instructions:</b><br>' .
                            '<br>' .
                            '>> <b>/help</b><br>' .
                            '   To print the robot commands<br>' .
                            ">> <b>/loop</b> on/off/state<br>" .
                            "   To query/change state of task repeater.<br>" .
                            '>> <b>/status</b><br>' .
                            '   To query the status of the robot.<br>' .
                            '>> <b>/stats</b><br>' .
                            '   To query the statistics of the robot.<br>' .
                            '>> <b>/notif OFF / ON 20</b><br>' .
                            '   No event notification or notify every 20 secs.<br>' .
                            '>> <b>/crash</b><br>' .
                            '   To generate an exception for testing.<br>' .
                            '>> <b>/restart</b><br>' .
                            '   To restart the robot.<br>' .
                            '>> <b>/stop</b><br>' .
                            '   To stop the script.<br>' .
                            '>> <b>/logout</b><br>' .
                            '   To terminate the robot\'s session.<br>' .
                            '<br>' .
                            '<b>**Valid prefixes are / and !</b><br>',
                    ]);
                    yield $this->logger("Command '/help' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                    break;
                case 'status':
                    $currentMemUsage = getSizeString(getMemUsage(true));
                    $peakMemUsage    = getSizeString(getMemUsage());
                    $memoryLimit     = ini_get('memory_limit');
                    $sessionSize     = getSizeString(getSessionSize(SESSION_FILE));
                    $launch = yield getLastLaunch($this);
                    if ($launch) {
                        $lastStopTime       = strval($launch['stop']);
                        $lastLaunchMethod   = $launch['method'];
                        $lastLaunchDuration = strval($launch['duration']) . " secs";
                        $lastPeakMemory     = getSizeString($launch['memory']);
                    } else {
                        $lastStopTime       = 'NOT AVAILABLE';
                        $lastLaunchMethod   = 'NOT AVAILABLE';
                        $lastLaunchDuration = 'NOT AVAILABLE';
                        $lastPeakMemory     = 'NOT AVAILABLE';
                    }
                    $notif = $this->getNotif();
                    $notifStr = 'OFF';
                    if ($notif['state']) {
                        $notifAge = $notif['age'];
                        $notifStr = $notifAge === 0 ? "ON / Never wipe" : "ON / Wipe after $notifAge secs.";
                    }
                    //yield $this->echo("Robot's Id: '" . ($this->robotId? $this->Robot ) . '!' . PHP_EOL);
                    $status  = '<b>STATUS:</b>  (Script: ' . SCRIPT_NAME . ' ' . SCRIPT_VERSION . ')<br>';
                    $status .= "Host: " . hostname() . "<br>";
                    $status .= "Robot's User-Name: $this->account<br>";
                    $status .= "Robot's User-Id: $this->robotId<br>";
                    $status .= "Uptime: " . getUptime($this->startTime) . "<br>";
                    $status .= "Peak Memory: $peakMemUsage<br>";
                    $status .= "Current Memory: $currentMemUsage<br>";
                    $status .= "Allowed Memory: $memoryLimit<br>";
                    $status .= 'CPU: '         . getCpuUsage()            . '<br>';
                    $status .= "Session Size: $sessionSize<br>";
                    $status .= 'Time: ' . date_default_timezone_get() . ' ' . date("d H:i:s") . '<br>';
                    $status .= 'Updates: '  . $this->updatesProcessed . '<br>';
                    $status .= 'Loop State: ' . ($this->getLoopState() ? 'ON' : 'OFF') . '<br>';
                    $status .= 'Notification: ' . $notifStr . PHP_EOL;
                    $status .= 'Launch Method: ' . getLaunchMethod() . '<br>';
                    $status .= 'Previous Stop Time: '       . $lastStopTime . '<br>';
                    $status .= 'Previous Launch Method: '   . $lastLaunchMethod . '<br>';
                    $status .= 'Previous Launch Duration: ' . $lastLaunchDuration . '<br>';
                    $status .= 'Previous Peak Memory: '     . $lastPeakMemory . '<br>';
                    //$this->echo(toJSON($peer, false));
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    => $status,
                        'parse_mode' => 'HTML',
                    ]);
                    yield $this->logger("Command '/status' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                    break;
                case 'stats':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    => "Preparing statistics ....",
                    ]);
                    $result = yield $this->contacts->getContacts();
                    $totalCount  = count($result['users']);
                    $mutualCount = 0;
                    foreach ($result['users'] as $user) {
                        $mutualCount += ($user['mutual_contact'] ?? false) ? 1 : 0;
                    }
                    unset($result);
                    $totalDialogsOut = 0;
                    $peerCounts   = [
                        'user' => 0, 'bot' => 0, 'basicgroup' => 0, 'supergroup' => 0, 'channel' => 0,
                        'chatForbidden' => 0, 'channelForbidden' => 0
                    ];
                    $params = [];
                    //$params['limit']       =  50;
                    //$params['max_dialogs'] = 200;
                    //$params['pause_min']   =   2;
                    //$params['pause_max']   =   6;
                    yield visitDialogs(
                        $this,
                        $params,
                        function (int $totalDialogs, int $index, int $peerId, string $subtype, string $name, ?array $peerval, array $message)
                        use (&$totalDialogsOut, &$peerCounts): void {
                            $totalDialogsOut = $totalDialogs;
                            $peerCounts[$subtype] += 1;
                        }
                    );
                    $stats  = '<b>STATISTICS</b>  (Script: ' . SCRIPT_NAME . ' ' . SCRIPT_VERSION . ')<br>';
                    $stats .= "Account: $this->account<br>";
                    $stats .= "Total Dialogs: $totalDialogsOut<br>";
                    $stats .= "Users: {$peerCounts['user']}<br>";
                    $stats .= "Bots: {$peerCounts['bot']}<br>";
                    $stats .= "Basic groups: {$peerCounts['basicgroup']}<br>";
                    $stats .= "Forbidden Basic groups: {$peerCounts['chatForbidden']}<br>";
                    $stats .= "Supergroups: {$peerCounts['supergroup']}<br>";
                    $stats .= "channels: {$peerCounts['channel']}<br>";
                    $stats .= "Forbidden Supergroups or Channels: {$peerCounts['channelForbidden']}<br>";
                    $stats .= "Total Contacts: $totalCount<br>";
                    $stats .= "Mutual Contacts: $mutualCount";
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    => $stats,
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'loop':
                    $param = strtolower($params[0] ?? '');
                    if (($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                        $loopStatePrev = $this->getLoopState();
                        $loopState = $param === 'on' ? true : ($param === 'off' ? false : $loopStatePrev);
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => 'The loop is ' . ($loopState ? 'ON' : 'OFF') . '!',
                        ]);
                        if ($loopState !== $loopStatePrev) {
                            $this->setLoopState($loopState);
                        }
                    } else {
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "The argument must be 'on', 'off, or 'state'.",
                        ]);
                    }
                    break;
                case 'crash':
                    yield $this->logger("Purposefully crashing the script....", Logger::ERROR);
                    throw new \Exception('Artificial exception generated for testing the robot.');
                case 'maxmem':
                    $arr = array();
                    try {
                        for ($i = 1;; $i++) {
                            $arr[] = md5(strvAL($i));
                        }
                    } catch (Exception $e) {
                        unset($arr);
                        $msg = $e->getMessage();
                        yield $this->logger($msg, Logger::ERROR);
                    }
                    break;
                case 'notif':
                    $param1 = strtolower($params[0] ?? '');
                    $paramsCount = count($params);
                    if (
                        ($param1 !==  'on' && $param1 !== 'off' && $param1 !== 'state') ||
                        ($param1  === 'on' && $paramsCount !== 2) ||
                        ($param1  === 'on' && !ctype_digit($params['1'])) ||
                        (($param1 === 'off' || $param1 === 'state') && $paramsCount !== 1)
                    ) {
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "The notif argument must be 'on 123', 'off, or 'state'.",
                        ]);
                        break;
                    }
                    switch ($param1) {
                        case 'state':
                            $notif = $this->getNotif();
                            $notifState = $notif['state'];
                            $notifAge   = $notif['age'];
                            break;
                        case 'on':
                            $notifState = true;
                            $notifAge   = intval($params[1]);
                            $this->setNotif($notifState, $notifAge);
                            break;
                        case 'off':
                            $notifState = false;
                            $this->setNotif($notifState);
                            break;
                    }
                    $message = "The notif is " . (!$notifState ? "OFF" : ("ON / $notifAge secs")) . "!";
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => $message,
                    ]);
                    break;
                case 'restart':
                    if (PHP_SAPI === 'cli') {
                        $result = yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "Command '/restart' is only avaiable under webservers. Ignored!",
                        ]);
                        yield $this->logger("Command '/restart' is only avaiable under webservers. Ignored!  " . date('d H:i:s!'), Logger::ERROR);
                        break;
                    }
                    yield $this->logger('The robot re-started by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Restarting the robot ...',
                    ]);
                    $date = $result['date'];
                    $this->restart();
                    break;
                case 'logout':
                    yield $this->logger('the robot is logged out by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $date = $result['date'];
                    $this->logout();
                case 'stop':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Robot is stopping ...',
                    ]);
                    break;

                    // Place your code below: =========================================

                    //case 'dasoor1':
                    // your code
                    // break;

                    // case 'dasoor2':
                    // your code
                    // break;

                    // ==================================================================

                default:
                    $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Invalid command: ' . "'" . $msgOrig . "'  received at " . date('d H:i:s', $moment)
                    ]);
                    break;
            } // enf of the command switch
        } // end of the commander qualification check

        //Function: Finnish executing the Stop command.
        if ($byRobot && $msgOrig === 'Robot is stopping ...') {
            $result = yield $this->messages->editMessage([
                'peer'    => $peer,
                'id'      => $messageId,
                'message' => 'Robot is stopped!',
            ]);
            $this->logger(toJSON($result), Logger::ERROR);
            if (Shutdown::removeCallback('restarter')) {
                yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $this->stop();
        }
    } // end of function
} // end of the class

//set_error_handler('errHandle');

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

$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger'] = Logger::FILE_LOGGER;
$settings['peer']['full_info_cache_time'] = 60;
$settings['serialization']['cleanup_before_serialization'] = true;
$settings['serialization']['serialization_interval'] = 60;
$settings['app_info']['app_version']    = SCRIPT_NAME . ' ' . SCRIPT_VERSION;
$settings['app_info']['system_version'] =  hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB";
$madelineProto = new API(SESSION_FILE, $settings);
if (!$madelineProto) {
    Logger::log("Strange! MadelineProto object is null. exiting ....", Logger::ERROR);
    exit("Unsuccessful MadelineProto Object creation.");
}
$madelineProto->logger("API object created!", Logger::ERROR);
$madelineProto->async(true);

$genLoop = new GenericLoop(
    $madelineProto,
    function () use ($madelineProto) {
        $eventHandler = $madelineProto->getEventHandler();
        $now = time();
        if ($eventHandler->getLoopState() && $now % 60 === 0) {
            $msg = 'Time is ' . date('H:i:s', $now) . '!';
            yield $madelineProto->logger($msg, Logger::ERROR);
            if (false) {
                yield $madelineProto->account->updateProfile([
                    'about' => date('H:i:s', $now)
                ]);
            }
            if (false) {
                $robotId = $eventHandler->getRobotID();
                yield $madelineProto->messages->sendMessage([
                    'peer'    => $robotId,
                    'message' => $msg
                ]);
            }
        }
        yield $this->sleep(1);
        $delay = secondsToNexMinute();
        return $delay; // Repeat at the begining of the next minute, sharp.
    },
    'Repeating Loop'
);

$robotName    = SCRIPT_NAME;
$startTime    = \time();
$launchesFile = \realpath('data/launches.txt');
$tempId = Shutdown::addCallback(
    static function () use ($madelineProto, $robotName, $startTime, $launchesFile) {
        $now          = time();
        $duration     = $now - $startTime;
        $launchMethod = getLaunchMethod();
        $memory       = getMemUsage(true);
        echo ('Shutting down ....<br>' . PHP_EOL);
        $msg = $robotName . " stopped at " . date("d H:i:s", $now) . "!  Execution duration:" . gmdate('H:i:s', $duration);
        if ($madelineProto) {
            try {
                $madelineProto->logger($msg, Logger::ERROR);
                $madelineProto->logger("Launch Method:'$launchMethod'  Duration: $duration", Logger::ERROR);
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
safeStartAndLoop($madelineProto, $genLoop, $maxRecycles);

exit(PHP_EOL . 'Finished' . PHP_EOL);
