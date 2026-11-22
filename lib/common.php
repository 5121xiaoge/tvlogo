<?php
/**
 * 公共工具函数（URL 解析、响应头解析、JSON 输出、M3U8 改写）。
 * 原先分别复制在 player/player2/resp/scan/socks5/val 中，现统一放在这里。
 * 兼容旧函数名，调用方无需改动。
 */

if (defined('COMMON_LOADED')) return;
define('COMMON_LOADED', true);

/* ======================== URL ======================== */

/**
 * 按 RFC 3986 把相对地址解析为绝对地址。
 * 支持：协议相对 //host/x、根路径 /x、?query、#frag、./ 与 ../
 * 修复旧实现的问题：
 *  - 基准地址以 / 结尾时（http://h/live/）会丢掉最后一级目录
 *  - Windows 服务器上 dirname() 返回反斜杠
 *  - 空字符串导致的下标警告
 *  - player 中 //cdn/x.ts 被拼成 http://host//cdn/x.ts
 */
function url_resolve($base, $rel) {
    $rel = trim((string) $rel);
    if ($rel === '') return (string) $base;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $rel)) return $rel; // 已经是绝对地址

    $b = @parse_url((string) $base);
    if (!$b || empty($b['host'])) return $rel;

    $scheme = $b['scheme'] ?? 'http';
    if (substr($rel, 0, 2) === '//') return $scheme . ':' . $rel;

    $auth = $scheme . '://'
        . (isset($b['user']) ? $b['user'] . (isset($b['pass']) ? ':' . $b['pass'] : '') . '@' : '')
        . $b['host']
        . (isset($b['port']) ? ':' . $b['port'] : '');
    $bpath = $b['path'] ?? '/';
    if ($bpath === '') $bpath = '/';

    if ($rel[0] === '?') return $auth . $bpath . $rel;
    if ($rel[0] === '#') return $auth . $bpath . (isset($b['query']) ? '?' . $b['query'] : '') . $rel;

    $cut   = strcspn($rel, '?#');
    $rpath = substr($rel, 0, $cut);
    $rest  = (string) substr($rel, $cut);

    $path = ($rpath[0] === '/')
        ? $rpath
        : substr($bpath, 0, strrpos($bpath, '/') + 1) . $rpath;

    // 去掉 . 和 .. 路径段
    $segs = explode('/', $path);
    $last = end($segs);
    $out  = [];
    foreach ($segs as $seg) {
        if ($seg === '..') {
            if (count($out) > 1) array_pop($out);
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    $path = implode('/', $out);
    if ($last === '.' || $last === '..') $path .= '/';
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;

    return $auth . $path . $rest;
}

if (!function_exists('is_absolute_url')) {
    function is_absolute_url($url) { return preg_match('/^https?:\/\//i', (string) $url); }
}
if (!function_exists('isAbsoluteUrl')) {
    function isAbsoluteUrl($url) { return is_absolute_url($url); }
}
if (!function_exists('resolve_relative_url')) {
    function resolve_relative_url($base_url, $relative_url) { return url_resolve($base_url, $relative_url); }
}
if (!function_exists('resolveRelativeUrl')) {
    function resolveRelativeUrl($baseUrl, $relativeUrl) { return url_resolve($baseUrl, $relativeUrl); }
}
if (!function_exists('absolute_url')) {
    function absolute_url($path, $base_url) { return url_resolve($base_url, $path); }
}

/* ======================== HTTP ======================== */

/** 解析原始响应头为 [小写名 => 值]（多次跳转时以最后一次为准） */
if (!function_exists('parseHeaders')) {
    function parseHeaders($header_str) {
        $headers = [];
        foreach (preg_split("/\r?\n/", (string) $header_str) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }
}

if (!function_exists('returnJson')) {
    function returnJson($data, $status = 200) {
        if (ob_get_level()) ob_end_clean();
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ======================== M3U8 代理改写（player / player2） ======================== */

if (!function_exists('build_proxy_url')) {
    function build_proxy_url($target_url, $proxyHost, $proxyPort, $proxyUser, $proxyPass, $action, $script_url) {
        $params = http_build_query([
            'action'     => $action,
            'url'        => $target_url,
            'proxy_host' => $proxyHost,
            'proxy_port' => $proxyPort,
            'proxy_user' => $proxyUser,
            'proxy_pass' => $proxyPass,
        ]);
        return $script_url . '?' . $params;
    }
}

if (!function_exists('rewrite_m3u8')) {
    /**
     * 把 M3U8 中的分片、子列表、KEY、MEDIA、MAP 地址改写为经由本代理的地址
     */
    function rewrite_m3u8($content, $base_url, $proxyHost, $proxyPort, $proxyUser, $proxyPass, $script_url) {
        $p = function ($url, $action) use ($base_url, $proxyHost, $proxyPort, $proxyUser, $proxyPass, $script_url) {
            return build_proxy_url(url_resolve($base_url, $url), $proxyHost, $proxyPort, $proxyUser, $proxyPass, $action, $script_url);
        };
        $tagAction = ['#EXT-X-KEY:' => 'segment', '#EXT-X-MEDIA:' => 'stream', '#EXT-X-MAP:' => 'segment'];

        $new_lines = [];
        foreach (explode("\n", (string) $content) as $line) {
            $line = rtrim($line, "\r");
            $trimmed = trim($line);

            if ($trimmed === '') { $new_lines[] = $line; continue; }

            if ($trimmed[0] === '#') {
                $done = false;
                foreach ($tagAction as $tag => $action) {
                    if (stripos($trimmed, $tag) === 0 && preg_match('/URI="([^"]+)"/i', $trimmed, $m)) {
                        $new_lines[] = str_replace($m[0], 'URI="' . $p($m[1], $action) . '"', $trimmed);
                        $done = true;
                        break;
                    }
                }
                if (!$done) $new_lines[] = $line;
                continue;
            }

            $absolute = url_resolve($base_url, $trimmed);
            $action = preg_match('/\.m3u8?($|\?)/i', $absolute) ? 'stream' : 'segment';
            $new_lines[] = build_proxy_url($absolute, $proxyHost, $proxyPort, $proxyUser, $proxyPass, $action, $script_url);
        }
        return implode("\n", $new_lines);
    }
}
