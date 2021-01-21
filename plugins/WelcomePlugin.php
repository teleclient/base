<?php

declare(strict_types=1);

namespace teleclient\base\plugins;

use teleclient\base\EventHandler;
use teleclient\base\plugins\Plugin;

class WelcomePlugin implements Plugin
{
    public function __construct()
    {
    }

    public function onStart(string $session, EventHandler $eh): \Generator
    {
        return;   // Comment out if you wish!
        $userIds = [];
        $titles  = [];
        $msgIds  = [];
        $dates   = [];
        yield visitAllDialogs(
            $eh,
            [/*'max_dialogs' => 20*/],
            function (
                object $ep,
                int    $totalDialogs,
                int    $index,
                int    $botapiId,
                string $subtype,
                string $name,
                array  $dialog,
                array  $peerval,
                ?array $message
            )
            use ($eh, &$userIds, &$titles, &$dates, &$msgIds) {
                if (
                    $subtype === 'user'  &&
                    $botapiId !== 777000 &&
                    $message             &&
                    !$peerval['self']    &&
                    !($peerval['deleted'] ?? false) &&
                    $message['from_id'] !== $eh->getRobotId()
                ) {
                    //$out = ['botapi_id' => $botapiId, 'name' => $name, 'subtype' => $subtype];
                    //yield $eh->logger(toJSON($out, false), Logger::ERROR);
                    $userIds[] = $botapiId;
                    $userName  = $peerval['username'] ?? '';
                    $firstName = $peerval['first_name'] ?? '';
                    $lastName  = $peerval['last_name'] ?? '';
                    $titles[]  = "'{$peerval['id']}' '$userName' '$firstName' '$lastName'";
                    $msgIds[]  = $message['id'];
                    $dates[]   = $message['date'];
                }
            }
        );
        $peerDialogs =  yield $eh->messages->getPeerDialogs([
            'peers' => $userIds
        ]);
        foreach ($peerDialogs['dialogs'] as $idx => &$dialog) {
            $topMessage    = $dialog['top_message'];           // int  The latest message ID
            $inboxMaxId    = $dialog['read_inbox_max_id'];     // int  Position up to which all incoming messages are read.
            $outboxMaxId   = $dialog['read_outbox_max_id'];    // int  Position up to which all outgoing messages are read.  Zero mean no response are sent
            $unreadCount   = $dialog['unread_count'];          // int  Number of unread messages.
            $mentionsCount = $dialog['unread_mentions_count']; // int  Number of unread mentions.
            if ($outboxMaxId === 0 && $inboxMaxId > 0 && $topMessage > 0) {
                $dialog['peer_id'] = $dialog['peer'];
                unset($dialog['peer']);
                unset($dialog['notify_settings']);
                unset($dialog['draft']);
                $dialog['title'] = $titles[$idx];
                $dialog['date']  = $dates[$idx];
                $dialog['message_id']  = $msgIds[$idx];
                yield $eh->logger("New Visitor: " . toJSON($dialog), Logger::ERROR);
            }
        }
    }

    public function __invoke(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    {
        yield $eh->echo("WelcomePlugin Processing started!" . PHP_EOL);
        $robotId   = $eh->getRobotId();
        $fromId    = $update['message']['from_id'] ?? 0;
        $peerType  = $update['message']['to_id']['_'] ?? '';
        $peer      = $update['message']['to_id'] ?? null;
        $toRobot   = $peerType === 'peerUser' && $peer['user_id'] === $robotId;
        $fromRobot = $fromId === $robotId;

        if (!$toRobot || $fromRobot) {
            return false;
        }
        $peerDialogs = yield $eh->messages->getPeerDialogs([
            'peers' => [$fromId]
        ]);
        yield $eh->logger("Visitor: " . toJSON($peerDialogs), Logger::ERROR);

        $peerDialog = $peerDialogs['dialogs'][0] ?? null;
        if (!$peerDialog) {
            return false;
        }
        $visitor = $peerDialog['users'][0];
        if ($visitor['bot'] ?? false) {
            return false;
        }
        $message = $peerDialog['messages']['0'];
        if ($robotId === $message['from_id'] ?? 0) {
            return false;
        }
        $dialog      = $peerDialog['dialogs'][0];
        $topMessage  = $dialog['top_message'];        // int  The latest message ID
        $inboxMaxId  = $dialog['read_inbox_max_id'];  // int  Position up to which all incoming messages are read.
        $outboxMaxId = $dialog['read_outbox_max_id']; // int  Position up to which all outgoing messages are read.  Zero mean no response are sent
        if ($outboxMaxId === 0 && $inboxMaxId > 0 && $topMessage > 0) {
            yield $eh->logger(" ", Logger::ERROR);
            yield $eh->logger("New Visitor: ", Logger::ERROR);
            yield $eh->logger(toJSON($dialog), Logger::ERROR);
            yield $eh->logger(toJSON($message), Logger::ERROR);
            yield $eh->logger(toJSON($visitor), Logger::ERROR);
            yield $eh->logger(" ", Logger::ERROR);
            return false;
        }
        return false;


        //yield $eh->logger(toJSON($update), Logger::ERROR);
        if (yield $this->newVisitor($eh, $fromId, $update['message'], 3/*$messageLimit*/)) {
            /*
            yield $eh->messages->sendMessage([
                'peer'            => $fromId,
                'reply_to_msg_id' => $msgId,
                'message'         => "Hello! My name is Esfand. What's your name?. '"
            ]);
            */
            yield $eh->logger("Replied to a new visitor", Logger::ERROR);
        } else {
            /*
            yield $eh->messages->sendMessage([
                'peer'            => $fromId,
                'reply_to_msg_id' => $msgId,
                'message'         => "Hi, John! What's new?'"
            ]);
            */
            yield $eh->logger("Replied to an existing visitor", Logger::ERROR);
        }
    }

    private function newVisitor(object $eh, int $fromId, array $message,  int $messageLimit): \Generator
    {
        if ($message === null) {
            yield $eh->logger("Last Message: NONE", Logger::ERROR);
            return false;
        }
        $peerDialogs =  yield $eh->messages->getPeerDialogs([
            'peers' => [$fromId]
        ]);
        $dialog = $peerDialogs['dialogs'][0] ?? null;
        if ($dialog) {
            $inboxMaxId    = $dialog['read_inbox_max_id'];     // int  Position up to which all incoming messages are read.
            $outboxMaxId   = $dialog['read_outbox_max_id'];    // int  Position up to which all outgoing messages are read.
            $unreadCount   = $dialog['unread_count'];          // int  Number of unread messages.
            $mentionsCount = $dialog['unread_mentions_count']; // int  Number of unread mentions.
            if ($inboxMaxId === 0 || $outboxMaxId === 0 || $unreadCount > 0 || $mentionsCount > 0) {
                yield $eh->logger("New Visitor: " . toJSON($dialog), Logger::ERROR);
                return true;
            }
        }
        return false;
    }

    private function verifyOldOrNew(object $eh, int $userId, array $dialog, array $message, int $messageLimit, int $lastStopTime): \Generator
    {
        if ($message === null) {
            yield $eh->logger("Last Message: NONE", Logger::ERROR);
            return false;
        }

        return false;
        //$user = yield $mp->users->getUsers(['id' => [$userId]]);
        //yield $mp->logger("The User: " . toJSON($user), Logger::ERROR);
        //yield $mp->logger("Last Message: " . toJSON($message), Logger::ERROR);
        if (($message['from_id']) !== $eh->getRobotId()) {
            $res = yield $eh->messages->getHistory([
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
                if ($msg['from_id'] === $eh->getRobotId()) {
                    $isNew = false;
                    break;
                }
            }
            yield $eh->logger("idx: '$idx'   " . ($isNew ? 'NEW' : "OLD"), Logger::ERROR);
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
}
