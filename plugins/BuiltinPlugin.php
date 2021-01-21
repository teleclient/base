<?php

declare(strict_types=1);

namespace teleclient\base\plugins;

use teleclient\base\EventHandler;
use teleclient\base\plugins\Plugin;
use \danog\MadelineProto\Logger;
use \danog\MadelineProto\Shutdown;
use function\Amp\File\{get, put, exists, getSize};

class BuiltinPlugin implements Plugin
{
    private $totalUpdates = 0;

    public function __construct()
    {
    }

    public function onStart(string $session, EventHandler $eh): \Generator
    {
        $this->totalUpdates = 0;

        // Send a startup notification and wipe it if configured so 
        $nowstr = date('d H:i:s', $eh->getStartTime());
        $text = SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $eh->getRobotName() . ' account.';
        $notif      = $eh->getNotif();
        $notifState = $notif['state'];
        $notifAge   = $notif['age'];
        $dest       = $eh->getRobotId();
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
    }

    //public function process(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    public function __invoke(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    {
        $this->totalUpdates += 1;

        if (
            $update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty'
        ) {
            return false;
        }

        $robotId      = $eh->getRobotId();
        $robotName    = $eh->getRobotName();
        $officeId     = $eh->getOfficeId();
        $admins       = $eh->getAdmins();
        $startTime    = $eh->getStartTime();
        $editMessage  = $eh->getEditMessage();
        $processCommands = $eh->getProcessCommands();
        $command      = $vars['command'];

        $verb         = $command['verb'];
        $params       = $command['params'];
        $msgType      = $update['_'];
        $msgDate      = $update['message']['date'] ?? null;
        $msgId        = $update['message']['id'] ?? 0;
        $msgText      = $update['message']['message'] ?? null;
        $fromId       = $update['message']['from_id'] ?? 0;
        $replyToId    = $update['message']['reply_to_msg_id'] ?? null;
        $peerType     = $update['message']['to_id']['_'] ?? '';
        $peer         = $update['message']['to_id'] ?? null;
        $isOutward    = $update['message']['out'] ?? false;

        $fromRobot    = $fromId   === $robotId && $msgText;
        $toRobot      = $peerType === 'peerUser' && $peer['user_id'] === $robotId && $msgText;
        $toOffice     = $peerType === 'peerChannel' && $peer['channel_id'] === $officeId;
        $fromAdmin    = in_array($fromId, $admins);

        switch ($processCommands && $fromAdmin && $toOffice && $verb ? $verb : '') {
            case '':
                // Not a verb and/or not sent by an admin.
                break;
            case 'ping':
                yield $eh->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $msgId,
                    'message'         => 'Pong'
                ]);
                yield $eh->logger("Command '/ping' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                break;
            default:
                yield $eh->messages->sendMessage([
                    'peer'            => $peer,
                    'reply_to_msg_id' => $msgId,
                    'message'         => "Invalid command: '$msgText'"
                ]);
                yield $eh->logger("Invalid Command '$msgText' rejected at " . date('d H:i:s!'), Logger::ERROR);
                break;
        }

        if ($fromRobot && $toRobot && $verb && $processCommands && $msgType === 'updateNewMessage') {
            switch ($verb) {
                case 'help':
                    $text = '' .
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
                        '<b>**Valid prefixes are / and !</b><br>';
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                    yield $eh->logger("Command '/help' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                    break;
                case 'status':
                    $currentMemUsage = getSizeString(\getCurrentMemory(true));
                    $peakMemUsage    = getSizeString(\getPeakMemory());
                    $memoryLimit     = ini_get('memory_limit');
                    $sessionSize     = getSizeString(getSessionSize(SESSION_FILE));
                    $launch = yield \getPreviousLaunch($eh, LAUNCHES_FILE, SCRIPT_START_TIME);
                    if ($launch) {
                        $lastStartTime      = strval($launch['time_start']);
                        $lastEndTime        = strval($launch['time_end']);
                        $lastLaunchMethod   = $launch['launch_method'];
                        $durationNano       = $lastEndTime - $lastStartTime;
                        $duration           = $lastEndTime ? \formatDuration($durationNano) : 'UNAVAILABLE';
                        $lastLaunchDuration = strval($duration);
                        $lastPeakMemory     = getSizeString($launch['memory_end']);
                    } else {
                        $lastEndTime        = 'UNAVAILABLE';
                        $lastLaunchMethod   = 'UNAVAILABLE';
                        $lastLaunchDuration = 'UNAVAILABLE';
                        $lastPeakMemory     = 'UNAVAILABLE';
                    }
                    $notif = $eh->getNotif();
                    $notifStr = 'OFF';
                    if ($notif['state']) {
                        $notifAge = $notif['age'];
                        $notifStr = $notifAge === 0 ? "ON / Never wipe" : "ON / Wipe after $notifAge secs.";
                    }
                    $status  = '<b>STATUS:</b>  (Script: ' . SCRIPT_NAME . ' ' . SCRIPT_VERSION . ')<br>';
                    $status .= "Host: " . hostname() . "<br>";
                    $status .= "Robot's Account: $robotName<br>";
                    $status .= "Robot's User-Id: $robotId<br>";
                    $status .= "Uptime: " . getUptime($startTime) . "<br>";
                    $status .= "Peak Memory: $peakMemUsage<br>";
                    $status .= "Current Memory: $currentMemUsage<br>";
                    $status .= "Allowed Memory: $memoryLimit<br>";
                    $status .= 'CPU: '         . getCpuUsage() . '<br>';
                    $status .= "Session Size: $sessionSize<br>";
                    $status .= 'Time: ' . date_default_timezone_get() . ' ' . date("d H:i:s") . '<br>';
                    $status .= 'Updates Processed: ' . $eh->updatesProcessed . '<br>';
                    $status .= 'Loop State: ' . ($eh->getLoopState() ? 'ON' : 'OFF') . '<br>';
                    $status .= 'Notification: ' . $notifStr . PHP_EOL;
                    $status .= 'Launch Method: ' . getLaunchMethod() . '<br>';
                    $status .= 'Previous Stop Time: '       . $lastEndTime . '<br>';
                    $status .= 'Previous Launch Method: '   . $lastLaunchMethod . '<br>';
                    $status .= 'Previous Launch Duration: ' . $lastLaunchDuration . '<br>';
                    $status .= 'Previous Peak Memory: '     . $lastPeakMemory . '<br>';
                    yield respond($eh, $peer, $msgId, $status, $editMessage);
                    yield $eh->logger("Command '/status' successfuly executed at " . date('d H:i:s!'), Logger::ERROR);
                    break;
                case 'stats':
                    $text = "Preparing statistics ....";
                    $result = yield respond($eh, $peer, $msgId, $text, $editMessage);
                    $response = yield $eh->contacts->getContacts();
                    $totalCount  = count($result['users']);
                    $mutualCount = 0;
                    foreach ($response['users'] as $user) {
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
                    yield visitAllDialogs/*visitDialogs*/(
                        $eh,
                        $params,
                        function (
                            $mp,
                            int    $totalDialogs,
                            int    $index,
                            int    $botapiId,
                            string $subtype,
                            string $name,
                            ?array $userOrChat,
                            array  $message
                        )
                        use (&$totalDialogsOut, &$peerCounts): void {
                            $totalDialogsOut = $totalDialogs;
                            $peerCounts[$subtype] += 1;
                        }
                    );
                    $stats  = '<b>STATISTICS</b>  (Script: ' . SCRIPT_NAME . ' ' . SCRIPT_VERSION . ')<br>';
                    $stats .= "Robot Account: $robotName<br>";
                    $stats .= "Total Dialogs: $totalDialogsOut<br>";
                    $stats .= "Users: {$peerCounts['user']}<br>";
                    $stats .= "Bots: {$peerCounts['bot']}<br>";
                    $stats .= "Basic groups: {$peerCounts['basicgroup']}<br>";
                    $stats .= "Forbidden Basic groups: {$peerCounts['chatForbidden']}<br>";
                    $stats .= "Supergroups: {$peerCounts['supergroup']}<br>";
                    $stats .= "Channels: {$peerCounts['channel']}<br>";
                    $stats .= "Forbidden Supergroups or Channels: {$peerCounts['channelForbidden']}<br>";
                    $stats .= "Total Contacts: $totalCount<br>";
                    $stats .= "Mutual Contacts: $mutualCount";
                    yield $eh->echo(toJSON($result) . PHP_EOL);
                    $resMsgId = $result[0]['message']['id'];
                    yield respond($eh, $peer, $resMsgId, $stats, false);
                    break;
                case 'loop':
                    $param = strtolower($params[0] ?? '');
                    if (($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                        $loopStatePrev = $eh->getLoopState();
                        $loopState = $param === 'on' ? true : ($param === 'off' ? false : $loopStatePrev);
                        $text = 'The loop is ' . ($loopState ? 'ON' : 'OFF') . '!';
                        yield respond($eh, $peer, $msgId, $text, $editMessage);
                        if ($loopState !== $loopStatePrev) {
                            $eh->setLoopState($loopState);
                        }
                    } else {
                        $text = "The argument must be 'on', 'off, or 'state'.";
                        yield respond($eh, $peer, $msgId, $text, $editMessage);
                    }
                    break;
                case 'crash':
                    yield $eh->logger("Purposefully crashing the script....", Logger::ERROR);
                    throw new \ErrorException('Artificial exception generated for testing the robot.');
                case 'maxmem':
                    $arr = array();
                    try {
                        for ($i = 1;; $i++) {
                            $arr[] = md5(strvAL($i));
                        }
                    } catch (\Exception $e) {
                        unset($arr);
                        $text = $e->getMessage();
                        yield $eh->logger($text, Logger::ERROR);
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
                        $text = "The notif argument must be 'on 123', 'off, or 'state'.";
                        yield respond($eh, $peer, $msgId, $text, $editMessage);
                        break;
                    }
                    switch ($param1) {
                        case 'state':
                            $notif = $eh->getNotif();
                            $notifState = $notif['state'];
                            $notifAge   = $notif['age'];
                            break;
                        case 'on':
                            $notifState = true;
                            $notifAge   = intval($params[1]);
                            $eh->setNotif($notifState, $notifAge);
                            break;
                        case 'off':
                            $notifState = false;
                            $eh->setNotif($notifState);
                            break;
                    }
                    $text = "The notif is " . (!$notifState ? "OFF" : ("ON / $notifAge secs")) . "!";
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                    break;
                case 'restart':
                    if (PHP_SAPI === 'cli') {
                        $text = "Command '/restart' is only avaiable under webservers. Ignored!";
                        yield respond($eh, $peer, $msgId, $text, $editMessage);
                        yield $eh->logger("Command '/restart' is only avaiable under webservers. Ignored!  " . date('d H:i:s!'), Logger::ERROR);
                        break;
                    }
                    yield $eh->logger('The robot re-started by the owner.', Logger::ERROR);
                    $text = 'Restarting the robot ...';
                    $result = yield respond($eh, $peer, $msgId, $text, $editMessage);
                    $eh->setStopReason('restart');
                    $date = $result['date'];
                    $eh->restart();
                    break;
                case 'logout':
                    yield $eh->logger('the robot is logged out by the owner.', Logger::ERROR);
                    $text = 'The robot is logging out. ...';
                    $result = yield respond($eh, $peer, $msgId, $text, $editMessage);
                    $date = $result['date'];
                    $eh->setStopReason('logout');
                    $eh->logout();
                case 'stop':
                    $text = 'Robot is stopping ...';
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                    yield $eh->logger($text . 'at ' . date('d H:i:s!'), Logger::ERROR);
                    $eh->setStopReason($verb);
                    break;
                default:
                    $text = 'Invalid command: ' . "'" . $msgText . "'  received at " . date('d H:i:s', $eh->getStartTime());
                    yield respond($eh, $peer, $msgId, $text, $editMessage);
                    break;

                    // ====== Place your command below: ===============================

                    //case 'dasoor1':
                    // your code
                    // break;

                    // case 'dasoor2':
                    // your code
                    // break;

                    // ==================================================================

            } // enf of the command switch
        } // end of the commander qualification check

        //Function: Finnish executing the Stop command.
        if ($fromRobot && $msgText === 'Robot is stopping ...') {
            if (Shutdown::removeCallback('restarter')) {
                yield $eh->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $eh->logger('Robot stopped at ' . date('d H:i:s!'), Logger::ERROR);
            yield $eh->stop();
        }

        return false;
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
}
