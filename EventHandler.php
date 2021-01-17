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
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\EventHandler as MadelineEventHandler;
use function \Amp\File\{get, put, exists, getSize};

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
    private $totalUpdates;

    private $robotId;     // id of this worker.
    private $robotName;   // username, firstname, or the id of the robot.

    private $owner;       // id or username of the owner of the workers.
    private $officeId;    // id of the office channel;
    private $admins;      // ids of the users which have admin rights to issue commands.
    private $workers;     // ids of the userbots which take orders from admins to execute commands.
    private $reportPeers; // ids of the support people who will receive the errors.

    private $stopReason;

    private $oldAge = 2;

    public function __construct(?APIWrapper $API)
    {
        parent::__construct($API);

        $this->verifyPlugin  = new  VerifyPlugin($API);
        $this->emptyPlugin   = new   EmptyPlugin($API);
        $this->welcomePlugin = new   EmptyPlugin($API);
        $this->builtinPlugin = new BuiltinPlugin($API);

        $this->startTime = time();
        $this->stopTime  = 0;

        $this->officeId  = 1373853876;
    }

    public function __magic_sleep()
    {
        return [];
    }
    public function __wakeup()
    {
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
        $command = parseCommand($update['message']['message'] ?? null);
        $vars    = ['command' => $command];

        $this->totalUpdates += 1;

        $robotId      = $this->getRobotId();
        $robotName    = $this->getRobotName();
        $officeId     = $this->getOfficeId();
        $admins       = $this->getAdmins();
        $oldAge       = $this->getOldAge();
        $startTime    = $this->getStartTime();
        $totalUpdates = $this->getTotalUpdates();

        $msgAge = $startTime - $update['message']['date'];

        $verb    = $command['verb'];
        $msgAge  = $this->getStartTime() - $update['message']['date'];
        $msgType      = $update['_'];
        $msgDate      = $update['message']['date']    ?? null;
        $msgText      = $update['message']['message'] ?? null;
        $fromId       = $update['message']['from_id'] ?? 0;
        $peerType     = $update['message']['to_id']['_'] ?? '';
        $peer         = $update['message']['to_id'] ?? null;
        $byRobot      = $fromId    === $this->robotId && $msgText;
        $toRobot      = $peerType  === 'peerUser' && $peer['user_id'] === $robotId && $msgText;
        $byVisitor = !$byRobot && $toRobot;
        $toOffice  = $peerType === 'peerChannel' && $peer['channel_id'] === $officeId;
        $fromAdmin = in_array($fromId, $admins);

        // Recognize and log old or new commands and reactions.
        if ($msgText && $byRobot && $toRobot && $msgType === 'updateNewMessage' && $verb) {
            $new  = $msgAge <= $this->oldAge;
            $age  = $new ? 'New' : 'Old';
            $now  = date('H:i:s', $this->getStartTime());
            $time = date('H:i:s', $msgDate);
            $text = "$age Command:{verb:'$verb', time:$time, now:$now, age:$msgAge}";
            yield $this->logger($text, Logger::ERROR);
        }

        // Start the Command Processing Engine
        if (
            !$this->processCommands &&
            $byRobot && $toRobot &&
            $msgType === 'updateNewMessage' &&
            $msgAge <= $this->oldAge
        ) {
            $this->processCommands = true;
            yield $this->logger('Command-Processing engine started at ' . date('H:i:s', $this->getStartTime()), Logger::ERROR);
        }

        // Log some information for debugging
        if (($byRobot || $toRobot) &&  true) {
            $criteria = ['by_robot' => $byRobot, 'to_robot' => $toRobot, 'process' => $this->processCommands];
            $criteria[$verb ? 'action' : 'reaction'] = mb_substr($msgText, 0, 40);
            $this->logger(toJSON($criteria, false), Logger::ERROR);
        }
        if ($fromAdmin || $toOffice) {
            yield $this->logger("fromId: $fromId, toOffice:" . ($toOffice ? 'true' : 'false'), Logger::ERROR);
            yield $this->logger(toJSON($update), Logger::ERROR);
        }

        //$processed = yield $this->dispatchOnUpdate($update, $session, $vars);
        $processed = yield $this->verifyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return;
        }
        $processed = yield $this->emptyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return;
        }
        $processed = yield $this->welcomePlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return;
        }
        $processed = yield $this->builtinPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return;
        }
    }

    public function onStart(): \Generator
    {
        yield $this->logger("Event Handler instantiated at " . date('d H:i:s', $this->getStartTime()) . "!", Logger::ERROR);

        $robot = yield $this->getSelf();
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

        $this->processCommands  = false;
        $this->updatesProcessed = 0;

        $maxRestart = 5;
        $eh = $this;
        //=============================================================
        $restartsCount = yield checkTooManyRestarts($eh, 'data/startups.txt');
        $nowstr = date('d H:i:s', $this->getStartTime());
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

        /*
        $text = SCRIPT_NAME . ' ' . SCRIPT_VERSION . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $this->getRobotName() . ' account.';
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
        */

        $session = '';
        yield $this->verifyPlugin->onStart($session, $this);
        yield $this->emptyPlugin->onStart($session, $this);
        yield $this->welcomePlugin->onStart($session, $this);
        yield $this->builtinPlugin->onStart($session, $this);
    }

    private function dispatchOnUpdate($update, $session, $vars): \Generator
    {
        $processed = yield $this->verifyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return true;
        }
        $processed = yield $this->emptyPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return true;
        }
        $processed = yield $this->welcomePlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return true;
        }
        $processed = yield $this->builtinPlugin->process($update, $session, $this, $vars);
        if ($processed) {
            return true;
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

    public function getOldAge(): int
    {
        return $this->oldAge;
    }

    public function getRobotName(): string
    {
        return $this->robotName;
    }

    public function getTotalUpdates(): int
    {
        return $this->totalUpdates;
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
