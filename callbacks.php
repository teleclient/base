<?php

declare(strict_types=1);


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

        try {
            //==============================================
            //$res = yield from $this->methodCallAsyncRead('messages.getDialogs', $this->dialog_params, ['datacenter' => $datacenter, 'FloodWaitLimit' => 100]);
            $res = yield $mp->messages->getDialogs($params);
            //==============================================
        } catch (RPCErrorException $e) {
            if (\strpos($e->rpc, 'FLOOD_WAIT_') === 0) {
                throw new Exception('FLOOD');
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
            $id = $mp->getId($dialog['peer']);
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
        use ($callback, $mp) {
            $index = 0;
            foreach ($dialogs as $idx => $dialog) {
                //yield $mp->logger("dialog $idx: " . toJSON($dialog, false), Logger::ERROR);
                $peer     = $dialog['peer'];
                $message  =  null;
                foreach ($messages as $msg) {
                    if ($dialog['top_message'] === $msg['id']) {
                        $message = $msg;
                        break;
                    }
                }
                if ($message === null) {
                    throw new Exception("Missing top-message $idx: " . toJSON($dialog));
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
                                } elseif (isset($chat['id'])) {
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
                yield $callback($totalDialogs, $index, $peerId, $subtype, $name, $peerval, $message);
                $index += 1;
            }
        }
    );
}
