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

    public function process(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    {
        $updateType  = $update['_'];
        $updateId    = $update['pts'];
        $userId       = $update['message']['from_id'] ?? 0;
        $msgId        = $update['message']['id']      ?? 0;
        $msg          = $update['message']['message'] ?? null;

        $chatInfo     = yield $eh->getInfo($update);
        $chatId       = $chatInfo['bot_api_id'];
        $chatType     = $chatInfo['type'];  //Can be either “private”, “group”, “supergroup” or “channel”
        $chatTitle    = $chatInfo['User']['username'] ?? ($chatInfo['Chat']['title'] ?? ' ');

        $msgFront = substr(\str_replace(array("\r", "\n"), '<br>', $msg), 0, 60);

        if (false) {
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
            $msg === null &&
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
    }
}
