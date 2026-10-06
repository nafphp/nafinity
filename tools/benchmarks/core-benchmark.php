<?php

// Measurement harness; generated packages are explicitly synthetic.
declare(strict_types=1);
$autoload = $argv[1] ?? throw new RuntimeException('Pass the Composer autoloader');
$scratch  = $argv[2] ?? sys_get_temp_dir() . '/naf-core-bench';
$size     = isset($argv[4]) ? (int) $argv[4] : null;
define('BASE_PATH', $scratch . '/host');
putenv('APP_ENV=test');
require $autoload;

use Composer\InstalledVersions;
use Naf\Core\App;
use Naf\Core\Container;
use Naf\Core\EventManager;
use Naf\Support\CoreFileLoader;
use Naf\Support\PluginBootOrder;

function installCatalog(int $count, string $scratch): array
{
    static $baseVersions;
    $baseVersions ??= array_filter(InstalledVersions::getRawData()['versions'], static fn($package) => ($package['type'] ?? '') !== 'naf-plugin');
    $paths    = [];
    $versions = $baseVersions;
    for ($i = 0;$i < $count;$i++) {
        $name            = 'measurement/plugin-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $path            = $scratch . '/plugins/' . $i;
        $paths[$name]    = $path;
        $versions[$name] = ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'type' => 'naf-plugin', 'install_path' => $path];
    }
    InstalledVersions::reload(['root' => ['name' => 'measurement/host', 'install_path' => BASE_PATH, 'type' => 'project'], 'versions' => $versions]);

    return $paths;
}

function measure(callable $action, int $runs = 51, bool $reportedDuration = false): array
{
    $values = [];
    for ($i = 0;$i < $runs + 1;$i++) {
        $start   = hrtime(true);
        $value   = $action();
        $elapsed = $reportedDuration ? $value : (hrtime(true) - $start) / 1e6;
        if ($i) {
            $values[] = $elapsed;
        }
    }
    sort($values);

    return ['p50_ms' => round($values[intdiv(count($values), 2)], 4), 'p95_ms' => round($values[(int) ceil(count($values) * .95) - 1], 4), 'runs' => $runs];
}

if (($argv[3] ?? '') === '--child') {
    installCatalog($size, $scratch);
    $start   = hrtime(true);
    $app     = new App(new Container());
    $elapsed = (hrtime(true) - $start) / 1e6;
    if (count($app->getPlugins()) !== $size || ($GLOBALS['measurement_boots'] ?? 0) !== $size) {
        throw new RuntimeException('Incomplete bootstrap');
    }
    echo json_encode(['ms' => $elapsed, 'peak_memory_bytes' => memory_get_peak_usage(true)]);
    exit;
}
if (is_dir($scratch) && count(scandir($scratch)) > 2 && !is_file($scratch . '/.naf-benchmark')) {
    throw new RuntimeException('Use an empty scratch directory or an existing benchmark directory');
}
@mkdir($scratch, 0777, true);
file_put_contents($scratch . '/.naf-benchmark', 'Synthetic benchmark fixtures only');
@mkdir(BASE_PATH . '/src', 0777, true);
file_put_contents(BASE_PATH . '/src/config.php', '<?php return [];');
for ($i = 0;$i < 250;$i++) {
    $path = $scratch . '/plugins/' . $i;
    @mkdir($path . '/src/views', 0777, true);
    $before = $i ? ['measurement/plugin-' . str_pad((string) ($i - 1), 3, '0', STR_PAD_LEFT)] : [];
    file_put_contents($path . '/composer.json', json_encode(['name' => 'measurement/plugin-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'type' => 'naf-plugin', 'extra' => ['naf' => ['boot' => ['after' => $before]]]]));
    foreach (['config', 'routes', 'functions', 'view_helpers'] as $file) {
        file_put_contents($path . '/src/' . $file . '.php', '<?php return [];');
    }
    file_put_contents($path . '/bootstrap.php', '<?php $GLOBALS["measurement_boots"] = ($GLOBALS["measurement_boots"] ?? 0) + 1;');
}
$result = ['recorded_at' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'opcache_cli' => ini_get('opcache.enable_cli'), 'kind' => 'synthetic plugin chain; CLI boot, not HTTP', 'plugins' => [], 'events' => []];
foreach ([0, 10, 25, 50, 100, 250] as $count) {
    $paths                     = installCatalog($count, $scratch);
    $manifests                 = array_map(fn($p) => json_decode(file_get_contents($p . '/composer.json'), true), $paths);
    $row                       = ['count' => $count];
    $row['composer_discovery'] = measure(fn() => InstalledVersions::getInstalledPackagesByType('naf-plugin'));
    $row['manifest_read']      = measure(fn() => array_map(fn($p) => json_decode(file_get_contents($p . '/composer.json'), true), $paths));
    $row['pure_order']         = measure(fn() => PluginBootOrder::resolve($manifests));
    $row['manifest_and_order'] = measure(fn() => PluginBootOrder::fromPaths($paths));
    $row['resources']          = measure(function () use ($paths) {
        foreach ($paths as $name => $path) {
            CoreFileLoader::createPlugin($name, $path);
        }
    });
    $boot   = [];
    $memory = [];
    for ($i = 0;$i < 21;$i++) {
        $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($autoload) . ' ' . escapeshellarg($scratch) . ' --child ' . $count;
        $sample  = json_decode(shell_exec($command), true, flags: JSON_THROW_ON_ERROR);
        if ($i) {
            $boot[]   = $sample['ms'];
            $memory[] = $sample['peak_memory_bytes'];
        }
    }
    sort($boot);
    $row['fresh_boot']   = ['p50_ms' => round(($boot[9] + $boot[10]) / 2, 4), 'p95_ms' => round($boot[18], 4), 'runs' => 20, 'peak_memory_bytes' => max($memory)];
    $result['plugins'][] = $row;
}
foreach ([0, 10, 100, 500] as $count) {
    $register        = fn() => new EventManager();
    $row             = ['count' => $count];
    $row['register'] = measure(function () use ($count) {
        $events = new EventManager();
        for ($i = 0;$i < $count;$i++) {
            $events->listen('measurement', static fn() => null, $i % 7);
        }
    });
    $row['first_dispatch'] = measure(function () use ($count) {
        $events = new EventManager();
        for ($i = 0;$i < $count;$i++) {
            $events->listen('measurement', static fn() => null, $i % 7);
        }$start = hrtime(true);
        $events->dispatch('measurement');

        return (hrtime(true) - $start) / 1e6;
    }, 51, true);
    $events = new EventManager();
    for ($i = 0;$i < $count;$i++) {
        $events->listen('measurement', static fn() => null, $i % 7);
    }
    $events->dispatch('measurement');
    $row['warm_dispatch']                           = measure(fn() => $events->dispatch('measurement'));
    $row['first_dispatch']['includes_registration'] = false;
    $result['events'][]                             = $row;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
