<?php
// RC Tech slideshow admin. Upload with the site to cPanel public_html.
// 1) Set your own password below (12+ characters).  2) Open rctech.com.np/admin.php
$PASSWORD = 'rctech';

$DATA = __DIR__ . '/slides.json';
$UP   = __DIR__ . '/uploads';
$MAX  = 3 * 1024 * 1024;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cut($s, $n) { $s = trim((string)$s); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function load() {
    global $DATA;
    $j = is_file($DATA) ? json_decode(file_get_contents($DATA), true) : null;
    if (!is_array($j)) $j = [];
    $j['interval'] = (int)($j['interval'] ?? 5500);
    $j['slides'] = array_values(is_array($j['slides'] ?? null) ? $j['slides'] : []);
    return $j;
}
function save($j) {
    global $DATA;
    $tmp = $DATA . '.tmp';
    $ok = file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    return $ok && rename($tmp, $DATA);
}
function clean_link($l) {
    $l = trim((string)$l);
    if ($l === '') return 'home';
    if (preg_match('#^https?://#i', $l)) return filter_var($l, FILTER_VALIDATE_URL) ? $l : 'home';
    return preg_match('#^[A-Za-z0-9/_%.\- ]{1,80}$#', $l) ? ltrim($l, '#') : 'home';
}
function upload($f) {
    global $UP, $MAX;
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $MAX) return [null, 'Image upload failed or is larger than 3 MB.'];
    $info = @getimagesize($f['tmp_name']) ?: [];
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $ext = $types[$info[2] ?? 0] ?? null;
    if (!$ext) return [null, 'Please use a JPG, PNG, WEBP or GIF image.'];
    if (!is_dir($UP)) @mkdir($UP, 0755);
    $name = 's' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $UP . '/' . $name)) return [null, 'Could not save the image. Make the uploads folder writable.'];
    return ['uploads/' . $name, null];
}
function drop_img($path) {
    global $UP;
    if (is_string($path) && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) @unlink($UP . '/' . basename($path));
}

if ($PASSWORD === 'CHANGE-THIS-PASSWORD') {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;max-width:560px;margin:60px auto;padding:0 20px"><h1>Set a password first</h1><p>Open <b>admin.php</b> in cPanel File Manager, change <code>CHANGE-THIS-PASSWORD</code> on line 4 to your own password, save, then reload this page.</p>';
    exit;
}
if (empty($_SESSION['t'])) $_SESSION['t'] = bin2hex(random_bytes(16));
$msg = $_SESSION['msg'] ?? ''; unset($_SESSION['msg']);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['a'] ?? '';
    if ($act === 'login') {
        if (hash_equals($PASSWORD, (string)($_POST['p'] ?? ''))) { session_regenerate_id(true); $_SESSION['in'] = 1; header('Location: admin.php'); exit; }
        sleep(1); $err = 'Wrong password.';
    } elseif (!empty($_SESSION['in']) && hash_equals($_SESSION['t'], (string)($_POST['csrf'] ?? ''))) {
        $j = load(); $s = &$j['slides']; $i = isset($_POST['i']) ? (int)$_POST['i'] : -1; $note = 'Saved.';
        if ($act === 'logout') { session_destroy(); header('Location: admin.php'); exit; }
        if ($act === 'interval') { $j['interval'] = max(2, min(20, (int)($_POST['sec'] ?? 5))) * 1000; }
        elseif ($act === 'add') {
            [$img, $e] = upload($_FILES['img'] ?? []);
            if ($e) $err = $e;
            elseif (cut($_POST['t'] ?? '', 80) === '') $err = 'A slide needs a title.';
            else $s[] = ['img' => $img ?? '', 't' => cut($_POST['t'], 80), 'd' => cut($_POST['d'] ?? '', 220), 'b' => cut($_POST['b'] ?? '', 30), 'l' => clean_link($_POST['l'] ?? ''), 'h' => 212, 'on' => true];
        } elseif (isset($s[$i])) {
            if ($act === 'save') {
                [$img, $e] = upload($_FILES['img'] ?? []);
                if ($e) $err = $e;
                else {
                    if ($img) { drop_img($s[$i]['img'] ?? ''); $s[$i]['img'] = $img; }
                    $s[$i]['t'] = cut($_POST['t'] ?? '', 80) ?: ($s[$i]['t'] ?? 'Slide');
                    $s[$i]['d'] = cut($_POST['d'] ?? '', 220); $s[$i]['b'] = cut($_POST['b'] ?? '', 30); $s[$i]['l'] = clean_link($_POST['l'] ?? '');
                }
            } elseif ($act === 'up' && $i > 0) { [$s[$i-1], $s[$i]] = [$s[$i], $s[$i-1]]; $note = 'Moved.'; }
            elseif ($act === 'down' && $i < count($s) - 1) { [$s[$i+1], $s[$i]] = [$s[$i], $s[$i+1]]; $note = 'Moved.'; }
            elseif ($act === 'toggle') { $s[$i]['on'] = empty($s[$i]['on']); }
            elseif ($act === 'del') { drop_img($s[$i]['img'] ?? ''); array_splice($s, $i, 1); $note = 'Deleted.'; }
        }
        if (!$err) { if (save($j)) $_SESSION['msg'] = $note; else $err = 'Could not write slides.json. Check file permissions (see README).'; }
        if (!$err) { header('Location: admin.php'); exit; }
    } else { $err = 'Session expired. Please log in again.'; }
}
$in = !empty($_SESSION['in']);
$j = $in ? load() : null; $T = h($_SESSION['t']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Slideshow admin · RC Tech</title>
<style>
:root{color-scheme:light dark;--bg:#f2f4f6;--s:#fff;--i:#0b2545;--m:#5b6b7f;--l:#dde3ea;--a:#b8862f}
@media (prefers-color-scheme:dark){:root{--bg:#0a1626;--s:#12233a;--i:#eaf0f7;--m:#98a9be;--l:#223650;--a:#e0b25a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--i);font:16px/1.5 system-ui,sans-serif}
.w{max-width:900px;margin:0 auto;padding:20px}h1{font-size:1.5rem;margin:0}
.c{background:var(--s);border:1px solid var(--l);border-radius:16px;padding:18px;margin:16px 0}
label{display:block;font-size:.85rem;font-weight:700;margin:12px 0 4px}
input,textarea{width:100%;padding:10px 12px;border:1px solid var(--l);border-radius:10px;background:var(--bg);color:inherit;font:inherit}
button,.b{border:0;border-radius:99px;padding:9px 16px;font:inherit;font-weight:700;cursor:pointer;background:var(--i);color:var(--bg);text-decoration:none;display:inline-block}
.g{background:var(--a);color:#fff}.o{background:transparent;color:var(--i);border:1px solid var(--l)}.r{background:#b3261e;color:#fff}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
img.p{width:100%;max-height:170px;object-fit:cover;border-radius:12px;background:#223}.off{opacity:.55}.ok{color:#1f8a5b;font-weight:700}.er{color:#c0392b;font-weight:700}.h{color:var(--m);font-size:.85rem}
.gr{display:grid;grid-template-columns:200px 1fr;gap:16px}@media (max-width:640px){.gr{grid-template-columns:1fr}}
</style></head><body><div class="w">
<?php if (!$in): ?>
<div class="c" style="max-width:380px;margin:60px auto"><h1>Slideshow admin</h1><p class="h">RC Tech website</p>
<?php if ($err) echo '<p class="er">' . h($err) . '</p>'; ?>
<form method="post"><input type="hidden" name="a" value="login"><label for="p">Password</label><input id="p" name="p" type="password" autocomplete="current-password" autofocus><div class="row"><button class="g">Log in</button></div></form></div>
<?php else: ?>
<div class="top"><h1>Slideshow admin</h1><div class="row" style="margin:0"><a class="b o" href="./" target="_blank">View site</a>
<form method="post"><input type="hidden" name="csrf" value="<?= $T ?>"><button class="o" name="a" value="logout">Log out</button></form></div></div>
<?php if ($msg) echo '<p class="ok">' . h($msg) . '</p>'; if ($err) echo '<p class="er">' . h($err) . '</p>'; ?>
<div class="c"><form method="post"><input type="hidden" name="csrf" value="<?= $T ?>"><label for="sec">Seconds per slide (2–20)</label>
<div class="row" style="margin-top:0"><input id="sec" name="sec" type="number" min="2" max="20" value="<?= (int)round($j['interval'] / 1000) ?>" style="width:100px"><button class="g" name="a" value="interval">Save</button></div></form></div>
<?php foreach ($j['slides'] as $k => $x): ?>
<div class="c <?= empty($x['on']) ? 'off' : '' ?>"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $T ?>"><input type="hidden" name="i" value="<?= $k ?>">
<div class="gr"><div><?php if (!empty($x['img'])): ?><img class="p" src="<?= h($x['img']) ?>" alt=""><?php else: ?><div class="h">No image</div><?php endif; ?>
<label>Replace image</label><input type="file" name="img" accept="image/jpeg,image/png,image/webp,image/gif"></div>
<div><label>Title</label><input name="t" value="<?= h($x['t'] ?? '') ?>" maxlength="80">
<label>Description</label><textarea name="d" rows="2" maxlength="220"><?= h($x['d'] ?? '') ?></textarea>
<label>Button text</label><input name="b" value="<?= h($x['b'] ?? '') ?>" maxlength="30">
<label>Button link</label><input name="l" value="<?= h($x['l'] ?? '') ?>" maxlength="200"></div></div>
<div class="row"><button class="g" name="a" value="save">Save</button><button class="o" name="a" value="up" <?= $k == 0 ? 'disabled' : '' ?>>↑ Up</button><button class="o" name="a" value="down" <?= $k == count($j['slides']) - 1 ? 'disabled' : '' ?>>↓ Down</button>
<button class="o" name="a" value="toggle"><?= empty($x['on']) ? 'Show' : 'Hide' ?></button><button class="r" name="a" value="del" onclick="return confirm('Delete this slide?')">Delete</button></div></form></div>
<?php endforeach; ?>
<div class="c"><h2 style="margin:0 0 4px">Add a slide</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $T ?>">
<label>Image (JPG, PNG, WEBP or GIF, up to 3 MB; wide 1600×640 works best)</label><input type="file" name="img" accept="image/jpeg,image/png,image/webp,image/gif">
<label>Title</label><input name="t" maxlength="80" required><label>Description</label><textarea name="d" rows="2" maxlength="220"></textarea>
<label>Button text</label><input name="b" maxlength="30" placeholder="Shop now"><label>Button link</label><input name="l" maxlength="200" placeholder="shop/Phones">
<p class="h">Link: a page (home, shop, shop/Phones, shop/Audio, repairs, orders, business, contact) or a full address starting with https://</p>
<div class="row"><button class="g" name="a" value="add">Add slide</button></div></form></div>
<?php endif; ?>
</div></body></html>
