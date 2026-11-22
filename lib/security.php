<?php
/**
 * 公共安全模块：所有 PHP 接口都在文件开头 require 本文件。
 *
 * 提供：
 *  - 统一的 CORS / 安全响应头
 *  - 可选的访问密钥（Cookie 方式，前端无需改动）
 *  - 可选的按 IP 限流
 *  - SSRF 防护：协议白名单 + 禁止访问内网/保留地址（含跳转后的每一跳）
 *  - 统一的 TLS 证书校验开关
 *
 * 所有可调参数都在 config.php 中，复制 config.sample.php 为 config.php 即可修改。
 */

if (defined('SEC_LOADED')) return;
define('SEC_LOADED', true);

$__secCfgFile = __DIR__ . '/config.php';
$__secCfg = file_exists($__secCfgFile) ? (require $__secCfgFile) : [];
if (!is_array($__secCfg)) $__secCfg = [];
$GLOBALS['SEC_CFG'] = array_merge([
    // 允许跨域调用的来源，空数组 = 仅同源可用；['*'] = 任意来源（不推荐）
    'allowed_origins' => [],
    // 访问密钥，留空则不启用。启用后需先访问任一接口 ?key=你的密钥 一次（写入 Cookie）
    'access_key'      => '',
    // 每个 IP 每分钟最多请求次数，0 = 不限制（播放器每分钟会请求几十个分片，建议 >= 600）
    'rate_limit'      => 0,
    // 是否允许访问内网 / 保留地址（家庭局域网自用、代理在内网时可设为 true）
    'allow_private'   => false,
    // 是否校验 HTTPS 证书。部分直播源证书不规范，如遇大量 SSL 错误可改为 false
    'ssl_verify'      => true,
    // 是否写调试日志（写到系统临时目录，不在网站目录下）
    'debug_log'       => false,
], $__secCfg);
unset($__secCfgFile, $__secCfg);

function sec_cfg($k) { return $GLOBALS['SEC_CFG'][$k] ?? null; }

/** 日志目录：放在系统临时目录，避免被 Web 直接访问 */
function sec_log_dir() {
    $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tvtools_logs';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

function sec_log($file, $line) {
    if (!sec_cfg('debug_log')) return;
    @file_put_contents(sec_log_dir() . DIRECTORY_SEPARATOR . basename($file), $line, FILE_APPEND);
}

/** 输出 CORS 头（替代原来的 Access-Control-Allow-Origin: *） */
function sec_cors() {
    $allowed = (array) sec_cfg('allowed_origins');
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array('*', $allowed, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    // 同源请求不需要 CORS 头
}

function sec_deny($code, $msg) {
    while (ob_get_level()) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function sec_client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function sec_rate_limit() {
    $limit = (int) sec_cfg('rate_limit');
    if ($limit <= 0) return;
    $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tvtools_rl_' . md5(sec_client_ip()) . '_' . floor(time() / 60);
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $n = (int) stream_get_contents($fp) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string) $n);
    flock($fp, LOCK_UN); fclose($fp);
    if ($n > $limit) sec_deny(429, '请求过于频繁，请稍后再试');
}

function sec_check_key() {
    $key = (string) sec_cfg('access_key');
    if ($key === '') return;
    if (isset($_GET['key']) && hash_equals($key, (string) $_GET['key'])) {
        setcookie('tvtools_key', $key, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        return;
    }
    if (isset($_COOKIE['tvtools_key']) && hash_equals($key, (string) $_COOKIE['tvtools_key'])) return;
    sec_deny(403, '需要访问密钥');
}

/** 每个接口开头调用一次 */
function sec_init() {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    header('X-Content-Type-Options: nosniff');
    sec_check_key();
    sec_rate_limit();
}

/* ======================== SSRF 防护 ======================== */

function sec_ip_is_public($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
        && !preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.|169\.254\.|::ffff:(127|10|192\.168|169\.254)\.|fe80:|fc|fd)/i', $ip);
}

/** 解析主机名得到全部 IP（IPv4 + IPv6） */
function sec_resolve($host) {
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = @gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
        $r = @dns_get_record($host, DNS_AAAA);
        if (is_array($r)) foreach ($r as $x) if (!empty($x['ipv6'])) $ips[] = $x['ipv6'];
    }
    return array_values(array_unique($ips));
}

/**
 * 校验目标 URL：只允许 http/https；（非代理模式下）禁止解析到内网地址。
 * @return string|null 错误信息，null 表示通过
 */
function sec_check_url($url, $proxied = false) {
    $p = @parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '无效的URL';
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return '只允许 http/https 协议';
    if ($proxied || sec_cfg('allow_private')) return null; // 走代理时由代理所在网络访问目标
    $host = strtolower(trim($p['host'], '[]'));
    if ($host === 'localhost' || substr($host, -10) === '.localhost') return '禁止访问本机地址';
    foreach (sec_resolve($host) as $ip) {
        if (!sec_ip_is_public($ip)) return '禁止访问内网或保留地址';
    }
    return null;
}

/** 校验用户提供的 SOCKS5 代理地址 */
function sec_check_proxy_host($host) {
    if ($host === '' || $host === null || sec_cfg('allow_private')) return null;
    foreach (sec_resolve($host) as $ip) {
        if (!sec_ip_is_public($ip)) return '代理地址不能是内网或保留地址';
    }
    return null;
}

/** 记录某个 curl 句柄使用了代理（在 CURLOPT_PROXY 前调用） */
function sec_mark_proxy($ch) {
    $GLOBALS['SEC_PROXIED'][is_object($ch) ? spl_object_id($ch) : (int) $ch] = true;
}
function sec_is_proxied($ch) {
    return !empty($GLOBALS['SEC_PROXIED'][is_object($ch) ? spl_object_id($ch) : (int) $ch]);
}

$GLOBALS['SEC_LAST_ERROR'] = '';
function sec_last_error() { return $GLOBALS['SEC_LAST_ERROR']; }

/**
 * 替代 curl_exec 的安全版本。
 * @param int $follow 大于 0 时手动跟随跳转（最多 $follow 次），每一跳都重新校验。
 *                    调用方需把 CURLOPT_FOLLOWLOCATION 设为 false。
 */
function sec_curl_exec($ch, $follow = 0) {
    $GLOBALS['SEC_LAST_ERROR'] = '';
    $proxied = sec_is_proxied($ch);

    if (defined('CURLOPT_PROTOCOLS')) {
        @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    $verify = (bool) sec_cfg('ssl_verify');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

    $hops = 0;
    while (true) {
        $url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($url !== '' && ($err = sec_check_url($url, $proxied))) {
            $GLOBALS['SEC_LAST_ERROR'] = $err;
            return false;
        }
        $resp = curl_exec($ch);

        // 事后校验实际连接的 IP（防止 DNS 重绑定 / hosts 映射到内网）
        $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if ($ip !== '' && !sec_cfg('allow_private') && !sec_ip_is_public($ip)) {
            $GLOBALS['SEC_LAST_ERROR'] = $proxied ? '代理地址不能是内网或保留地址' : '禁止访问内网或保留地址';
            return false;
        }

        if ($follow <= 0 || $resp === false) return $resp;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($code < 300 || $code >= 400 || $next === '') return $resp;
        if (++$hops > $follow) { $GLOBALS['SEC_LAST_ERROR'] = '跳转次数过多'; return false; }
        curl_setopt($ch, CURLOPT_URL, $next);
    }
}

/** 只允许安全的媒体类 Content-Type 透传给浏览器 */
function sec_safe_content_type($ct, $default = 'application/octet-stream', $allowImage = false) {
    $ct = strtolower(trim(explode(';', (string) $ct)[0]));
    if ($ct === '') return $default;
    if (preg_match('#^(video|audio)/[a-z0-9.+-]+$#', $ct)) return $ct;
    if (in_array($ct, ['application/vnd.apple.mpegurl', 'application/x-mpegurl', 'application/octet-stream', 'application/dash+xml', 'video/mp2t'], true)) return $ct;
    if ($allowImage && preg_match('#^image/(png|jpe?g|gif|webp|x-icon|vnd\.microsoft\.icon|bmp)$#', $ct)) return $ct; // 不允许 svg
    return $default;
}

sec_init();
