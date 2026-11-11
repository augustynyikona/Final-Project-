<?php
/**
 * Canonical base URL for links sent outside the browser session
 * (staff invite emails, guardian SMS/WhatsApp links).
 *
 * Order of preference:
 *   1. APP_URL from .env        - explicit override (set this on Hostinger)
 *   2. The request host         - always reachable for whoever generated the link
 *   3. LAN address via nip.io   - only if that address actually accepts a connection
 *
 * Loopback is never converted to nip.io: gethostbyname() commonly returns the
 * Debian hostname alias 127.0.1.1, which resolves but has nothing listening.
 */
require_once __DIR__ . '/env.php';

if (!function_exists('app_is_loopback')) {
    function app_is_loopback(string $ip): bool
    {
        return $ip === '::1' || $ip === '0.0.0.0' || str_starts_with($ip, '127.');
    }
}

if (!function_exists('app_scheme')) {
    function app_scheme(): string
    {
        $fwd = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($fwd !== '') {
            return strtolower(trim(explode(',', $fwd)[0])) === 'https' ? 'https' : 'http';
        }
        $https = (string)($_SERVER['HTTPS'] ?? '');
        return ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }
}

if (!function_exists('app_candidate_ips')) {
    function app_candidate_ips(): array
    {
        $ips = [];
        if (!empty($_SERVER['SERVER_ADDR'])) {
            $ips[] = (string)$_SERVER['SERVER_ADDR'];
        }
        $name = gethostname();
        if ($name) {
            foreach ((array)@gethostbynamel($name) as $ip) {
                $ips[] = (string)$ip;
            }
        }
        if (function_exists('stream_socket_client')) {
            $probe = @stream_socket_client('udp://8.8.8.8:53', $en, $es, 0.3);
            if ($probe) {
                $peer = @stream_socket_get_name($probe, false);
                @fclose($probe);
                if (is_string($peer) && $peer !== '') {
                    $ips[] = str_contains($peer, ':') ? explode(':', $peer)[0] : $peer;
                }
            }
        }

        $out = [];
        foreach ($ips as $ip) {
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            if (app_is_loopback($ip)) {
                continue;
            }
            if (str_starts_with($ip, '172.1') || str_starts_with($ip, '172.2')) {
                continue; // docker/bridge ranges are not routable from other devices
            }
            if (!in_array($ip, $out, true)) {
                $out[] = $ip;
            }
        }
        return $out;
    }
}

if (!function_exists('app_host_accepts')) {
    function app_host_accepts(string $ip, int $port): bool
    {
        $sock = @fsockopen($ip, $port, $en, $es, 0.4);
        if (!$sock) {
            return false;
        }
        @fclose($sock);
        return true;
    }
}

if (!function_exists('app_base_url')) {
    function app_base_url(): string
    {
        $explicit = trim((string)vax_env('APP_URL', ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $scheme = app_scheme();
        $host   = (string)($_SERVER['HTTP_HOST'] ?? '');

        $hostOnly = $host;
        $port     = 0;
        if (preg_match('/^(\[[0-9a-fA-F:]+\]|[^:]+)(?::(\d+))?$/', $host, $m)) {
            $hostOnly = $m[1];
            $port     = isset($m[2]) ? (int)$m[2] : 0;
        }
        if ($port === 0) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        $isLoopbackHost = ($hostOnly === '')
            || strcasecmp($hostOnly, 'localhost') === 0
            || ((bool)filter_var($hostOnly, FILTER_VALIDATE_IP) && app_is_loopback($hostOnly));

        if (!$isLoopbackHost) {
            return "{$scheme}://{$host}";
        }

        foreach (app_candidate_ips() as $ip) {
            if (app_host_accepts($ip, $port)) {
                return "{$scheme}://{$ip}.nip.io:{$port}";
            }
        }

        return "{$scheme}://" . ($host !== '' ? $host : 'localhost');
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return app_base_url() . ($path === '' ? '' : '/' . $path);
    }
}

if (!function_exists('app_login_url')) {
    function app_login_url(string $query = ''): string
    {
        $url = app_url('auth/login.php');
        if ($query !== '') {
            $url .= '?' . ltrim($query, '?&');
        }
        return $url;
    }
}
