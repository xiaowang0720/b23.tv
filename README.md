# b23.tv — 短链分发站点项目

三连/麻辣烫粉丝向跳转分发站,部署于 `https://yuyubi.cfd`。管理员通过后台维护若干个"短链",每个短链对应一种落地页类型,把用户带到指定网盘链接。

---

## 一、项目介绍

- **域名** `yuyubi.cfd` → `116.204.133.21`(阿里云,CentOS 8.0 + nginx 1.14 + PHP-FPM 7.4)
- **短链格式** `https://yuyubi.cfd/{短链}/`,短链一经创建永久稳定,不随配置变动而改变
- **两种落地页类型**:
  - **麻辣烫** —— 固定内容落地页(素材图、文案),不带跳转
  - **三连** —— 关注/点赞/收藏引导流程,完成后跳转网盘链接
- **后台** `https://yuyubi.cfd/admin/`:管理短链、切换类型、维护网盘地址、改密码
- 旧版(文件夹直连时代)已整体下线并归档,见 `old/` 与本仓库 `yuyubi-site/` 部署包

---

## 二、目录结构与文件分布

### 2.1 服务器(生产环境)

```
/var/www/html/                  web 根目录(nginx 直接服务)
├── page.php                    短链路由(唯一对外执行的 PHP)
├── admin.php                   后台入口(仅经 /admin/ 与 /admin.php 可执行)
├── assets/                     图片素材(banner1/4~9/bili-logo/illustration 等)
├── 404.html / 50x.html         错误页
└── index.html                  根路径占位页
/var/private/site/              私有区(仅 php-fpm 可读,nginx 无法访问)
├── config.json                 链接配置(后台增删改的对象)
├── admin_pass.php              后台密码(php return 格式)
├── login_lock.json             登录失败锁定记录
└── templates/                  页面模板(唯一真源)
    ├── malatang.html           麻辣烫模板
    └── redirect.html           三连模板(内嵌 __PAN_URL__ 注入点)
/opt/yuyubi-site/               部署包源码(= 本仓库 yuyubi-site/ 1:1)
/root/site_backup/              归档的旧版页面(lian~lian7)
/root/yuyubi-site.tar.gz        打好的完整复刻包
```

### 2.2 本仓库(git)

```
b23.tv/
├── README.md                    本文档
├── old/                         旧版页面存档(已下线,勿部署)
└── yuyubi-site/                 部署包(与 /opt/yuyubi-site/ 一致)
    ├── install.sh               一键部署/复刻脚本(幂等,root 执行)
    ├── nginx.conf               完整 nginx 配置(含路由/限流/certbot 段)
    ├── hardening.ini            PHP 加固配置 → /etc/php.d/99-site.ini
    └── site/
        ├── page.php             短链路由
        ├── admin.php            后台
        ├── templates/           两个页面模板
        └── webroot/assets/      素材(部署时拷到 /var/www/html/assets)
```

---

## 三、后台操作流程

入口:`https://yuyubi.cfd/admin/`(或 `/admin.php`)。

| 操作 | 步骤 |
| --- | --- |
| 登录 | 输入密码(存在 `/var/private/site/admin_pass.php`,初始 `admin123...`,登录后请立即修改) |
| 新建链接 | 底部「＋ 新增一行(默认 麻辣烫)」→ 填短链(小写字母/数字/下划线/横线,2–64 位)→ 选类型 → 填网盘(可选)→「保存配置」 |
| 切换类型 | 行内「类型」下拉改为 麻辣烫/三连,保存即生效 |
| 配置网盘 | 三连类型下填地址(需 `http(s)://` 开头,不填则用全局默认);麻辣烫无需填写 |
| 删除链接 | 点行尾红色「删除」→ 确认后整行置灰 →「保存配置」才真正移除;保存前可「撤销」 |
| 修改密码 | 页面底部「修改密码」卡片(新密码 ≥8 位且含字母和数字) |
| 预览地址 | 每行显示 `https://yuyubi.cfd/{短链}/`,可一键「复制」 |

**当前链接(2026-08-17)**

| 短链 | 类型 | 网盘地址 |
| --- | --- | --- |
| lian | 三连 | `pan.quark.cn/s/2d7f97658fd5` |
| lian2 | 三连 | `pan.quark.cn/s/0555b0023715` |
| lian3 | 三连 | `pan.quark.cn/s/ec94369ea647` |
| lian4 | 三连 | `pan.quark.cn/s/de9ed8398ec2` |
| lian5 | 三连 | 默认 |
| lian6 | 三连 | 默认 |
| lian7 | 三连 | 默认 |

全局默认网盘:`https://pan.quark.cn/s/27b2535c2450`

> 规则:短链只认第一段路径(`/{slug}/` 或 `/{slug}`),任何更深路径、`/page.php`、`/admin*` 均返回 404;网盘地址仅允许 `http://` / `https://` 前缀,防止注入。

---

## 四、页面原理(技术说明)

- **路由**:nginx 将所有未命中静态文件的请求转发给 `page.php`;`page.php` 按短链查 `config.json`,按类型读取模板渲染输出(服务端注入,前端无任何配置请求)。
- **三连页**:
  - 内嵌 `window.__PAN_URL__ = "<注入的网盘地址>"`,由 `page.php` 用 `str_replace('"__PAN_URL__"', …)` 注入;
  - 也支持 `?url=` 参数临时覆盖目标地址;
  - 验证流程完成后跳转网盘;Cookie 用于识别已完成三连的用户(键 `iMxBt`)。
- **素材**:模板引用 `/assets/xxx.jpg`(绝对路径),由 nginx 直接服务,缓存 1 天。
- **模板修改**:直接编辑 `/var/private/site/templates/*.html` 即可,无需重启任何服务(每次请求实时读取);改完记得同步回本仓库 `yuyubi-site/site/templates/`。

---

## 五、日常服务器操作

```bash
# 连接(密码勿写入文档)
ssh root@116.204.133.21

# 校验并重载 nginx(改 nginx.conf / 加路由后必做)
nginx -t && systemctl reload nginx

# 重启 php-fpm(改了 php.ini / 加固配置后)
systemctl restart php-fpm

# 看日志
tail -f /var/log/nginx/access.log      # 访问日志
tail -f /var/log/nginx/error.log       # nginx 错误
tail -f /var/log/php-fpm/error.log     # PHP 错误

# 备份配置(改动前建议)
cp /var/private/site/config.json /root/config.json.bak

# 证书续期(已配 cron,手动验证可跑)
certbot renew --dry-run
```

**部署新版本文件**:改动后把对应文件上传覆盖即可生效(PHP/HTML 无需重启),静态素材放 `/var/www/html/assets/`。

---

## 六、新服务器复刻(完整部署)

1. 准备一台 CentOS 8 服务器,配置好可用 yum 源(参考原服务器 `/etc/yum.repos.d/`),DNS 指向它;
2. 上传部署包并解压(可只传 `/root/yuyubi-site.tar.gz`):
   ```bash
   tar xzf yuyubi-site.tar.gz -C /opt
   cd /opt/yuyubi-site && bash install.sh
   ```
3. `install.sh` 自动完成:装包(nginx/php-fpm)、部署文件、迁移/初始化 `config.json`、写入 nginx.conf 与 PHP 加固、启动服务、签发证书(缺 certbot 时提示)。
4. 若新服务器没有配置,会生成**初始密码 `Admin@123456`**,登录后立即修改;
5. 如需沿用现有配置,把旧服务器 `/var/private/site/config.json` 与 `admin_pass.php` 一并拷过去即可(脚本不会覆盖已存在的密码文件)。

---

## 七、安全说明

- 密码与配置存放在 `/var/private/site/`(web 根之外),nginx 无法直接下载;
- 后台三重防爆破:nginx `limit_req`(每 IP 20 次/分,突发 40)+ 应用层 5 次失败锁 10 分钟 + 会话 Cookie `Secure/HttpOnly/SameSite`;错误密码尝试会生成 `login_lock.json` 记录;
- PHP 加固(`/etc/php.d/99-site.ini`):`expose_php=Off`、`cgi.fix_pathinfo=0`、禁用 `exec/shell_exec/curl_exec` 等危险函数;
- 短链与网盘地址均做服务端白名单校验;`page.php`、`admin.php` 无法被直接访问执行;
- 老版路径(`/lian/admin.php`、`/lian/config.json` 等)均已下线返回 404,旧文件归档于 `/root/site_backup/`。

---

## 八、更新记录

- **2026-08-17 v2**:重构为"短链路由 + 配置驱动"架构;后台迁移至 `/admin/`,白色新界面;模板移入私有目录;补齐部署包素材与复刻流程;旧版归档。
