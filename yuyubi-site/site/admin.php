<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const PRIV_DIR  = '/var/private/site';
const PASS_FILE = PRIV_DIR . '/admin_pass.php';
const CONF_FILE = PRIV_DIR . '/config.json';
const LOCK_FILE = PRIV_DIR . '/login_lock.json';
const DEFAULT_PAN = 'https://pan.quark.cn/s/27b2535c2450';

$now = time();

// 读取并清除 Flash 提示消息 (PRG 模式防刷新重复提交)
$msg = $_SESSION['flash_msg'] ?? null;
$err = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function urlOk(string $u): bool { return $u !== '' && preg_match('#^https?://#i', $u) === 1 && strlen($u) <= 2048; }
function loadConf(): array {
    $d = is_file(CONF_FILE) ? @json_decode((string)file_get_contents(CONF_FILE), true) : null;
    return is_array($d) ? $d : ['default_pan_url' => DEFAULT_PAN, 'updated_at' => 0, 'links' => []];
}
function saveConf(array $c): bool {
    return file_put_contents(CONF_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}
function authPassword(): string {
    return is_file(PASS_FILE) ? (string)require PASS_FILE : '';
}
function clientIp(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }
function lockRead(): array {
    if (!is_file(LOCK_FILE)) return [];
    $d = @json_decode((string)file_get_contents(LOCK_FILE), true);
    return is_array($d) ? $d : [];
}
function lockWrite(array $s): void {
    $h = @fopen(LOCK_FILE, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    ftruncate($h, 0);
    fwrite($h, json_encode($s));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

$authed = !empty($_SESSION['auth']);

// ---------- 登出 ----------
if ($authed && isset($_GET['logout'])) {
    unset($_SESSION['auth']);
    session_destroy();
    header('Location: /admin/');
    exit;
}

// ---------- 登录 ----------
if (!$authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $ip    = clientIp();
    $locks = lockRead();
    $until = $locks[$ip]['until'] ?? 0;

    if ($until > $now) {
        $err = '尝试次数过多，' . max(1, intdiv($until - $now, 60) + 1) . ' 分钟后可重试';
    } elseif (hash_equals(authPassword(), (string)($_POST['password'] ?? ''))) {
        unset($locks[$ip]);
        lockWrite($locks);
        $_SESSION['auth'] = true;
        session_write_close();
        header('Location: /admin/');
        exit;
    } else {
        $locks[$ip]['count'] = ($locks[$ip]['count'] ?? 0) + 1;
        if ($locks[$ip]['count'] >= 5) {
            $locks[$ip]['until'] = $now + 600;
            $locks[$ip]['count'] = 0;
        }
        lockWrite($locks);
        $err = '密码错误';
    }
}

// ---------- 登录页 ----------
if (!$authed) {
    header('Cache-Control: no-store, max-age=0');
    ?>
    <!doctype html><html lang="zh-CN"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>登录 - 链接管理</title>
    <style>
    :root { --primary: #1677ff; --primary-hover: #0958d9; }
    *{box-sizing:border-box}
    body{font-family:"Microsoft YaHei",system-ui,-apple-system,sans-serif;background:#f0f2f5;color:#1f2329;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
    .box{background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.04);padding:40px 48px;width:380px;max-width:100%;transition:transform 0.3s ease}
    .logo{width:48px;height:48px;border-radius:12px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 4px 12px rgba(22,119,255,.25)}
    .logo svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    h1{font-size:22px;margin:0 0 8px;text-align:center;font-weight:600;color:#1a1a1a}
    .sub{text-align:center;color:#8c8c8c;font-size:14px;margin:0 0 28px}
    input{width:100%;box-sizing:border-box;padding:12px 16px;border-radius:10px;border:1px solid #d9d9d9;background:#fff;color:#1f2329;margin-bottom:20px;font-size:15px;outline:none;transition:all 0.2s}
    input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(22,119,255,.12)}
    button{width:100%;padding:12px;border:0;border-radius:10px;background:var(--primary);color:#fff;font-size:16px;cursor:pointer;font-weight:500;transition:all 0.2s}
    button:hover{background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 4px 12px rgba(22,119,255,.2)}
    button:active{transform:translateY(0)}
    .err{background:#fff2f0;border:1px solid #ffccc7;color:#ff4d4f;padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
    @keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
    .err { animation: slideDown 0.3s ease; }
    </style></head><body>
    <div class="box">
        <div class="logo">
            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
        </div>
        <h1>短链分发后台</h1>
        <p class="sub">欢迎回来，请输入管理密码</p>
        <?php if ($err !== null): ?>
            <div class="err">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?= e($err) ?>
            </div>
        <?php endif; ?>
        <form method="post" action="/admin/">
            <input type="password" name="password" placeholder="请输入管理员密码" required autofocus>
            <button type="submit">登 录</button>
        </form>
    </div></body></html>
    <?php
    exit;
}

// ---------- 已登录：修改密码 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op']) && $_POST['op'] === 'chpass') {
    $opw = (string)($_POST['old_pw'] ?? '');
    $npw = (string)($_POST['new_pw'] ?? '');
    if (!hash_equals(authPassword(), $opw)) {
        $err = '原密码错误';
    } elseif (strlen($npw) < 8 || !preg_match('/[A-Za-z]/', $npw) || !preg_match('/\d/', $npw)) {
        $err = '新密码至少 8 位且需包含字母和数字';
    } else {
        $content = "<?php\nreturn " . var_export($npw, true) . ";\n";
        if (file_put_contents(PASS_FILE, $content, LOCK_EX) === false) {
            $err = '写入失败，请检查目录权限';
        } else {
            $_SESSION['flash_msg'] = '密码已修改成功';
            session_write_close();
            header('Location: /admin/');
            exit;
        }
    }
}

// ---------- 已登录：保存链接配置 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['op']) && $_POST['op'] === 'save') {
    $conf = loadConf();
    $postedUt = (int)($_POST['updated_at'] ?? 0);
    if ((int)($conf['updated_at'] ?? 0) !== $postedUt) {
        $err = '配置已在其他页面被修改，请刷新页面后重试';
    } else {
        $default = trim((string)($_POST['default_pan_url'] ?? ''));
        if (!urlOk($default)) {
            $err = '默认网盘链接格式不正确（需以 http:// 或 https:// 开头）';
        } else {
            $slugs = (array)($_POST['slug'] ?? []);
            $modes = (array)($_POST['mode'] ?? []);
            $pans  = (array)($_POST['pan'] ?? []);
            $notes = (array)($_POST['note'] ?? []);
            $dels  = (array)($_POST['delete'] ?? []);
            $links = [];
            $bad   = null;
            foreach ($slugs as $i => $raw) {
                if (isset($dels[$i]) && (int)$dels[$i] === 1) continue;
                $slug = strtolower(trim((string)$raw));
                if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $slug)) {
                    $bad = '短链格式错误：仅限字母/数字/下划线/横线，2-64 位（第 ' . ($i + 1) . ' 行）';
                    break;
                }
                $mode = (($modes[$i] ?? '') === 'redirect') ? 'redirect' : 'malatang';
                $pan  = trim((string)($pans[$i] ?? ''));
                
                // 保留了你优秀的判断逻辑：只有在 redirect 模式下才校验网盘链接的合法性
                if ($mode === 'redirect' && $pan !== '' && !urlOk($pan)) {
                    $bad = '网盘链接需以 http:// 或 https:// 开头（第 ' . ($i + 1) . ' 行）';
                    break;
                }
                $note = trim((string)($notes[$i] ?? ''));
                if (strlen($note) > 200) {
                    $bad = 'b站短链备注过长（第 ' . ($i + 1) . ' 行）';
                    break;
                }
                $links[$slug] = ['mode' => $mode, 'pan' => $pan, 'note' => $note];
            }
            if ($bad !== null) {
                $err = $bad;
            } else {
                $conf['default_pan_url'] = $default;
                $conf['links']           = $links;
                $conf['updated_at']      = $now;
                if (!saveConf($conf)) {
                    $err = '写入 config 失败，请检查服务器权限';
                } else {
                    $_SESSION['flash_msg'] = '配置已保存生效，共 ' . count($links) . ' 个链接';
                    session_write_close();
                    header('Location: /admin/');
                    exit;
                }
            }
        }
    }
}

$conf = loadConf();
$default = (string)($conf['default_pan_url'] ?? DEFAULT_PAN);
$links = $conf['links'] ?? [];
$updatedAt = (int)($conf['updated_at'] ?? 0);
$rows = [];
foreach ($links as $slug => $v) {
    $rows[] = ['slug' => $slug, 'mode' => is_array($v) ? (string)($v['mode'] ?? 'malatang') : 'malatang', 'pan' => is_array($v) ? (string)($v['pan'] ?? '') : '', 'note' => is_array($v) ? (string)($v['note'] ?? '') : ''];
}
$hasLinks = count($rows) > 0;

header('Cache-Control: no-store, max-age=0');
?>
<!doctype html><html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>链接管理 - b23分发系统</title>
<style>
:root { --primary: #1677ff; --primary-hover: #0958d9; --bg: #f5f7fa; --text: #1f2329; --text-light: #8f959e; --border: #e8ebf0; }
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:24px 24px 100px;font-size:14px}
.wrap{max-width:1100px;margin:0 auto}

/* 顶部栏 */
.topbar{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.topbar h1{font-size:18px;margin:0;display:flex;align-items:center;gap:10px;font-weight:600}
.topbar h1 .logo{width:32px;height:32px;border-radius:8px;background:var(--primary);color:#fff;display:inline-flex;align-items:center;justify-content:center}
.topbar h1 .logo svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.auth{display:flex;align-items:center;gap:16px;color:var(--text-light);font-size:13px}

/* 卡片与表单元素 */
.card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);padding:24px;margin-bottom:24px}
.card h2{font-size:16px;margin:0 0 16px;font-weight:600;display:flex;align-items:center;gap:8px}
.card h2::before{content:"";width:4px;height:16px;border-radius:2px;background:var(--primary);display:inline-block}
.hint{font-size:13px;color:var(--text-light);margin:-8px 0 16px}
label{display:block;margin-bottom:8px;font-weight:500}
input[type=text],input[type=password],select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:#fff;color:var(--text);font-size:14px;outline:none;transition:all 0.2s}
input:focus,select:focus{border-color:var(--primary);box-shadow:0 0 0 2px rgba(22,119,255,.1)}
input.invalid {border-color:#ff4d4f; background:#fff2f0;}

/* 表格样式 */
.table-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:800px}
th{color:var(--text-light);font-weight:500;text-align:left;padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;background:#fafafa}
th:first-child{border-radius:8px 0 0 0} th:last-child{border-radius:0 8px 0 0}
td{padding:14px;border-bottom:1px solid var(--border);vertical-align:top;transition:all 0.3s}
tbody tr:hover{background:#fcfcfd}
tbody tr.row-del{background:#fff1f0; opacity:0.8;}
tbody tr.row-del input, tbody tr.row-del select{color:#bfbfbf;text-decoration:line-through;border-color:#f0f0f0;background:#fafafa;pointer-events:none}
.c-slug{width:120px}.c-mode{width:120px}.c-note{width:240px}.c-op{width:90px;text-align:center}
.short-url{font-size:12px;color:var(--primary);margin-top:8px;display:flex;align-items:center;gap:6px;word-break:break-all}
.short-url span {background:#e6f4ff;padding:2px 6px;border-radius:4px;}

/* 按钮组 */
.btn{background:var(--primary);color:#fff;border:0;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;font-weight:500;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s}
.btn:hover{background:var(--primary-hover)}
.btn.ghost{background:#fff;color:var(--text);border:1px solid var(--border)}
.btn.ghost:hover{background:#fafafa;border-color:#d9d9d9;color:var(--primary)}
.btn.danger-text{background:transparent;color:#ff4d4f;padding:6px 12px;border:none}
.btn.danger-text:hover{background:#fff2f0}
.btn.undo-text{background:#f5f5f5;color:var(--text-light);padding:6px 12px;border:none}
.btn.small{padding:6px 12px;font-size:13px}
.icon-btn{border:none;background:none;color:var(--text-light);cursor:pointer;padding:4px;border-radius:4px;display:flex}
.icon-btn:hover{background:#f0f0f0;color:var(--primary)}

.add-row-btn{width:100%;margin-top:16px;padding:12px;border:1px dashed #d9d9d9;border-radius:8px;background:#fafafa;color:var(--primary);font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all 0.2s}
.add-row-btn:hover{border-color:var(--primary);background:#e6f4ff}
.empty{text-align:center;padding:40px 0;color:var(--text-light)}

/* 悬浮操作底栏 */
.sticky-footer{position:fixed;bottom:0;left:0;right:0;background:rgba(255,255,255,0.9);backdrop-filter:blur(8px);border-top:1px solid var(--border);padding:16px 24px;display:flex;justify-content:center;box-shadow:0 -4px 12px rgba(0,0,0,.03);z-index:100}
.sticky-footer .inner{max-width:1100px;width:100%;display:flex;justify-content:space-between;align-items:center}
.btn.loading{opacity:0.7;cursor:not-allowed;pointer-events:none}
.spinner{animation:spin 1s linear infinite;width:16px;height:16px}
@keyframes spin{from{transform:rotate(0deg)} to{transform:rotate(360deg)}}
@keyframes highlightRow{0%{background:#e6f4ff} 100%{background:transparent}}
.row-new{animation:highlightRow 1.5s ease-out}

/* Toast 通知 */
#toast-container{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:1000;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px;background:#fff;box-shadow:0 6px 16px rgba(0,0,0,.12);font-size:14px;transform:translateY(-20px);opacity:0;transition:all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-left:4px solid #52c41a}
.toast.success .icon{color:#52c41a}
.toast.error{border-left:4px solid #ff4d4f}
.toast.error .icon{color:#ff4d4f}
</style>
</head><body>

<div id="toast-container"></div>

<div class="wrap">
    <div class="topbar">
        <h1>
            <span class="logo">
                <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
            </span>
            链接分发管理
        </h1>
        <div class="auth">
            <span>管理员在线</span>
            <button class="btn ghost small" onclick="location.href='/admin/?logout=1'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                退出登录
            </button>
        </div>
    </div>

    <form method="post" action="/admin/" id="saveForm">
    <input type="hidden" name="op" value="save">
    <input type="hidden" name="updated_at" value="<?= $updatedAt ?>">

    <div class="card">
        <h2>全局默认网盘链接</h2>
        <p class="hint">「三连」模式下若未单独填写跳转地址，将统一跳转到此处配置的默认地址。</p>
        <input type="text" name="default_pan_url" id="defaultPan" value="<?= e($default) ?>" placeholder="例如: https://pan.quark.cn/s/...">
    </div>

    <div class="card">
        <h2>短链路由表</h2>
        <?php if (!$hasLinks): ?>
            <div class="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d9d9d9" stroke-width="1.5" style="margin-bottom:10px"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                <div>暂无配置任何短链，点击下方新增</div>
            </div>
        <?php endif; ?>
        
        <div class="table-scroll">
        <table id="linkTable" style="<?= !$hasLinks ? 'display:none;' : '' ?>">
            <thead><tr>
                <th class="c-slug">短链路径 (Slug)</th>
                <th class="c-mode">落地页类型</th>
                <th>网盘地址（三连专属）</th>
                <th class="c-note">备注 (如B站短链)</th>
                <th class="c-op">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td>
                        <input type="text" name="slug[]" value="<?= e($r['slug']) ?>" class="slug-in" placeholder="如 lian8">
                        <div class="short-url">
                            <span>/<?= e($r['slug']) ?>/</span>
                            <button type="button" class="icon-btn copy-btn" title="复制完整链接" data-url="https://yuyubi.cfd/<?= e($r['slug']) ?>/">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                            </button>
                        </div>
                    </td>
                    <td>
                        <select name="mode[]" class="mode-in">
                            <option value="malatang" <?= $r['mode'] === 'malatang' ? 'selected' : '' ?>>麻辣烫</option>
                            <option value="redirect" <?= $r['mode'] === 'redirect' ? 'selected' : '' ?>>三连跳转</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="pan[]" value="<?= e($r['pan']) ?>" class="pan-in" <?= $r['mode'] === 'malatang' ? 'readonly style="background:#fafafa; color:#bfbfbf;" placeholder="内容已保留(当前模式不生效)"' : 'placeholder="http://..."' ?>>
                    </td>
                    <td><input type="text" name="note[]" value="<?= e($r['note']) ?>" class="note-in" placeholder="选填备忘录" maxlength="200"></td>
                    <td class="c-op">
                        <input type="checkbox" name="delete[<?= $i ?>]" value="1" class="del" hidden>
                        <button type="button" class="btn danger-text del-btn">删除</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="button" class="add-row-btn" id="addRow">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            新增路由配置
        </button>
    </div>

    <div class="sticky-footer">
        <div class="inner">
            <span class="hint" style="margin:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                配置修改后，点击保存立即生效。
            </span>
            <div style="display:flex;gap:12px">
                <button class="btn ghost" type="reset" id="resetBtn">撤销更改</button>
                <button class="btn" type="submit" id="saveBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="btn-icon"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    保存配置
                </button>
            </div>
        </div>
    </div>
    </form>

    <div class="card" style="margin-top:24px; border:1px solid #ffccc7; box-shadow:none;">
        <h2 style="color:#ff4d4f">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            修改管理密码
        </h2>
        <form method="post" action="/admin/" style="max-width:320px">
            <input type="hidden" name="op" value="chpass">
            <label>当前原密码</label>
            <input type="password" name="old_pw" required>
            <label>新密码 <span style="font-size:12px;color:#8f959e;font-weight:normal">(≥8位，需含字母和数字)</span></label>
            <input type="password" name="new_pw" required>
            <button class="btn ghost small" type="submit" style="margin-top:4px">确认修改</button>
        </form>
    </div>
</div>

<script>
// --- Toast 提示系统 ---
function showToast(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const iconSvg = type === 'success' 
        ? '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
        : '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
    toast.innerHTML = iconSvg + '<span>' + msg + '</span>';
    container.appendChild(toast);
    
    // 强制回流以触发过渡动画
    toast.offsetHeight; 
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// 接收后端的成功/错误消息
<?php if ($msg !== null): ?> window.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($msg) ?>, 'success')); <?php endif; ?>
<?php if ($err !== null): ?> window.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($err) ?>, 'error')); <?php endif; ?>

// --- 表单与表格交互逻辑 ---
(function () {
    const tbody = document.querySelector('#linkTable tbody');
    const table = document.getElementById('linkTable');
    if (!tbody) return;

    // 实时校验方法
    function checkInput(input, type) {
        let val = input.value.trim();
        let valid = true;
        if(val === '') {
            input.classList.remove('invalid');
            return;
        }
        if(type === 'slug') {
            valid = /^[a-z0-9][a-z0-9_-]{1,63}$/i.test(val);
        } else if(type === 'url') {
            valid = /^https?:\/\//i.test(val);
        }
        input.classList.toggle('invalid', !valid);
    }

    // 绑定行事件
    function bindRow(tr) {
        const del = tr.querySelector('.del');
        const btn = tr.querySelector('.del-btn');
        const slugIn = tr.querySelector('.slug-in');
        const mode = tr.querySelector('.mode-in');
        const pan = tr.querySelector('.pan-in');
        const copy = tr.querySelector('.copy-btn');
        const urlText = tr.querySelector('.short-url span');
        
        // 删除切换
        btn.addEventListener('click', function () {
            del.checked = !del.checked;
            tr.classList.toggle('row-del', del.checked);
            
            if(del.checked) {
                btn.textContent = '撤销删除';
                btn.className = 'btn undo-text del-btn';
            } else {
                btn.textContent = '删除';
                btn.className = 'btn danger-text del-btn';
            }
        });

        // 模式切换时，改为 readonly 以保留内容
        mode.addEventListener('change', function () {
            if (mode.value === 'malatang') {
                pan.readOnly = true;
                pan.style.background = '#fafafa';
                pan.style.color = '#bfbfbf';
                pan.classList.remove('invalid');
                pan.setAttribute('placeholder', '内容已保留(当前模式不生效)');
            } else {
                pan.readOnly = false;
                pan.style.background = '';
                pan.style.color = '';
                pan.setAttribute('placeholder', 'http://...');
                checkInput(pan, 'url');
            }
        });

        // 路径实时更新与校验
        slugIn.addEventListener('input', function() {
            let slug = slugIn.value.trim().toLowerCase();
            urlText.textContent = '/' + (slug || '...') + '/';
            copy.setAttribute('data-url', 'https://yuyubi.cfd/' + slug + '/');
            checkInput(slugIn, 'slug');
        });
        
        // 网盘地址校验
        pan.addEventListener('input', () => checkInput(pan, 'url'));

        // 复制功能
        copy.addEventListener('click', function () {
            const u = copy.getAttribute('data-url') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(u).then(() => {
                    const originalHTML = copy.innerHTML;
                    copy.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#52c41a" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                    setTimeout(() => copy.innerHTML = originalHTML, 1500);
                });
            }
        });
    }

    // 初始化现有行
    tbody.querySelectorAll('tr').forEach(bindRow);
    
    // 全局默认网盘校验
    const defaultPan = document.getElementById('defaultPan');
    if(defaultPan) {
        defaultPan.addEventListener('input', () => checkInput(defaultPan, 'url'));
    }

    // 重置按钮逻辑
    document.getElementById('saveForm').addEventListener('reset', function () {
        setTimeout(() => { // 等待原生 reset 完成
            tbody.querySelectorAll('tr').forEach(tr => {
                tr.classList.remove('row-del');
                const btn = tr.querySelector('.del-btn');
                if(btn) { btn.textContent = '删除'; btn.className = 'btn danger-text del-btn'; }
                const slugIn = tr.querySelector('.slug-in');
                if(slugIn) { slugIn.dispatchEvent(new Event('input')); }
                const mode = tr.querySelector('.mode-in');
                if(mode) { mode.dispatchEvent(new Event('change')); }
            });
            document.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));
        }, 10);
    });

    // 新增一行逻辑
    document.getElementById('addRow').addEventListener('click', function () {
        table.style.display = 'table';
        document.querySelector('.empty')?.remove();
        
        const tr = document.createElement('tr');
        tr.className = 'row-new';
        tr.innerHTML = `
            <td>
                <input type="text" name="slug[]" value="" class="slug-in" placeholder="如 lian8">
                <div class="short-url"><span>/.../</span>
                <button type="button" class="icon-btn copy-btn" data-url=""><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button></div>
            </td>
            <td>
                <select name="mode[]" class="mode-in">
                    <option value="malatang" selected>麻辣烫</option>
                    <option value="redirect">三连跳转</option>
                </select>
            </td>
            <td><input type="text" name="pan[]" value="" class="pan-in" readonly style="background:#fafafa; color:#bfbfbf;" placeholder="内容已保留(当前模式不生效)"></td>
            <td><input type="text" name="note[]" value="" class="note-in" placeholder="选填备忘录" maxlength="200"></td>
            <td class="c-op">
                <input type="checkbox" name="delete[${tbody.rows.length}]" value="1" class="del" hidden>
                <button type="button" class="btn danger-text del-btn">删除</button>
            </td>
        `;
        tbody.appendChild(tr);
        bindRow(tr);
        
        // 滚动并聚焦
        const slugInput = tr.querySelector('.slug-in');
        slugInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => slugInput.focus(), 300);
    });

    // 提交表单增加 Loading 效果
    document.getElementById('saveForm').addEventListener('submit', function(e) {
        const btn = document.getElementById('saveBtn');
        const icon = btn.querySelector('.btn-icon');
        btn.classList.add('loading');
        icon.outerHTML = '<svg class="spinner btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>';
        btn.innerHTML = btn.innerHTML.replace('保存配置', '正在保存...');
        // 允许表单自然提交
    });
})();
</script>
</body></html>