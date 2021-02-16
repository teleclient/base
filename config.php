<?php

declare(strict_types=1);

use \danog\MadelineProto\Logger;

return (object) [
    'host' => '',              // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone' => 'Asia/Tehran',
    'mp' => [
        0 => [
            'session'  => 'session.madeline',
            'settings' => [
                'app_info' => [
                    'api_id' => 6,                                    // <== Use your own, or let MadelineProto ask you.
                    'api_hash' => "eb06d4abfb49dc3eeb1aeb98ae0f581e", // <== Use your own, or let MadelineProto ask you.
                ],
                'logger' => [
                    'logger'       => Logger::FILE_LOGGER,
                    'logger_level' => Logger::ERROR,
                ],
                'peer' => [
                    'full_info_cache_time' => 60,
                ],
                'serialization' => [
                    'cleanup_before_serialization' => true,
                ],
                'app_info' => [
                    'app_version'    => SCRIPT_NAME . ' ' . SCRIPT_VERSION,
                    'system_version' => \hostname() . ' ' . PHP_SAPI === 'cli' ? 'CLI' : "WEB",
                ]
            ]
        ]
    ]
];
