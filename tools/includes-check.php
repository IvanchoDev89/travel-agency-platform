<?php
/**
 * Static guard: every class file in includes/ must be loadable.
 *
 * The plugin has two supported loader strategies:
 *
 *   1. Explicit `require_once` list (legacy).
 *   2. Composer `classmap` autoloading (current). Composer scans includes/ and
 *      writes every class into vendor/composer/autoload_classmap.php.
 *
 * A new class file that is neither required nor present in the generated
 * classmap fails at runtime with a fatal error instead of being caught by any
 * linter. This check closes that gap for both strategies.
 *
 * The classmap assertion is by class name *and* file path, so it also catches
 * a class that was renamed inside an already-mapped file. It deliberately does
 * not compare mtimes: Composer leaves the generated file untouched when the
 * classmap content would not change, which would produce false positives.
 *
 *   php tools/includes-check.php
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root          = dirname(__DIR__);
$plugin        = $root . '/app/public/wp-content/plugins/travel-agency-platform';
$dir           = $plugin . '/includes';
$classmap_file = $root . '/vendor/composer/autoload_classmap.php';

if (!is_dir($dir)) {
    fwrite(STDERR, "[includes-check] no se encuentra $dir\n");
    exit(1);
}

// Collect the files that participate in an explicit require_once list.
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

// Load the Composer classmap when present, keyed by class and by path.
$classmap_by_class = [];
$classmap_by_path  = [];
if (is_file($classmap_file)) {
    /** @var array<string,string> $map */
    $map = include $classmap_file;
    if (is_array($map)) {
        foreach ($map as $class => $path) {
            $normalized = str_replace('\\', '/', (string) $path);
            $classmap_by_class[$class] = $normalized;
            $classmap_by_path[$normalized][] = $class;
        }
    }
}
$used_map = !empty($classmap_by_class);

$missing = []; // new file that no loader knows about
$stale   = []; // path is mapped, but the class it declares is not the mapped one
$total   = 0;

foreach (glob($dir . '/*.php') as $file) {
    $name = basename($file);
    $total++;
    $code = file_get_contents($file);
    if (!preg_match('/^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $code, $m)) {
        continue;
    }
    $class = $m[1];

    // Strategy 1: explicit require_once in any loaded source.
    if (strpos($all, "'includes/$name'") !== false
        || strpos($all, "\"includes/$name\"") !== false) {
        continue;
    }

    // Strategy 2: the class must be in the classmap and point at this file.
    $expected = '/includes/' . $name;
    $mapped_path = $classmap_by_class[$class] ?? '';
    if ($mapped_path !== '' && substr($mapped_path, -strlen($expected)) === $expected) {
        continue;
    }

    // Is the file mapped at all? Then the class inside it was renamed.
    $known_classes = [];
    foreach ($classmap_by_path as $path => $classes) {
        if (substr($path, -strlen($expected)) === $expected) {
            $known_classes = array_merge($known_classes, $classes);
        }
    }
    if ($known_classes) {
        $stale[] = $name . ' (' . $class . ' vs ' . implode(', ', $known_classes) . ')';
    } else {
        $missing[] = $name;
    }
}

echo "[includes-check] $total ficheros en includes/" . ($used_map ? ' (classmap detectado)' : '') . "\n";

if ($missing) {
    foreach ($missing as $m) {
        fwrite(STDERR, "[includes-check] NO CARGABLE: includes/$m (ni require_once ni classmap)\n");
    }
}
if ($stale) {
    foreach ($stale as $m) {
        fwrite(STDERR, "[includes-check] CLASE DESINCRONIZADA: includes/$m\n");
    }
}
if ($missing || $stale) {
    fwrite(STDERR, "[includes-check] FAILED: " . count($missing) . " sin cargador, " . count($stale)
        . " desincronizada(s). Ejecuta `composer dump-autoload -o`.\n");
    exit(1);
}

echo "[includes-check] OK\n";
