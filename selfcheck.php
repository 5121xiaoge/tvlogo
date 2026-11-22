<?php
/**
 * 部署自检脚本：上传后用浏览器访问一次 /selfcheck.php，全部通过后请删除本文件。
 * 只读检查，不会修改任何文件。启用 access_key 后需要先通过 ?key= 验证。
 */
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/common.php';
header('Content-Type: text/html; charset=utf-8');

$results = [];
function check($group, $name, $ok, $detail = '') {
    global $results;
    $results[] = [$group, $name, $ok === null ? 'warn' : ($ok ? 'ok' : 'fail'), $detail];
}

/* ---------- 环境 ---------- */
check('环境', 'PHP 版本 ≥ 7.3', version_compare(PHP_VERSION, '7.3.0', '>='), PHP_VERSION);
check('环境', 'curl 扩展', extension_loaded('curl'), extension_loaded('curl') ? (curl_version()['version'] ?? '') : '未安装，所有工具都无法使用');
check('环境', 'json 扩展', extension_loaded('json'));
check('环境', 'zlib 扩展（EPG gzip 解压）', extension_loaded('zlib') ? true : null, extension_loaded('zlib') ? '' : '未安装，player2 无法解压 .gz 节目单');
check('环境', 'CURLOPT_PROTOCOLS 支持', defined('CURLOPT_PROTOCOLS'), defined('CURLOPT_PROTOCOLS') ? '' : 'curl 版本过旧，协议白名单不生效');
$tmp = sys_get_temp_dir();
check('环境', '临时目录可写', is_writable($tmp), $tmp);
$cache = __DIR__ . '/socks5/cache';
check('环境', 'socks5/cache 可写', is_dir($cache) ? is_writable($cache) : (is_writable(__DIR__ . '/socks5') ? null : false), is_dir($cache) ? '' : '目录不存在，首次查询时会自动创建');

/* ---------- 配置 ---------- */
$hasCfg = file_exists(__DIR__ . '/lib/config.php');
check('配置', 'lib/config.php', $hasCfg ? true : null, $hasCfg ? '已加载' : '未创建，使用默认值（仅同源、禁止内网、校验证书）');
check('配置', '访问密钥', sec_cfg('access_key') !== '' ? true : null, sec_cfg('access_key') !== '' ? '已启用' : '未启用：公网部署建议设置 access_key');
check('配置', '限流', (int) sec_cfg('rate_limit') > 0 ? true : null, (int) sec_cfg('rate_limit') > 0 ? sec_cfg('rate_limit') . ' 次/分钟' : '未启用');
check('配置', '跨域白名单', in_array('*', (array) sec_cfg('allowed_origins'), true) ? null : true, in_array('*', (array) sec_cfg('allowed_origins'), true) ? '允许任意来源（不推荐）' : (implode(', ', (array) sec_cfg('allowed_origins')) ?: '仅同源'));
check('配置', '内网访问', sec_cfg('allow_private') ? null : true, sec_cfg('allow_private') ? '已允许（仅适合局域网自用）' : '已禁止');
check('配置', '证书校验', sec_cfg('ssl_verify') ? true : null, sec_cfg('ssl_verify') ? '开启' : '已关闭');
check('配置', 'display_errors 关闭', !ini_get('display_errors') || ini_get('display_errors') === 'Off');

/* ---------- URL 解析 ---------- */
$urlCases = [
    ['http://h:8080/live/index.m3u8?t=1', 'seg1.ts', 'http://h:8080/live/seg1.ts'],
    ['http://h/live/', 'a.ts', 'http://h/live/a.ts'],
    ['http://h/a/b/c.m3u8', '../x/y.ts', 'http://h/a/x/y.ts'],
    ['http://h/a/b/c.m3u8', '/root.ts?k=1', 'http://h/root.ts?k=1'],
    ['https://h/a/c.m3u8', '//cdn.x/z.ts', 'https://cdn.x/z.ts'],
    ['http://h', 'a.ts', 'http://h/a.ts'],
    ['http://h/a/c.m3u8', './d/e.ts', 'http://h/a/d/e.ts'],
    ['http://h/a/c.m3u8', '?q=2', 'http://h/a/c.m3u8?q=2'],
    ['http://h/a/c.m3u8', 'http://o/p.ts', 'http://o/p.ts'],
    ['http://h/a/b/c', '../../../../x', 'http://h/x'],
];
$bad = [];
foreach ($urlCases as [$b, $r, $want]) {
    $got = url_resolve($b, $r);
    if ($got !== $want) $bad[] = "$b + $r => $got（应为 $want）";
}
check('功能', '相对地址解析（10 个用例）', !$bad, $bad ? implode('<br>', array_map('htmlspecialchars', $bad)) : '');

$m3u = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key.bin\"\n#EXTINF:6,\nseg1.ts\nsub/index.m3u8\n";
$out = rewrite_m3u8($m3u, 'http://h/live/index.m3u8', '', '', '', '', 'https://me/player/proxy.php');
$okRw = strpos($out, 'action=segment&url=' . urlencode('http://h/live/seg1.ts')) !== false
     && strpos($out, 'action=stream&url=' . urlencode('http://h/live/sub/index.m3u8')) !== false
     && strpos($out, 'URI="https://me/player/proxy.php?action=segment&url=' . urlencode('http://h/live/key.bin')) !== false;
check('功能', 'M3U8 改写（分片 / 子列表 / KEY）', $okRw, $okRw ? '' : '<pre>' . htmlspecialchars($out) . '</pre>');

/* ---------- 安全 ---------- */
$blocked = ['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.1/', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/'];
if (!sec_cfg('allow_private')) {
    $leak = array_filter($blocked, function ($u) { return sec_check_url($u) === null; });
    check('安全', '拦截内网地址（6 个）', !$leak, $leak ? '未拦截：' . implode(', ', $leak) : '');
} else {
    check('安全', '拦截内网地址', null, '已配置 allow_private=true，跳过');
}
$protoBad = array_filter(['file:///etc/passwd', 'gopher://x/', 'dict://x:11211/', 'ftp://x/'], function ($u) { return sec_check_url($u) === null; });
check('安全', '拦截非 http/https 协议', !$protoBad, $protoBad ? implode(', ', $protoBad) : '');
check('安全', '公网地址可放行', sec_check_url('https://example.com/a.m3u8') === null, sec_check_url('https://example.com/a.m3u8') ?: '');
check('安全', '危险 Content-Type 被替换', sec_safe_content_type('text/html') === 'application/octet-stream' && sec_safe_content_type('image/svg+xml', 'x', true) === 'x');

$leftovers = [];
foreach (['resp/logs/proxy_debug.log', 'LOGO/epg.xml', 'player/hls.js', 'player2/hls.js'] as $f) if (file_exists(__DIR__ . '/' . $f)) $leftovers[] = $f;
check('安全', '敏感/废弃文件已清理', !$leftovers, implode(', ', $leftovers));

/* ---------- 外网与 Web 访问 ---------- */
$self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
function http_status($url, $verify = true) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0]);
    curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $e = curl_error($ch); curl_close($ch);
    return [$c, $e];
}
if (extension_loaded('curl')) {
    [$c, $e] = http_status('https://www.baidu.com/');
    check('网络', '服务器可访问外网（HTTPS + 证书）', $c > 0, $c > 0 ? "HTTP $c" : $e);
    foreach (['lib/security.php', 'lib/config.sample.php', 'socks5/cache/'] as $p) {
        [$c] = http_status($self . '/' . $p, false);
        check('网络', "禁止 Web 访问 /$p", $c === 0 ? null : ($c === 403 || $c === 404), $c === 0 ? '无法从服务器访问自身，请手动在浏览器打开确认' : "HTTP $c" . (($c === 403 || $c === 404) ? '' : '（Nginx 需手动配置 deny 规则，见 readme）'));
    }
    [$c] = http_status($self . '/LOGO/CCTV1.png', false);
    check('网络', '台标可访问 /LOGO/CCTV1.png', $c === 0 ? null : $c === 200, $c ? "HTTP $c" : '无法从服务器访问自身');
}

/* ---------- 输出 ---------- */
$cnt = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($results as $r) $cnt[$r[2]]++;
$icon = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌'];
?><!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>部署自检</title>
<style>body{font-family:system-ui,"Microsoft YaHei",sans-serif;max-width:960px;margin:24px auto;padding:0 16px;color:#222}
table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top;font-size:14px}
th{background:#f6f6f6}.fail{background:#fff1f0}.warn{background:#fffbe6}.sum{font-size:18px;margin:12px 0}
.tip{background:#fff1f0;border:1px solid #ffa39e;padding:10px;border-radius:6px}pre{white-space:pre-wrap;margin:4px 0}</style></head><body>
<h2>📺 tvlogo 部署自检</h2>
<div class="sum">✅ 通过 <?= $cnt['ok'] ?>　⚠️ 提醒 <?= $cnt['warn'] ?>　❌ 失败 <?= $cnt['fail'] ?></div>
<p class="tip">检查完请<b>删除 selfcheck.php</b>，它会暴露服务器环境信息。</p>
<table><tr><th>分类</th><th>检查项</th><th>结果</th><th>说明</th></tr>
<?php foreach ($results as [$g, $n, $s, $d]): ?>
<tr class="<?= $s ?>"><td><?= $g ?></td><td><?= htmlspecialchars($n) ?></td><td><?= $icon[$s] ?></td><td><?= $d ?></td></tr>
<?php endforeach; ?>
</table></body></html>
