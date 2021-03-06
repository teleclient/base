<?php

declare(strict_types=1);

namespace teleclient\base\plugins;

use teleclient\base\EventHandler;
use teleclient\base\plugins\Plugin;

class EmptyPlugin implements Plugin
{
    public function onStart(string $session, EventHandler $eh): \Generator
    {
        return;
        yield;
    }

    public function __invoke(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator
    {
        $session = '';

        return false;
        yield;
    }
}
