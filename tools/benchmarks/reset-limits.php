<?php

declare(strict_types=1);

// Fresh login counters for repeated disposable measurements; rules stay enabled.
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_probe') {
    throw new RuntimeException('Only the disposable benchmark schema may be reset');
}

$connection = new PDO(
    'mysql:host=db;dbname=nafinity_probe',
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$connection->exec('DELETE FROM naf_rate_limits');
echo "Reset only disposable probe limiter counters\n";
