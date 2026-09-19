<?php

declare(strict_types=1);

/**
 * The order plugins boot in.
 *
 * Anything named here boots first, in this order; everything else follows in
 * whatever order Composer reports. That order is alphabetical, which is not an
 * order anyone chose -- naf/board would land third, between naf/auth-ldap and
 * naf/cli, purely because of its name.
 *
 * That breaks it. The board reaches for services other packages register while
 * booting -- Auth for the project policy, JobRepository for the maintenance job,
 * CommandRegistry for its commands -- so every package it builds on has to have
 * had its turn first. Booting third, it finds no scheduler and stops.
 *
 * So the framework packages come first and naf/board comes last among them.
 * Extensions are not listed at all: unnamed packages follow, which is exactly
 * where an extension belongs, because it replaces definitions the board has by
 * then already registered.
 */
return [
    'naf/auth',
    'naf/auth-ldap',
    'naf/cli',
    'naf/client',
    'naf/database',
    'naf/form',
    'naf/i18n',
    'naf/mail',
    'naf/mcp',
    'naf/oauth-client',
    'naf/orm',
    'naf/queue',
    'naf/rate-limit',
    'naf/schedule',
    'naf/session',
    'naf/storage',
    'naf/view',
    'naf/board',
];
