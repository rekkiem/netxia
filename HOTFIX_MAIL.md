# HOTFIX — Correo de formularios (Gmail API por HTTPS)

**Causa:** el hosting solo permite salida a una lista blanca (`gmail.googleapis.com`, `www.googleapis.com`).
SMTP (Gmail, Brevo, local) y `oauth2.googleapis.com` dan `errno 13`. La rama `fix/portal-blog-admin` no causó esto.

**Solución:** enviar con la **Gmail API** (HTTPS) y renovar el token contra `www.googleapis.com/oauth2/v4/token`.
Cascada: Gmail API → SMTP → `mail()`. Si todo falla, queda en `logs/email_errors.log` (el lead ya está guardado en `data/`).

## 1. Credenciales de Google (una vez, ~10 min) — con la cuenta netxia.chile@gmail.com
1. https://console.cloud.google.com → proyecto nuevo `netxia-mail`.
2. *APIs y servicios → Biblioteca* → habilitar **Gmail API**.
3. *Pantalla de consentimiento OAuth* → Externo → nombre y correo → agregar el permiso `.../auth/gmail.send`
   → **Publicar la app ("En producción")**. En modo *Testing* el refresh token muere a los 7 días.
4. *Credenciales → Crear ID de cliente OAuth → Aplicación web* →
   URI de redirección autorizada: `https://developers.google.com/oauthplayground` → copiar **Client ID** y **Client secret**.
5. https://developers.google.com/oauthplayground → ⚙ → *Use your own OAuth credentials* → pegar ID y secret →
   en Step 1 escribir el scope `https://www.googleapis.com/auth/gmail.send` → *Authorize APIs* → entrar con netxia.chile@gmail.com
   (si dice "app no verificada": *Avanzado → Ir a…*) → Step 2 *Exchange authorization code for tokens* → copiar **Refresh token** (`1//0...`).
6. En el `php/config.php` del servidor (no versionado) agregar:
```php
define('GMAIL_CLIENT_ID',     'xxxx.apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', 'xxxx');
define('GMAIL_REFRESH_TOKEN', '1//0xxxx');
```

## 2. Subir por FTP (solo estos 4)
`php/mailer.php` · `php/submit_requirements.php` · `php/submit_job.php` · `php/mail_test.php`
(en `mail_test.php` cambia `CAMBIA_ESTO` por una cadena larga).

## 3. Probar
- `https://netxia.cl/php/mail_test.php?k=TOKEN` → debe decir `Access token … OK`
- `https://netxia.cl/php/mail_test.php?k=TOKEN&send=1` → `OK vía gmail_api` y llega a contacto@netxia.cl
- Luego enviar una cotización y una postulación reales desde el sitio.

## 4. Limpiar
Borrar del servidor: `mail_test.php`, `net_probe.php`, `net_probe2.php`, `test_email.php`.

## Si el paso 3 falla
- `token: ... HTTP 400 invalid_grant` → refresh token mal copiado o app aún en *Testing*.
- `token: ... Couldn't connect` → el hosting también bloquea el endpoint de tokens en `www.googleapis.com`:
  no hay ruta de correo saliente en ese hosting → migrar el sitio a la VPS.

## Recuperar leads de las pruebas fallidas (FTP)
`data/requirements/AAAA-MM.json` (cotizaciones) · `data/applications/AAAA-MM.json` (postulaciones) · `uploads/cv/`
