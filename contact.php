<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $nombre = htmlspecialchars($_POST['nombre'] ?? '');
    $email = htmlspecialchars($_POST['email'] ?? '');
    $mensaje = htmlspecialchars($_POST['mensaje'] ?? '');

    // Validar campos requeridos
    if (empty($nombre) || empty($email) || empty($mensaje)) {
        http_response_code(400);
        echo 'Error: Todos los campos son requeridos';
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo 'Error: Email inválido';
        exit;
    }

    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP (leer credenciales de variables de entorno)
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'mail.netxia.cl';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'contacto@netxia.cl';
        $mail->Password   = getenv('SMTP_PASS');
        
        if (!$mail->Password) {
            throw new Exception('Credenciales SMTP no configuradas. Establece SMTP_PASS como variable de entorno.');
        }
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Remitente
        $mail->setFrom('contacto@netxia.cl', 'Formulario Web NETXIA');

        // Destinatarios
        $mail->addAddress('contacto@netxia.cl');
        $mail->addAddress('rekkiem@gmail.com');

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = 'Nuevo mensaje desde formulario web';

        $mail->Body = "
            <h2>Nuevo contacto desde la web</h2>
            <p><strong>Nombre:</strong> $nombre</p>
            <p><strong>Email:</strong> $email</p>
            <p><strong>Mensaje:</strong><br>$mensaje</p>
        ";

        $mail->AltBody = "Nombre: $nombre\nEmail: $email\nMensaje:\n$mensaje";

        $mail->send();
        http_response_code(200);
        echo "OK";

    } catch (Exception $e) {
        http_response_code(500);
        echo "Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
