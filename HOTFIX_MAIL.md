# HOTFIX — Correo (Gmail API por HTTPS)

El hosting solo permite salida a hosts Google. SMTP da `Permission denied (13)`.

## Código
- `php/mailer.php` — cascada Gmail API → SMTP → mail()
- Leads con `notificado` / reintento en admin
- `ADMIN_EMAIL_COPY` para respaldo (Gmail distinto del From)

## Config (servidor)
1. Google Cloud: Gmail API + OAuth app en **Producción** + scope `gmail.send`
2. OAuth Playground → refresh token
3. En `php/config.php`:
```php
define('GMAIL_CLIENT_ID', '….apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', '…');
define('GMAIL_REFRESH_TOKEN', '1//…');
define('ADMIN_EMAIL', 'contacto@netxia.cl');
define('ADMIN_EMAIL_COPY', 'tu-respaldo@gmail.com');
```

## Probar
Admin `/php/admin/` → **Correo** → token + test send → formularios reales → **Leads**.

## Si falla
| Síntoma | Acción |
|---|---|
| invalid_grant | Token mal / app en Testing |
| Couldn't connect | Allowlist — VPS |
| send OK, no llega a contacto@ | Webmail 50webs; revisa COPY |
