<?php
/**
 * NETXIA Blog Admin — panel mínimo compatible con 50webs Free
 * URL: https://netxia.cl/php/admin/
 * Sin MySQL · edita data/blog.json · genera HTML estático en /blog/
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

// ── Password: define('BLOG_ADMIN_PASS', '...') en config.php (texto plano o hash bcrypt)
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

function admin_login_limited(): bool {
    return count(admin_recent_failed_logins()) >= 8;
}

function admin_record_failed_login(): void {
    $f = admin_rate_file();
    $data = admin_recent_failed_logins();
    $now = time();
    $data[] = $now;
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

function blog_path(): string {
    return DATA_DIR . '/blog.json';
}

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
        '{{TITLE}}'       => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        '{{TITLE_HTML}}'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        '{{SUMMARY}}'     => htmlspecialchars((string)($post['resumen'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{SLUG}}'        => htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'),
        '{{CATEGORY}}'    => htmlspecialchars((string)($post['categoria'] ?? 'Blog'), ENT_QUOTES, 'UTF-8'),
        '{{AUTHOR}}'      => htmlspecialchars((string)($post['autor'] ?? 'Equipo Netxia'), ENT_QUOTES, 'UTF-8'),
        '{{READ_TIME}}'   => htmlspecialchars((string)($post['lectura'] ?? '5 min'), ENT_QUOTES, 'UTF-8'),
        '{{DATE_HUMAN}}'  => htmlspecialchars(date('d/m/Y', $ts), ENT_QUOTES, 'UTF-8'),
        '{{BODY_HTML}}'   => markdown_lite($bodyMd),
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
        $urls[] = [
            'loc' => 'https://netxia.cl/blog/' . $slug . '.html',
            'lastmod' => $p['fecha'] ?? date('Y-m-d'),
            'prio' => '0.8',
        ];
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


function admin_retry_lead(string $type, string $id): array {
    $lead = lead_find($type, $id);
    if (!$lead) return ['ok' => false, 'msg' => 'Lead no encontrado'];
    try {
        $mail = create_mailer();
        if ($type === 'requirements') {
            netxia_add_admin_recipients($mail, 'Netxia');
            $mail->addReplyTo((string)($lead['email'] ?? ''), (string)($lead['nombre'] ?? ''));
            $empresa = (string)($lead['empresa'] ?? '');
            $servicio = (string)($lead['servicio'] ?? '');
            $mail->Subject = "🔔 Requerimiento (reintento): $empresa ($servicio)";
            $mail->isHTML(true);
            $mail->Body = '<p><strong>Reintento</strong></p><p>' . htmlspecialchars($empresa) . ' — '
                . htmlspecialchars((string)($lead['nombre'] ?? '')) . '<br>'
                . htmlspecialchars((string)($lead['email'] ?? '')) . '</p><p>'
                . nl2br(htmlspecialchars((string)($lead['detalle'] ?? ''))) . '</p><p>ID: '
                . htmlspecialchars($id) . '</p>';
            $mail->AltBody = 'Reintento ' . $id;
            $log = 'requirements';
        } else {
            netxia_add_admin_recipients($mail, 'Netxia RRHH');
            $mail->addReplyTo((string)($lead['email'] ?? ''), (string)($lead['nombre'] ?? ''));
            $nombre = (string)($lead['nombre'] ?? '');
            $cargo = (string)($lead['cargo'] ?? '');
            $cv = (string)($lead['cv_archivo'] ?? '');
            if ($cv !== '' && is_file(UPLOAD_DIR . '/' . $cv)) {
                $mail->addAttachment(UPLOAD_DIR . '/' . $cv, 'CV_' . $nombre);
            }
            $mail->Subject = "👤 Postulación (reintento): $nombre — $cargo";
            $mail->isHTML(true);
            $mail->Body = '<p><strong>Reintento</strong></p><p>' . htmlspecialchars($nombre) . ' — '
                . htmlspecialchars($cargo) . '</p><p>'
                . nl2br(htmlspecialchars((string)($lead['carta'] ?? ''))) . '</p><p>ID: '
                . htmlspecialchars($id) . '</p>';
            $mail->AltBody = 'Reintento job ' . $id;
            $log = 'jobs';
        }
        $via = null; $errors = null;
        $ok = netxia_send($mail, $log, $via, $errors);
        lead_set_notification($type, $id, $ok, $via, $ok ? null : $errors);
        return $ok ? ['ok' => true, 'msg' => "Notificado vía $via"] : ['ok' => false, 'msg' => $errors ?: 'Fallo'];
    } catch (Throwable $e) {
        lead_set_notification($type, $id, false, null, $e->getMessage());
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

$flash = '';
$flashErr = false;
$leadFilter = $_GET['filter'] ?? 'all';
$view  = $_GET['view'] ?? (admin_logged_in() ? 'leads' : 'login');
$editSlug = $_GET['slug'] ?? '';

// ── Actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        if (!admin_csrf_ok()) {
            $flash = 'Sesión expirada. Recarga e intenta de nuevo.';
            $view = 'login';
        } elseif (admin_login_limited()) {
            $flash = 'Demasiados intentos. Espera una hora o contacta al admin.';
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
                header('Location: index.php?view=leads');
                exit;
            }
            admin_record_failed_login();
            $flash = 'Contraseña incorrecta.';
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
            $view = 'list';
        } else {
        $posts = load_posts();
        $slug  = trim((string)($_POST['slug'] ?? ''));
        $title = trim((string)($_POST['titulo'] ?? ''));
        if ($title === '') {
            $flash = 'El título es obligatorio.';
            $view = 'edit';
        } else {
            if ($slug === '') $slug = slugify($title);
            $slug = slugify($slug);

            $body = (string)($_POST['cuerpo'] ?? '');
            $post = [
                'slug'       => $slug,
                'titulo'     => $title,
                'resumen'    => trim((string)($_POST['resumen'] ?? '')),
                'categoria'  => trim((string)($_POST['categoria'] ?? 'Inteligencia Artificial')),
                'fecha'      => trim((string)($_POST['fecha'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'lectura'    => trim((string)($_POST['lectura'] ?? '5 min')) ?: '5 min',
                'autor'      => trim((string)($_POST['autor'] ?? 'Equipo Netxia')) ?: 'Equipo Netxia',
                'imagen_alt' => trim((string)($_POST['imagen_alt'] ?? $title)),
                'url'        => '/blog/' . $slug . '.html',
                'publicado'  => isset($_POST['publicado']),
            ];

            // Upsert by slug
            $found = false;
            foreach ($posts as $i => $p) {
                if (($p['slug'] ?? '') === $slug) {
                    $posts[$i] = $post;
                    $found = true;
                    break;
                }
            }
            if (!$found) $posts[] = $post;

            // Persist body markdown alongside JSON for re-editing
            $mdDir = DATA_DIR . '/blog_bodies';
            if (!is_dir($mdDir)) @mkdir($mdDir, 0755, true);
            @file_put_contents($mdDir . '/' . $slug . '.md', $body, LOCK_EX);

            if ($post['publicado']) {
                render_article_file($post, $body !== '' ? $body : $post['resumen']);
            } else {
                // unpublish: optional leave file but hide from index
            }

            save_posts($posts);
            update_sitemap($posts);
            log_event('blog_admin', 'Saved post: ' . $slug);
            $flash = 'Artículo guardado: ' . $slug;
            $view = 'list';
            $editSlug = '';
        }
        } // csrf ok
    }

    if ($action === 'delete' && admin_logged_in()) {
        if (!admin_csrf_ok()) {
            $flash = 'Token inválido. Recarga la página.';
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
}


    if ($action === 'retry_lead' && admin_logged_in()) {
        $view = 'leads';
        if (!admin_csrf_ok()) { $flash = 'Token inválido.'; $flashErr = true; }
        else {
            $type = ($_POST['lead_type'] ?? '') === 'applications' ? 'applications' : 'requirements';
            $res = admin_retry_lead($type, (string)($_POST['lead_id'] ?? ''));
            $flash = $res['msg']; $flashErr = !$res['ok'];
        }
    }
    if ($action === 'probe_token' && admin_logged_in()) {
        $view = 'mail';
        if (!admin_csrf_ok()) { $flash = 'Token inválido.'; $flashErr = true; }
        else {
            $p = gmail_probe_token();
            $flash = $p['msg']; $flashErr = !$p['ok'];
        }
    }
    if ($action === 'test_send' && admin_logged_in()) {
        $view = 'mail';
        if (!admin_csrf_ok()) { $flash = 'Token inválido.'; $flashErr = true; }
        else {
            try {
                $mail = create_mailer();
                netxia_add_admin_recipients($mail, 'Netxia');
                $mail->Subject = '✅ Test Netxia admin ' . date('H:i:s');
                $mail->isHTML(true);
                $mail->Body = '<p>Prueba panel admin — ' . date('c') . '</p>';
                $mail->AltBody = 'Test Netxia';
                $via = null; $errors = null;
                $ok = netxia_send($mail, 'test', $via, $errors);
                $flash = $ok ? ("OK vía $via → " . implode(', ', netxia_admin_emails())) : ('FALLÓ: ' . $errors);
                $flashErr = !$ok;
            } catch (Throwable $e) {
                $flash = $e->getMessage(); $flashErr = true;
            }
        }
    }

// Load edit form data
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

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin · Netxia</title>
  <style>
    :root { --bg:#06091A; --card:#0D1230; --border:#1a2040; --text:#EEF2FF; --muted:#8B9DC3; --cyan:#00D2FF; --ok:#00E887; --err:#ff6b7a; }
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
    .wrap{max-width:900px;margin:0 auto;padding:1.5rem}
    h1{font-size:1.4rem;margin:0 0 1rem}
    .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:1rem}
    label{display:block;font-size:.85rem;color:var(--muted);margin:.75rem 0 .3rem}
    input,select,textarea{width:100%;padding:.65rem .75rem;border-radius:8px;border:1px solid var(--border);background:#080C20;color:var(--text);font:inherit}
    textarea{min-height:220px;font-family:ui-monospace,monospace;font-size:.9rem}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    @media(max-width:640px){.row{grid-template-columns:1fr}}
    .btn{display:inline-block;padding:.6rem 1rem;border-radius:8px;border:0;background:var(--cyan);color:#06091A;font-weight:700;cursor:pointer;text-decoration:none}
    .btn.secondary{background:transparent;border:1px solid var(--border);color:var(--text)}
    .btn.danger{background:var(--err);color:#fff}
    .btn + .btn{margin-left:.5rem}
    .flash{padding:.75rem 1rem;border-radius:8px;background:rgba(0,232,135,.12);border:1px solid var(--ok);color:var(--ok);margin-bottom:1rem}
    .flash.err{background:rgba(255,107,122,.12);border-color:var(--err);color:var(--err)}
    table{width:100%;border-collapse:collapse;font-size:.9rem}
    th,td{text-align:left;padding:.6rem;border-bottom:1px solid var(--border)}
    th{color:var(--muted);font-weight:600}
    .muted{color:var(--muted);font-size:.85rem}
    .top{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
    .check{display:flex;align-items:center;gap:.5rem;margin-top:1rem}
    .check input{width:auto}

    .nav{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
    .nav a{padding:.4rem .8rem;border:1px solid var(--border);border-radius:8px;color:var(--text);text-decoration:none;font-size:.9rem}
    .nav a.active{border-color:var(--cyan);color:var(--cyan)}
    .badge{display:inline-block;padding:.12rem .4rem;border-radius:6px;font-size:.75rem;font-weight:600}
    .badge.ok{background:rgba(0,232,135,.15);color:var(--ok)}
    .badge.bad{background:rgba(255,107,122,.15);color:var(--err)}
    .badge.warn{background:rgba(255,184,0,.12);color:#FFB800}
    form.inline{display:inline;margin-left:.3rem}
    pre.log{background:#080C20;border-radius:8px;padding:.75rem;font-size:.75rem;color:var(--muted);overflow:auto;max-height:200px;white-space:pre-wrap}
    .flash.err{background:rgba(255,107,122,.12);border-color:var(--err);color:var(--err)}
  </style>
</head>
<body>
<div class="wrap">
<?php $__csrf = htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>
<?php if ($view === 'login' || !admin_logged_in()): ?>
  <h1>Admin Blog · Netxia</h1>
  <?php if ($flash): ?><div class="flash <?= !empty($flashErr) ? 'err' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <div class="card" style="max-width:400px">
    <?php if (!$adminPassConfigured && IS_LOCAL): ?>
      <p class="muted">Modo local sin <code>BLOG_ADMIN_PASS</code>: usa <code>dev-only-local</code> o configura una clave en <code>php/config.php</code>.</p>
    <?php else: ?>
      <p class="muted">Ingresa la contraseña definida en <code>BLOG_ADMIN_PASS</code> (config.php).</p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <label>Contraseña</label>
      <input type="password" name="password" required autofocus>
      <p style="margin-top:1rem"><button class="btn" type="submit">Entrar</button></p>
    </form>
  </div>
<?php else: admin_require_login(); ?>
  <div class="nav">
    <a href="?view=leads" class="<?= $view === 'leads' ? 'active' : '' ?>">Leads</a>
    <a href="?view=mail" class="<?= $view === 'mail' ? 'active' : '' ?>">Correo</a>
    <a href="?view=list" class="<?= in_array($view, ['list','edit'], true) ? 'active' : '' ?>">Blog</a>
    <form method="post" class="inline" style="margin-left:auto">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <button class="btn secondary" type="submit">Salir</button>
    </form>
  </div>
  <?php if ($flash): ?><div class="flash <?= !empty($flashErr) ? 'err' : '' ?>"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if ($view === 'leads'):
  $reqs = leads_list('requirements');
  $apps = leads_list('applications');
  $all = array_merge(
    array_map(fn($r) => $r + ['_kind' => 'requirements'], $reqs),
    array_map(fn($r) => $r + ['_kind' => 'applications'], $apps)
  );
  usort($all, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));
  if ($leadFilter === 'pending') $all = array_values(array_filter($all, fn($r) => ($r['notificado'] ?? false) !== true));
  elseif ($leadFilter === 'ok') $all = array_values(array_filter($all, fn($r) => ($r['notificado'] ?? false) === true));
  $pendingN = count(array_filter(array_merge($reqs, $apps), fn($r) => ($r['notificado'] ?? false) !== true));
?>
  <div class="top">
    <h1>Leads (<?= count($all) ?>) · pendientes: <?= (int)$pendingN ?></h1>
    <div>
      <a class="btn secondary" href="?view=leads&filter=all">Todos</a>
      <a class="btn secondary" href="?view=leads&filter=pending">No notificados</a>
      <a class="btn secondary" href="?view=leads&filter=ok">Notificados</a>
    </div>
  </div>
  <div class="card">
  <?php if (!$all): ?><p class="muted">Sin leads en data/.</p><?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Contacto</th><th>Correo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($all as $r):
        $kind = $r['_kind'] ?? 'requirements';
        $id = (string)($r['id'] ?? '');
        $notif = $r['notificado'] ?? null;
        if ($notif === true) $badge = '<span class="badge ok">OK' . (!empty($r['notif_via']) ? ' · ' . htmlspecialchars((string)$r['notif_via']) : '') . '</span>';
        elseif ($notif === false) $badge = '<span class="badge bad">No notificado</span>';
        else $badge = '<span class="badge warn">Sin estado</span>';
        $who = $kind === 'requirements'
          ? htmlspecialchars((string)($r['empresa'] ?? '')) . '<br><span class="muted">' . htmlspecialchars((string)($r['nombre'] ?? '')) . ' · ' . htmlspecialchars((string)($r['email'] ?? '')) . '</span>'
          : htmlspecialchars((string)($r['nombre'] ?? '')) . '<br><span class="muted">' . htmlspecialchars((string)($r['cargo'] ?? '')) . ' · ' . htmlspecialchars((string)($r['email'] ?? '')) . '</span>';
      ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr((string)($r['fecha'] ?? ''), 0, 16)) ?></td>
          <td><?= $kind === 'requirements' ? 'Cotización' : 'Postulación' ?></td>
          <td><?= $who ?></td>
          <td><?= $badge ?><?php if (!empty($r['notif_error'])): ?><div class="muted"><?= htmlspecialchars(mb_substr((string)$r['notif_error'], 0, 90)) ?></div><?php endif; ?></td>
          <td><?php if ($notif !== true): ?>
            <form method="post" class="inline" onsubmit="return confirm('¿Reintentar correo?')">
              <input type="hidden" name="action" value="retry_lead">
              <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
              <input type="hidden" name="lead_type" value="<?= htmlspecialchars($kind) ?>">
              <input type="hidden" name="lead_id" value="<?= htmlspecialchars($id) ?>">
              <button class="btn" type="submit">Reintentar</button>
            </form>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <p class="muted">Leads en JSON. El correo es notificación (reintento = Gmail API).</p>

<?php elseif ($view === 'mail'):
  $health = netxia_mail_health(false);
?>
  <h1>Salud del correo</h1>
  <div class="card">
    <p>Gmail API: <?= $health['gmail_api_configured'] ? '<span class="badge ok">credenciales presentes</span>' : '<span class="badge bad">falta GMAIL_* en config.php</span>' ?></p>
    <p>SMTP_PASS: <?= $health['smtp_pass_set'] ? '<span class="badge ok">set</span>' : '<span class="badge warn">vacío</span>' ?> (cascada 2ª, suele fallar en 50webs)</p>
    <p>ADMIN_EMAIL: <code><?= htmlspecialchars((string)$health['admin_email']) ?></code></p>
    <p>ADMIN_EMAIL_COPY: <?= $health['admin_email_copy'] ? '<code>' . htmlspecialchars((string)$health['admin_email_copy']) . '</code>' : '<span class="badge warn">no definido</span>' ?></p>
    <p>Destinatarios: <?= htmlspecialchars(implode(', ', $health['recipients']) ?: '(ninguno)') ?></p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="probe_token">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <button class="btn" type="submit">Probar access token</button>
    </form>
    <form method="post" class="inline" onsubmit="return confirm('¿Enviar email de prueba?')">
      <input type="hidden" name="action" value="test_send">
      <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
      <button class="btn secondary" type="submit">Email de prueba</button>
    </form>
  </div>
  <?php if (!empty($health['last_email_errors'])): ?>
  <div class="card">
    <p><strong>email_errors.log</strong></p>
    <pre class="log"><?= htmlspecialchars(implode("\n", $health['last_email_errors'])) ?></pre>
  </div>
  <?php endif; ?>
  <div class="card"><p class="muted">Guía: HOTFIX_MAIL.md · App OAuth en <strong>Producción</strong> · scope gmail.send</p></div>

<?php elseif ($view === 'edit'): ?>
  <div class="top">
    <h1><?= $editSlug === 'new' || $editSlug === '' ? 'Nuevo artículo' : 'Editar: ' . htmlspecialchars($editSlug) ?></h1>
    <div>
      <a class="btn secondary" href="?view=list">← Lista</a>
      <form method="post" style="display:inline" onsubmit="return confirm('¿Cerrar sesión?')">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
        <button class="btn secondary" type="submit">Salir</button>
      </form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
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
    <label>Resumen (tarjeta del listado)</label>
    <textarea name="resumen" style="min-height:80px"><?= htmlspecialchars((string)$editPost['resumen']) ?></textarea>
    <div class="row">
      <div>
        <label>Fecha</label>
        <input type="date" name="fecha" value="<?= htmlspecialchars((string)$editPost['fecha']) ?>">
      </div>
      <div>
        <label>Tiempo de lectura</label>
        <input name="lectura" value="<?= htmlspecialchars((string)$editPost['lectura']) ?>">
      </div>
    </div>
    <div class="row">
      <div>
        <label>Autor</label>
        <input name="autor" value="<?= htmlspecialchars((string)$editPost['autor']) ?>">
      </div>
      <div>
        <label>Alt imagen (accesibilidad)</label>
        <input name="imagen_alt" value="<?= htmlspecialchars((string)($editPost['imagen_alt'] ?? '')) ?>">
      </div>
    </div>
    <label>Cuerpo (Markdown simple: ## título, - lista, **negrita**)</label>
    <textarea name="cuerpo" placeholder="## Introducción&#10;&#10;Párrafo de ejemplo.&#10;&#10;- Punto uno&#10;- Punto dos"><?= htmlspecialchars((string)($editPost['cuerpo'] ?? '')) ?></textarea>
    <label class="check"><input type="checkbox" name="publicado" <?= !empty($editPost['publicado']) || !isset($editPost['publicado']) ? 'checked' : '' ?>> Publicado (visible en el sitio)</label>
    <p style="margin-top:1.25rem">
      <button class="btn" type="submit">Guardar y generar HTML</button>
      <a class="btn secondary" href="?view=list">Cancelar</a>
    </p>
    <p class="muted">Al guardar se actualiza <code>data/blog.json</code>, se genera <code>blog/slug.html</code> y se regenera el sitemap.</p>
  </form>
<?php else: $posts = load_posts(); ?>
  <div class="top">
    <h1>Artículos del blog (<?= count($posts) ?>)</h1>
    <div>
      <a class="btn" href="?view=edit&slug=new">+ Nuevo</a>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="admin_csrf" value="<?= $__csrf ?>">
        <button class="btn secondary" type="submit">Salir</button>
      </form>
    </div>
  </div>
  <?php if ($flash): ?><div class="flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
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
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar <?= $s ?>?')">
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
  <p class="muted">Hosting 50webs Free: sin MySQL. Todo vive en JSON + HTML estático. Cambia <code>BLOG_ADMIN_PASS</code> en config.php.</p>
<?php endif; /* views leads|mail|edit|list */ ?>
<?php endif; /* login */ ?>
</div>
</body>
</html>
