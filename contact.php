<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $nombre = htmlspecialchars($_POST['nombre'] ?? '');
    $email = htmlspecialchars($_POST['email'] ?? '');
    $mensaje = htmlspecialchars($_POST['mensaje'] ?? '');

    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP (USA TU CORREO REAL)
        $mail->isSMTP();
        $mail->Host       = 'mail.netxia.cl'; // o smtp.gmail.com si usas Gmail
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contacto@netxia.cl'; // correo que envía
        $mail->Password   = 'TU_PASSWORD_AQUI';   // contraseña real o app password
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
