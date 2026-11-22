<?php
/**
 * 复制本文件为 config.php 后修改。config.php 不要提交到公开仓库。
 */
return [
    // 允许跨域调用的来源。空数组 = 仅同源；例如 ['https://live.404003.xyz']
    'allowed_origins' => [],
    // 访问密钥。设置后先访问一次 任意工具/api.php?key=你的密钥（写入 Cookie），之后正常使用
    'access_key'      => '',
    // 每 IP 每分钟最大请求数，0 = 不限。用播放器时建议 >= 600
    'rate_limit'      => 0,
    // 家庭局域网自用、或 SOCKS5 代理在内网时改为 true
    'allow_private'   => false,
    // 遇到大量 SSL 证书错误的直播源时可改为 false
    'ssl_verify'      => true,
    // 调试日志（写入系统临时目录 tvtools_logs/）
    'debug_log'       => false,
];
