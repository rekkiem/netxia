# NETXIA — MVP Web 2026 v2.0
## Gmail SMTP · Claude AI · JSON Storage · 50webs Compatible

---

## ⚡ CONFIGURACIÓN EN 5 MINUTOS

### Paso 1 — App Password de Gmail (OBLIGATORIO para emails)

El hosting 50webs Free **bloquea SMTP propio**. La solución es Gmail SMTP.

1. Ve a → https://myaccount.google.com/security
2. Activa **"Verificación en 2 pasos"**
3. Busca **"Contraseñas de aplicaciones"**
4. Crea una: Aplicación = "Correo", Dispositivo = "Otro" → "Netxia Web"
5. Copia los **16 caracteres** generados (sin espacios, ej: `abcdabcdabcdabcd`)

### Paso 2 — Editar `php/config.php`

```php
// Gmail SMTP (ya configurado — solo completa la contraseña)
define('SMTP_PASS', 'abcdabcdabcdabcd'); // ← Tus 16 chars del App Password

// Chatbot Claude AI
define('ANTHROPIC_API_KEY', 'sk-ant-...');  // ← console.anthropic.com
```

### Paso 3 — Subir por FTP a 50webs

```
Host FTP : ftp.netxia.cl  (o el que indica 50webs)
Usuario  : tu usuario de 50webs
Puerto   : 21
Ruta     : /www/netxia.cl/
```

Sube TODOS los archivos manteniendo la estructura de carpetas.
En FileZilla: Servidor → **Forzar mostrar archivos ocultos** (para ver .htaccess).

### Paso 4 — Verificar email

Visita: `https://netxia.cl/php/test_email.php`
Haz clic en "Enviar email de prueba" y verifica que llega a contacto@netxia.cl.
**Luego elimina ese archivo del servidor.**

---

## 📁 Estructura del proyecto

```
netxia/
├── index.html                    ← Página principal (Hero, Servicios, Formularios)
├── 404.html / 403.html / 500.html
├── robots.txt · sitemap.xml
├── servicios-inteligencia-artificial.html
├── servicios-ciberseguridad.html
│
├── css/styles.css                ← Todo el CSS (responsive incluido)
├── js/
│   ├── main.js                   ← Animaciones, formularios, blog loader
│   └── chatbot.js                ← Widget chatbot
│
├── php/
│   ├── config.php                ← ⚠️ CONFIGURAR: SMTP_PASS + API_KEY
│   ├── csrf.php                  ← Tokens de seguridad
│   ├── chatbot.php               ← Proxy Claude API
│   ├── submit_requirements.php   ← Handler cotizaciones → Gmail
│   ├── submit_job.php            ← Handler postulaciones → Gmail + adjunto CV
│   ├── blog.php                  ← Proxy blog JSON (fallback)
│   ├── test_email.php            ← ⚠️ ELIMINAR tras verificar
│   └── phpmailer/                ← PHPMailer v6 (3 archivos, sin Composer)
│
├── blog/
│   ├── index.html                ← Listado con filtros por categoría
│   ├── tendencias-ia-2026.html
│   ├── ciberseguridad-empresas-chile.html
│   └── cloud-devops-chile-2026.html
│
├── data/
│   ├── .htaccess                 ← blog.json público, resto bloqueado
│   ├── blog.json                 ← Índice del blog (editable)
│   ├── requirements/             ← Cotizaciones guardadas (JSON por mes)
│   ├── applications/             ← Postulaciones guardadas (JSON por mes)
│   └── sessions/                 ← Sesiones PHP
│
├── uploads/
│   ├── .htaccess                 ← Bloquea ejecución PHP + acceso directo
│   └── cv/                       ← CVs subidos (PDF/DOCX)
│
└── logs/
    ├── .htaccess                 ← Acceso bloqueado
    ├── requirements.log
    ├── jobs.log
    ├── chatbot.log
    └── email_errors.log          ← Errores de email (diagnóstico)
```

---

## 🔧 DESARROLLO LOCAL (XAMPP)

### Configuración XAMPP necesaria

**1. Habilitar curl** (para el chatbot):
- Abre `C:\xampp\php\php.ini`
- Busca `;extension=curl` → quita el `;`
- Reinicia Apache

**2. RewriteBase para subdirectorio:**
En `.htaccess`, línea 13:
```apache
# Producción (50webs):
RewriteBase /

# Local XAMPP en /netxia/:
RewriteBase /netxia/
```

**3. Comportamiento en local:**
- Rate limiting desactivado automáticamente
- Cookies sin flag `secure` (HTTP en local)
- El mensaje de éxito de formularios incluye el estado del email
- Si SMTP_PASS está vacío: formularios funcionan (guardan JSON) pero no envían email

---

## 📝 AGREGAR ARTÍCULOS AL BLOG

### 1. Editar `data/blog.json`

```json
{
  "slug": "mi-nuevo-articulo",
  "titulo": "Título del Artículo",
  "resumen": "Resumen de 1-2 oraciones para la tarjeta.",
  "categoria": "Ciberseguridad",
  "fecha": "2026-07-01",
  "lectura": "8 min",
  "autor": "Equipo Netxia",
  "imagen_alt": "Descripción accesible de la imagen",
  "url": "./blog/mi-nuevo-articulo.html"
}
```

Categorías: `"Inteligencia Artificial"` · `"Ciberseguridad"` · `"Cloud & DevOps"`

### 2. Crear HTML del artículo

Copia `blog/tendencias-ia-2026.html` como plantilla.

### 3. Actualizar `sitemap.xml`

Agrega la nueva URL.

---

## ✅ CHECKLIST DESPLIEGUE EN 50WEBS

**Pre-vuelo:**
- [ ] `SMTP_PASS` configurado con App Password de 16 chars
- [ ] `ANTHROPIC_API_KEY` configurado
- [ ] `RewriteBase /` (sin `/netxia/`)

**Verificación post-subida:**
- [ ] `https://netxia.cl/` carga correctamente
- [ ] Blog carga artículos (sección blog visible)
- [ ] `https://netxia.cl/php/test_email.php` → email de prueba llega
- [ ] Formulario de cotización → mensaje de éxito + email llega
- [ ] Formulario de postulación → mensaje de éxito + email con CV adjunto
- [ ] Chatbot responde (si API key configurada)
- [ ] `https://netxia.cl/data/` devuelve 403
- [ ] `https://netxia.cl/php/config.php` devuelve 403
- [ ] **ELIMINAR** `php/test_email.php` del servidor

---

## 🐛 SOLUCIÓN DE PROBLEMAS

| Problema | Causa | Solución |
|---|---|---|
| Email no llega | SMTP_PASS vacío o incorrecto | Verifica App Password en test_email.php |
| Gmail rechaza conexión | 2FA no activo en Gmail | Activa verificación en 2 pasos primero |
| Blog no carga artículos | data/.htaccess mal copiado | Verifica que blog.json es accesible en /data/blog.json |
| Chatbot "curl deshabilitado" | PHP sin extensión curl | Habilita en php.ini y reinicia Apache |
| Chatbot HTTP 400 | Modelo inválido | Verifica `CHATBOT_MODEL` = `claude-haiku-4-5-20251001` |
| Formulario "token inválido" | Sesiones no funcionan | Crear `data/sessions/` con permisos 755 |
| CV no se guarda | Sin permisos en uploads/ | Chmod 755 a `uploads/cv/` |
| 500 en PHP | display_errors on en XAMPP | Normal en local; en producción está desactivado |

---

## 📞 Soporte

- 📧 contacto@netxia.cl
- 🌐 https://netxia.cl
- 📱 +56 9 8902 4643

*Netxia MVP v2.0 · PHP 8.x · Vanilla JS · Sin MySQL · Sin Composer · 50webs Compatible*
