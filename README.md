# NETXIA — Rediseño Web 2026
## Guía de Despliegue en 50webs (Plan Básico)

---

## 📦 Estructura del Proyecto

```
netxia_rediseno/
├── index.html                  ← Página principal (SPA)
├── 404.html / 403.html / 500.html
├── robots.txt / sitemap.xml
├── .htaccess                   ← Seguridad + caché + reescritura
│
├── css/
│   └── styles.css              ← Todos los estilos (responsive incluido)
│
├── js/
│   ├── main.js                 ← Animaciones, formularios, blog loader
│   └── chatbot.js              ← Widget del chatbot
│
├── php/
│   ├── config.php              ← ⚠️ CONFIGURAR ANTES DE SUBIR
│   ├── csrf.php                ← Generador de tokens CSRF
│   ├── chatbot.php             ← Proxy Claude API → chatbot
│   ├── submit_requirements.php ← Handler formulario requerimientos
│   ├── submit_job.php          ← Handler portal de talento
│   └── phpmailer/              ← PHPMailer v6 (3 archivos core)
│
├── blog/
│   ├── index.html              ← Listado del blog con filtros
│   ├── tendencias-ia-2026.html
│   ├── ciberseguridad-empresas-chile.html
│   └── cloud-devops-chile-2026.html
│
├── data/
│   ├── .htaccess               ← Bloquea acceso público (CRÍTICO)
│   ├── blog.json               ← Índice del blog
│   ├── requirements/           ← Formularios guardados (.json por mes)
│   ├── applications/           ← Postulaciones (.json por mes)
│   └── sessions/               ← Sesiones PHP (si hosting lo permite)
│
├── uploads/
│   ├── .htaccess               ← Bloquea ejecución PHP + acceso
│   └── cv/                     ← CVs subidos
│
└── logs/
    ├── .htaccess               ← Acceso bloqueado
    └── *.log                   ← Generados automáticamente
```

---

## ⚙️ CONFIGURACIÓN OBLIGATORIA ANTES DE SUBIR

### 1. `php/config.php` — Credenciales

Edita este archivo con tus datos reales:

```php
// SMTP (email de contacto@netxia.cl en 50webs)
define('SMTP_HOST',  'mail.netxia.cl');     // Servidor SMTP de 50webs
define('SMTP_PORT',  587);                   // O 465 para SSL
define('SMTP_USER',  'contacto@netxia.cl'); // Tu cuenta de correo
define('SMTP_PASS',  'TU_CONTRASEÑA_AQUI'); // ← Completa esto

// Chatbot Claude API
define('ANTHROPIC_API_KEY', 'sk-ant-...'); // ← Tu API key de Anthropic
```

> **SMTP en 50webs:** Ve a cp.50webs.com → Correo → crea la cuenta contacto@netxia.cl.
> El servidor SMTP suele ser `mail.TUDOMINIO.cl` o `smtp.50webs.com`.
> Confirma en el panel bajo "Información de Sistema".

### 2. API Key del Chatbot

1. Ve a https://console.anthropic.com/
2. Crea una API key en "API Keys"
3. Pégala en `php/config.php` → `ANTHROPIC_API_KEY`
4. El chatbot es 100% funcional con Claude Sonnet 4

> **Sin API key:** El chatbot mostrará un mensaje de error amigable
> invitando al usuario a contactar por email. El resto del sitio funciona normal.

---

## 🚀 DESPLIEGUE EN 50WEBS — Paso a Paso

### Método A: FTP (Recomendado para subida inicial)

1. **Datos FTP de 50webs:**
   - Host: `ftp.netxia.cl` o `ftp.50webs.com`
   - Usuario: tu usuario de 50webs
   - Puerto: 21

2. **Cliente FTP:** Usa FileZilla (gratuito)

3. **Pasos:**
   ```
   a. Conecta por FTP al servidor
   b. Navega a /www/netxia.cl/ (o la ruta mostrada en el panel)
   c. Sube TODOS los archivos manteniendo la estructura de carpetas
   d. Verifica que .htaccess se subió (FileZilla muestra archivos ocultos
      con: Servidor → Forzar mostrar archivos ocultos)
   ```

4. **Verificar permisos tras la subida:**
   - Carpetas: `755`
   - Archivos PHP: `644`
   - `data/`, `logs/`, `uploads/cv/`: `755` (necesita escritura del servidor)

### Método B: Administrador de Archivos de 50webs

1. Ve a cp.50webs.com → Archivos → Manejar Archivos
2. Selecciona el host `netxia.cl`
3. Comprime el proyecto en ZIP y usa "Comprimir/Extraer" del panel

---

## 📋 CHECKLIST POST-DESPLIEGUE

### Funcionalidad básica
- [ ] `https://netxia.cl/` carga correctamente
- [ ] El menú de navegación funciona (links internos con `#`)
- [ ] Las animaciones de scroll-reveal se activan
- [ ] El blog carga artículos desde `data/blog.json`
- [ ] La página 404 personalizada aparece (prueba con `/pagina-inexistente`)

### Formulario de Requerimientos
- [ ] El formulario multi-paso avanza entre pasos
- [ ] Validación del lado del cliente funciona (campo vacío → error visual)
- [ ] Prueba con datos reales → verificar que llega email a `contacto@netxia.cl`
- [ ] Los datos se guardan en `data/requirements/YYYY-MM.json`
- [ ] Mensaje de éxito aparece tras envío exitoso

### Portal de Talento
- [ ] Subida de CV acepta PDF y DOCX hasta 2 MB
- [ ] Rechaza archivos PHP o scripts
- [ ] Email de notificación llega con CV adjunto
- [ ] Los datos se guardan en `data/applications/YYYY-MM.json`

### Chatbot
- [ ] El botón flotante aparece en esquina inferior derecha
- [ ] Al hacer clic, la ventana se abre con mensaje de bienvenida
- [ ] Las respuestas llegan desde Claude API
- [ ] Si no hay API key: mensaje de error amigable (no crash)
- [ ] Funciona en móvil (ventana se adapta al ancho de pantalla)

### Seguridad
- [ ] `https://netxia.cl/data/` devuelve 403
- [ ] `https://netxia.cl/logs/` devuelve 403
- [ ] `https://netxia.cl/php/config.php` devuelve 403
- [ ] `https://netxia.cl/uploads/cv/` devuelve 403
- [ ] Cabeceras de seguridad presentes (verificar con https://securityheaders.com)

### SEO y Accesibilidad
- [ ] Revisar con Google Lighthouse (Meta > DevTools > Lighthouse)
  - Performance: ≥ 85 (móvil)
  - Accessibility: ≥ 90
  - SEO: ≥ 95
- [ ] Verificar sitemap en https://netxia.cl/sitemap.xml
- [ ] Verificar robots.txt en https://netxia.cl/robots.txt

---

## 🔧 AGREGAR NUEVOS ARTÍCULOS AL BLOG

### Paso 1: Editar `data/blog.json`

Agrega un nuevo objeto al inicio del array:

```json
{
  "slug": "tu-nuevo-articulo",
  "titulo": "Título del Artículo",
  "resumen": "Descripción de 1-2 oraciones para la tarjeta del blog.",
  "categoria": "Ciberseguridad",
  "fecha": "2026-04-01",
  "lectura": "7 min",
  "autor": "Equipo Netxia",
  "imagen_alt": "Descripción de la imagen para accesibilidad",
  "url": "./blog/tu-nuevo-articulo.html"
}
```

Categorías disponibles: `"Inteligencia Artificial"`, `"Ciberseguridad"`, `"Cloud & DevOps"`

### Paso 2: Crear el archivo HTML del artículo

Copia `blog/tendencias-ia-2026.html` como base y edita:
- `<title>` y `<meta name="description">`
- `<h1 class="article-title">`
- El contenido dentro de `<article class="article-body">`
- La fecha y categoría en `.article-meta-bar`

### Paso 3: Actualizar `sitemap.xml`

Agrega la nueva URL con su `<lastmod>`.

---

## 🐛 SOLUCIÓN DE PROBLEMAS COMUNES EN 50WEBS

| Problema | Causa probable | Solución |
|----------|---------------|----------|
| El blog no carga artículos | `data/blog.json` no encontrado o CORS | Verificar que el archivo existe y la ruta es `./data/blog.json` |
| Formulario devuelve "token inválido" | Sesiones PHP no funcionan | Crear carpeta `data/sessions/` con permiso 755 |
| Email no llega | SMTP mal configurado | Verificar credenciales en config.php; probar con `mail()` como fallback |
| Chatbot devuelve error | API key no configurada o sin saldo | Verificar `ANTHROPIC_API_KEY` en config.php |
| `.htaccess` no funciona | `mod_rewrite` desactivado | Contactar soporte 50webs para activar `AllowOverride All` |
| Archivos PHP muestran código | PHP no configurado | Verifica que los archivos tienen extensión `.php`, no `.html` |
| Error 500 al subir CV | Permisos de directorio | Chmod 755 a `uploads/cv/` desde el administrador de archivos |
| Sesiones no persisten | `session_save_path` sin permiso | Crear `data/sessions/` manualmente y verificar permiso 755 |

---

## 📊 MONITOREO Y MANTENIMIENTO

### Revisar logs periódicamente

Los logs se generan automáticamente en:
- `logs/requirements.log` — formularios de cotización
- `logs/jobs.log` — postulaciones de trabajo
- `logs/chatbot.log` — mensajes del chatbot
- `logs/rl_*.json` — archivos de rate limiting (se auto-limpian)

### Revisar datos guardados

Las postulaciones y requerimientos se guardan en:
- `data/requirements/2026-03.json` (un archivo por mes)
- `data/applications/2026-03.json`

Estos son archivos JSON con array de objetos. Puedes abrirlos en cualquier editor de texto.

### Agregar más artículos al blog

Edita `data/blog.json` y crea el `.html` correspondiente en `/blog/`.

---

## 🔐 LÍMITES DEL PLAN GRATUITO 50WEBS

| Recurso | Límite | Estado del proyecto |
|---------|--------|-------------------|
| Espacio disco | 500 MB | ✅ ~15 MB (sin imágenes externas) |
| Tráfico mensual | 5 GB | ✅ Optimizado con caché |
| Archivos totales | 5,000 | ✅ ~60 archivos actuales |
| Tamaño por archivo | 2 MB | ✅ Todos bajo 500 KB |
| PHP | 4.4–8.5 | ✅ Compatible (PHP 7.4+) |
| MySQL | ❌ No incluido | ✅ Usamos JSON flat files |
| SMTP | Limitado | ⚠️ Configurar manualmente |

---

## 📞 SOPORTE TÉCNICO

Para consultas sobre este proyecto:
- 📧 contacto@netxia.cl
- 🌐 https://netxia.cl
- 📱 +56 9 8902 4643

---

*Netxia Rediseño Web 2026 — Desarrollado con PHP 8.x, Vanilla JS, CSS3*
*Sin dependencias npm · Sin MySQL · Compatible 100% con hosting compartido*
