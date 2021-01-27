<?php

declare(strict_types=1);

namespace teleclient\base;

include __DIR__ .        "/plugins/Plugin.php";
include __DIR__ .  "/plugins/VerifyPlugin.php";
include __DIR__ .   "/plugins/EmptyPlugin.php";
include __DIR__ . "/plugins/WelcomePlugin.php";
include __DIR__ . "/plugins/BuiltinPlugin.php";

use teleclient\base\plugins\VerifyPlugin;
use teleclient\base\plugins\EmptyPlugin;
use teleclient\base\plugins\BuiltinPlugin;
use \danog\MadelineProto\Logger;
use \danog\MadelineProto\APIWrapper;
use \danog\MadelineProto\EventHandler as MadelineEventHandler;
use function\Amp\File\{get, put, exists, getSize};

class EventHandler extends MadelineEventHandler
{
    public static $userSelf;
    public static  $botSelf;

    public  $verifyPlugin;
    public   $emptyPlugin;
    public $welcomePlugin;
    public $builtinPlugin;

    private $startTime;
    private $stopTime;

    private $robotId;     // id of this worker.
    private $robotName;   // username, firstname, or the id of the robot.

    private $owner;       // id or username of the owner of the workers.
    private $officeId;    // id of the office channel;
    private $admins;      // ids of the users which have admin rights to issue commands.
    private $workers;     // ids of the userbots which take orders from admins to execute commands.
    private $reportPeers; // ids of the support people who will receive the errors.

    private $stopReason;

    private $processCommands = false;
    private $editMessage     = false;

    public function __construct(?APIWrapper $API)
    {
        parent::__construct($API);

        $this->verifyPlugin  = new  VerifyPlugin($this);
        $this->emptyPlugin   = new   EmptyPlugin($this);
        $this->welcomePlugin = new   EmptyPlugin($this);
        $this->builtinPlugin = new BuiltinPlugin($this);

        $this->startTime       = time();
        $this->stopTime        = 0;
        $this->stopReason      = 'UNKNOWN';
        $this->processCommands = false;
        $this->officeId        = 1373853876;
    }

    public function __magic_sleep()
    {
        return [];
    }
    public function __wakeup()
    {
    }

    public function onStart(): \Generator
    {
        $launchMethod = \getLaunchMethod();
        $peakMemory   = \getPeakMemory();
        $launch       = \appendLaunchRecord(LAUNCHES_FILE, SCRIPT_START_TIME, $launchMethod, 'kill', $peakMemory);
        yield $this->logger("Event Handler instantiated at " . date('d H:i:s', $this->getStartTime()) . "using $peakMemory!", Logger::ERROR);
        yield $this->logger(toJSON($launch), Logger::ERROR);
        unset($launch);

        $robot = yield $this->getSelf();
        if (!\is_array($robot)) {
            throw new \Exception("Self is not available!");
        }
        $this->robotId = $robot['id'];
        if (isset($robot['username'])) {
            $this->robotName = $robot['username'];
        } elseif (isset($robot['first_name'])) {
            $this->robotName = $robot['first_name'];
        } else {
            $this->robotName = strval($robot['id']);
        }

        $this->ownerId     = $this->robotId;
        $this->admins      = [$this->robotId];
        $this->reportPeers = [$this->robotId];

        $this->processCommands = false;
        $this->editMessage     = false;

        $robotId    = $this->getRobotId();
        $robotName  = $this->getRobotName();
        $officeId   = $this->getOfficeId();
        $admins     = $this->getAdmins();
        $adminsJson = '[';
        foreach ($admins as $idx => $admin) {
            if ($idx === 0) {
                $adminsJson .= "$admin";
            } else {
                $adminsJson .=  ", $admin";
            }
            $adminsJson .=  "]";
        }
        yield $this->logger("$robotName: {officeId:$officeId,  robotId:$robotId,  admins: $adminsJson}", Logger::ERROR);

        $session = '';
        yield  $this->verifyPlugin->onStart($session, $this);
        yield   $this->emptyPlugin->onStart($session, $this);
        yield $this->welcomePlugin->onStart($session, $this);
        yield $this->builtinPlugin->onStart($session, $this);
    }

    public function onUpdateEditMessage(array $update): \Generator
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateEditChannelMessage(array $update): \Generator
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewChannelMessage(array $update): \Generator
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage(array $update): \Generator
    {
        $session = SESSION_FILE;
        $command = \parseCommand($update['message']['message'] ?? null);
        $vars    = ['command' => $command];

        $robotId         = $this->getRobotId();
        $robotName       = $this->getRobotName();
        $officeId        = $this->getOfficeId();
        $admins          = $this->getAdmins();
        $startTime       = $this->getStartTime();
        $processCommands = $this->getProcessCommands();

        $verb = $command['verb'];

        $fromId    = $update['message']['from_id'] ?? 0;
        $peerType  = $update['message']['to_id']['_'] ?? '';
        $peer      = $update['message']['to_id'] ?? null;
        $msgType   = $update['_'];
        $msgDate   = $update['message']['date']    ?? null;
        $msgText   = $update['message']['message'] ?? null;
        $msgIsNew  = $msgDate > $startTime;

        $fromRobot = $fromId   === $robotId;
        $toRobot   = $peerType === 'peerUser'    && $peer['user_id']    === $robotId;
        $toOffice  = $peerType === 'peerChannel' && $peer['channel_id'] === $officeId;
        $fromAdmin = in_array($fromId, $admins);

        // Start the Command Processing Engine based on the date of a received command
        if (
            $verb && !$processCommands &&
            $msgIsNew && $msgType === 'updateNewMessage' &&
            ($fromRobot && ($toRobot || $toOffice) || $fromAdmin && $toOffice)
        ) {
            $this->processCommands = true;
            $processCommands = $this->getProcessCommands();
            yield $this->logger('Command-Processing engine started at ' . date('d H:i:s'), Logger::ERROR);
        }

        //yield $this->dispatchEvent($update, $session, $vars);
        $processed = yield ($this->verifyPlugin)($update, $session, $this, $vars);
        if ($processed) {
            //return;
        }
        $processed = yield ($this->emptyPlugin)($update, $session, $this, $vars);
        if ($processed) {
            //return;
        }
        $processed = yield ($this->welcomePlugin)($update, $session, $this, $vars);
        if ($processed) {
            //return;
        }
        $processed = yield ($this->builtinPlugin)($update, $session, $this, $vars);
        if ($processed) {
            return;
        }
    }

    private function dispatchEvent($update, $session, $vars): \Generator
    {
        $processed = yield $this->verifyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            //return true;
        }
        $processed = yield $this->emptyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            //return true;
        }
        $processed = yield $this->welcomePlugin->process($update, $session, $this, $vars);
        if ($processed) {
            //return true;
        }
        $processed = yield $this->builtinPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            //return true;
        }
        return false;
    }

    public function getRobotID(): int
    {
        return $this->robotId;
    }

    public function getStartTime(): int
    {
        return $this->startTime;
    }

    public function getOfficeId(): int
    {
        return $this->officeId;
    }

    public function getProcessCommands(): bool
    {
        return $this->processCommands;
    }

    public function getRobotName(): string
    {
        return $this->robotName;
    }

    public function getAdmins(): array
    {
        return $this->admins;
    }

    public function getStopReason(): string
    {
        return $this->stopReason ?? 'UNKNOWN';
    }
    public function setStopReason(string $stopReason): void
    {
        $this->stopReason = $stopReason;
    }

    function getEditMessage()
    {
        return $this->editMessage;
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
} // end of the class
