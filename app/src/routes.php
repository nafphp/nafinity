<?php

declare(strict_types=1);

/**
 * Routes this installation adds.
 *
 * NAF loads this file after every plugin, so naf/board has already registered
 * its own. A path claimed here that the board also claims wins, which is how you
 * replace one of its pages rather than working around it.
 */

use Nafinity\Controllers\DemoController;

use function Naf\route;

// Remove this once you have seen it work; see the controller.
route()->add('GET', '/demo', [DemoController::class, 'index'], 'demo');
