<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendMail($to, $subject, $body)
{
    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host = $_ENV['SMTP_HOST'];

        $mail->SMTPAuth = true;

        $mail->Username = $_ENV['SMTP_USER'];

        $mail->Password = $_ENV['SMTP_PASS'];

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = $_ENV['SMTP_PORT'];

        $mail->setFrom(
            $_ENV['SMTP_FROM'],
            $_ENV['SMTP_NAME']
        );

        $mail->addAddress($to);

        $mail->isHTML(true);

        $mail->Subject = $subject;

        $mail->Body = $body;

        $mail->send();

        return true;

    } catch (Exception $e) {

        log_error('mail', 'Unable to send email', [
            'error' => $mail->ErrorInfo
        ]);

        return false;
    }
}