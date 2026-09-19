# Deploy producción — netxia.cl (50webs Free)

Rama canónica: **`fix/portal-blog-admin`** (blog + Gmail API + admin leads).

## Ramas

| Rama | Uso |
|---|---|
| `main` | Base antigua (SMTP; blog roto en prod) |
| `fix/portal-blog-admin` | **Deploy** |
| `hotfix/mail-gmail-api` | Apunta al mismo mail stack; no despliegues aparte |
| `codex/generate-virtual-agent-for-ai-services` | Prototipo aparte — **no mergear** |

## No pisar en el servidor
- `php/config.php`
- `data/requirements/`, `data/applications/`, `data/sessions/`
- `uploads/cv/*`, `logs/*`

## Subir
- `php/mailer.php`, `php/submit_requirements.php`, `php/submit_job.php`, `php/admin/index.php`
- resto del portal (`.htaccess`, `js/main.js`, blog, etc.) si aún no está

## config.php (añadir si falta)
```php
define('GMAIL_CLIENT_ID', '…');
define('GMAIL_CLIENT_SECRET', '…');
define('GMAIL_REFRESH_TOKEN', '…');
define('ADMIN_EMAIL_COPY', 'respaldo@gmail.com'); // ≠ netxia.chile@gmail.com
define('BLOG_ADMIN_PASS', '…');
```

## Post-deploy
1. Admin → Correo → Probar token + email de prueba
2. Cotización y postulación reales
3. Leads → Notificado
4. Borrar `test_email.php` / probes

Ver `HOTFIX_MAIL.md`.
