## Netxia (repo summary)

Sitio estático HTML + PHP para 50webs Free. Sin Composer, sin MySQL obligatorio.

### Estructura clave
- `index.html` — landing (es-CL)
- `css/styles.css`, `js/main.js`, `js/chatbot.js`
- `php/config.php` — secretos (no versionado; copiar de `example.config.php`)
- `php/blog.php` — API JSON del blog (NO servir `/data/` en público)
- `php/admin/` — panel blog con contraseña `BLOG_ADMIN_PASS`
- `php/submit_requirements.php`, `php/submit_job.php`, `php/chatbot.php`, `php/csrf.php`
- `php/phpmailer/` — vendored
- `data/blog.json` — índice del blog
- `blog/*.html` — artículos estáticos

### Convenciones
- Español Chile (`lang="es-CL"`)
- Nombres en kebab-case
- El blog se consume siempre vía `/php/blog.php` porque `/data/` está bloqueado por `.htaccess`
