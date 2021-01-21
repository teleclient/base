<?php

declare(strict_types=1);

/*
 * To create a new Plugin, such as XxxxPlugin:
 * 1) Copy the EmptyPlugin into a file: plugins\XxxxPlugin as an starting point.
 * 2) Add a line at the top of the EventHandler.php file: include __DIR__ . "/plugins/XxxxPlugin.php";
 * 3) Add the following next to the other plugin calls:
 *        $processed = yield $this->emptyPlugin->process($update, $session, $this, $vars);
 *        if ($processed) {
 *            return;
 *        }
 */

namespace teleclient\base\plugins;

use teleclient\base\EventHandler;

interface Plugin
{
    public function onStart(string $session, EventHandler $eh): \Generator;

    public function __invoke(array $update, string $session, EventHandler $eh = null, array $vars = null): \Generator;
}
