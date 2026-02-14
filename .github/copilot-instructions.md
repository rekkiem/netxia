## Repo summary

- This repository is a small static website (HTML) with a PHP contact handler that uses a vendored copy of PHPMailer. There is no build system — files are served as-is by a web server.

## Key files & directories

- [index.html](index.html) — main landing page, site-level metadata and patterns (Spanish / es-CL). Use this as the template for other pages.
- [contact.php](contact.php) — contact form handler. Important: this is the only server-side script; it expects a POST with `nombre`, `email`, `mensaje` and uses PHPMailer to send mail.
- [PHPMailer-master/](PHPMailer-master/) — vendored PHPMailer library (manual install variant). See [PHPMailer-master/README.md](PHPMailer-master/README.md) for upgrade/install notes.
- [blog/](blog/) — static blog pages; follow existing naming and permalink conventions when adding posts.

## Big-picture architecture

- Static HTML site served by a standard web server (Apache/IIS/nginx). No frontend toolchain or bundler present.
- Contact form flow: client form -> POST to `contact.php` -> `PHPMailer/src/*` are required directly -> SMTP server (configured inside `contact.php`) sends mail. The handler returns `OK` (HTTP 200) or `Mailer Error: ...` (HTTP 500).

## Project-specific conventions & patterns

- Content language: Spanish (Chilean) — pages use `lang="es-CL"` and Chilean geo metadata in `index.html`.
- File naming: lowercase, dash-separated (for example `servicios-inteligencia-artificial.html`). Keep existing structure when adding pages.
- PHPMailer is vendored (not composer-installed). If you upgrade PHPMailer, prefer switching to Composer and replacing the manual `require 'PHPMailer/src/...';` lines with `require 'vendor/autoload.php';`.

## Developer workflows (how to run, test, debug)

- Local static server (quick): from repo root run:

```bash
php -S localhost:8000
```

- Test contact handler with curl (replace values):

```bash
curl -X POST -d "nombre=Prueba&email=test@example.com&mensaje=hola" http://localhost:8000/contact.php
```

- To get SMTP debug output, temporarily set `$mail->SMTPDebug = SMTP::DEBUG_SERVER;` in `contact.php` (remove before committing).
- For safe testing avoid real SMTP credentials; use a sandbox service (Mailtrap, Ethereal) and update SMTP host/credentials in `contact.php`.

## Integration points & gotchas

- `contact.php` currently contains SMTP config and a plaintext placeholder password (`TU_PASSWORD_AQUI`). Do NOT commit real credentials. Move credentials to environment variables or a non-committed config file before deployment.
- Recipient addresses are hard-coded in `contact.php` (`$mail->addAddress(...)`). Update there when changing destination emails.
- Because PHPMailer is vendored, patching files inside `PHPMailer-master/src/` is fragile — prefer upgrading via Composer.

## Examples (common edits)

- Change contact recipient: edit `contact.php` and update the `$mail->addAddress(...)` lines.
- Switch to Composer: run `composer require phpmailer/phpmailer`, remove manual `require` lines and add `require 'vendor/autoload.php';` in `contact.php`.

## Security & maintenance notes

- Never commit SMTP usernames/passwords or production secrets. Use environment variables or a secrets manager.
- The contact handler responds with raw PHPMailer error text on failure — avoid exposing that in production (log internally instead).

## Where to look first when debugging

- Check `contact.php` for SMTP host, port, auth settings, and recipient addresses.
- Verify `PHPMailer-master/src/` is present if errors complain about missing classes.

## When in doubt

- Ask for clarification and point to the specific file and line range. Example: "Look at `contact.php` where SMTP is configured (contact.php)."

---
If any section is unclear or you'd like additional examples (deploy commands, environment config examples, or converting to Composer), tell me which area to expand.
