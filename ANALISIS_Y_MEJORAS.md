# Netxia — Análisis del portal productivo y plan de correcciones

**Repo:** https://github.com/rekkiem/netxia  
**Producción:** https://netxia.cl/ (Apache · 50webs Free · PHP sin MySQL)  
**Rama de trabajo:** `fix/portal-blog-admin`  
**Fecha de revisión:** 2026-09-17

---

## 1. Resumen ejecutivo

El sitio es un MVP estático + PHP (formularios, chatbot, blog vía JSON) bien orientado a las limitaciones de **50webs Free** (sin depender de MySQL/Composer).  
El fallo más grave en producción era el **blog vacío**: el frontend pedía `/data/blog.json` y el `.htaccess` devolvía **403**. El proxy `/php/blog.php` sí funcionaba, pero el JS no lo usaba.

Esta rama corrige ese y otros bugs, limpia restos de FrontPage, endurece seguridad y agrega un **admin de blog sin base de datos**.

---

## 2. Errores encontrados (producción)

| # | Severidad | Problema | Evidencia | Corrección en esta rama |
|---|-----------|----------|-----------|-------------------------|
| 1 | **Crítica** | Blog home y `/blog/` no cargan artículos | `GET /data/blog.json` → **403**; JS solo usaba esa URL | `main.js` + `blog/index.html` usan `/php/blog.php` (fallback a JSON) |
| 2 | Alta | URLs del índice `./blog/...` rotas desde `/blog/` | Paths relativos inconsistentes | URLs absolutas `/blog/{slug}.html` en JSON y normalización en API |
| 3 | Alta | `php/test_email.php` público | HTTP 200 en producción | Bloqueado en `.htaccess` (`FilesMatch`) |
| 4 | Media | Artículo legacy `blog-tendencias-ia-2026.html` con links rotos (`contacto.html`, Clash Display) | Duplicado + 404 | Redirect 301 + stub HTML |
| 5 | Media | `postinfo.html` + `AuthUserFile` FrontPage en `.htaccess` | Ruido/seguridad | Eliminados |
| 6 | Media | Logo Schema `assets/img/netxia-logo.webp` 404 | Assets sin ese archivo | `assets/img/netxia-logo.svg` y Schema actualizado |
| 7 | Media | `contacto.html` 404 (link en artículo legacy) | — | Redirect del legacy; contacto vive en `#contacto` |
| 8 | Baja | Typo “Tuempresa” en blog index | — | Corregido |
| 9 | Baja | Contraste Lighthouse (`--text-2/3`) | Score accesibilidad | Tokens de color un poco más claros |
| 10 | Baja | SEO: grid del blog vacío sin JS | Solo “Cargando…” | Cards estáticas de fallback + JS las refresca |
| 11 | Info | `data/blog.json` no versionado / no accesible | Repo sin `data/` | `data/blog.json` versionado; acceso solo vía PHP |
| 12 | Info | Copilot instructions desactualizadas | Hablan de `contact.php` | Documentación actualizada en README/análisis |

---

## 3. Arquitectura actual (post-fix)

```
Navegador
  ├─ index.html / blog/*     (HTML estático)
  ├─ /php/blog.php           → lee data/blog.json (público)
  ├─ /php/csrf.php           → token sesión
  ├─ /php/submit_*.php       → JSON + Gmail SMTP
  ├─ /php/chatbot.php        → Gemini o FAQ local
  └─ /php/admin/             → CRUD blog (sesión + contraseña)
       └─ escribe data/blog.json + blog/{slug}.html + sitemap.xml
```

Restricciones **50webs Free** respetadas:

- Sin MySQL (MySQL es opcional/pago en free)
- Sin Composer (PHPMailer vendored)
- Disco/archivos acotados → HTML estático por artículo
- SMTP propio bloqueado → Gmail App Password

---

## 4. Solución de administración del blog (simple)

### Por qué no WordPress / MySQL

En free de 50webs: cuota de disco, CPU y MySQL limitado u opcional. WordPress es pesado, actualizable y frágil en free hosting. El stack actual (JSON + HTML) ya encaja.

### Qué se implementó

Panel en **`/php/admin/`**:

1. Login con `BLOG_ADMIN_PASS` (config.php) — texto o hash bcrypt  
2. Listar / crear / editar / borrar artículos  
3. Cuerpo en **Markdown simple** (`##`, listas, `**negrita**`)  
4. Al guardar:
   - Actualiza `data/blog.json`
   - Guarda cuerpo en `data/blog_bodies/{slug}.md` (para re-editar)
   - Genera `blog/{slug}.html` desde plantilla
   - Regenera `sitemap.xml`
5. `noindex` + sesión PHP; sin DB

### Cómo usarlo en producción

1. Subir esta rama por FTP  
2. En `php/config.php` (no versionado):
   ```php
   define('BLOG_ADMIN_PASS', 'elige-una-clave-larga');
   ```
3. Abrir `https://netxia.cl/php/admin/`  
4. Publicar  
5. Verificar home y `/blog/`

### Flujo alternativo (aún más simple)

Solo editar `data/blog.json` por FTP y copiar un HTML de plantilla — documentado en README como Opción B.

---

## 5. Mejoras propuestas (roadmap)

### Corto plazo (esta semana)

- [ ] Reemplazar `assets/img/netxia-logo.svg` por logo final de marca si existe manual corporativo  
- [ ] Confirmar `SMTP_PASS` y borrar `test_email.php` del servidor si aún está  
- [ ] Cambiar `BLOG_ADMIN_PASS` a un hash bcrypt  
- [ ] Probar formularios cotización/postulación end-to-end  
- [ ] Activar redirect HTTPS en `.htaccess` si el SSL de 50webs está OK  
- [ ] Completar RUT real o quitar la mención (ya suavizada)

### Medio plazo (producto)

- [ ] Página dedicada “Cloud & DevOps” (hoy solo ancla a cotizar)  
- [ ] Unificar páginas de servicios con el mismo footer/nav del index  
- [ ] Imágenes reales por post (WebP < 100 KB por límite free)  
- [ ] Newsletter simple (solo captura email → JSON + mail)  
- [ ] Casos de éxito / logos clientes (con permiso)  
- [ ] JSON-LD `BlogPosting` por artículo  

### Largo plazo (si crece el tráfico)

- [ ] Plan Mini/Starter 50webs o VPS barato si se necesita MySQL/caché  
- [ ] CMS headless o Markdown en Git + Actions (si dejan de usar solo FTP)  
- [ ] Separar assets a CDN gratuito (Cloudflare)  

---

## 6. Checklist de despliegue de esta rama

1. Merge o subida FTP de `fix/portal-blog-admin`  
2. Asegurar que existe `php/config.php` (desde `example.config.php`) con SMTP + `BLOG_ADMIN_PASS`  
3. Permisos 755 en `data/`, `logs/`, `uploads/cv/`  
4. Verificar:
   - `https://netxia.cl/php/blog.php` → JSON de 3 artículos  
   - Home: sección blog con cards  
   - `https://netxia.cl/blog/` filtros  
   - `https://netxia.cl/data/blog.json` → **403** (correcto)  
   - `https://netxia.cl/php/test_email.php` → **403**  
   - `https://netxia.cl/php/admin/` → login  

---

## 7. Archivos tocados en la rama

- `js/main.js` — loader del blog  
- `blog/index.html` — loader + typo + nav móvil  
- `php/blog.php` — API robusta (publicado, orden, URLs)  
- `php/admin/*` — panel  
- `blog/_template/article.html` — plantilla  
- `data/blog.json` + `data/.htaccess`  
- `.htaccess` v1.3 (sin FrontPage, bloqueos extra, redirect legacy)  
- `robots.txt`, `sitemap` (admin lo regenera)  
- `css/styles.css` — contraste  
- `index.html` — fallback SEO blog, og:image, footer  
- `assets/img/og-cover.svg`  
- Eliminado `postinfo.html`  
- `README.md`, este documento  

---

*Análisis generado tras revisión del repo y del sitio en vivo (HTTP probes 2026-09-17).*


---

## 8. Refuerzos posteriores (misma rama)

- CSRF en todas las acciones del admin (login, logout, save, delete)
- Rate limit de login: 8 intentos/hora por IP (`data/admin/rl_*.json`)
- En producción, admin **503** si `BLOG_ADMIN_PASS` es vacío o el placeholder de ejemplo
- `session_regenerate_id` al login exitoso
- Escape HTML en loaders del blog (home + `/blog/`) para evitar XSS desde JSON
- Cuerpos Markdown seed en `data/blog_bodies/` para re-editar los 3 posts existentes
- `.gitignore` ignora contadores de rate-limit del admin

