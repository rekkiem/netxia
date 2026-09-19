# HOTFIX — Correo de formularios (Gmail API por HTTPS)

**Causa:** 50webs aplica lista blanca de salida. Solo pasan hosts Google
(`gmail.googleapis.com`, `www.googleapis.com`). SMTP y APIs de terceros → errno 13.

**Código:** `php/mailer.php` — cascada **Gmail API → SMTP → mail()**.  
Leads se guardan **antes** de notificar. Admin: **Blog | Leads | Correo**.

Ver **DEPLOY_PROD.md** para pasos de producción.

## Config mínima en `php/config.php` (servidor)

```php
define('GMAIL_CLIENT_ID',     '….apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', '…');
define('GMAIL_REFRESH_TOKEN', '1//0…');
define('ADMIN_EMAIL',      'contacto@netxia.cl');
define('ADMIN_EMAIL_COPY', 'respaldo@gmail.com'); // ≠ netxia.chile@gmail.com
```

## Archivos

| Archivo | Rol |
|---|---|
| `php/mailer.php` | Gmail API, cascada, leads, reintento |
| `php/submit_*.php` | Persistencia + notificación + estado |
| `php/admin/index.php` | Blog + Leads + salud correo |

## Criterio de resuelto

- Formulario real llega a contacto@ **y** a COPY
- Fallo → lead "No notificado" + Reintentar en admin
- Token caído visible en pestaña Correo
- Sin scripts de diagnóstico sueltos en el servidor
