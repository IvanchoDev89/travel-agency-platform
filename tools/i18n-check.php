<?php
/**
 * i18n consistency check — no wp-cli required.
 *
 * Fails (exit 1) when a translated string used in the plugin's PHP is absent
 * from any of the shipped catalogs. Catches "added a new __() but forgot to
 * regenerate the .po files", which is otherwise only noticed at runtime.
 *
 *   php tools/i18n-check.php
 *   php tools/i18n-check.php --quiet   # only report failures
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root     = dirname(__DIR__);
$plugin   = $root . '/app/public/wp-content/plugins/travel-agency-platform';
$languages = $plugin . '/languages';
$domain   = 'travel-agency-platform';

$catalogs = [
    'es_ES' => $languages . '/travel-agency-platform-es_ES.po',
    'en_US' => $languages . '/travel-agency-platform-en_US.po',
];

$quiet = in_array('--quiet', array_slice($argv, 1), true);

foreach ($catalogs as $locale => $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "[i18n-check] missing catalog: $file\n");
        exit(1);
    }
}

/** Append a quoted PO string body to $into. */
function tap_po_unquote($raw) {
    $raw = trim($raw);
    if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
        $raw = substr($raw, 1, -1);
    }
    return stripcslashes($raw);
}

/** All msgids (and msgid_plural forms) declared in a .po file. */
function tap_po_msgids($file) {
    $ids   = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = trim($lines[$i]);
        // "msgid_plural" starts with "msgid_" and must not be treated as an id.
        if (strpos($line, 'msgid ') === 0) {
            $msg = tap_po_unquote(substr($line, 6));
            $i++;
            while ($i < $n && substr(trim($lines[$i]), 0, 1) === '"') {
                $msg .= tap_po_unquote($lines[$i]);
                $i++;
            }
            if ($msg !== '') {
                $ids[$msg] = true;
            }
            continue;
        }
        if (strpos($line, 'msgid_plural ') === 0) {
            $msg = tap_po_unquote(substr($line, 13));
            $i++;
            while ($i < $n && substr(trim($lines[$i]), 0, 1) === '"') {
                $msg .= tap_po_unquote($lines[$i]);
                $i++;
            }
            if ($msg !== '') {
                $ids[$msg] = true;
            }
            continue;
        }
        $i++;
    }
    return $ids;
}

/** Extract the argument list of the call whose '(' is at $open. */
function tap_args($src, $open) {
    $depth = 1; // we start just inside the call's opening parenthesis
    $args  = [];
    $start = $open + 1;
    $len   = strlen($src);
    $quote = null;
    for ($i = $open + 1; $i < $len; $i++) {
        $ch = $src[$i];
        if ($quote !== null) {
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                $args[] = trim(substr($src, $start, $i - $start));
                return [$args, $i];
            }
        } elseif ($ch === ',' && $depth === 1) {
            $args[] = trim(substr($src, $start, $i - $start));
            $start  = $i + 1;
        }
    }
    return [[], $len];
}

/** Literal value of a single/double quoted PHP string, or null. */
function tap_literal($expr) {
    $expr = trim($expr);
    if (strlen($expr) < 2) {
        return null;
    }
    $q = $expr[0];
    if (($q !== "'" && $q !== '"') || substr($expr, -1) !== $q) {
        return null;
    }
    $body = substr($expr, 1, -1);
    if (strpos($body, '\\') !== false) {
        $body = stripcslashes($body);
    }
    return $body;
}

/** Collect every literal msgid used with the plugin domain inside a PHP file. */
function tap_php_msgids($file, $domain) {
    $src = file_get_contents($file);
    $ids = [];
    $funcs = '(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e)';
    if (!preg_match_all('/\b' . $funcs . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $ids;
    }
    foreach ($m[0] as $hit) {
        $open  = $hit[1] + strlen($hit[0]) - 1;
        list($args, ) = tap_args($src, $open);
        if (count($args) < 2) {
            continue;
        }
        $msg = tap_literal($args[0]);
        if ($msg === null || $msg === '') {
            continue;
        }
        $last = tap_literal($args[count($args) - 1]);
        if ($last !== $domain) {
            continue;
        }
        $ids[$msg] = true;
    }
    return $ids;
}

function tap_php_files($dir) {
    $out = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'vendor' || $entry === 'node_modules') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, tap_php_files($path));
        } elseif (substr($entry, -4) === '.php') {
            $out[] = $path;
        }
    }
    return $out;
}

$used = [];
foreach (tap_php_files($plugin) as $file) {
    foreach (tap_php_msgids($file, $domain) as $msg => $_) {
        $used[$msg] = true;
    }
}

$problems = 0;
foreach ($catalogs as $locale => $file) {
    $have  = tap_po_msgids($file);
    $missing = array_diff_key($used, $have);
    $count = count($used);
    $ok    = $count - count($missing);
    if (!$quiet) {
        echo "[i18n-check] $locale: $ok/$count msgids present\n";
    }
    if ($missing) {
        $problems += count($missing);
        foreach (array_keys($missing) as $msg) {
            $shown = strlen($msg) > 90 ? substr($msg, 0, 87) . '...' : $msg;
            fwrite(STDERR, "[i18n-check] $locale missing: $shown\n");
        }
    }
}

if ($problems) {
    fwrite(STDERR, "[i18n-check] FAILED: $problems missing catalog entries. Run 'make i18n'.\n");
    exit(1);
}
echo "[i18n-check] OK\n";
