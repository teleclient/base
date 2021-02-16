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

function toJSON($var, bool $pretty = true) // bool|string
{
    if (isset($var['request'])) {
        unset($var['request']);
    }
    $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty ? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '') ? $json : var_export($var, true);
    return $json;
}

function errHandle($errNo, $errStr, $errFile, $errLine)
{
    $msg = "$errStr in $errFile on line $errLine";
    if ($errNo === E_NOTICE || $errNo === E_WARNING) {
        throw new ErrorException($msg, $errNo);
    } else {
        echo $msg;
    }
}
function exception_error_handler($severity, $message, $file, $line)
{
    //if (!(error_reporting() & $severity)) {
    // This error code is not included in error_reporting
    //return;
    //}
    throw new ErrorException($message, 0, $severity, $file, $line);
}

function nowMilli(): int
{
    $mt = explode(' ', microtime());
    return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
}

function formatDuration(int $elapsedNano): string
{
    $seconds = intdiv((int)$elapsedNano, 1000000000);
    $fractions = ((int)$elapsedNano) % 1000000000;
    $fracs  = intdiv($fractions, 1000000);

    $days  = floor($seconds / (3600 * 24));
    $hours = floor($seconds / 3600 % 3600);
    $mins  = floor($seconds / 60 % 60);
    $secs  = floor($seconds % 60);
    return sprintf('%01d %02d:%02d:%02d.%03d', $days, $hours, $mins, $secs, $fracs);
}

function timeDiffFormatted(float $startTime, float $endTime = null): string
{
    $endTime = $endTime ?? microtime(true);

    $diff = $endTime - $startTime;

    $sec   = intval($diff);
    $micro = $diff - $sec;
    return strftime('%T', mktime(0, 0, $sec)) . str_replace('0.', '.', sprintf('%.3f', $micro));
}

function getUptime(float $start, float $end = null): string
{
    $end = $end !== 0 ? $end : \microtime();
    $age     = ($end - $start) * 1000000;
    $days    = floor($age  / 86400);
    $hours   = floor(($age / 3600) % 3600);
    $minutes = floor(($age / 60) % 60);
    $seconds = $age % 60;
    $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
    return $ageStr;
}

function getWinMemory(): int
{
    $cmd = 'tasklist /fi "pid eq ' . strval(getmypid()) . '"';
    $tasklist = trim(exec($cmd, $output));
    $mem_val = mb_strrchr($tasklist, ' ', TRUE);
    $mem_val = trim(mb_strrchr($mem_val, ' ', FALSE));
    $mem_val = str_replace('.', '', $mem_val);
    $mem_val = str_replace(',', '', $mem_val);
    $mem_val = intval($mem_val);
    return $mem_val;
}

function getPeakMemory(): int
{
    switch (PHP_OS_FAMILY) {
        case 'Linux':
            $mem = memory_get_peak_usage(true);
            break;
        case 'Windows':
            $mem = getWinMemory();
            break;
        default:
            throw new Exception('Unknown OS: ' . PHP_OS_FAMILY);
    }
    return $mem;
}

function getCurrentMemory(): int
{
    switch (PHP_OS_FAMILY) {
        case 'Linux':
            $mem = memory_get_usage(true);
            break;
        case 'Windows':
            $mem = memory_get_usage(true);
            break;
        default:
            throw new Exception('Unknown OS: ' . PHP_OS_FAMILY);
    }
    return $mem;
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
    $name = \getHostname();
    if (!$full && $name && strpos($name, '.') !== false) {
        $name = substr($name, 0, strpos($name, '.'));
    }
    return $name;
}

function strStartsWith(string $haystack, string $needle, bool $caseSensitive = true): bool
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

function secondsToNexMinute(float $now = null): int
{
    $now   = $now ?? \microtime();
    $now   = (int) ($now * 1000000);
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


function authorized($api): int
{
    return $api ? ($api->API ? $api->API->authorized : 4) : 5;
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
        case 4:
            return 'INVALID_APP';
        case 5:
            return 'NULL_API_OBJECT';
        default:
            throw new \ErrorException("Invalid authorization status: $authorized");
    }
}

function parseCommand(?string $msg, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => null, 'params' => []];
    $msg = $msg ? trim($msg) : '';
    if (strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
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

function sendAndDelete(object $eh, int $dest, string $text, \UserDate $dateObj, int $delaysecs = 30, bool $delmsg = true): Generator
{
    $result = yield $eh->messages->sendMessage([
        'peer'    => $dest,
        'message' => $text
    ]);
    if ($delmsg) {
        $msgid = $result['updates'][1]['message']['id'];
        $eh->callFork((function () use ($eh, $msgid, $delaysecs, $dateObj) {
            try {
                yield $eh->sleep($delaysecs);
                yield $eh->messages->deleteMessages([
                    'revoke' => true,
                    'id'     => [$msgid]
                ]);
                yield $eh->logger('Robot\'s startup message is deleted at ' . $dateObj->milli() . '!', Logger::ERROR);
            } catch (\Exception $e) {
                yield $eh->logger($e, Logger::ERROR);
            }
        })());
    }
}

function getWebServerName(): ?string
{
    return $_SERVER['SERVER_NAME'] ?? null;
}
function setWebServerName(string $serverName): void
{
    if ($serverName !== '') {
        $_SERVER['SERVER_NAME'] = $serverName;
    }
}

function appendLaunchRecord(object $eh, string $fileName, float $scriptStartTime, string $launchMethod, string $stopReason, int $peakMemory): array
{
    $record['time_start']    = $scriptStartTime;
    $record['time_end']      = 0;
    $record['launch_method'] = $launchMethod; // \getLaunchMethod();
    $record['stop_reason']   = $stopReason;
    $record['memory_start']  = $peakMemory; // \getPeakMemory();
    $record['memory_end']    = 0;

    $line = "{$record['time_start']} {$record['time_end']} {$record['launch_method']} {$record['stop_reason']} {$record['memory_start']} {$record['memory_end']}";
    file_put_contents($fileName, "\n" . $line, FILE_APPEND | LOCK_EX);
    //yield \Amp\File\put($fileName, "\n" . $line);

    return $record;
}

function updateLaunchRecord(string $fileName, float $scriptStartTime, float $scriptEndTime, string $stopReason, int $peakMemory): array
{
    $record = null;
    $new    = null;
    $lines = file($fileName);
    //$lines  = yield Amp\File\get($fileName);
    $key    = $scriptStartTime . ' ';
    $content = '';
    foreach ($lines as $line) {
        if (strStartsWith($line, $key)) {
            $items = explode(' ', $line);
            $record['time_start']    = intval($items[0]); // $scriptStartTime
            $record['time_end']      = $scriptEndTime;
            $record['launch_method'] = $items[2]; // \getLaunchMethod();
            $record['stop_reason']   = $stopReason;
            $record['memory_start']  = intval($items[4]);
            $record['memory_end']    = $peakMemory; // \getPeakMemory();
            $new = "{$record['time_start']} {$record['time_end']} {$record['launch_method']} {$record['stop_reason']} {$record['memory_start']} {$record['memory_end']}";
            $content .= $new . "\n";
        } else {
            $content .= $line;
        }
    }
    if ($new === null) {
        throw new \ErrorException("Launch record not found! key: $scriptStartTime");
    }
    file_put_contents($fileName, rtrim($content));
    //yield Amp\File\put($fileName, rtrim($content));
    return $record;
}

function getPreviousLaunch(object $eh, string $fileName, float $scriptStartTime): \Generator
{
    $content = yield get($fileName);
    if ($content === '') {
        return null;
    }
    $content = substr($content, 1);
    $lines = explode("\n", $content);
    yield $eh->logger("Launches Count:" . count($lines), Logger::ERROR);
    $record = null;
    $key = strval($scriptStartTime) . ' ';
    foreach ($lines as $line) {
        if (strStartsWith($line, $key)) {
            break;
        }
        $record = $line;
    }
    if ($record === null) {
        return null;
    }
    $fields = explode(' ', trim($record));
    if (count($fields) !== 6) {
        throw new \ErrorException("Invalid launch information .");
    }
    $launch['time_start']    = intval($fields[0]);
    $launch['time_end']      = intval($fields[1]);
    $launch['launch_method'] = $fields[2];
    $launch['stop_reason']   = $fields[3];
    $launch['memory_start']  = intval($fields[4]);
    $launch['memory_end']    = intval($fields[5]);
    return $launch;
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
    $reason   = $mp->__get('shutdown_reason');
    if ($duration /*&& $reason && $reason !== 'stop' && $reason !== 'restart'*/) {
        return $duration;
    }
    return -1;
}

function getURL(): ?string
{
    //$_SERVER['REQUEST_URI'] => '/base/?MadelineSelfRestart=1755455420394943907'
    $url = null;
    if (PHP_SAPI !== 'cli') {
        $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    return $url;
}

function checkTooManyRestartsAsync(object $eh, string $startupFilename): \Generator
{
    //$startupFilename = 'data/startups.txt';
    $startups = [];
    if (yield exists($startupFilename)) {
        $startupsText = yield get($startupFilename);
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
    yield put($startupFilename, $startupsText);
    $restartsCount = count($startups);
    yield $eh->logger("startups: {now:$nowMilli, count0:$startupsCount0, count1:$restartsCount}", Logger::ERROR);
    return $restartsCount;
}

function checkTooManyRestarts(string $startupFilename): int
{
    $startups = [];
    if (\file_exists($startupFilename)) {
        $startupsText = \file_get_contents($startupFilename);
        $startups = explode('\n', $startupsText);
    } else {
        // Create the file
    }

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
    \file_put_contents($startupFilename, $startupsText);
    $restartsCount = count($startups);
    return $restartsCount;
}

function visitAllDialogs(object $mp, ?array $params, Closure $sliceCallback = null): \Generator
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
    $limit = min($limit, $maxDialogs);
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
        //yield $mp->logger('Request: ' . toJSON($params, false), Logger::ERROR);
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
        yield $mp->logger($countMsg, Logger::ERROR);
        if (count($res['messages']) !== $sliceSize) {
            throw new Exception('Unequal slice size.');
        }

        if ($sliceCallback !== null) {
            //===================================================================================================
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
            //yield $mp->logger("Sent Dialogs:$sentDialogs,  Max Dialogs:$maxDialogs, Slice Size:$sliceSize", Logger::ERROR);
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
                    //yield $mp->logger("lastPeer is set to $id.", Logger::ERROR);
                }
                if (!$lastId) {
                    $lastId = $dialog['top_message'];
                    //yield $mp->logger("lastId is set to $lastId.", Logger::ERROR);
                }
                foreach ($res['messages'] as $message) {
                    $idBot = yield $mp->getId($message);
                    if (
                        $message['_'] !== 'messageEmpty' &&
                        $idBot  === $lastPeer            &&
                        $lastId === $message['id']
                    ) {
                        $lastDate = $message['date'];
                        //yield $mp->logger("lastDate is set to $lastDate from {$message['id']}.", Logger::ERROR);
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
            //yield $mp->logger("Pausing for $pause seconds. ...", Logger::ERROR);
            //yield $mp->logger(" ", Logger::ERROR);
            yield $mp->sleep($pause);
        } else {
            //yield $mp->logger(" ", Logger::ERROR);
        }
    } // end of while/for
}


function getDialogs(object $eh, int $messageLimit, int $lastStopTime): \Generator
{
    $dialogs = [];
    yield visitAllDialogs(
        $eh,
        [/*'max_dialogs' => 20*/],
        function (
            $ep,
            int    $totalDialogs,
            int    $index,
            int    $botapiId,
            string $subtype,
            string $name,
            array  $dialog,
            array  $peerval,
            ?array $message
        )
        use (&$dialogs, $eh, $messageLimit, $lastStopTime) {
            if (false && $subtype === 'user') {
                unset($dialog['notify_settings']);
                unset($dialog['draft']);
                if ($user['deleted'] ?? false) {
                    $dialog['deleted'] = true;
                }
                if ($message !== null) {
                    $dialog['date'] = $message['date'];
                }
                $dialogs[] = ['name' => $name] + $dialog;
            }
            if ($subtype === 'user' && !$peerval['self'] && $botapiId !== 777000 && $message && $message['from_id'] !== $eh->getRobotId()) {
                //$out = ['botapi_id' => $botapiId, 'name' => $name, 'subtype' => $subtype];
                //yield $eh->logger(toJSON($out),     Logger::ERROR);
                //yield $eh->logger(toJSON($peerval), Logger::ERROR);
                //yield $eh->logger(toJSON($message), Logger::ERROR);
                $messageLimit = 3;
                //$userType = yield verifyOldOrNew($eh, $botapiId, $dialog, $message, $messageLimit, $lastStopTime);
            }
        }
    );
    return $dialogs;
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


function respond(object $eh, array $peer, int $msgId, string $text, $editMessage = false): \Generator
{
    if ($editMessage) {
        $result = yield $eh->messages->editMessage([
            'peer'       => $peer,
            'id'         => $msgId,
            'message'    => $text,
            'parse_mode' => 'HTML',
        ]);
    } else {
        $result = yield $eh->messages->sendMessage([
            'peer'            => $peer,
            'reply_to_msg_id' => $msgId,
            'message'         => $text,
            'parse_mode'      => 'HTML',
        ]);
    }
    return $result;
}

function safeStartAndLoop(API $mp, string $eventHandler, object $config, array $genLoops): void
{
    $mp->async(true);
    $mp->loop(function () use ($mp, $eventHandler, $config, $genLoops) {
        $errors = [];
        while (true) {
            try {
                $started = false;
                $me = yield $mp->start();
                yield $mp->setEventHandler($eventHandler);
                $eventHandlerObj = $mp->getEventHandler($eventHandler);
                $eventHandlerObj->setConfig($config);
                $eventHandlerObj->setSelf($me);
                foreach ($genLoops as $genLoop) {
                    $genLoop->start(); // Do NOT use yield.
                }
                $started = true;
                Tools::wait(yield from $mp->API->loop());
                break;
            } catch (\Throwable $e) {
                $errors = [\time() => $errors[\time()] ?? 0];
                $errors[\time()]++;
                if ($errors[\time()] > 10 && (!$mp->inited() || !$started)) {
                    yield $mp->logger->logger("More than 10 errors in a second and not inited, exiting!", Logger::FATAL_ERROR);
                    break;
                }
                yield $mp->logger->logger((string) $e, Logger::FATAL_ERROR);
                yield $mp->report("Surfaced: $e");
            }
        }
    });
}

function getPeers(API $MadelineProto): array
{
    $msgIds = Tools::getVar($MadelineProto->API, 'msg_ids');
    Logger::log(toJSON($msgIds), Logger::ERROR);
    return $msgIds;
}

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
        if (!$webServerName) {
            echo ("To enable the restart, the constant SERVER_NAME must be defined!" . PHP_EOL);
            $webServerName = '';
        }
    }
    return $webServerName;
}

function sanityCheck(API $MadelineProto, object $config, \UserDate $dateObj): void
{
    $variables['script_name']         = SCRIPT_NAME;
    $variables['script_version']      = SCRIPT_VERSION;
    $variables['os_family']           = PHP_OS_FAMILY;
    $variables['php_version']         = PHP_VERSION;
    $variables['server_name']         = \getWebServerName();
    $variables['request_url']         = REQUEST_URL;
    $variables['user_agent']          = USER_AGENT;
    $variables['memory_limit']        = MEMORY_LIMIT;
    $variables['session_file']        = $config->mp0->session;
    $variables['script_start_time']   = $dateObj->milli(SCRIPT_START_TIME);
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

function getUserAgent(): ?string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? null;
}

/*
function readableMilli(float $time, \DateTimeZone $timeZoneObj, string $format = 'H:i:s.v'): string
{
    $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
    $dateObj->setTimeZone($timeZoneObj);
    return $dateObj->format($format);
}

function mySqlTime(float $time = null): string
{
    $time   = $time ?? \microtime(true);
    $tzObj  = new \DateTimeZone('gmt');
    $format = 'Y-m-d H:i:s.u';

    $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
    $dateObj->setTimeZone($tzObj);
    return $dateObj->format($format);
}
*/

class UserDate
{
    private $timeZoneObj;

    function __construct(string $zone)
    {
        $this->timeZoneObj = new \DateTimeZone($zone);
    }

    public function milli(float $time = null, string $format = 'H:i:s.v'): string
    {
        $time   = $time ?? \microtime(true);
        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
        $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }

    function mySqlmicro(float $time = null): string
    {
        $time   = $time ?? \microtime(true);
        $format = 'Y-m-d H:i:s.u';

        $dateObj = \DateTimeImmutable::createFromFormat('U.u', number_format($time, 6, '.', ''));
        $dateObj->setTimeZone($this->timeZoneObj);
        return $dateObj->format($format);
    }
}
