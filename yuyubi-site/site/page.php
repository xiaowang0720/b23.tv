<?php
declare(strict_types=1);

const PRIV_DIR   = '/var/private/site';
const CONF_FILE  = PRIV_DIR . '/config.json';
const TPL_DIR    = PRIV_DIR . '/templates';
const ASSET_DIR  = '/var/www/html/assets';
const DEFAULT_PAN = 'https://pan.quark.cn/s/27b2535c2450';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function loadConf(): array {
    $d = is_file(CONF_FILE) ? @json_decode((string)file_get_contents(CONF_FILE), true) : null;
    return is_array($d) ? $d : ['default_pan_url' => DEFAULT_PAN, 'links' => []];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$trim = trim($path, '/');
$segs = $trim === '' ? [] : explode('/', $trim);

// 只接受单段短链（/{slug} 或 /{slug}/），深层路径一律 404
$slug = count($segs) === 1 ? $segs[0] : '';

// 禁止直接访问管理端/路由脚本
if ($slug === 'admin.php' || $slug === 'page.php' || $slug === 'admin') {
    http_response_code(404);
    exit('404');
}

$conf  = loadConf();
$links = $conf['links'] ?? [];
$link  = $links[$slug] ?? null;

if (!is_array($link)) {
    http_response_code(404);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px"><h1>404</h1><p>页面不存在或已下线</p></body></html>';
    exit;
}

$mode = ($link['mode'] ?? 'malatang') === 'redirect' ? 'redirect' : 'malatang';
$tpl  = @file_get_contents(TPL_DIR . '/' . $mode . '.html');

if ($tpl === false) {
    http_response_code(500);
    exit('500');
}

if ($mode === 'redirect') {
    $pan  = !empty($link['pan']) ? $link['pan'] : (string)($conf['default_pan_url'] ?? DEFAULT_PAN);
    $json = json_encode($pan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tpl  = str_replace('"__PAN_URL__"', $json, $tpl);
}

echo $tpl;