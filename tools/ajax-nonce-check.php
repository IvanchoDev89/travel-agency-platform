<?php
/**
 * Static guard: every admin-ajax handler must verify a nonce.
 *
 * Scans the plugin for wp_ajax_* registrations and checks that the target
 * method body actually calls check_ajax_referer() / wp_verify_nonce(). Catches
 * the class of bug where a state-changing endpoint is exposed without CSRF
 * protection — without needing a database or a running WordPress.
 *
 *   php tools/ajax-nonce-check.php
 *
 * Intentional exceptions go into the $ALLOW list below, with a reason.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root   = dirname(__DIR__);
$plugin = $root . '/app/public/wp-content/plugins/travel-agency-platform';

/**
 * Handlers that legitimately skip nonce verification, with the reason.
 * Only list read-only endpoints here — never a handler that writes.
 */
$ALLOW = [
    // Public read-only search/quote endpoints. They are covered by a nonce
    // since v1.5.12; keep them listed only if that is ever reverted.
];

function tap_ajax_files($dir) {
    $out = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'vendor' || $entry === 'node_modules') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, tap_ajax_files($path));
        } elseif (substr($entry, -4) === '.php') {
            $out[] = $path;
        }
    }
    return $out;
}

/** Class name => file that declares it. */
function tap_class_map($files) {
    $map = [];
    foreach ($files as $file) {
        if (preg_match_all('/^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', file_get_contents($file), $m)) {
            foreach ($m[1] as $class) {
                $map[$class] = $file;
            }
        }
    }
    return $map;
}

/** Body of $method inside $src (from its opening brace to the matching one). */
function tap_method_body($src, $method) {
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $open = strpos($src, '{', $m[0][1] + strlen($m[0][0]));
    if ($open === false) {
        return null;
    }
    $depth = 0;
    $len   = strlen($src);
    $quote = null;
    for ($i = $open; $i < $len; $i++) {
        $ch = $src[$i];

        if ($quote !== null) {
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === $quote) {
                $quote = null;
            }
            continue;
        }

        // Comments must be skipped before quotes: an apostrophe inside one
        // (for example "PayPal's" in a docblock) would open a phantom string
        // and unbalance the brace count, making an existing body look missing.
        if ($ch === '/' && $i + 1 < $len && $src[$i + 1] === '/') {
            $nl = strpos($src, "\n", $i);
            $i  = ($nl === false) ? $len : $nl;
            continue;
        }
        if ($ch === '#' && !($i + 1 < $len && $src[$i + 1] === '[')) {
            $nl = strpos($src, "\n", $i);
            $i  = ($nl === false) ? $len : $nl;
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $src[$i + 1] === '*') {
            $end = strpos($src, '*/', $i + 2);
            $i   = ($end === false) ? $len : $end + 1;
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            continue;
        }
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return null;
}

$files      = tap_ajax_files($plugin);
$classMap   = tap_class_map($files);
$handlers   = [];
$unresolved = [];

foreach ($files as $file) {
    $src = file_get_contents($file);
    if (!preg_match_all(
        "/add_action\\(\\s*'(wp_ajax(?:_nopriv)?)_([a-z0-9_]+)'\\s*,\\s*\\[\\s*'([A-Za-z_][A-Za-z0-9_]*)'\\s*,\\s*'([A-Za-z_][A-Za-z0-9_]*)'/",
        $src,
        $m,
        PREG_SET_ORDER
    )) {
        continue;
    }
    foreach ($m as $hit) {
        $action  = $hit[2];
        $class   = $hit[3];
        $method  = $hit[4];
        if (strpos($hit[1], 'nopriv') !== false) {
            continue; // registered twice for the same handler; report once
        }
        $key = $action;
        if (isset($handlers[$key])) {
            continue;
        }
        if (!isset($classMap[$class])) {
            $unresolved[] = $action . ' (' . $class . '::' . $method . ')';
            continue;
        }
        $body = tap_method_body(file_get_contents($classMap[$class]), $method);
        if ($body === null) {
            $unresolved[] = $action . ' (' . $class . '::' . $method . ' sin cuerpo)';
            continue;
        }
        $hasNonce = (bool) preg_match('/\b(check_ajax_referer|wp_verify_nonce|check_admin_referer)\s*\(/', $body);
        // A handler may delegate nonce checking to a private helper
        // (e.g. self::check_public_nonce()). Follow that helper: it must exist
        // in the same class and actually call a wp_verify_nonce() variant.
        $delegated = false;
        if (!$hasNonce) {
            $classSrc = file_get_contents($classMap[$class]);
            // Follow up to 3 levels of delegated nonce helpers (e.g. a guard
            // calling self::check_public_nonce() which calls public_nonce_ok()).
            $seen = [];
            for ($depth = 0; $depth < 3; $depth++) {
                if (!preg_match('/\$this->([a-z_]+nonce[a-z_]*)\s*\(|self::([a-z_]+nonce[a-z_]*)\s*\(/i', $body, $dm)) break;
                $helper = $dm[1] ?: $dm[2];
                if (isset($seen[$helper])) break;
                $seen[$helper] = true;
                $helperBody = tap_method_body($classSrc, $helper);
                if ($helperBody === null) break;
                if (preg_match('/\bwp_verify_nonce\s*\(|\bcheck_ajax_referer\s*\(|\bcheck_admin_referer\s*\(/', $helperBody)) {
                    $delegated = true;
                    break;
                }
                $body = $helperBody;
            }
        }
        $handlers[$action] = [
            'class' => $class,
            'method' => $method,
            'nonce' => $hasNonce || $delegated,
            'file' => $classMap[$class],
        ];
    }
}

$bad = [];
foreach ($handlers as $action => $info) {
    if ($info['nonce'] || in_array($action, $ALLOW, true)) {
        continue;
    }
    $bad[$action] = $info;
}

$total = count($handlers);
$ok    = $total - count($bad);
echo "[ajax-nonce] handlers registrados: $total, con nonce: $ok\n";

foreach ($unresolved as $u) {
    fwrite(STDERR, "[ajax-nonce] sin resolver: $u\n");
}

if ($bad) {
    foreach ($bad as $action => $info) {
        fwrite(STDERR, "[ajax-nonce] SIN NONCE: $action -> {$info['class']}::{$info['method']} (" . basename($info['file']) . ")\n");
    }
    fwrite(STDERR, "[ajax-nonce] FAILED: " . count($bad) . " handler(s) sin verificación de nonce.\n");
    exit(1);
}
echo "[ajax-nonce] OK\n";
