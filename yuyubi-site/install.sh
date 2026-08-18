#!/usr/bin/env bash
# 站点一键部署 / 复刻脚本（幂等，可重复执行）
# 用法: 以 root 运行本脚本后即可 https://yuyubi.cfd/xxx/ 访问, /admin/ 进后台
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
WEB_ROOT=/var/www/html
PRIV=/var/private/site
TPL="$PRIV/templates"
DOMAIN=yuyubi.cfd
PHP_SOCK=unix:/run/php-fpm/www.sock

need_cmd(){ command -v "$1" >/dev/null 2>&1 || { echo "缺少命令: $1"; exit 1; }; }
need_cmd php || { echo "需要先安装 php-cli"; exit 1; }

echo "==> 1/8 安装/检查必要软件包"
if command -v nginx >/dev/null 2>&1 && command -v php-fpm >/dev/null 2>&1; then
    echo "    nginx / php-fpm 已存在，跳过安装"
else
    set +e
    yum install -y nginx php-fpm php-cli php-json php-mbstring 2>&1 | tail -3
    if [ $? -ne 0 ]; then
        echo "!!! 安装失败。若为全新服务器，请先配置可用 yum 源后再重试。"
        echo "    提示: 可参考原服务器 /etc/yum.repos.d/*.repo 与 php74 仓库。"
    fi
    set -e
fi

echo "==> 2/8 准备目录"
mkdir -p "$WEB_ROOT" "$PRIV" "$TPL" "$WEB_ROOT/assets"
install -d -m 0700 "$PRIV"

echo "==> 3/8 部署站点文件"
install -m 0640 "$SRC/site/page.php"   "$WEB_ROOT/page.php"
install -m 0640 "$SRC/site/admin.php"  "$WEB_ROOT/admin.php"
install -m 0644 "$SRC/site/templates/malatang.html" "$TPL/malatang.html"
install -m 0644 "$SRC/site/templates/redirect.html" "$TPL/redirect.html"

# 未自带 auto 密码时，不覆盖已有密码文件
if [ ! -f "$PRIV/admin_pass.php" ]; then
    echo "<?php" >  "$PRIV/admin_pass.php"
    echo "// 初始密码: Admin@123456  (登录后请立即修改)" >> "$PRIV/admin_pass.php"
    echo "return 'Admin@123456';"    >> "$PRIV/admin_pass.php"
    chmod 0640 "$PRIV/admin_pass.php"
    echo "    已生成初始密码 Admin@123456，请登录后台后立即修改";
fi

# 资源文件：优先使用已有资产的拷贝，否则从脚本自带目录拷贝
if [ -d "$WEB_ROOT/lian/assets" ] && [ ! -e "$WEB_ROOT/assets/banner1.jpg" ]; then
    cp -r "$WEB_ROOT/lian/assets/." "$WEB_ROOT/assets/"
    echo "    已从旧目录复制 $WEB_ROOT/lian/assets 到 $WEB_ROOT/assets"
elif [ -d "$SRC/site/webroot/assets" ] && [ ! -e "$WEB_ROOT/assets/banner1.jpg" ]; then
    cp -r "$SRC/site/webroot/assets/." "$WEB_ROOT/assets/"
fi

# 静态错误页
echo '<!doctype html><html><head><meta charset="utf-8"><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px"><h1>404</h1><p>页面不存在</p></body></html>' > "$WEB_ROOT/404.html"
echo '<!doctype html><html><head><meta charset="utf-8"><title>50x</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px"><h1>服务暂时不可用</h1></body></html>' > "$WEB_ROOT/50x.html"
echo '<!doctype html><html><head><meta charset="utf-8"><title>yuyubi</title></head><body style="font-family:sans-serif;text-align:center;padding-top:80px;color:#666">nothing here</body></html>' > "$WEB_ROOT/index.html"

echo "==> 4/8 迁移/初始化配置"
php -r '
$f = "/var/private/site/config.json";
if (!is_file($f) && is_file("/var/www/html/lian/config.json")) $f = "/var/www/html/lian/config.json";
$old = is_file($f) ? json_decode(file_get_contents($f), true) : null;
$links = [];
$def = "https://pan.quark.cn/s/27b2535c2450";
if (is_array($old)) {
    $d1 = $old["default"] ?? "";
    $d2 = $old["default_pan_url"] ?? "";
    if (is_string($d1) && strpos($d1, "pan.quark.cn") !== false) $def = $d1;
    if (is_string($d2) && $d2 !== "") $def = $d2;
}
foreach ((array)($old["links"] ?? $old["targets"] ?? []) as $k => $v) {
    if (!is_array($v) || !is_string($k)) continue;
    $raw = $v["slug"] ?? $k;
    $slug = strtolower(trim(is_string($raw) ? $raw : $k, "/"));
    if ($slug === "" || !preg_match("/^[a-z0-9][a-z0-9_-]{1,63}$/", $slug)) continue;
    $pan  = trim($v["pan"] ?? $v["url"] ?? "");
    $mode = ($v["mode"] ?? "redirect") === "malatang" ? "malatang" : "redirect";
    $links[$slug] = ["mode" => $mode, "pan" => $pan];
}
$conf = ["default_pan_url" => $def, "updated_at" => time(), "links" => $links];
file_put_contents("/var/private/site/config.json", json_encode($conf, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo "    迁移生成 " . count($links) . " 个链接，默认网盘: $def\n";
'

echo "==> 5/8 写入 nginx 配置"
SRCTPL="$SRC/nginx.conf"
if [ -f "$SRCTPL" ]; then
    install -m 0644 "$SRCTPL" /etc/nginx/nginx.conf
    echo "    使用脚本自带 nginx.conf"
else
    echo "!!! 缺少 $SRCTPL，跳过 nginx 配置（请手动配置）"
fi

echo "==> 6/8 PHP 加固"
mkdir -p /etc/php.d
install -m 0644 "$SRC/hardening.ini" /etc/php.d/99-site.ini

echo "==> 7/8 启动服务 / 权限"
chown -R apache:apache "$PRIV" 2>/dev/null || true
chown -R apache:apache "$WEB_ROOT" 2>/dev/null || true
systemctl enable --now nginx php-fpm 2>/dev/null || true
systemctl try-restart php-fpm 2>/dev/null || true
nginx -t || { echo "!!! nginx 配置校验失败"; exit 1; }
systemctl reload nginx 2>/dev/null || systemctl restart nginx

echo "==> 8/8 HTTPS(如未签发)"
CERT=/etc/letsencrypt/live/$DOMAIN/fullchain.pem
if [ ! -f "$CERT" ]; then
    need_cmd certbot || { echo "跳过 HTTPS: certbot 未安装"; }
    certbot -n -m admin@$DOMAIN --agree-tos --redirect --nginx -d $DOMAIN 2>/dev/null || echo "    签发失败请手动处理"
    systemctl reload nginx
else
    echo "    已存在证书，跳过签发"
fi

echo
echo "完成。后台: https://$DOMAIN/admin/  链接: https://$DOMAIN/{短链}/"
echo "若为新服务器，请将本目录整体打包到新机器并重新执行本脚本。"