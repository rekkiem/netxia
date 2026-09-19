<?php
/**
 * NETXIA Admin — Blog + Leads + Correo
 * URL: https://netxia.cl/php/admin/
 * 50webs Free · sin MySQL · JSON + HTML
 */
ini_set('display_errors', '0');
error_reporting(E_ERROR);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Falta php/config.php. Copia example.config.php y configúralo.');
}
require_once $configFile;
require_once dirname(__DIR__) . '/mailer.php';
netxia_session_start();

$configuredAdminPass = defined('BLOG_ADMIN_PASS') ? trim((string)BLOG_ADMIN_PASS) : '';
$adminPassPlaceholders = ['', 'CambiaEstoNetxia2026', 'REEMPLAZA_CON_CLAVE_O_HASH_BCRYPT'];
$adminPassConfigured = !in_array($configuredAdminPass, $adminPassPlaceholders, true);
if (!$adminPassConfigured && !IS_LOCAL) {
    http_response_code(503);
    exit('Admin deshabilitado: configura BLOG_ADMIN_PASS en php/config.php (usa password_hash).');
}
$adminPass = $adminPassConfigured ? $configuredAdminPass : 'dev-only-local';

function admin_logged_in(): bool {
    return !empty($_SESSION['blog_admin']) && $_SESSION['blog_admin'] === true;
}
function admin_require_login(): void {
    if (!admin_logged_in()) {
        header('Location: index.php?view=login');
        exit;
    }
}
function admin_csrf_token(): string {
    netxia_session_start();
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}
function admin_csrf_ok(): bool {
    netxia_session_start();
    $t = (string)($_POST['admin_csrf'] ?? '');
    return $t !== '' && !empty($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $t);
}
function admin_rate_file(): string {
    $ip = preg_replace('/[^a-fA-F0-9\.:]/', '', $_SERVER['REMOTE_ADDR'] ?? '0') ?: '0';
    if (!is_dir(DATA_DIR . '/admin')) @mkdir(DATA_DIR . '/admin', 0755, true);
    return DATA_DIR . '/admin/rl_' . md5($ip) . '.json';
}
function admin_recent_failed_logins(): array {
    $f = admin_rate_file();
    $now = time();
    $data = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?? []) : [];
    return array_values(array_filter($data, fn($t) => ($now - (int)$t) < 3600));
}
function admin_login_limited(): bool { return count(admin_recent_failed_logins()) >= 8; }
function admin_record_failed_login(): void {
    $f = admin_rate_file();
    $data = admin_recent_failed_logins();
    $data[] = time();
    @file_put_contents($f, json_encode($data), LOCK_EX);
}
function admin_clear_failed_logins(): void {
    $f = admin_rate_file();
    if (is_file($f)) @unlink($f);
}
function admin_public_base(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/php/admin(?:/index\.php)?/?$#', '', $script);
    return rtrim((string)($base ?? ''), '/');
}
function admin_blog_url(string $slug): string {
    return admin_public_base() . '/blog/' . rawurlencode($slug) . '.html';
}
function blog_path(): string { return DATA_DIR . '/blog.json'; }
function load_posts(): array {
    $f = blog_path();
    if (!is_file($f)) return [];
    $data = json_decode((string)file_get_contents($f), true);
    return is_array($data) ? $data : [];
}
function save_posts(array $posts): bool {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    $json = json_encode(array_values($posts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return (bool)@file_put_contents(blog_path(), $json . "\n", LOCK_EX);
}
function slugify(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower((string)$text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string)$text, '-') ?: 'articulo-' . date('Ymd');
}
function markdown_lite(string $md): string {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $html = [];
    $inUl = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($inUl) { $html[] = '</ul>'; $inUl = false; }
            continue;
        }
        if (preg_match('/^### (.+)$/', $trim, $m)) {
            if ($inUl) { $html[] = '</ul>'; $inUl = false; }
            $html[] = '<h3>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h3>';
            continue;
        }
        if (preg_match('/^## (.+)$/', $trim, $m)) {
            if ($inUl) { $html[] = '</ul>'; $inUl = false; }
            $html[] = '<h2 style="font-family:var(--font-display);color:var(--text);margin:2rem 0 1rem">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</h2>';
            continue;
        }
        if (preg_match('/^[-*] (.+)$/', $trim, $m)) {
            if (!$inUl) { $html[] = '<ul>'; $inUl = true; }
            $item = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
            $html[] = '<li>' . $item . '</li>';
            continue;
        }
        if ($inUl) { $html[] = '</ul>'; $inUl = false; }
        $p = htmlspecialchars($trim, ENT_QUOTES, 'UTF-8');
        $p = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $p);
        $html[] = '<p>' . $p . '</p>';
    }
    if ($inUl) $html[] = '</ul>';
    return implode("\n", $html);
}
function render_article_file(array $post, string $bodyMd): bool {
    $tplPath = dirname(__DIR__, 2) . '/blog/_template/article.html';
    if (!is_file($tplPath)) return false;
    $tpl = file_get_contents($tplPath);
    $title = (string)($post['titulo'] ?? 'Artículo');
    $slug  = (string)($post['slug'] ?? 'articulo');
    $fecha = (string)($post['fecha'] ?? date('Y-m-d'));
    $ts = strtotime($fecha) ?: time();
    $replacements = [
        '{{TITLE}}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        '{{TITLE_HTML}}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        '{{SUMMARY}}' => htmlspecialchars((string)($post['resumen'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{SLUG}}' => htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'),
        '{{CATEGORY}}' => htmlspecialchars((string)($post['categoria'] ?? 'Blog'), ENT_QUOTES, 'UTF-8'),
        '{{AUTHOR}}' => htmlspecialchars((string)($post['autor'] ?? 'Equipo Netxia'), ENT_QUOTES, 'UTF-8'),
        '{{READ_TIME}}' => htmlspecialchars((string)($post['lectura'] ?? '5 min'), ENT_QUOTES, 'UTF-8'),
        '{{DATE_HUMAN}}' => htmlspecialchars(date('d/m/Y', $ts), ENT_QUOTES, 'UTF-8'),
        '{{BODY_HTML}}' => markdown_lite($bodyMd),
    ];
    $html = strtr($tpl, $replacements);
    $out = dirname(__DIR__, 2) . '/blog/' . $slug . '.html';
    return (bool)@file_put_contents($out, $html, LOCK_EX);
}
function update_sitemap(array $posts): void {
    $root = dirname(__DIR__, 2);
    $urls = [
        ['loc' => 'https://netxia.cl/', 'lastmod' => date('Y-m-d'), 'prio' => '1.0'],
        ['loc' => 'https://netxia.cl/blog/', 'lastmod' => date('Y-m-d'), 'prio' => '0.9'],
    ];
    foreach ($posts as $p) {
        if (($p['publicado'] ?? true) === false) continue;
        $slug = $p['slug'] ?? '';
        if (!$slug) continue;
        $urls[] = ['loc' => 'https://netxia.cl/blog/' . $slug . '.html', 'lastmod' => $p['fecha'] ?? date('Y-m-d'), 'prio' => '0.8'];
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
        $xml .= '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
        $xml .= "    <changefreq>monthly</changefreq>\n";
        $xml .= '    <priority>' . $u['prio'] . "</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    @file_put_contents($root . '/sitemap.xml', $xml, LOCK_EX);
}

$flash = '';
$flashErr = false;
$allowedViews = ['login', 'list', 'edit', 'leads', 'mail'];
$view = $_GET['view'] ?? (admin_logged_in() ? 'list' : 'login');
if (!in_array($view, $allowedViews, true)) {
    $view = admin_logged_in() ? 'list' : 'login';
}
$editSlug = $_GET['slug'] ?? '';
$mailHealth = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        if (!admin_csrf_ok()) {
            $flash = 'Sesión expirada. Recarga e intenta de nuevo.';
            $flashErr = true;
            $view = 'login';
        } elseif (admin_login_limited()) {
            $flash = 'Demasiados intentos. Espera una hora.';
            $flashErr = true;
            $view = 'login';
        } else {
            $pass = (string)($_POST['password'] ?? '');
            $ok = false;
            if (str_starts_with($adminPass, '$2y$') || str_starts_with($adminPass, '$2a$')) {
                $ok = password_verify($pass, $adminPass);
            } else {
                $ok = hash_equals($adminPass, $pass);
            }
            if ($ok) {
                admin_clear_failed_logins();
                session_regenerate_id(true);
                $_SESSION['blog_admin'] = true;
                $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                header('Location: index.php?view=list');
                exit;
            }
            admin_record_failed_login();
            $flash = 'Contraseña incorrecta.';
            $flashErr = true;
            $view = 'login';
        }
    }

    if ($action === 'logout') {
        if (admin_csrf_ok()) {
            unset($_SESSION['blog_admin']);
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        }
        header('Location: index.php?view=login');
        exit;
    }

    if ($action === 'save' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido. Recarga la página.';
            $flashErr = true;
            $view = 'list';
        } else {
            $posts = load_posts();
            $slug  = trim((string)($_POST['slug'] ?? ''));
            $title = trim((string)($_POST['titulo'] ?? ''));
            if ($title === '') {
                $flash = 'El título es obligatorio.';
                $flashErr = true;
                $view = 'edit';
            } else {
                if ($slug === '') $slug = slugify($title);
                $slug = slugify($slug);
                $body = (string)($_POST['cuerpo'] ?? '');
                $post = [
                    'slug' => $slug,
                    'titulo' => $title,
                    'resumen' => trim((string)($_POST['resumen'] ?? '')),
                    'categoria' => trim((string)($_POST['categoria'] ?? 'Inteligencia Artificial')),
                    'fecha' => trim((string)($_POST['fecha'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                    'lectura' => trim((string)($_POST['lectura'] ?? '5 min')) ?: '5 min',
                    'autor' => trim((string)($_POST['autor'] ?? 'Equipo Netxia')) ?: 'Equipo Netxia',
                    'imagen_alt' => trim((string)($_POST['imagen_alt'] ?? $title)),
                    'url' => '/blog/' . $slug . '.html',
                    'publicado' => isset($_POST['publicado']),
                ];
                $found = false;
                foreach ($posts as $i => $p) {
                    if (($p['slug'] ?? '') === $slug) {
                        $posts[$i] = $post;
                        $found = true;
                        break;
                    }
                }
                if (!$found) $posts[] = $post;
                $mdDir = DATA_DIR . '/blog_bodies';
                if (!is_dir($mdDir)) @mkdir($mdDir, 0755, true);
                @file_put_contents($mdDir . '/' . $slug . '.md', $body, LOCK_EX);
                if ($post['publicado']) {
                    render_article_file($post, $body !== '' ? $body : $post['resumen']);
                }
                save_posts($posts);
                update_sitemap($posts);
                log_event('blog_admin', 'Saved post: ' . $slug);
                $flash = 'Artículo guardado: ' . $slug;
                $view = 'list';
                $editSlug = '';
            }
        }
    }

    if ($action === 'delete' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido.';
            $flashErr = true;
            $view = 'list';
        } else {
            $slug = slugify((string)($_POST['slug'] ?? ''));
            $posts = array_values(array_filter(load_posts(), fn($p) => ($p['slug'] ?? '') !== $slug));
            save_posts($posts);
            update_sitemap($posts);
            $html = dirname(__DIR__, 2) . '/blog/' . $slug . '.html';
            if (is_file($html)) @unlink($html);
            $md = DATA_DIR . '/blog_bodies/' . $slug . '.md';
            if (is_file($md)) @unlink($md);
            log_event('blog_admin', 'Deleted post: ' . $slug);
            $flash = 'Artículo eliminado: ' . $slug;
            $view = 'list';
        }
    }

    if ($action === 'probe_token' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido.';
            $flashErr = true;
        } else {
            $p = gmail_probe_token();
            $flash = $p['msg'];
            $flashErr = !$p['ok'];
        }
        $view = 'mail';
    }

    if ($action === 'test_mail' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido.';
            $flashErr = true;
        } else {
            try {
                $mail = create_mailer();
                netxia_add_admin_recipients($mail, 'Netxia');
                $mail->Subject = '✅ Test Netxia admin ' . date('H:i:s');
                $mail->isHTML(true);
                $dest = implode(', ', netxia_admin_emails());
                $mail->Body = '<p>Prueba desde el panel admin de Netxia.</p><p>Destinos: ' . htmlspecialchars($dest) . '</p><p>' . date('c') . '</p>';
                $mail->AltBody = 'Prueba Netxia ' . date('c');
                $via = null;
                $errors = null;
                $ok = netxia_send($mail, 'admin_test', $via, $errors);
                if ($ok) {
                    $flash = "Email de prueba OK vía $via → $dest";
                } else {
                    $flash = 'Fallo envío: ' . ($errors ?: 'desconocido');
                    $flashErr = true;
                }
            } catch (\Throwable $e) {
                $flash = $e->getMessage();
                $flashErr = true;
            }
        }
        $view = 'mail';
    }

    if ($action === 'resend_lead' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido.';
            $flashErr = true;
        } else {
            $type = (string)($_POST['lead_type'] ?? 'requirements');
            if (!in_array($type, ['requirements', 'applications'], true)) {
                $type = 'requirements';
            }
            $id = (string)($_POST['lead_id'] ?? '');
            $r = lead_resend($type, $id);
            $flash = $r['msg'];
            $flashErr = !$r['ok'];
        }
        $view = 'leads';
    }
}

$editPost = [
    'slug' => '', 'titulo' => '', 'resumen' => '', 'categoria' => 'Inteligencia Artificial',
    'fecha' => date('Y-m-d'), 'lectura' => '5 min', 'autor' => 'Equipo Netxia',
    'imagen_alt' => '', 'publicado' => true, 'cuerpo' => '',
];
if ($view === 'edit' && admin_logged_in()) {
    foreach (load_posts() as $p) {
        if (($p['slug'] ?? '') === $editSlug) {
            $editPost = array_merge($editPost, $p);
            $mdFile = DATA_DIR . '/blog_bodies/' . $editSlug . '.md';
            if (is_file($mdFile)) $editPost['cuerpo'] = (string)file_get_contents($mdFile);
            break;
        }
    }
    if ($editSlug === 'new') {
        $editPost['slug'] = '';
        $editPost['fecha'] = date('Y-m-d');
    }
}

if ($view === 'mail' && admin_logged_in()) {
    $mailHealth = netxia_mail_health(false);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$__csrf = htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin · Netxia</title>
  <style>
    :root { --bg:#06091A; --card:#0D1230; --border:#1a2040; --text:#EEF2FF; --muted:#8B9DC3; --cyan:#00D2FF; --ok:#00E887; --err:#ff6b7a; --warn:#FFB800; }
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
    .wrap{max-width:980px;margin:0 auto;padding:1.5rem}
    h1{font-size:1.35rem;margin:0 0 1rem}
    .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1rem}
    label{display:block;font-size:.85rem;color:var(--muted);margin:.75rem 0 .3rem}
    input,select,textarea{width:100%;padding:.65rem .75rem;border-radius:8px;border:1px solid var(--border);background:#080C20;color:var(--text);font:inherit}
    textarea{min-height:220px;font-family:ui-monospace,monospace;font-size:.9rem}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    @media(max-width:640px){.row{grid-template-columns:1fr}}
    .btn{display:inline-block;padding:.55rem .9rem;border-radius:8px;border:0;background:var(--cyan);color:#06091A;font-weight:700;cursor:pointer;text-decoration:none;font-size:.9rem}
    .btn.secondary{background:transparent;border:1px solid var(--border);color:var(--text)}
    .btn.danger{background:var(--err);color:#fff}
    .btn + .btn, form.inline{margin-left:.35rem}
    form.inline{display:inline}
    .flash{padding:.75rem 1rem;border-radius:8px;background:rgba(0,232,135,.12);border:1px solid var(--ok);color:var(--ok);margin-bottom:1rem}
    .flash.err{background:rgba(255,107,122,.12);border-color:var(--err);color:var(--err)}
    table{width:100%;border-collapse:collapse;font-size:.88rem}
    th,td{text-align:left;padding:.55rem .4rem;border-bottom:1px solid var(--border);vertical-align:top}
    th{color:var(--muted);font-weight:600}
    .muted{color:var(--muted);font-size:.85rem}
    .top{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
    .nav{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
    .nav a{padding:.4rem .75rem;border-radius:8px;border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:.9rem}
    .nav a.active{border-color:var(--cyan);color:var(--cyan)}
    .check{display:flex;align-items:center;gap:.5rem;margin-top:1rem}
    .check input{width:auto}
    .badge{display:inline-block;padding:.15rem .45rem;border-radius:6px;font-size:.75rem;font-weight:600}
    .badge.ok{background:rgba(0,232,135,.15);color:var(--ok)}
    .badge.err{background:rgba(255,107,122,.15);color:var(--err)}
    .badge.warn{background:rgba(255,184,0,.15);color:var(--warn)}
    .stat{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
    .stat div{background:#080C20;border:1px solid var(--border);border-radius:8px;padding:.75rem}
    .stat b{display:block;font-size:1.1rem}
    pre.logs{background:#080C20;border-radius:8px;padding:.75rem;font-size:.75rem;color:var(--muted);max-height:180px;overflow:auto;white-space:pre-wrap}
    .truncate{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  </style>
</head>
<body>
<div class="wrap">
<?php if ($view === 'login' || !admin_logged_in()): ?>
  <h1>Admin · Netxia</h1>
  <?php if ($flash): ?><div class="flash <?= $flashErr ? 'err' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <div class="card" style="max-width:400px">
    <?php if (!$adminPassConfigured && IS_LOCAL): ?>
      <p class="muted">Modo local: usa <code>dev-only-local</code> o configura <code>BLOG_ADMIN_PASS</code>.</p>
    <?php else: ?>
      <p class="muted">Contraseña de <code>BLOG_ADMIN_PASS</code> en config.php.</p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <label>Contraseña</label>
      <input type="password" name="password" required autofocus>
      <p style="margin-top:1rem"><button class="btn" type="submit">Entrar</button></p>
    </form>
  </div>
<?php else:
  admin_require_login();
  $nav = $view;
  if ($nav === 'edit') $nav = 'list';
?>
  <div class="top">
    <h1>Admin · Netxia</h1>
    <form method="post" class="inline" onsubmit="return confirm('¿Cerrar sesión?')">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <button class="btn secondary" type="submit">Salir</button>
    </form>
  </div>
  <nav class="nav">
    <a href="?view=list" class="<?= $nav === 'list' ? 'active' : '' ?>">Blog</a>
    <a href="?view=leads" class="<?= $nav === 'leads' ? 'active' : '' ?>">Leads</a>
    <a href="?view=mail" class="<?= $nav === 'mail' ? 'active' : '' ?>">Correo</a>
  </nav>
  <?php if ($flash): ?><div class="flash <?= $flashErr ? 'err' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if ($view === 'edit'): ?>
  <div class="top">
    <h1 style="font-size:1.15rem"><?= $editSlug === 'new' || $editSlug === '' ? 'Nuevo artículo' : 'Editar: ' . htmlspecialchars($editSlug) ?></h1>
    <a class="btn secondary" href="?view=list">← Lista</a>
  </div>
  <form method="post" class="card">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
    <label>Título *</label>
    <input name="titulo" required value="<?= htmlspecialchars((string)$editPost['titulo']) ?>">
    <div class="row">
      <div>
        <label>Slug (URL, sin .html)</label>
        <input name="slug" placeholder="se-genera-del-titulo" value="<?= htmlspecialchars((string)$editPost['slug']) ?>">
      </div>
      <div>
        <label>Categoría</label>
        <select name="categoria">
          <?php foreach (['Inteligencia Artificial','Ciberseguridad','Cloud & DevOps'] as $c): ?>
            <option <?= (($editPost['categoria'] ?? '') === $c) ? 'selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <label>Resumen</label>
    <textarea name="resumen" style="min-height:80px"><?= htmlspecialchars((string)$editPost['resumen']) ?></textarea>
    <div class="row">
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= htmlspecialchars((string)$editPost['fecha']) ?>"></div>
      <div><label>Lectura</label><input name="lectura" value="<?= htmlspecialchars((string)$editPost['lectura']) ?>"></div>
    </div>
    <div class="row">
      <div><label>Autor</label><input name="autor" value="<?= htmlspecialchars((string)$editPost['autor']) ?>"></div>
      <div><label>Alt imagen</label><input name="imagen_alt" value="<?= htmlspecialchars((string)($editPost['imagen_alt'] ?? '')) ?>"></div>
    </div>
    <label>Cuerpo (Markdown: ##, -, **negrita**)</label>
    <textarea name="cuerpo"><?= htmlspecialchars((string)($editPost['cuerpo'] ?? '')) ?></textarea>
    <label class="check"><input type="checkbox" name="publicado" <?= !empty($editPost['publicado']) || !isset($editPost['publicado']) ? 'checked' : '' ?>> Publicado</label>
    <p style="margin-top:1.25rem">
      <button class="btn" type="submit">Guardar y generar HTML</button>
      <a class="btn secondary" href="?view=list">Cancelar</a>
    </p>
  </form>

<?php elseif ($view === 'leads'):
  $reqs = leads_list('requirements', 8);
  $apps = leads_list('applications', 8);
  $all = array_merge(
      array_map(fn($r) => $r + ['_kind' => 'Cotización', '_type' => 'requirements'], $reqs),
      array_map(fn($r) => $r + ['_kind' => 'Postulación', '_type' => 'applications'], $apps)
  );
  usort($all, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));
  $pending = count(array_filter($all, fn($r) => $r['notificado'] !== true));
?>
  <div class="stat" style="margin-bottom:1rem">
    <div><span class="muted">Leads listados</span><b><?= count($all) ?></b></div>
    <div><span class="muted">Sin notificar / legacy</span><b style="color:<?= $pending ? 'var(--err)' : 'var(--ok)' ?>"><?= $pending ?></b></div>
  </div>
  <div class="card">
    <?php if (!$all): ?>
      <p class="muted">No hay leads en <code>data/requirements</code> ni <code>data/applications</code>.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Contacto</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($all, 0, 80) as $r):
        $id = (string)($r['id'] ?? '');
        $type = (string)($r['_type'] ?? 'requirements');
        $n = $r['notificado'];
        if ($n === true) {
            $badge = '<span class="badge ok">Notificado' . (!empty($r['notif_via']) ? ' · ' . htmlspecialchars((string)$r['notif_via']) : '') . '</span>';
        } elseif ($n === false) {
            $badge = '<span class="badge err">No notificado</span>';
        } else {
            $badge = '<span class="badge warn">Legacy</span>';
        }
        $who = $type === 'applications'
            ? htmlspecialchars((string)($r['nombre'] ?? '')) . ' · ' . htmlspecialchars((string)($r['cargo'] ?? ''))
            : htmlspecialchars((string)($r['empresa'] ?? '')) . ' · ' . htmlspecialchars((string)($r['nombre'] ?? ''));
        $mail = htmlspecialchars((string)($r['email'] ?? ''));
        $err = (string)($r['notif_error'] ?? '');
      ?>
        <tr>
          <td class="muted" style="white-space:nowrap"><?= htmlspecialchars(substr((string)($r['fecha'] ?? ''), 0, 16)) ?></td>
          <td><?= htmlspecialchars((string)($r['_kind'] ?? '')) ?></td>
          <td>
            <div><?= $who ?></div>
            <div class="muted truncate" title="<?= $mail ?>"><?= $mail ?></div>
            <?php if ($err && $n !== true): ?><div class="muted" style="color:var(--err);font-size:.75rem"><?= htmlspecialchars(mb_substr($err, 0, 120)) ?></div><?php endif; ?>
          </td>
          <td><?= $badge ?></td>
          <td style="white-space:nowrap">
            <form method="post" class="inline" onsubmit="return confirm('¿Reenviar notificación de este lead?')">
              <input type="hidden" name="action" value="resend_lead">
              <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
              <input type="hidden" name="lead_type" value="<?= htmlspecialchars($type) ?>">
              <input type="hidden" name="lead_id" value="<?= htmlspecialchars($id) ?>">
              <button class="btn secondary" type="submit">Reintentar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <p class="muted">Los leads se guardan siempre en JSON aunque falle el correo. Reintenta cuando el token Gmail esté OK (pestaña Correo).</p>

<?php elseif ($view === 'mail'):
  $h = $mailHealth ?? netxia_mail_health(false);
?>
  <div class="card">
    <h2 style="margin:0 0 1rem;font-size:1.05rem">Salud del correo</h2>
    <div class="stat">
      <div>
        <span class="muted">Gmail API</span>
        <b class="<?= $h['gmail_api_configured'] ? '' : '' ?>" style="color:<?= $h['gmail_api_configured'] ? 'var(--ok)' : 'var(--err)' ?>">
          <?= $h['gmail_api_configured'] ? 'Configurado' : 'Sin GMAIL_*' ?>
        </b>
      </div>
      <div>
        <span class="muted">SMTP_PASS</span>
        <b style="color:<?= $h['smtp_pass_set'] ? 'var(--ok)' : 'var(--muted)' ?>"><?= $h['smtp_pass_set'] ? 'Set (cascada 2ª)' : 'Vacío' ?></b>
      </div>
      <div>
        <span class="muted">ADMIN_EMAIL</span>
        <b style="font-size:.95rem"><?= htmlspecialchars((string)$h['admin_email']) ?></b>
      </div>
      <div>
        <span class="muted">ADMIN_EMAIL_COPY</span>
        <b style="font-size:.95rem;color:<?= $h['admin_email_copy'] ? 'var(--ok)' : 'var(--warn)' ?>">
          <?= $h['admin_email_copy'] ? htmlspecialchars((string)$h['admin_email_copy']) : 'No configurado' ?>
        </b>
      </div>
    </div>
    <p class="muted" style="margin-top:1rem">Destinatarios efectivos: <code><?= htmlspecialchars(implode(', ', $h['recipients']) ?: '—') ?></code></p>
    <p style="margin-top:1rem">
      <form method="post" class="inline">
        <input type="hidden" name="action" value="probe_token">
        <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
        <button class="btn" type="submit">Probar access token</button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="test_mail">
        <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
        <button class="btn secondary" type="submit">Enviar email de prueba</button>
      </form>
    </p>
  </div>
  <div class="card">
    <h2 style="margin:0 0 .75rem;font-size:1.05rem">Últimos errores de email</h2>
    <?php if (empty($h['last_email_errors'])): ?>
      <p class="muted">Sin entradas en <code>logs/email_errors.log</code>.</p>
    <?php else: ?>
      <pre class="logs"><?= htmlspecialchars(implode("\n", $h['last_email_errors'])) ?></pre>
    <?php endif; ?>
  </div>
  <div class="card">
    <p class="muted" style="margin:0">Configura en el servidor <code>php/config.php</code> (no versionado):</p>
    <pre class="logs" style="margin-top:.75rem">define('GMAIL_CLIENT_ID', '….apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', '…');
define('GMAIL_REFRESH_TOKEN', '1//0…');
define('ADMIN_EMAIL', 'contacto@netxia.cl');
define('ADMIN_EMAIL_COPY', 'tu-respaldo@gmail.com'); // ≠ netxia.chile@gmail.com</pre>
    <p class="muted">Guía: <code>HOTFIX_MAIL.md</code> · App OAuth en <strong>Producción</strong> (no Testing).</p>
  </div>

<?php else:
  $posts = load_posts();
?>
  <div class="top">
    <h1 style="font-size:1.15rem">Artículos del blog (<?= count($posts) ?>)</h1>
    <a class="btn" href="?view=edit&slug=new">+ Nuevo</a>
  </div>
  <div class="card">
    <?php if (!$posts): ?>
      <p class="muted">No hay artículos. Crea el primero.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Título</th><th>Categoría</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p):
        $rawSlug = (string)($p['slug'] ?? '');
        $s = htmlspecialchars($rawSlug, ENT_QUOTES, 'UTF-8');
        $viewUrl = htmlspecialchars(admin_blog_url($rawSlug), ENT_QUOTES, 'UTF-8');
      ?>
        <tr>
          <td class="muted"><?= htmlspecialchars((string)($p['fecha'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($p['titulo'] ?? '')) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($p['categoria'] ?? '')) ?></td>
          <td><?= (($p['publicado'] ?? true) !== false) ? '✅' : '⏸' ?></td>
          <td style="white-space:nowrap">
            <a class="btn secondary" href="?view=edit&slug=<?= urlencode($rawSlug) ?>">Editar</a>
            <a class="btn secondary" href="<?= $viewUrl ?>" target="_blank" rel="noopener">Ver</a>
            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar <?= $s ?>?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
              <input type="hidden" name="slug" value="<?= $s ?>">
              <button class="btn danger" type="submit">Borrar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
  <p class="muted">50webs Free · Gmail API HTTPS · Blog JSON · Leads reintentables</p>
<?php endif; ?>
</div>
</body>
</html>
