<?php

declare(strict_types=1);

namespace teleclient\base\plugins;

use teleclient\base\EventHandler;
use teleclient\base\plugins\Plugin;

class VerifyPlugin implements Plugin
{
    public function onStart(string $session, EventHandler $eh): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    {
        $debug   = false;
        $session = SESSION_FILE;
        $command = \parseCommand($update['message']['message'] ?? null);
        $vars    = ['command' => $command];

        $robotId         = $eh->getRobotId();
        $robotName       = $eh->getRobotName();
        $officeId        = $eh->getOfficeId();
        $admins          = $eh->getAdmins();
        $startTime       = $eh->getStartTime();
        $processCommands = $eh->getProcessCommands();
        $startTime       = $eh->getStartTime();

        $updateType = $update['_'];
        $updateId   = $update['pts'];
        $userId     = $update['message']['from_id'] ?? 0;
        $msgId      = $update['message']['id']      ?? 0;
        $msgText    = $update['message']['message'] ?? null;

        if ($debug) {
            $chatInfo   = yield $eh->getInfo($update);
            $chatId     = $chatInfo['bot_api_id'];
            $chatType   = $chatInfo['type'];  //Can be either “private”, “group”, “supergroup” or “channel”
            $chatTitle  = $chatInfo['User']['username'] ?? ($chatInfo['Chat']['title'] ?? ' ');
        }

        $command    = $vars['command'];
        $verb       = $command['verb'];

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

        $msgFront = substr(\str_replace(array("\r", "\n"), '<br>', $msgText), 0, 60);

        if ($debug) {
            $msgDetail = "$session  chatID:$chatId/$msgId  $updateType/$updateId  $chatType:[$chatTitle]  msg:[$msgFront]";
            yield $eh->echo(PHP_EOL . $msgDetail . PHP_EOL);
        }

        if (
            $userId === 0 &&
            isset($update['message']['to_id']['_']) &&
            $update['message']['to_id']['_'] !== 'peerChannel'
        ) {
            $eh->logger("Missing from_id.  $updateType update_id: $updateId");
            $eh->logger(toJSON($update) . PHP_EOL);
        }

        if (
            $msgText === null &&
            $update['message']['_'] !== 'messageService'
        ) {
            $eh->logger("Missing message text. update_id: $updateId");
            $eh->logger(toJSON($update) . PHP_EOL);
        }

        if (
            $msgId === 0 &&
            $update['message']['_'] !== 'messageService'
        ) {
            $eh->logger("Missing message_id. update_id: $updateId");
            $eh->logger(toJSON($update) . PHP_EOL);
        }

        // Recognize and log old or new commands.
        if (
            $verb && $msgType === 'updateNewMessage' &&
            ($fromRobot && ($toRobot || $toOffice) || $fromAdmin && $toOffice)
        ) {
            $type   = $msgIsNew  ? 'New' : 'Old';
            $from   = $fromRobot ? 'robot' : $fromId;
            $to     = $toRobot   ? 'robot' : 'office';
            $exec   = $processCommands ? 'true' : 'false';
            $age    = \formatDuration(\abs($startTime - $msgDate) * 1000000000);
            $age    = $startTime > $msgDate ? $age : (-1 * $age);
            $start  = date('H:i:s', $startTime);
            $now    = date('H:i:s');
            $issued = date('H:i:s', $msgDate);
            $text   = "$type Command:{verb:'$msgText', from:$from, to:$to, exec:$exec, age:$age, issued:$issued, start:$start, now:$now}";
            yield $this->logger($text, Logger::ERROR);
        }

        // Log some information for debugging
        if (($fromRobot || $toRobot || $fromAdmin || $toOffice) &&  true) {
            $from = $fromRobot ? 'robot' : ($fromAdmin ? strval($fromId) : ('?' . strval($fromId) . '?'));
            $to   =   $toRobot ? 'robot' : ($toOffice ? 'office' : '?' . 'peer' . '?');
            $criteria = [
                'from'    => $from,
                'to'      => $to,
                'process' => ($processCommands ? 'true' : 'false'),
                ($verb ? 'action' : 'reaction') => mb_substr($msgText ?? '_NULL_', 0, 40)
            ];
            $this->logger(toJSON($criteria, false), Logger::ERROR);
        }
        if ($fromAdmin || $toOffice) {
            $text = "fromAdmin: $fromId, toOffice:" . ($toOffice ? 'true' : 'false');
            //yield $this->logger("fromId: $fromId, toOffice:" . ($toOffice ? 'true' : 'false'), Logger::ERROR);
            yield $this->logger(toJSON($update, false), Logger::ERROR);
        }

        $params['verb']         = $verb ? $msgText : '_NONE"';
        $params['from_robot']   = $fromRobot ? 'true' : 'false';
        $params['from_admin']   = $fromAdmin ? 'true' : 'false';
        $params['to_robot']     = $toRobot ?   'true' : 'false';
        $params['to_office']    = $toOffice ?  'true' : 'false';
        $params['msg_type']     = $msgType;
        $params['msg_date']     = date('d H:i:s', $msgDate);
        $params['start_time']   = date('d H:i:s', $startTime);
        $params['current_time'] = date('d H:i:s');
        $params['execute']      = $processCommands() ? 'true' : 'false';
        //yield $this->logger('params: ' . toJSON($params), Logger::ERROR);
    }
}
