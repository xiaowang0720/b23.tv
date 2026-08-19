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
$err = null;
$msg = null;

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
    *{box-sizing:border-box}
    body{font-family:"Microsoft YaHei",system-ui,-apple-system,sans-serif;background:#f3f5f9;color:#1f2329;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
    .box{background:#fff;border:1px solid #e4e7ef;border-radius:14px;box-shadow:0 4px 18px rgba(16,24,40,.07);padding:36px 40px;width:340px;max-width:100%}
    .logo{width:44px;height:44px;border-radius:11px;background:#3478f6;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;margin:0 auto 14px}
    h1{font-size:18px;margin:0 0 4px;text-align:center;font-weight:600}
    .sub{text-align:center;color:#8a93a6;font-size:12px;margin:0 0 22px}
    input{width:100%;box-sizing:border-box;padding:11px 13px;border-radius:9px;border:1px solid #d9dde5;background:#fff;color:#1f2329;margin-bottom:14px;font-size:14px;outline:none}
    input:focus{border-color:#3478f6;box-shadow:0 0 0 3px rgba(52,120,246,.14)}
    button{width:100%;padding:11px;border:0;border-radius:9px;background:#3478f6;color:#fff;font-size:15px;cursor:pointer;font-weight:500}
    button:hover{background:#2a67d9}
    .err{background:#fdecec;border:1px solid #f5c6c6;color:#c0392b;padding:10px 13px;border-radius:9px;font-size:13px;margin-bottom:14px}
    .hint{font-size:12px;color:#a5adbb;text-align:center;margin-top:16px}
    </style></head><body>
    <div class="box">
        <div class="logo">🔗</div>
        <h1>链接管理后台</h1>
        <p class="sub">请输入管理密码登录</p>
        <?php if ($err !== null): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
        <form method="post" action="/admin/">
            <input type="password" name="password" placeholder="管理密码" required autofocus>
            <button type="submit">登 录</button>
        </form>
        <div class="hint"></div>
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
            $msg = '密码已修改';
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
                    $bad = '短链格式错误：仅限字母/数字/下划线/横线，2-64 位，且不能以符号开头（第 ' . ($i + 1) . ' 行）';
                    break;
                }
                $mode = (($modes[$i] ?? '') === 'redirect') ? 'redirect' : 'malatang';
                $pan  = trim((string)($pans[$i] ?? ''));
                if ($pan !== '' && !urlOk($pan)) {
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
                    $err = '写入 config 失败，请检查权限';
                } else {
                    $msg = '已保存，共 ' . count($links) . ' 个链接';
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
<title>链接管理后台</title>
<style>
*{box-sizing:border-box}
body{font-family:"Microsoft YaHei",system-ui,-apple-system,sans-serif;background:#f3f5f9;color:#1f2329;margin:0;padding:24px;font-size:14px}
.wrap{max-width:1000px;margin:0 auto}
.topbar{background:#fff;border:1px solid #e4e7ef;border-radius:14px;box-shadow:0 2px 10px rgba(16,24,40,.04);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.topbar h1{font-size:17px;margin:0;display:flex;align-items:center;gap:9px}
.topbar h1 .logo{width:34px;height:34px;border-radius:9px;background:#3478f6;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:15px}
.auth{display:flex;align-items:center;gap:10px;color:#8a93a6;font-size:13px}
.btn{background:#3478f6;color:#fff;border:0;border-radius:9px;padding:10px 20px;font-size:14px;cursor:pointer;font-weight:500}
.btn:hover{background:#2a67d9}
.btn.ghost{background:#fff;color:#4a5468;border:1px solid #d9dde5}
.btn.ghost:hover{background:#f6f8fc;border-color:#c3c9d6}
.btn.small{background:#fff;color:#4a5468;border:1px solid #d9dde5;border-radius:8px;padding:5px 12px;font-size:12px;cursor:pointer}
.btn.small:hover{background:#f6f8fc}
.card{background:#fff;border:1px solid #e4e7ef;border-radius:14px;box-shadow:0 2px 10px rgba(16,24,40,.04);padding:20px 22px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 14px;color:#4a5468;display:flex;align-items:center;gap:8px}
.card h2::before{content:"";width:4px;height:15px;border-radius:2px;background:#3478f6;display:inline-block}
label{display:block;margin-bottom:6px;color:#5b667a;font-size:13px}
input[type=text],input[type=password],select{width:100%;padding:9px 11px;border-radius:9px;border:1px solid #d9dde5;background:#fff;color:#1f2329;font-size:14px;outline:none}
input:focus,select:focus{border-color:#3478f6;box-shadow:0 0 0 3px rgba(52,120,246,.13)}
table{width:100%;border-collapse:collapse}
th{color:#8a93a6;font-weight:500;text-align:left;padding:8px 10px;border-bottom:1px solid #eef0f5;font-size:12px;background:#f8fafc;white-space:nowrap}
th:first-child{border-radius:8px 0 0}
td{padding:10px;border-bottom:1px solid #f0f2f7;vertical-align:top}
tbody tr:hover{background:#fafbfe}
tbody tr.row-del{background:#fdf4f4}
tbody tr.row-del input,tbody tr.row-del select{color:#b9bdc7;text-decoration:line-through}
.c-slug{width:190px}.c-mode{width:110px}.c-note{width:170px}.c-op{width:86px;text-align:center}
.short-url{font-size:12px;color:#8ea0be;margin-top:4px;word-break:break-all;display:flex;align-items:center;gap:6px}
.copy-btn{border:0;background:transparent;color:#3478f6;font-size:12px;cursor:pointer;padding:0}
.copy-btn:hover{text-decoration:underline}
.msg{background:#e9f8ef;border:1px solid #b8e6c9;color:#1e7e46;padding:10px 14px;border-radius:10px;margin-bottom:14px}
.err{background:#fdecec;border:1px solid #f5c6c6;color:#c0392b;padding:10px 14px;border-radius:10px;margin-bottom:14px}
.empty{color:#a5adbb;text-align:center;padding:26px 0}
.add-row-btn{width:100%;margin-top:12px;padding:10px;border:1px dashed #b9c6de;border-radius:10px;background:#f8fafd;color:#3478f6;font-size:14px;cursor:pointer}
.add-row-btn:hover{background:#eef4ff;border-color:#3478f6}
.del-btn{border:1px solid #f0b4b4;background:#fff;color:#d9534f;border-radius:8px;padding:6px 14px;font-size:13px;cursor:pointer}
.del-btn:hover{background:#fdf3f3}
.del-btn.undo{border-color:#c9cfda;color:#8a93a6;background:#f6f8fc}
.footer{display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap}
.hint{font-size:12px;color:#a5adbb;margin-top:10px}
.table-scroll{overflow-x:auto}
</style></head><body>
<div class="wrap">
    <div class="topbar">
        <h1><span class="logo">🔗</span>链接管理后台</h1>
        <div class="auth">已登录 <button class="btn ghost small" onclick="location.href='/admin/?logout=1'">退出登录</button></div>
    </div>

    <?php if ($msg !== null): ?><div class="msg"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err !== null): ?><div class="err"><?= e($err) ?></div><?php endif; ?>

    <form method="post" action="/admin/" id="saveForm">
    <input type="hidden" name="op" value="save">
    <input type="hidden" name="updated_at" value="<?= $updatedAt ?>">

    <div class="card">
        <h2>全局默认网盘链接</h2>
        <p class="hint" style="margin-top:-6px">「三连」类型的链接若未单独填写地址，会统一跳转到这里的地址。</p>
        <input type="text" name="default_pan_url" value="<?= e($default) ?>">
    </div>

    <div class="card">
        <h2>链接列表</h2>
        <?php if (!$hasLinks): ?><div class="empty">还没有链接，点下方「新增一行」开始</div><?php endif; ?>
        <div class="table-scroll">
        <table id="linkTable">
            <thead><tr>
                <th class="c-slug">网站路径</th>
                <th class="c-mode">类型</th>
                <th>网盘地址（三连模式跳转）</th>
                <th class="c-note">b站短链(备注)</th>
                <th class="c-op">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td>
                        <input type="text" name="slug[]" value="<?= e($r['slug']) ?>" class="slug-in" placeholder="如 lian8">
                        <div class="short-url">https://yuyubi.cfd/<?= e($r['slug']) ?>/<button type="button" class="copy-btn" data-url="https://yuyubi.cfd/<?= e($r['slug']) ?>/">复制</button></div>
                    </td>
                    <td>
                        <select name="mode[]" class="mode-in">
                            <option value="malatang" <?= $r['mode'] === 'malatang' ? 'selected' : '' ?>>麻辣烫</option>
                            <option value="redirect" <?= $r['mode'] === 'redirect' ? 'selected' : '' ?>>三连</option>
                        </select>
                    </td>
                    <td><input type="text" name="pan[]" value="<?= e($r['pan']) ?>" class="pan-in" <?= $r['mode'] === 'malatang' ? 'disabled placeholder="麻辣烫模式无需填写"' : '' ?>></td>
                    <td><input type="text" name="note[]" value="<?= e($r['note']) ?>" class="note-in" placeholder="生成的b站短链" maxlength="200"></td>
                    <td class="c-op">
                        <input type="checkbox" name="delete[<?= $i ?>]" value="1" class="del" hidden>
                        <button type="button" class="del-btn">删除</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="button" class="add-row-btn" id="addRow">＋ 新增一行（默认 麻辣烫）</button>
    </div>

    <div class="card">
        <h2>操作</h2>
        <div class="footer">
            <button class="btn" type="submit">保存配置</button>
            <button class="btn ghost" type="reset">重置未保存更改</button>
        </div>
        <p class="hint">点「保存配置」后立即生效；删除的链接在保存后才真正移除。</p>
    </div>
    </form>

    <div class="card">
        <h2>修改密码</h2>
        <form method="post" action="/admin/" style="max-width:430px">
            <input type="hidden" name="op" value="chpass">
            <label>原密码</label>
            <input type="password" name="old_pw" required>
            <label style="margin-top:10px">新密码（至少 8 位，含字母和数字）</label>
            <input type="password" name="new_pw" required>
            <button class="btn ghost" type="submit" style="margin-top:12px">修改密码</button>
        </form>
    </div>
</div>

<script>
(function () {
    var tbody = document.querySelector('#linkTable tbody');
    if (!tbody) return;

    function bindRow(tr) {
        var del = tr.querySelector('.del');
        var btn = tr.querySelector('.del-btn');
        btn.addEventListener('click', function () {
            if (!del.checked && !confirm('确定删除这个链接吗？保存后生效。')) return;
            del.checked = !del.checked;
            tr.classList.toggle('row-del', del.checked);
            btn.textContent = del.checked ? '撤销' : '删除';
            btn.classList.toggle('undo', del.checked);
            var url = tr.querySelector('.short-url');
            if (url) url.style.opacity = del.checked ? '.45' : '1';
        });
        var mode = tr.querySelector('.mode-in');
        var pan = tr.querySelector('.pan-in');
        if (mode) mode.addEventListener('change', function () {
            if (mode.value === 'malatang') {
                pan.disabled = true;
                pan.setAttribute('placeholder', '麻辣烫模式无需填写');
            } else {
                pan.disabled = false;
                pan.removeAttribute('placeholder');
            }
        });
        var slugIn = tr.querySelector('.slug-in');
        var urlText = tr.querySelector('.url-text');
        var copy = tr.querySelector('.copy-btn');
        if (slugIn && urlText) {
            var refreshUrl = function () {
                var slug = (slugIn.value || '').trim().toLowerCase();
                urlText.textContent = 'https://yuyubi.cfd/' + (slug || '…') + '/';
                if (copy) copy.setAttribute('data-url', 'https://yuyubi.cfd/' + slug + '/');
            };
            slugIn.addEventListener('input', refreshUrl);
            refreshUrl();
        }
        if (copy) copy.addEventListener('click', function () {
            var u = copy.getAttribute('data-url') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(u).then(function () {
                    copy.textContent = '已复制';
                    setTimeout(function () { copy.textContent = '复制'; }, 1200);
                });
            }
        });
    }

    tbody.querySelectorAll('tr').forEach(bindRow);

    document.getElementById('saveForm').addEventListener('reset', function () {
        tbody.querySelectorAll('tr').forEach(function (tr) {
            var del = tr.querySelector('.del');
            var btn = tr.querySelector('.del-btn');
            if (!del || !btn) return;
            del.checked = false;
            tr.classList.remove('row-del');
            btn.textContent = '删除';
            btn.classList.remove('undo');
            var url = tr.querySelector('.short-url');
            if (url) url.style.opacity = '1';
        });
    });

    document.getElementById('addRow').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" name="slug[]" value="" class="slug-in" placeholder="如 lian8">' +
            '<div class="short-url"><span class="url-text">https://yuyubi.cfd/…/</span>' +
            '<button type="button" class="copy-btn" data-url="">复制</button></div></td>' +
            '<td><select name="mode[]" class="mode-in">' +
            '<option value="malatang" selected>麻辣烫</option><option value="redirect">三连</option></select></td>' +
            '<td><input type="text" name="pan[]" value="" class="pan-in" disabled placeholder="麻辣烫模式无需填写"></td>' +
            '<td><input type="text" name="note[]" value="" class="note-in" placeholder="生成的b站短链" maxlength="200"></td>' +
            '<td class="c-op"><input type="checkbox" name="delete[' + tbody.rows.length + ']" value="1" class="del" hidden>' +
            '<button type="button" class="del-btn">删除</button></td>';
        tbody.appendChild(tr);
        bindRow(tr);
        tr.querySelector('.slug-in').focus();
    });
})();
</script>
</body></html>