# Deploy producción — netxia.cl (correo + blog + admin)

**Rama:** `fix/portal-blog-admin` @ `c884514` (igual que `hotfix/mail-gmail-api`)

## Por qué aún no llegan correos (2026-09-19)

Diagnóstico en vivo de https://netxia.cl:

| Check | Resultado | Lectura |
|---|---|---|
| `/php/admin/` título | `Admin Blog · Netxia` | **Código viejo** (el nuevo dice `Admin · Netxia` y tiene pestañas Leads/Correo) |
| `/php/mailer.php` | **404** | El hotfix **no está en el servidor** |
| `/php/test_email.php` | **200** | Script de prueba aún público (riesgo) |
| SMTP a Gmail | errno 13 | 50webs bloquea SMTP; solo HTTPS a Google APIs |

**Conclusión:** el repo ya tiene la solución; **falta subir por FTP** + **pegar GMAIL_* en config.php**.

---

## 0. Ramas

| Rama | Uso |
|---|---|
| `fix/portal-blog-admin` | **Deploy este** |
| `hotfix/mail-gmail-api` | Mismo tip — no hace falta desplegar aparte |
| `codex/...` | Prototipo chatbot — **no mergear** |
| `main` | Antigua (SMTP only) |

```powershell
git fetch origin
git checkout fix/portal-blog-admin
git pull origin fix/portal-blog-admin
```

---

## 1. Credenciales Gmail API (~10 min) — cuenta netxia.chile@gmail.com

1. https://console.cloud.google.com → proyecto `netxia-mail`
2. Habilitar **Gmail API**
3. OAuth → Externo → scope `https://www.googleapis.com/auth/gmail.send`
4. **Publicar en Producción** (Testing = token muere a 7 días)
5. Credencial OAuth Web → redirect `https://developers.google.com/oauthplayground`
6. OAuth Playground → ⚙ own credentials → Authorize `gmail.send` → Exchange → **Refresh token**

---

## 2. Editar en el servidor `php/config.php` (NUNCA desde Git)

```php
define('GMAIL_CLIENT_ID',     'xxxx.apps.googleusercontent.com');
define('GMAIL_CLIENT_SECRET', 'xxxx');
define('GMAIL_REFRESH_TOKEN', '1//0xxxx');

define('ADMIN_EMAIL',      'contacto@netxia.cl');
// Gmail de respaldo DISTINTO del From (netxia.chile@gmail.com)
define('ADMIN_EMAIL_COPY', 'tu-respaldo@gmail.com');

define('BLOG_ADMIN_PASS', '…'); // ya existente
// SMTP_* puede quedarse; en 50webs casi siempre falla (cascada 2ª)
```

---

## 3. Subir por FTP (FileZilla / cPanel)

Desde la carpeta del repo en la rama `fix/portal-blog-admin`:

```
php/mailer.php                 ← NUEVO (antes daba 404)
php/submit_requirements.php
php/submit_job.php
php/admin/index.php            ← pestañas Leads | Correo | Blog
php/test_email.php             ← ahora responde 410 Gone
php/example.config.php         ← solo referencia; NO pisa config.php
.htaccess
DEPLOY_PROD.md
HOTFIX_MAIL.md
```

**No pisar:** `php/config.php`, `data/requirements/`, `data/applications/`, `uploads/cv/`, `logs/`

**Borrar del server si existen:** `php/mail_test.php`, `php/net_probe.php`, `php/net_probe2.php`

---

## 4. Verificar que el deploy pegó

| URL | Debe verse |
|---|---|
| https://netxia.cl/php/admin/ | Título **Admin · Netxia** + links **Leads \| Correo \| Blog** (tras login) |
| https://netxia.cl/php/mailer.php | 404 o vacío (no se ejecuta por URL; el 404 HTTP de Apache está OK si el archivo existe solo para include — si FileZilla lo subió, no se “abre” por diseño) |
| https://netxia.cl/php/test_email.php | **410 Gone** |
| Admin → Correo → Probar token | **Access token OK** |
| Admin → Correo → Email de prueba | Llega a contacto@ **y** a COPY |
| Formulario real | Lead en **Leads** con **Notificado · gmail_api** |

---

## 5. Acciones del dueño (fuera del código)

1. Webmail 50webs: spam/cuota/filtros de `contacto@netxia.cl` (U2)
2. Si COPY llega y contacto@ no → problema del buzón 50webs
3. DNS opcional: TXT `_dmarc` `v=DMARC1; p=none; rua=mailto:tu-gmail`
4. No reenviar contacto@ al mismo Gmail del From

---

## Criterio de resuelto

- [ ] Formulario real → contacto@netxia.cl **y** ADMIN_EMAIL_COPY  
- [ ] Fallo → lead **No notificado** + Reintentar  
- [ ] Token caído visible en Admin → Correo  
- [ ] test_email / probes no públicos  
