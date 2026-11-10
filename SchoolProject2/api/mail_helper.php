<?php
// api/mail_helper.php

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
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        // IMPORTANT: Replace this with the email address that generated the App Password
        $mail->Username   = 'augustynyikona10@gmail.com'; 
        $mail->Password   = 'zgqmsxifjhtrjtvx'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
        $mail->Port       = 465;

        // Recipients
        $mail->setFrom('admin@vaxicare.com', 'VaxiCare System');
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