<?php
/**
 * Minimal .env loader for VaxiCare.
 *
 * Secrets live in a .env file OUTSIDE the web root and outside git:
 *   <repo>/.env                    (one level above SchoolProject2/)
 *   <repo parent>/.env             (one level above the repo)
 *   SchoolProject2/.env            (only if deployed that way)
 *
 * Precedence: a real environment variable (already exported) always wins, so
 * the same code works with Hostinger's "Environment variables" panel.
 */

/**
 * Read a single key from a parsed .env array, cached per request.
 */
function vax_env_raw(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    // Search order: most specific first.
    $candidates = [
        dirname(__DIR__) . '/.env',          // SchoolProject2/.env
        dirname(__DIR__, 2) . '/.env',       // repo root
        dirname(__DIR__, 3) . '/.env',       // above the repo
    ];

    foreach ($candidates as $path) {
        if (!is_readable($path) || !is_file($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Strip optional surrounding quotes.
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last  = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                }
            }

            if ($key !== '' && !array_key_exists($key, $cache)) {
                $cache[$key] = $val;
            }
        }
    }

    return $cache;
}

/**
 * Resolve a setting: real environment variable first, then .env, else $default.
 */
function vax_env(string $key, string $default = ''): string
{
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    $all = vax_env_raw();
    if (isset($all[$key]) && $all[$key] !== '') {
        return $all[$key];
    }
    return $default;
}

/**
 * True when a setting exists and is non-empty (used to skip disabled gateways).
 */
function vax_env_has(string $key): bool
{
    return vax_env($key) !== '';
}
