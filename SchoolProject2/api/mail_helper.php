<?php
// api/mail_helper.php

require_once __DIR__ . '/../config/env.php';

// Adjust path depending on entry point
$phpmailerDir = __DIR__ . '/PHPMailer/src/';
require_once $phpmailerDir . 'Exception.php';
require_once $phpmailerDir . 'PHPMailer.php';
require_once $phpmailerDir . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an email using VaxiCare's configured SMTP credentials.
 *
 * @param string $toEmail Recipient's email address
 * @param string $toName Recipient's name
 * @param string $subject Subject line
 * @param string $bodyHTML HTML message content
 * @param string $altBody Optional text-only backup content
 * @return bool True if successful, false otherwise
 */
function sendVaxiCareEmail($toEmail, $toName, $subject, $bodyHTML, $altBody = '') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
  $mail->isSMTP();
  $mail->Host       = vax_env('SMTP_HOST', 'smtp.gmail.com');
  $mail->SMTPAuth   = true;
  $mail->Username   = vax_env('SMTP_USER');
  $mail->Password   = vax_env('SMTP_PASS');
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
  $mail->Port       = (int) vax_env('SMTP_PORT', '465');

        // Recipients
        $mail->setFrom(vax_env('SMTP_FROM', vax_env('SMTP_USER')), vax_env('SMTP_FROM_NAME', 'VaxiCare'));
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHTML;
        $mail->AltBody = $altBody ?: strip_tags($bodyHTML);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("VaxiCare Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>