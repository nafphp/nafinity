<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use function Naf\app;

define('BASE_PATH', __DIR__);

app()->run();
