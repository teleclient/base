<?php

declare(strict_types=1);

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\APIWrapper;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\EventHandler as MadelineEventHandler;
use function\Amp\File\{get, put, exists, getSize};

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

    private $oldAge = 2;

    public function __construct(?APIWrapper $API)
    {
        parent::__construct($API);

        $this->startTime = time();
        $this->stopTime  = 0;

        $this->officeId  = 1373853876;
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
        $lastStopTime       = $launch['stop']     ?? 0;
        $lastLaunchMethod   = $launch['method']   ?? null;
        $lastLaunchDuration = $launch['duration'] ?? 0;
        $lastPeakMemory     = $launch['memory']   ?? 0;

        // ================================================================================
        $dialogs = [];
        $mp = $this;
        yield visitAllDialogs(
            $this,
            [/*'max_dialogs' => 20*/],
            function ($mp, int $totalDialogs, int $index, int $botapiId, string $subtype, string $name, array $dialog, array $peerval, ?array $message)
            use (&$dialogs, $lastStopTime) {
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
                //yield $this->logger(toJSON($dialog, false));
                if ($subtype === 'user' && !$peerval['self'] && $botapiId !== 777000 && $message && $message['from_id'] !== $this->robotId) {
                    //$out = ['botapi_id' => $botapiId, 'name' => $name, 'subtype' => $subtype];
                    //yield $mp->logger(toJSON($out),     Logger::ERROR);
                    //yield $mp->logger(toJSON($peerval), Logger::ERROR);
                    //yield $mp->logger(toJSON($message), Logger::ERROR);
                    $messageLimit = 5;
                    $isNew = yield verifyOldOrNew($mp, $botapiId, $dialog, $message, $messageLimit, $lastStopTime);
                }
            }
        );
        foreach ($dialogs as $dialog) {
            yield $this->logger(toJSON($dialog), Logger::ERROR);
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
            yield $this->echo(toJSON($update) . '<br>'  . PHP_EOL);
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
            if (newVisitor($this, $fromId, $update['message'], 5/*$messageLimit*/)) {
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
                    $status  = '<b>STATUS:</b>  (Script: ' . SCRIPT_NAME . ' ' . SCRIPT_VERSION . ')<br>';
                    $status .= "Host: " . hostname() . "<br>";
                    $status .= "Robot's User-Name: $this->account<br>";
                    $status .= "Robot's User-Id: $this->robotId<br>";
                    $status .= "Uptime: " . getUptime($this->startTime) . "<br>";
                    $status .= "Peak Memory: $peakMemUsage<br>";
                    $status .= "Current Memory: $currentMemUsage<br>";
                    $status .= "Allowed Memory: $memoryLimit<br>";
                    $status .= 'CPU: '         . getCpuUsage() . '<br>';
                    $status .= "Session Size: $sessionSize<br>";
                    $status .= 'Time: ' . date_default_timezone_get() . ' ' . date("d H:i:s") . '<br>';
                    $status .= 'Updates Processed: ' . $this->updatesProcessed . '<br>';
                    $status .= 'Loop State: ' . ($this->getLoopState() ? 'ON' : 'OFF') . '<br>';
                    $status .= 'Notification: ' . $notifStr . PHP_EOL;
                    $status .= 'Launch Method: ' . getLaunchMethod() . '<br>';
                    $status .= 'Previous Stop Time: '       . $lastStopTime . '<br>';
                    $status .= 'Previous Launch Method: '   . $lastLaunchMethod . '<br>';
                    $status .= 'Previous Launch Duration: ' . $lastLaunchDuration . '<br>';
                    $status .= 'Previous Peak Memory: '     . $lastPeakMemory . '<br>';
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
                    yield visitAllDialogs/*visitDialogs*/(
                        $this,
                        $params,
                        function ($mp, int $totalDialogs, int $index, int $botapiId, string $subtype, string $name, ?array $userOrChat, array $message)
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
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Invalid command: ' . "'" . $msgOrig . "'  received at " . date('d H:i:s', $moment)
                    ]);
                    break;
            } // enf of the command switch
        } // end of the commander qualification check

        //Function: Finnish executing the Stop command.
        if ($byRobot && $msgOrig === 'Robot is stopping ...') {
            if (Shutdown::removeCallback('restarter')) {
                yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $this->stop();
        }
    } // end of function
} // end of the class
