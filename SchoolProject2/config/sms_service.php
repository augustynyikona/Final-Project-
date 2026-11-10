<?php
/**
 * VaxiCare SMS Service Configuration
 * 
 * Integrated with RapidAPI & Africa's Talking SMS Gateways
 * Application: Vaxicare (App ID: 9217789)
 * Supports automated normalization for Zambian mobile networks (Airtel, MTN, Zamtel).
 */

require_once __DIR__ . '/db.php';

// ==========================================
// 1. RAPIDAPI D7SMS GATEWAY CREDENTIALS
// ==========================================
define('RAPIDAPI_KEY', 'ed6f57c9a9mshbdf7a4dd61f16a8p1fa334jsn3642f91c5200');
define('RAPIDAPI_APP_ID', '9217789');
define('RAPIDAPI_APP_NAME', 'Vaxicare');
define('RAPIDAPI_D7_HOST', 'd7sms.p.rapidapi.com');
// D7 Networks Access Token from https://app.d7networks.com (API Tokens page)
define('D7_TOKEN', ''); 

// EasySendSMS Gateway Account (Fallback 1)
define('RAPIDAPI_HOST', 'easysendsms.p.rapidapi.com');
define('EASYSENDSMS_USERNAME', 'augustynyikona10@gmail.com');
define('EASYSENDSMS_PASSWORD', 'ess54895');
define('EASYSENDSMS_SENDER_ID', 'VaxiCare');

// ==========================================
// 2. AFRICA'S TALKING GATEWAY CREDENTIALS (RELIABLE BACKUP)
// ==========================================
define('AT_USERNAME', 'sandbox');
define('AT_API_KEY', 'atsk_5780b20f053d81e6c371f900857d74e16eca868d706b692688c7965c8134442e1ba48bd7');
define('AT_SENDER_ID', 'VaxiCare');

/**
 * Send an SMS message using RapidAPI Gateway with automatic fallback
 * 
 * @param string $phone The recipient's phone number (e.g. 0971725900, 0963912783)
 * @param string $message The text message body
 * @param int|null $appointment_id Optional appointment ID for tracking
 * @param string|null $reminder_type Optional reminder type ('1_day', '3_day', '7_day', '2_hour', 'manual')
 * @return array Array with ['success' => bool, 'provider' => string, 'detail' => string]
 */
function sendSMS($phone, $message, $appointment_id = null, $reminder_type = null)
{
    global $pdo;

    // Normalize phone number for Zambia
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($digits, '0') === 0) {
        $clean_phone = '260' . substr($digits, 1);
    } elseif (strpos($digits, '260') === 0) {
        $clean_phone = $digits;
    } elseif (strlen($digits) === 9) {
        $clean_phone = '260' . $digits;
    } else {
        $clean_phone = $digits;
    }

    $is_success = false;
    $provider_used = 'None';
    $status_detail = '';

    // ----------------------------------------------------
    // Gateway Attempt 1: RapidAPI D7SMS Gateway
    // ----------------------------------------------------
    if (defined('RAPIDAPI_KEY') && !empty(RAPIDAPI_KEY) && defined('D7_TOKEN') && !empty(D7_TOKEN)) {
        $d7_url = 'https://' . RAPIDAPI_D7_HOST . '/messages/v1/send';
        $d7_payload = json_encode([
            'messages' => [
                [
                    'channel' => 'sms',
                    'recipients' => ['+' . $clean_phone],
                    'content' => $message,
                    'msg_type' => 'text'
                ]
            ],
            'message_globals' => [
                'originator' => 'VaxiCare'
            ]
        ]);

        $ch = curl_init($d7_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $d7_payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-RapidAPI-Key: ' . RAPIDAPI_KEY,
            'X-RapidAPI-Host: ' . RAPIDAPI_D7_HOST,
            'Token: ' . D7_TOKEN,
            'Authorization: Bearer ' . D7_TOKEN,
            'Content-Type: application/json'
        ]);

        $d7_res = curl_exec($ch);
        $d7_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($d7_code >= 200 && $d7_code < 300) {
            $is_success = true;
            $provider_used = 'D7SMS (RapidAPI)';
            $status_detail = 'Sent (D7SMS)';
        } else {
            $status_detail = "D7SMS: HTTP $d7_code";
        }
    }

    // ----------------------------------------------------
    // Gateway Attempt 2: RapidAPI EasySendSMS Gateway
    // ----------------------------------------------------
    if (!$is_success && defined('RAPIDAPI_KEY') && !empty(RAPIDAPI_KEY)) {
        $rapid_url = 'https://' . RAPIDAPI_HOST . '/bulksms';
        $post_fields = http_build_query([
            'username' => EASYSENDSMS_USERNAME,
            'password' => EASYSENDSMS_PASSWORD,
            'from'     => EASYSENDSMS_SENDER_ID,
            'to'       => $clean_phone,
            'text'     => $message,
            'type'     => 0
        ]);

        $ch = curl_init($rapid_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-RapidAPI-Key: ' . RAPIDAPI_KEY,
            'X-RapidAPI-Host: ' . RAPIDAPI_HOST,
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $rapid_res = curl_exec($ch);
        $rapid_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $trimmed_res = trim($rapid_res);

        // Numeric positive number > 2000 is a valid EasySendSMS message ID
        if ($rapid_code === 200 && is_numeric($trimmed_res) && (int)$trimmed_res > 2000) {
            $is_success = true;
            $provider_used = 'RapidAPI';
            $status_detail = 'Sent (MsgID: ' . $trimmed_res . ')';
        } else {
            $status_detail = "RapidAPI: HTTP $rapid_code (Resp: $trimmed_res)";
        }
    }

    // ----------------------------------------------------
    // Gateway Attempt 2: Africa's Talking Gateway (Automated Fallback)
    // ----------------------------------------------------
    if (!$is_success && defined('AT_API_KEY') && !empty(AT_API_KEY)) {
        $at_url = 'https://api.sandbox.africastalking.com/version1/messaging';
        $at_phone = '+' . $clean_phone;

        $ch = curl_init($at_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'username' => AT_USERNAME,
            'to'       => $at_phone,
            'message'  => $message
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apiKey: ' . AT_API_KEY,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $at_res = curl_exec($ch);
        $at_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($at_res, true);
        if ($at_code === 201 && isset($json['SMSMessageData']['Recipients'])) {
            $recipient_info = $json['SMSMessageData']['Recipients'][0] ?? null;
            if ($recipient_info && ($recipient_info['status'] === 'Success' || $recipient_info['statusCode'] == 101)) {
                $is_success = true;
                $provider_used = "Africa's Talking";
                $status_detail = 'Sent (' . $recipient_info['messageId'] . ')';
            } else {
                $status_detail = 'AT Failed: ' . ($recipient_info['status'] ?? 'Unknown');
            }
        } else {
            $status_detail .= " | AT: HTTP $at_code";
        }
    }

    // Prepare status for DB logging (max 20 chars for database column)
    $db_status = $is_success ? 'Sent' : substr($status_detail, 0, 20);

    // Record entry into sms_logs
    try {
        $stmt = $pdo->prepare("INSERT INTO sms_logs (appointment_id, recipient_phone, message, reminder_type, status, sent_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$appointment_id, $clean_phone, $message, $reminder_type, $db_status]);
    } catch (Exception $e) {
        // Silently preserve stability
    }

    // Return status boolean for compatibility with existing codebase
    return $is_success;
}