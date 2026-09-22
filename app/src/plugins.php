<?php

declare(strict_types=1);

use Composer\InstalledVersions;

/**
 * Infrastructure first, installed extensions next, Board last.
 *
 * Extensions only note their providers during plugin boot. Board registers its
 * defaults and then runs those providers by index/id in its own bootstrap.
 * Include every installed plugin here so a newly required package automatically
 * boots before Board, without editing this list or needing a framework hook.
 */
$infrastructure = [
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
    'naf/rbac',
    'naf/schedule',
    'naf/session',
    'naf/storage',
    'naf/view',
    'naf/websocket',
];

$installed = InstalledVersions::getInstalledPackagesByType('naf-plugin');

return [
    ...$infrastructure,
    ...array_values(array_diff($installed, [...$infrastructure, 'naf/board'])),
    'naf/board',
];
