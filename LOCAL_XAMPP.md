# Netxia — setup local XAMPP (`http://localhost/netxia/`)

## 1. Traer la rama de fixes

En PowerShell, desde tu carpeta del proyecto:

```powershell
cd "C:\Users\rafae\OneDrive\Documents\PRG\NETXIA"
git fetch origin
git checkout fix/portal-blog-admin
git pull origin fix/portal-blog-admin
```

Si prefieres no cambiar de rama y aplicar un patch:

```powershell
git fetch origin
git checkout -b fix/portal-blog-admin origin/fix/portal-blog-admin
```

## 2. Config PHP local

```powershell
copy php\example.config.php php\config.php
```

Edita `php\config.php`:

- `SMTP_PASS` — opcional en local (formularios guardan JSON igual)
- `BLOG_ADMIN_PASS` — pon una clave simple, ej. `dev-local-2026`
- `GEMINI_API_KEY` — opcional (sin key usa FAQ local)

## 3. RewriteBase para subdirectorio `/netxia/`

En `.htaccess`, deja:

```apache
RewriteBase /netxia/
```

(comenta o quita `RewriteBase /`)

## 4. Carpeta en XAMPP

Opciones:

**A)** Ya tienes el repo en `Documents\PRG\NETXIA` y Apache apunta ahí vía alias/vhost, o  
**B)** Copia/junction a `C:\xampp\htdocs\netxia`:

```powershell
# junction (sin copiar, recomendado)
cmd /c mklink /J "C:\xampp\htdocs\netxia" "C:\Users\rafae\OneDrive\Documents\PRG\NETXIA"
```

## 5. XAMPP

- **Apache = Start** (verde) — ya lo tienes
- **MySQL no es necesario** para Netxia (JSON + archivos). El error de MySQL en el panel se puede ignorar.

Permisos/carpetas:

```powershell
New-Item -ItemType Directory -Force -Path data\sessions, data\requirements, data\applications, data\blog_bodies, data\admin, logs, uploads\cv | Out-Null
```

## 6. Probar

| URL | Esperado |
|-----|----------|
| http://localhost/netxia/ | Home |
| http://localhost/netxia/php/blog.php | JSON de artículos |
| http://localhost/netxia/blog/ | Listado |
| http://localhost/netxia/php/admin/ | Login admin |
| http://localhost/netxia/data/blog.json | 403 (correcto) |

## 7. Volver a main / merge

Cuando valides:

```powershell
git checkout main
git merge fix/portal-blog-admin
git push origin main
```

Para producción 50webs: `RewriteBase /` y subir por FTP.
