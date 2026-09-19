<?php
/**
 * DESHABILITADO — usar Admin → Correo (https://netxia.cl/php/admin/?view=mail)
 * No exponer diagnósticos con secretos en URLs públicas.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
echo "Gone. Usa el panel admin autenticado: /php/admin/?view=mail\n";
exit;
