<?php

declare(strict_types=1);

return [
    'admins'     => [],
    //'ownerid'  => 1234,
    //'officeid' => 1234,
    //'host'     => '', // <== In case $_SERVER['SERVER_NAME'] is not defined, set the webserver's host name.
    'zone'     => 'America/Los_Angeles',
    'prefixes' => '!/',
    'edit'     => false,
    'maxrestarts' => 10,
    'mp'   => [
        0 => [
            'filterlog'    => true,
            'notification' => 'off',
            'phone'    => '+19498364399',
            'password' => '',
            'session'  => 'madeline.madeline',
            'settings' => [
                'app_info' => [
                    'app_version'    => SCRIPT_INFO,   // <== Do not change!
                    'device_model'   => 'DEVICE_MODEL',
                    'system_version' => 'SYSTEM_VERSION',
                    //'api_id'   => 904912,
                    //'api_hash' => "8208f08eefc502bedea8b5d437be898e",
                ],
                'logger' => [
                    'logger'       => \danog\MadelineProto\Logger::FILE_LOGGER,
                    'logger_param' => __DIR__ . '/MadelineProto.log',
                    'logger_level' => \danog\MadelineProto\Logger::NOTICE,
                    'max_size'     => 100 * 1024 * 1024
                ],
                'peer' => [
                    'full_info_cache_time' => 60,
                ],
                'serialization' => [
                    'cleanup_before_serialization' => true,
                ],
            ]
        ]
    ]
];
