<?php

declare(strict_types=1);

/**
 * Routes this installation adds.
 *
 * NAF loads this file after every plugin, so naf/board has already registered
 * its own. To replace a board route, reuse its route name, method and path.
 * A different name adds a route; matching still prefers the first matching path.
 * For example: route()->add('GET', '/projects', $handler, 'projects').
 */

use Nafinity\Controllers\DemoController;

use function Naf\route;

// Remove this once you have seen it work; see the controller.
route()->add('GET', '/demo', [DemoController::class, 'index'], 'demo');
