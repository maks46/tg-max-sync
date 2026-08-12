#!/usr/bin/env php
<?php

/**
 * xray/update-config.php
 *
 * Downloads a proxy subscription URL (v2rayN / happ / sing-box format),
 * picks the best server (first in list by default), and writes xray/config.json.
 *
 * Supported URI schemes: vless://, vmess://, trojan://, ss://
 *
 * Usage:
 *   php /app/xray/update-config.php
 *
 * Required env var:
 *   XRAY_SUBSCRIPTION_URL — HTTP(S) URL that returns a base64-encoded list of proxy URIs
 *
 * Optional env vars:
 *   XRAY_SERVER_INDEX  — 0-based index of server to use from the list (default: 0)
 *   XRAY_SOCKS_PORT    — local SOCKS5 port xray will listen on (default: 10808)
 */

declare(strict_types=1);

// ── Load .env ──────────────────────────────────────────────────────────────────
$envFile = '/app/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
            (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        if (!isset($_ENV[$k])) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}

function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ── Config ─────────────────────────────────────────────────────────────────────
$subscriptionUrl = env('XRAY_SUBSCRIPTION_URL');
$serverIndex     = (int) env('XRAY_SERVER_INDEX', '0');
$socksPort       = (int) env('XRAY_SOCKS_PORT', '10808');
$userAgent       = env('XRAY_USER_AGENT', 'clash-meta/1.18.0');
// Written to data/ because xray/ is mounted read-only
$outputFile      = '/app/data/xray-config.json';

if ($subscriptionUrl === '') {
    fwrite(STDERR, "XRAY_SUBSCRIPTION_URL is not set. Skipping config generation.\n");
    exit(0);
}

// ── Download subscription ──────────────────────────────────────────────────────
echo "Fetching subscription from: $subscriptionUrl\n";

echo "User-Agent: $userAgent\n";
$ctx  = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => $userAgent]]);
$body = @file_get_contents($subscriptionUrl, false, $ctx);
if ($body === false) {
    fwrite(STDERR, "Failed to download subscription URL.\n");
    exit(1);
}

// ── Detect and parse subscription format ──────────────────────────────────────
// Subscription content may be:
//   A) base64-encoded list of proxy URIs (v2rayN / happ format)
//   B) Clash YAML (contains "proxies:" key)
//   C) Plain list of proxy URIs

$raw = trim($body);
$decoded = base64_decode($raw, true);
if ($decoded === false || !preg_match('/^(vless|vmess|trojan|ss|ssr):\/\//m', $decoded)) {
    // Not a valid base64 URI list — try as-is
    $decoded = $raw;
}

$outbound = null;

// Check if this is Clash YAML format
if (str_contains($decoded, 'proxies:') || str_starts_with($decoded, 'mixed-port:') || str_starts_with($decoded, 'port:')) {
    echo "Detected Clash YAML subscription format.\n";
    $outbound = parseClashYaml($decoded, $serverIndex);
} else {
    // URI list format
    $allUris = array_values(array_filter(
        array_map('trim', explode("\n", $decoded)),
        fn($l) => $l !== '' && !str_starts_with($l, '#')
    ));

    if (empty($allUris)) {
        fwrite(STDERR, "Subscription returned no servers.\n");
        exit(1);
    }

    // Filter out placeholder/stub entries: 0.0.0.0 address or port <= 1
    $uris = array_values(array_filter($allUris, function (string $u): bool {
        $noScheme = preg_replace('#^[a-z]+://#', '', $u);
        $noScheme = explode('#', $noScheme, 2)[0];
        if (str_starts_with($u, 'vmess://')) {
            $json = base64_decode(substr($u, 8), true);
            if ($json) {
                $v = json_decode($json, true);
                $addr = $v['add'] ?? '';
                $port = (int)($v['port'] ?? 0);
                if ($addr === '0.0.0.0' || $addr === '' || $port <= 1) return false;
            }
            return true;
        }
        if (preg_match('/@(\[?[^\]@]+\]?):(\d+)/u', $noScheme, $m)) {
            $addr = trim($m[1], '[]');
            $port = (int)$m[2];
            if ($addr === '0.0.0.0' || $addr === '' || $port <= 1) return false;
        }
        return true;
    }));

    echo "Found " . count($allUris) . " server(s) total, " . count($uris) . " valid. Using index $serverIndex.\n";

    if (empty($uris)) {
        fwrite(STDERR, "No valid servers in subscription (all filtered as stubs).\n");
        exit(1);
    }

    if (!isset($uris[$serverIndex])) {
        fwrite(STDERR, "Server index $serverIndex out of range (0–" . (count($uris) - 1) . "). Using index 0.\n");
        $serverIndex = 0;
    }

    $uri = $uris[$serverIndex];
    echo "Selected: $uri\n";

    $outbound = match (true) {
        str_starts_with($uri, 'vless://')  => parseVless($uri),
        str_starts_with($uri, 'vmess://')  => parseVmess($uri),
        str_starts_with($uri, 'trojan://') => parseTrojan($uri),
        str_starts_with($uri, 'ss://')     => parseSS($uri),
        default => null,
    };
}

if ($outbound === null) {
    fwrite(STDERR, "Failed to parse any server from subscription.\n");
    exit(1);
}

// ── Build full xray config ─────────────────────────────────────────────────────
$config = [
    'log' => ['loglevel' => 'warning'],
    'inbounds' => [[
        'tag'      => 'socks-in',
        'port'     => $socksPort,
        'listen'   => '127.0.0.1',
        'protocol' => 'socks',
        'settings' => ['auth' => 'noauth', 'udp' => true],
    ]],
    'outbounds' => [
        $outbound,
        ['tag' => 'direct',  'protocol' => 'freedom'],
        ['tag' => 'block',   'protocol' => 'blackhole'],
    ],
    'routing' => [
        'domainStrategy' => 'AsIs',
        'rules' => [
            // Route RFC-1918 private IPs directly (no proxy)
            [
                'type'        => 'field',
                'ip'          => ['geoip:private'],
                'outboundTag' => 'direct',
            ],
        ],
    ],
];

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (file_put_contents($outputFile, $json) === false) {
    fwrite(STDERR, "Failed to write $outputFile\n");
    exit(1);
}

echo "Config written to $outputFile\n";
exit(0);

// ── Parsers ────────────────────────────────────────────────────────────────────

function parseVless(string $uri): array
{
    // vless://uuid@host:port?params#name
    $rest = substr($uri, 8); // strip "vless://"
    [$userinfo, $hostAndRest] = explode('@', $rest, 2);
    $uuid = $userinfo;

    // split host:port?params#name
    if (preg_match('/^(\[.+?\]|[^:]+):(\d+)(.*)$/', $hostAndRest, $m)) {
        $host = trim($m[1], '[]');
        $port = (int)$m[2];
        $tail = $m[3];
    } else {
        throw new \InvalidArgumentException("Cannot parse VLESS host/port from: $hostAndRest");
    }

    $params = [];
    if (str_contains($tail, '?')) {
        [, $query] = explode('?', $tail, 2);
        $query = explode('#', $query, 2)[0];
        parse_str($query, $params);
    }

    $security = $params['security'] ?? 'none';
    $network  = $params['type']     ?? 'tcp';
    $flow     = $params['flow']     ?? '';

    $user = ['id' => $uuid, 'encryption' => 'none'];
    if ($flow !== '') {
        $user['flow'] = $flow;
    }

    $outbound = [
        'tag'      => 'proxy',
        'protocol' => 'vless',
        'settings' => ['vnext' => [[
            'address' => $host,
            'port'    => $port,
            'users'   => [$user],
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];

    return $outbound;
}

function parseVmess(string $uri): array
{
    // vmess://base64(json)
    $json = base64_decode(substr($uri, 8), true);
    if ($json === false) {
        throw new \InvalidArgumentException("Cannot base64-decode VMess URI");
    }
    $v = json_decode($json, true);
    if (!$v) {
        throw new \InvalidArgumentException("Cannot JSON-decode VMess config");
    }

    $network  = $v['net']  ?? 'tcp';
    $security = $v['tls']  ?? 'none';
    $host     = $v['add']  ?? '';
    $port     = (int)($v['port'] ?? 443);
    $uuid     = $v['id']   ?? '';
    $aid      = (int)($v['aid']  ?? 0);

    $params = [
        'host'        => $v['host'] ?? $host,
        'path'        => $v['path'] ?? '',
        'sni'         => $v['sni']  ?? ($v['host'] ?? ''),
        'fp'          => $v['fp']   ?? '',
        'serviceName' => $v['path'] ?? '',
    ];

    return [
        'tag'      => 'proxy',
        'protocol' => 'vmess',
        'settings' => ['vnext' => [[
            'address' => $host,
            'port'    => $port,
            'users'   => [['id' => $uuid, 'alterId' => $aid, 'security' => 'auto']],
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];
}

function parseTrojan(string $uri): array
{
    // trojan://password@host:port?params#name
    $rest = substr($uri, 9);
    [$password, $hostAndRest] = explode('@', $rest, 2);
    $password = urldecode($password);

    if (preg_match('/^(\[.+?\]|[^:]+):(\d+)(.*)$/', $hostAndRest, $m)) {
        $host = trim($m[1], '[]');
        $port = (int)$m[2];
        $tail = $m[3];
    } else {
        throw new \InvalidArgumentException("Cannot parse Trojan host/port");
    }

    $params = [];
    if (str_contains($tail, '?')) {
        [, $query] = explode('?', $tail, 2);
        $query = explode('#', $query, 2)[0];
        parse_str($query, $params);
    }

    $security = $params['security'] ?? 'tls';
    $network  = $params['type']     ?? 'tcp';

    return [
        'tag'      => 'proxy',
        'protocol' => 'trojan',
        'settings' => ['servers' => [[
            'address'  => $host,
            'port'     => $port,
            'password' => $password,
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];
}

function parseSS(string $uri): array
{
    // ss://base64(method:password)@host:port#name  OR
    // ss://base64(method:password@host:port)#name
    $uri = explode('#', $uri, 2)[0]; // strip name
    $rest = substr($uri, 5);         // strip "ss://"

    if (str_contains($rest, '@')) {
        [$b64, $hostPort] = explode('@', $rest, 2);
        $decoded = base64_decode($b64, true) ?: urldecode($b64);
        [$method, $password] = explode(':', $decoded, 2);
        preg_match('/^(\[.+?\]|[^:]+):(\d+)$/', $hostPort, $m);
        $host = trim($m[1] ?? '', '[]');
        $port = (int)($m[2] ?? 443);
    } else {
        $decoded = base64_decode($rest, true);
        // method:password@host:port
        [$methodPass, $hostPort] = explode('@', $decoded, 2);
        [$method, $password] = explode(':', $methodPass, 2);
        preg_match('/^(\[.+?\]|[^:]+):(\d+)$/', $hostPort, $m);
        $host = trim($m[1] ?? '', '[]');
        $port = (int)($m[2] ?? 443);
    }

    return [
        'tag'      => 'proxy',
        'protocol' => 'shadowsocks',
        'settings' => ['servers' => [[
            'address'  => $host,
            'port'     => $port,
            'method'   => $method,
            'password' => $password,
        ]]],
        'streamSettings' => ['network' => 'tcp'],
    ];
}

/**
 * Parse Clash YAML subscription and return an xray outbound for server at $index.
 * Supports: vless, vmess, trojan, ss proxy types.
 * Uses a simple line-by-line YAML parser (no external dependencies).
 */
function parseClashYaml(string $yaml, int $index): ?array
{
    // Extract the proxies: block — everything from "proxies:" to next top-level key or EOF
    if (!preg_match('/^proxies\s*:(.*?)(?=^\w|\z)/ms', $yaml, $m)) {
        fwrite(STDERR, "Clash YAML: no 'proxies:' section found.\n");
        return null;
    }

    $proxiesBlock = $m[1];

    // Split into individual proxy entries (each starts with "  - ")
    $entries = preg_split('/^  - /m', $proxiesBlock, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($entries)) {
        fwrite(STDERR, "Clash YAML: no proxy entries found.\n");
        return null;
    }

    // Filter valid entries (skip stubs: address 0.0.0.0 or port 0/1)
    $valid = [];
    foreach ($entries as $entry) {
        $p = parseClashProxyBlock("  - " . $entry);
        if ($p !== null) {
            $addr = $p['server'] ?? '';
            $port = (int)($p['port'] ?? 0);
            if ($addr === '0.0.0.0' || $addr === '' || $port <= 1) continue;
            $valid[] = $p;
        }
    }

    echo "Clash YAML: " . count($entries) . " entries, " . count($valid) . " valid. Using index $index.\n";

    if (empty($valid)) {
        fwrite(STDERR, "Clash YAML: no valid proxies.\n");
        return null;
    }

    if (!isset($valid[$index])) {
        fwrite(STDERR, "Clash YAML: index $index out of range, using 0.\n");
        $index = 0;
    }

    $p = $valid[$index];
    $type = strtolower($p['type'] ?? '');
    $name = $p['name'] ?? 'proxy';
    echo "Selected Clash proxy: [$type] $name @ {$p['server']}:{$p['port']}\n";

    return match ($type) {
        'vless'         => clashVlessToXray($p),
        'vmess'         => clashVmessToXray($p),
        'trojan'        => clashTrojanToXray($p),
        'ss', 'shadowsocks' => clashSSToXray($p),
        default => null,
    };
}

/** Parse a single Clash proxy YAML block into a key=>value array. */
function parseClashProxyBlock(string $block): ?array
{
    $result = [];
    // Match "  key: value" lines (including nested under transport opts)
    preg_match_all('/^\s+(\S[^:]*?)\s*:\s*(.+)$/m', $block, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $key = trim($m[1]);
        $val = trim($m[2], " \t\"'");
        $result[$key] = $val;
    }
    return empty($result) ? null : $result;
}

function clashVlessToXray(array $p): array
{
    $network  = $p['network'] ?? $p['type_net'] ?? 'tcp';
    $security = $p['tls'] === 'true' || $p['tls'] === '1' ? 'tls' : 'none';
    if (!empty($p['reality-opts'])) $security = 'reality';
    $flow = $p['flow'] ?? '';

    $user = ['id' => $p['uuid'] ?? '', 'encryption' => 'none'];
    if ($flow !== '') $user['flow'] = $flow;

    $params = [
        'sni' => $p['servername'] ?? $p['sni'] ?? ($p['server'] ?? ''),
        'fp'  => $p['client-fingerprint'] ?? '',
        'pbk' => $p['reality-opts'] ?? '', // simplified
        'sid' => '',
        'spx' => '',
        'path'        => $p['ws-path'] ?? $p['path'] ?? '/',
        'host'        => $p['ws-headers'] ?? $p['host'] ?? '',
        'serviceName' => $p['grpc-service-name'] ?? '',
    ];

    return [
        'tag'      => 'proxy',
        'protocol' => 'vless',
        'settings' => ['vnext' => [[
            'address' => $p['server'],
            'port'    => (int)$p['port'],
            'users'   => [$user],
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];
}

function clashVmessToXray(array $p): array
{
    $network  = $p['network'] ?? 'tcp';
    $security = (!empty($p['tls']) && $p['tls'] !== 'false' && $p['tls'] !== '0') ? 'tls' : 'none';
    $params = [
        'sni'         => $p['servername'] ?? ($p['server'] ?? ''),
        'fp'          => $p['client-fingerprint'] ?? '',
        'path'        => $p['ws-path'] ?? $p['path'] ?? '/',
        'host'        => $p['ws-headers'] ?? ($p['server'] ?? ''),
        'serviceName' => $p['grpc-service-name'] ?? '',
    ];

    return [
        'tag'      => 'proxy',
        'protocol' => 'vmess',
        'settings' => ['vnext' => [[
            'address' => $p['server'],
            'port'    => (int)$p['port'],
            'users'   => [['id' => $p['uuid'] ?? '', 'alterId' => (int)($p['alterId'] ?? 0), 'security' => 'auto']],
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];
}

function clashTrojanToXray(array $p): array
{
    $network  = $p['network'] ?? 'tcp';
    $security = 'tls';
    $params   = [
        'sni' => $p['sni'] ?? $p['servername'] ?? ($p['server'] ?? ''),
        'fp'  => $p['client-fingerprint'] ?? '',
        'path' => $p['ws-path'] ?? '/',
        'host' => $p['server'] ?? '',
        'serviceName' => $p['grpc-service-name'] ?? '',
    ];

    return [
        'tag'      => 'proxy',
        'protocol' => 'trojan',
        'settings' => ['servers' => [[
            'address'  => $p['server'],
            'port'     => (int)$p['port'],
            'password' => $p['password'] ?? '',
        ]]],
        'streamSettings' => buildStreamSettings($network, $security, $params),
    ];
}

function clashSSToXray(array $p): array
{
    return [
        'tag'      => 'proxy',
        'protocol' => 'shadowsocks',
        'settings' => ['servers' => [[
            'address'  => $p['server'],
            'port'     => (int)$p['port'],
            'method'   => $p['cipher'] ?? $p['method'] ?? 'aes-256-gcm',
            'password' => $p['password'] ?? '',
        ]]],
        'streamSettings' => ['network' => 'tcp'],
    ];
}

/**
 * Build streamSettings block from parsed params.
 * Handles: tcp, ws, grpc, h2, reality, tls, none.
 */
function buildStreamSettings(string $network, string $security, array $p): array
{
    $ss = ['network' => $network];

    // Transport settings
    switch ($network) {
        case 'ws':
            $ss['wsSettings'] = [
                'path'    => $p['path'] ?? '/',
                'headers' => ['Host' => $p['host'] ?? ($p['sni'] ?? '')],
            ];
            break;
        case 'grpc':
            $ss['grpcSettings'] = ['serviceName' => $p['serviceName'] ?? ($p['path'] ?? '')];
            break;
        case 'h2':
        case 'http':
            $ss['httpSettings'] = [
                'host' => [$p['host'] ?? ($p['sni'] ?? '')],
                'path' => $p['path'] ?? '/',
            ];
            break;
    }

    // TLS / REALITY
    $security = strtolower($security);
    if ($security === 'reality') {
        $ss['security'] = 'reality';
        $ss['realitySettings'] = [
            'serverName'  => $p['sni']         ?? '',
            'fingerprint' => $p['fp']           ?? 'chrome',
            'publicKey'   => $p['pbk']          ?? '',
            'shortId'     => $p['sid']          ?? '',
            'spiderX'     => $p['spx']          ?? '',
        ];
    } elseif ($security === 'tls') {
        $ss['security'] = 'tls';
        $ss['tlsSettings'] = [
            'serverName'    => $p['sni'] ?? ($p['host'] ?? ''),
            'fingerprint'   => $p['fp']  ?? '',
            'allowInsecure' => false,
        ];
    } else {
        $ss['security'] = 'none';
    }

    return $ss;
}
