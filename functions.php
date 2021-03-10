<?php

// Ask your questions in the MadelineProto [FA] support group.

declare(strict_types=1);

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
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

function includeMadeline(string $source = 'phar', string $param = null)
{
    switch ($source) {
        case 'phar':
            if (!\file_exists('madeline.php')) {
                \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
            }
            if ($param) {
                define('MADELINE_BRANCH', $param);
            }
            include 'madeline.php';
            break;
        case 'composer':
            $prefix = !$param ? '' : ($param . '/');
            include $prefix . 'vendor/autoload.php';
            break;
        default:
            throw new \ErrorException("Invalid argument: '$source'");
    }
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


function authorized(object $api): int
{
    return !isset($api->API) ? -2 : $api->API->authorized;
}
function getAuthorized(int $authorized): string
{
    switch ($authorized) {
        case  3:
            return 'LOGGED_IN';
        case  0:
            return 'NOT_LOGGED_IN';
        case  1:
            return 'WAITING_CODE';
        case  2:
            return 'WAITING_PASSWORD';
        case -1:
            return 'WAITING_SIGNUP';
        case -2:
            return 'UNINSTANTIATED_MTPROTO';
        default:
            throw new Exception("Invalid authorization status: $authorized");
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


function visitAllDialogs($mp, ?array $params, Closure $sliceCallback = null): \Generator
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
    $limit = min($limit, $maxDialogs);
    yield $mp->logger($json, Logger::ERROR);

    $params = [
        'offset_date' => 0,
        'offset_id'   => 0,
        'offset_peer' => ['_' => 'inputPeerEmpty'],
        'limit'       => $limit,
        'hash'        => 0,
    ];
    $res = ['count' => 1];
    $fetched     = 0;
    $dialogIndex = 0;
    $sentDialogs = 0;
    $dialogIds   = [];
    while ($fetched < $res['count']) {
        //yield $mp->echo(PHP_EOL . 'Request: ' . toJSON($params, false) . PHP_EOL);
        yield $mp->logger('Request: ' . toJSON($params, false), Logger::ERROR);

        try {
            //==============================================
            $res = yield $mp->messages->getDialogs($params, ['FloodWaitLimit' => 200]);
            //==============================================
        } catch (RPCErrorException $e) {
            if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                throw new Exception('FLOOD' . $e->rpc);
            }
        }

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
            //yield $sliceCallback($totalDialogs, $res['dialogs'], $res['messages'], $res['chats'], $res['users']);
            foreach ($res['dialogs'] ?? [] as $dialog) {
                $dialogInfo = yield resolveDialog($mp, $dialog, $res['messages'], $res['chats'], $res['users']);
                $botapiId = $dialogInfo['botapi_id'];
                if (!isset($dialogIds[$botapiId])) {
                    $dialogIds[] = $botapiId;
                    yield $sliceCallback(
                        $mp,
                        $totalDialogs,
                        $dialogIndex,
                        $dialogInfo['botapi_id'],
                        $dialogInfo['subtype'],
                        $dialogInfo['name'],
                        $dialogInfo['dialog'],
                        $dialogInfo['user_or_chat'],
                        $dialogInfo['message']
                    );
                    $dialogIndex += 1;
                    $sentDialogs += 1;
                }
            }
            //===================================================================================================
            //$sentDialogs += count($res['dialogs']);
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
                    yield $mp->logger("lastPeer is set to $id.", Logger::ERROR);
                }
                if (!$lastId) {
                    $lastId = $dialog['top_message'];
                    yield $mp->logger("lastId is set to $lastId.", Logger::ERROR);
                }
                foreach ($res['messages'] as $message) {
                    $idBot = yield $mp->getId($message);
                    if (
                        $message['_'] !== 'messageEmpty' &&
                        $idBot  === $lastPeer            &&
                        $lastId === $message['id']
                    ) {
                        $lastDate = $message['date'];
                        yield $mp->logger("lastDate is set to $lastDate from {$message['id']}.", Logger::ERROR);
                        break;
                    }
                }
            }
        }
        if ($lastDate) {
            $params['offset_date'] = $lastDate;
            $params['offset_peer'] = $lastPeer;
            $params['offset_id']   = $lastId;
            $params['count']       = $sliceSize;
        } else {
            yield $mp->echo('*** NO LAST-DATE EXISTED' . PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if (!isset($res['count'])) {
            yield $mp->echo('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...' . PHP_EOL);
            yield $mp->logger('*** All ' . $totalDialogs . ' Dialogs fetched. EXITING ...', Logger::ERROR);
            break;
        }
        if ($pauseMin > 0 || $pauseMax > 0) {
            $pause = $pauseMax <= $pauseMin ? $pauseMin : rand($pauseMin, $pauseMax);
            yield $mp->echo("Pausing for $pause seconds. ..." . PHP_EOL);
            yield $mp->logger("Pausing for $pause seconds. ...", Logger::ERROR);
            yield $mp->logger(" ", Logger::ERROR);
            yield $mp->sleep($pause);
        } else {
            yield $mp->logger(" ", Logger::ERROR);
        }
    } // end of while/for
}

function resolveDialog($mp, array $dialog, array $messages, array $chats, array $users)
{
    $peer     = $dialog['peer'];
    $message  =  null;
    foreach ($messages as $msg) {
        if ($dialog['top_message'] === $msg['id']) {
            $message = $msg;
            break;
        }
    }
    if ($message === null) {
        throw new Exception("Missing top-message: " . toJSON($dialog));
    }
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
                    } elseif (isset($user['id'])) {
                        $name = strval($user['id']);
                    } else {
                        $name = '';
                    }
                    if (!isset($message['from_id'])) {
                        $mp->logger('ERROR user: '    . toJSON($user),    Logger::ERROR);
                        $mp->logger('ERROR message: ' . toJSON($message), Logger::ERROR);
                        throw new Exception('Mismatch');
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
    return [
        'botapi_id'    => $peerId,
        'subtype'      => $subtype,
        'name'         => $name,
        'dialog'       => $dialog,
        'user_or_chat' => $peerval,
        'message'      => $message
    ];
}

function newVisitor($mp, int $fromId, array $message,  int $messageLimit): Generator
{
    return true;
    yield;
}
function verifyOldOrNew($mp, int $userId, array $dialog, array $message, int $messageLimit, int $lastStopTime): Generator
{
    if ($message === null) {
        yield $mp->logger("Last Message: NONE", Logger::ERROR);
        return false;
    }
    return false;
    //$user = yield $mp->users->getUsers(['id' => [$userId]]);
    //yield $mp->logger("The User: " . toJSON($user), Logger::ERROR);
    //yield $mp->logger("Last Message: " . toJSON($message), Logger::ERROR);
    if (($message['from_id']) !== $mp->getRobotId()) {
        $res = yield $mp->messages->getHistory([
            'peer'        => $message,
            'limit'       => $messageLimit,
            'offset_id'   => 0,
            'offset_date' => 0,
            'add_offset'  => 0,
            'max_id'      => 0,
            'min_id'      => 0,
        ]);
        $messages = $res['messages'];
        $chats    = $res['chats'];
        $users    = $res['users'];

        $isNew = true;
        foreach ($res['messages'] as $idx => $msg) {
            if ($msg['from_id'] === $mp->getRobotId()) {
                $isNew = false;
                break;
            }
        }
        yield $mp->logger("idx: '$idx'   " . ($isNew ? 'NEW' : "OLD"), Logger::ERROR);
        $mostRecent = \max($mp->startTime - 60 * 60 * 24 * 7, $lastStopTime);
        if ($message['date'] > $mostRecent) {
            if ($isNew) {
                //yield $mp->logger("New User: " . toJSON($message), Logger::ERROR);
            } else {
                //yield $mp->logger("Old User: " . toJSON($message), Logger::ERROR);
            }
        }
    }
    return $isNew;
}
