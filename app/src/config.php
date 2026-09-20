<?php

declare(strict_types=1);

use Naf\Board\Models\User;
use Naf\Mail\Core\Transport\MailTransport;
use Naf\Storage\Adapters\LocalAdapter;

/**
 * What this installation decides.
 *
 * NAF merges configuration as core defaults, then every plugin, then this file:
 * whatever stands here wins. naf/board proposes nothing, so everything the
 * application runs on is in one place and can be seen without reading a
 * dependency -- including the parts the board needs to work at all, which are
 * marked as such below.
 *
 * `ENV:NAME` is resolved by NAF from the environment; docker/ and .env supply the
 * values in development. Do not reach for getenv() here, or a value ends up
 * being read in two different ways.
 */
return [
    // What people see, and what the application believes its own address is.
    // Links in mail and OAuth redirects are built from the latter.
    'app'        => ['name' => 'Nafinity', 'url' => 'ENV:APP_URL'],
    'public_url' => 'ENV:APP_URL',

    // The board refuses to boot without these four, and says which one is missing.
    'database' => [
        'driver'   => 'ENV:DB_DRIVER',
        'host'     => 'ENV:DB_HOST',
        'port'     => 'ENV:DB_PORT',
        'database' => 'ENV:DB_DATABASE',
        'username' => 'ENV:DB_USERNAME',
        'password' => 'ENV:DB_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    // trust_proxy_headers stays false unless a proxy you control sets them; it
    // decides whether a client may tell the application its own scheme and host.
    'session'         => ['storage' => 'default', 'trust_proxy_headers' => false],
    'csrf_validation' => true,

    // Off by default: an installation that delivers mail should say so
    // deliberately, and say who it comes from.
    'nafinity' => ['mail_enabled' => false, 'mail_from' => 'ENV:NAFINITY_MAIL_FROM'],

    /*
     * Live updates. Off by default: the pages work without them, so an
     * installation opts in rather than having a second listening port appear
     * because it upgraded.
     *
     * The key signs the connect tickets. Both the web process and the server
     * read it, and it is the only thing between a forged ticket and a channel --
     * so it comes from the environment and never from a file in a repository.
     */
    'websocket' => [
        'enabled' => 'ENV:WEBSOCKET_ENABLED',
        'key'     => 'ENV:WEBSOCKET_KEY',
        'url'     => 'ENV:WEBSOCKET_URL',
    ],
    'mail' => ['transport' => MailTransport::class],

    // Only reached once a provider is configured. auto_register stays false so an
    // external account cannot create a local one by signing in.
    'oauth' => [
        'accounts'    => ['provider' => 'users', 'auto_register' => false],
        'error_route' => '/login',
        'tokens'      => ['store' => false],
    ],

    // ---------------------------------------------------------------------
    // What naf/board needs to work. Change these only with the board in mind.

    // Sign-in resolves against the board's own user model.
    'auth' => [
        'users' => [
            'model'          => User::class,
            'username_field' => 'email',
            'password_field' => 'password_hash',
        ],
        'session' => true,
    ],

    // Ticket attachments. The path is inside this installation's storage.
    'storage' => [
        'disks' => [
            'attachments' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage/attachments',
            ],
        ],
    ],

    // The worker and the ticker touch these so the health check can see they live.
    'queue'    => ['heartbeat_file' => '/tmp/nafinity-worker-heartbeat'],
    'schedule' => ['heartbeat_file' => '/tmp/nafinity-ticker-heartbeat'],

    // Where this installation's own templates go. A file here wins over the
    // board's; src/ first, matching how naf/framework orders its VIEW_PATHS.
    'view' => ['paths' => ['src/views', 'app/views']],
];
