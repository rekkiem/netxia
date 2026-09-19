# Deploy producción — Netxia (correo + blog + admin)

## Problema

50webs solo permite salida HTTPS a hosts Google. SMTP a `smtp.gmail.com` → `Permission denied (13)`.

## Solución

`php/mailer.php`: **Gmail API → SMTP → mail()**. Leads con estado de notificación. Admin: Blog | Leads | Correo.

---

## 1. Credenciales Gmail API (~10 min)

1. https://console.cloud.google.com → proyecto `netxia-mail`
2. Habilitar **Gmail API**
3. OAuth consentimiento → Externo → scope `https://www.googleapis.com/auth/gmail.send`
4. **Publicar app (Producción)** — si queda en Testing el refresh token muere a los 7 días
5. Credenciales → OAuth client ID (Web) → redirect `https://developers.google.com/oauthplayground`
6. https://developers.google.com/oauthplayground → ⚙ your credentials → Authorize scope `gmail.send` → Exchange → copiar **Refresh token**

Cuenta: `netxia.chile@gmail.com`

---

## 2. Editar SOLO en el servidor: `php/config.php`

No sobrescribas este archivo desde Git. Añade:

```php
define('GMAIL_CLIENT_ID',     'xxxx.apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', 'xxxx');
define('GMAIL_REFRESH_TOKEN', '1//0xxxx');

define('ADMIN_EMAIL',      'contacto@netxia.cl');
// Copia a un Gmail DISTINTO del remitente (netxia.chile@gmail.com)
define('ADMIN_EMAIL_COPY', 'tu-respaldo-personal@gmail.com');

define('BLOG_ADMIN_PASS', '…'); // o password_hash(...)
// SMTP_* puede quedarse (cascada 2ª; en 50webs suele fallar)
```

---

## 3. Subir por FTP (rama `fix/portal-blog-admin`)

```
php/mailer.php
php/submit_requirements.php
php/submit_job.php
php/admin/index.php
php/example.config.php   (referencia; NO pisa config.php)
.htaccess
HOTFIX_MAIL.md
DEPLOY_PROD.md
```

**No subir / no pisar:** `php/config.php`, `data/requirements/*`, `data/applications/*`, `uploads/cv/*`, `logs/*`

**Borrar del servidor si existen:** `php/test_email.php`, `mail_test.php`, `net_probe.php`, `net_probe2.php`

---

## 4. Probar

1. https://netxia.cl/php/admin/ → login  
2. Pestaña **Correo** → **Probar access token** → debe OK  
3. **Enviar email de prueba** → llega a ADMIN_EMAIL y COPY  
4. Formulario cotización + postulación reales  
5. Pestaña **Leads** → Notificado / Reintentar  

---

## 5. Acciones del dueño (fuera del código)

1. Webmail 50webs: spam, cuota, filtros de `contacto@netxia.cl`
2. Si COPY llega y contacto@ no → problema del buzón 50webs (reenvío o cuota)
3. DNS (opcional): TXT `_dmarc` con `v=DMARC1; p=none; rua=mailto:tu-gmail`
4. No reenviar contacto@ al mismo Gmail del From (duplicados)

---

## Si falla

| Síntoma | Acción |
|---|---|
| token invalid_grant | Refresh token mal / app en Testing |
| token Couldn't connect | Allowlist cambió → VPS |
| send OK, no llega a contacto@ | Webmail 50webs; revisa COPY |
| Lead "No notificado" | Correo → token OK → Leads → Reintentar |
