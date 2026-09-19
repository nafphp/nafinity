<?php

declare(strict_types=1);

namespace Nafinity\Controllers;

use Naf\Board\Contracts\PageRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A page of your own, to prove the wiring before you write anything real.
 *
 * Delete this class, its route in src/routes.php and src/views/demo.phtml once
 * you have seen /demo answer. Nothing else refers to them.
 *
 * Two things are worth copying from it. The constructor asks for a contract and
 * NAF supplies it -- there is nothing to register. And PageRendererInterface
 * renders inside the application shell, so the page gets the navigation, the
 * language and the signed-in person without knowing how any of that works;
 * rendering a template directly would give you a bare page instead.
 */
final class DemoController
{
    public function __construct(private readonly PageRendererInterface $pages)
    {
    }

    public function index(): ResponseInterface
    {
        return $this->pages->render('demo', [
            'heading' => 'Your own page',
            'file'    => 'app/src/Controllers/DemoController.php',
        ]);
    }
}
