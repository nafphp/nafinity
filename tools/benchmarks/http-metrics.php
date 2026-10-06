<?php

// Installed only in the disposable acceptance host, never in a shipped image.
use Naf\Core\Event;

use function Naf\event;

event()->listen(Event::REQUEST_START, static function (): void {
    $GLOBALS['naf_probe_boot_ms'] = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
});
event()->listen(Event::RESPONSE_SEND, static function (): void {
    header('X-Naf-Probe-Boot-Ms: ' . round($GLOBALS['naf_probe_boot_ms'] ?? 0, 3));
    header('X-Naf-Probe-Memory: ' . memory_get_peak_usage(true));
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/projects/')) {
        $pdo = \Naf\app()->container()->get(PDO::class);
        $row = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM);
        header('X-Naf-Probe-Queries: ' . max(0, (int) $row[1] - 1));
    }
}, -1000);
