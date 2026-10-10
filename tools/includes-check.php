<?php
/**
 * Static guard: every class file in includes/ must be loaded.
 *
 * The plugin loads its includes through an explicit require_once list, so a
 * new class file that is not added there fails at runtime with a fatal error
 * instead of being caught by any linter. This check closes that gap.
 *
 *   php tools/includes-check.php
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root   = dirname(__DIR__);
$plugin = $root . '/app/public/wp-content/plugins/travel-agency-platform';
$dir    = $plugin . '/includes';

if (!is_dir($dir)) {
    fwrite(STDERR, "[includes-check] no se encuentra $dir\n");
    exit(1);
}

$sources = [];
foreach (scandir($plugin) as $entry) {
    if (substr($entry, -4) === '.php') {
        $sources[$entry] = file_get_contents($plugin . '/' . $entry);
    }
}
foreach (glob($dir . '/*.php') as $file) {
    $sources['includes/' . basename($file)] = file_get_contents($file);
}
$all = implode("\n", $sources);

$missing = [];
$total   = 0;
foreach (glob($dir . '/*.php') as $file) {
    $name = basename($file);
    $total++;
    // only class files must be wired into the loader
    $code = file_get_contents($file);
    if (!preg_match('/^\s*class\s+[A-Za-z_]/m', $code)) {
        continue;
    }
    if (strpos($all, "'includes/$name'") === false && strpos($all, "\"includes/$name\"") === false) {
        $missing[] = $name;
    }
}

echo "[includes-check] $total ficheros en includes/\n";
if ($missing) {
    foreach ($missing as $m) {
        fwrite(STDERR, "[includes-check] NO REQUERIDO: includes/$m\n");
    }
    fwrite(STDERR, "[includes-check] FAILED: " . count($missing) . " fichero(s) sin require_once.\n");
    exit(1);
}
echo "[includes-check] OK\n";
