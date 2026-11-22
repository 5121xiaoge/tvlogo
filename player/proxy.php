<?php
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/common.php';

/**
 * SOCKS5代理视频流转发脚本 - 支持音视频分离M3U8
 */

// 完全禁用输出缓冲
while (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

// 禁止错误输出
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);

// 配置常量
define('CURL_TIMEOUT', 20);
define('CURL_MAX_REDIRS', 10);
//define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('USER_AGENT', 'okhttp/3.15');


// ======================== 参数解析 ========================
$action = isset($_GET['action']) ? $_GET['action'] : 'stream';
$url = isset($_GET['url']) ? $_GET['url'] : '';
$proxyHost = isset($_GET['proxy_host']) ? $_GET['proxy_host'] : '';
$proxyPort = isset($_GET['proxy_port']) ? $_GET['proxy_port'] : '';
$proxyUser = isset($_GET['proxy_user']) ? $_GET['proxy_user'] : '';
$proxyPass = isset($_GET['proxy_pass']) ? $_GET['proxy_pass'] : '';

// 获取脚本URL
$script_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
              . $_SERVER['HTTP_HOST']
              . $_SERVER['SCRIPT_NAME'];

// ======================== 测试接口 ========================
if ($action === 'test') {
    header('Content-Type: application/json');
    sec_cors();
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => '无效的请求数据']);
        exit;
    }
    
    $proxy = isset($data['proxy']) ? $data['proxy'] : [];
    $testUrl = isset($data['testUrl']) ? $data['testUrl'] : 'https://httpbin.org/ip';
    
    $testProxyHost = isset($proxy['host']) ? $proxy['host'] : '';
    $testProxyPort = isset($proxy['port']) ? $proxy['port'] : '';
    $testProxyUser = isset($proxy['user']) ? $proxy['user'] : '';
    $testProxyPass = isset($proxy['pass']) ? $proxy['pass'] : '';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
    
    if (!empty($testProxyHost) && !empty($testProxyPort)) {
        $proxyAddr = $testProxyHost . ':' . $testProxyPort;
        sec_mark_proxy($ch); curl_setopt($ch, CURLOPT_PROXY, $proxyAddr);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($testProxyUser)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $testProxyUser . ':' . $testProxyPass);
        }
    }
    
    $response = sec_curl_exec($ch, CURL_MAX_REDIRS);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (sec_last_error() ?: curl_error($ch));
    curl_close($ch);
    
    if ($error) {
        echo json_encode(['success' => false, 'error' => $error]);
    } else {
        $data = json_decode($response, true);
        echo json_encode([
            'success' => true,
            'ip' => isset($data['origin']) ? $data['origin'] : 'unknown',
            'httpCode' => $httpCode
        ]);
    }
    exit;
}

// ======================== 片段请求处理 ========================
if ($action === 'segment') {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => CURL_MAX_REDIRS,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => USER_AGENT,
    ]);
    
    if (!empty($proxyHost) && !empty($proxyPort)) {
        sec_mark_proxy($ch); curl_setopt($ch, CURLOPT_PROXY, $proxyHost . ':' . $proxyPort);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($proxyUser)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
        }
    }
    
    if (isset($_SERVER['HTTP_RANGE'])) {
        curl_setopt($ch, CURLOPT_RANGE, $_SERVER['HTTP_RANGE']);
    }
    
    $data = sec_curl_exec($ch, CURL_MAX_REDIRS);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (sec_last_error() ?: curl_error($ch));
    curl_close($ch);
    
    if ($error || $data === false) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo "片段获取失败: " . $error;
        exit;
    }
    
    if ($http_code == 206) {
        http_response_code(206);
    } else {
        http_response_code(200);
    }
    header('Content-Type: video/MP2T');
    header('Content-Length: ' . strlen($data));
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
    sec_cors();
    echo $data;
    exit;
}

// ======================== 流请求处理（M3U8） ========================
if ($action === 'stream' && !empty($url)) {
    $decodedUrl = urldecode($url);
    while (strpos($decodedUrl, '%') !== false && preg_match('/%[0-9A-Fa-f]{2}/', $decodedUrl)) {
        $decoded = urldecode($decodedUrl);
        if ($decoded === $decodedUrl) break;
        $decodedUrl = $decoded;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $decodedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => CURL_MAX_REDIRS,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => USER_AGENT,
    ]);
    
    if (!empty($proxyHost) && !empty($proxyPort)) {
        sec_mark_proxy($ch); curl_setopt($ch, CURLOPT_PROXY, $proxyHost . ':' . $proxyPort);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($proxyUser)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
        }
    }
    
    $content = sec_curl_exec($ch, CURL_MAX_REDIRS);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (sec_last_error() ?: curl_error($ch));
    curl_close($ch);
    
    if ($error || $content === false || $http_code >= 400) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo "无法获取视频流: " . $error;
        exit;
    }
    
    $is_m3u8 = (stripos($content, '#EXTM3U') !== false);
    
    if ($is_m3u8) {
        $new_content = rewrite_m3u8($content, $final_url, $proxyHost, $proxyPort, $proxyUser, $proxyPass, $script_url);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Content-Length: ' . strlen($new_content));
        header('Cache-Control: no-cache, must-revalidate');
        sec_cors();
        echo $new_content;
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($content));
        sec_cors();
        echo $content;
    }
    exit;
}

// 默认返回帮助信息
header('Content-Type: text/html; charset=utf-8');
echo '<h1>视频代理服务</h1>';
echo '<p>使用方式: proxy.php?action=stream&url=视频地址&proxy_host=IP&proxy_port=端口</p>';
