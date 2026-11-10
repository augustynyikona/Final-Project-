
<?php
/**
 * VaxiCare In-System Notification & Clinical Booking Dispatcher Helper
 * 
 * Automatically triggers in-system alerts and logs for:
 * 1. The Guardian (inbox message, mobile app notification bell, and digital SMS alert log)
 * 2. Clinical Staff / Nurses (inbox alert, real-time topbar bell notification)
 * 3. Mobile API consumers (recorded into sms_logs for Flutter app)
 * 4. Conflict detection & automated multi-interval reminders (7-day, 5-day, 2-day)
 */

if (!function_exists('recordAppointmentNotification')) {
    function recordAppointmentNotification($pdo, $appointment_id, $action = 'booked', $extra = []) {
        if (!$pdo || empty($appointment_id)) {
            return false;
        }

        try {
            // 1. Fetch complete appointment, child, guardian, and optional vaccine details
            $stmt = $pdo->prepare("
                SELECT 
                    a.id, a.child_id, a.vaccine_id, a.appointment_date, a.appointment_time, a.status, a.notes, a.cancellation_reason, a.room_number,
                    c.name as child_name, c.guardian_id,
                    u.name as guardian_name, u.phone as guardian_phone, u.email as guardian_email,
                    v.name as vaccine_name
                FROM appointments a
                JOIN children c ON a.child_id = c.id
                JOIN users u ON c.guardian_id = u.id
                LEFT JOIN vaccines v ON a.vaccine_id = v.id
                WHERE a.id = ?
            ");
            $stmt->execute([(int)$appointment_id]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                return false;
            }

            $child_name     = htmlspecialchars($appt['child_name']);
            $vaccine_name   = !empty($appt['vaccine_name']) ? htmlspecialchars($appt['vaccine_name']) : (!empty($appt['notes']) ? htmlspecialchars($appt['notes']) : 'Clinical Review');
            $guardian_name  = htmlspecialchars($appt['guardian_name']);
            $guardian_id    = (int)$appt['guardian_id'];
            $guardian_phone = $appt['guardian_phone'];
            $formatted_date = date('M d, Y', strtotime($appt['appointment_date']));
            $formatted_time = date('h:i A', strtotime($appt['appointment_time']));

            // Determine system staff sender
            $senderStmt = $pdo->query("SELECT id FROM users WHERE role IN ('doctor', 'nurse', 'admin') ORDER BY id ASC LIMIT 1");
            $system_sender_id = (int)($senderStmt->fetchColumn() ?: 1);

            $reason = trim($extra['reason'] ?? ($appt['cancellation_reason'] ?? ''));
            $cancelled_by = $extra['cancelled_by_name'] ?? 'Doctor';

            if ($action === 'cancelled') {
                $guardian_subject = "Appointment Cancelled: $vaccine_name for $child_name";
                $guardian_body    = "Your appointment has been cancelled by the doctor." . (!empty($reason) ? " Reason: " . $reason . "." : "") . " Please check your appointment section or contact the hospital for further assistance.";
                
                $nurse_subject    = "Appointment Cancelled by $cancelled_by: $child_name";
                $nurse_body       = "$cancelled_by has cancelled the appointment for $child_name ($vaccine_name) scheduled on $formatted_date at $formatted_time." . (!empty($reason) ? " Reason: " . $reason : "");
                $reminder_type    = "cancelled";
                $sms_status       = "Cancelled";

                // Remove or invalidate any pending future automated reminders for this cancelled visit
                try {
                    $pdo->prepare("DELETE FROM sms_logs WHERE appointment_id = ? AND reminder_type IN ('7_days', '5_days', '2_days')")->execute([(int)$appointment_id]);
                } catch (Exception $del_err) {}

            } elseif ($action === 'rescheduled') {
                $guardian_subject = "Appointment Rescheduled: $vaccine_name for $child_name";
                $guardian_body    = "Your appointment for $child_name ($vaccine_name) has been rescheduled to $formatted_date at $formatted_time at Kansenshi Pediatric Clinic.";
                
                $nurse_subject    = "Schedule Update: $child_name - $vaccine_name";
                $nurse_body       = "Appointment for $child_name ($vaccine_name) has been moved to $formatted_date at $formatted_time.";
                $reminder_type    = "rescheduled";
                $sms_status       = "In-System";

                // Reset reminder flags so fresh reminders can be evaluated for new date
                try {
                    $pdo->prepare("DELETE FROM sms_logs WHERE appointment_id = ? AND reminder_type IN ('7_days', '5_days', '2_days')")->execute([(int)$appointment_id]);
                } catch (Exception $del_err) {}

            } else {
                $guardian_subject = "Appointment Confirmed: $vaccine_name for $child_name";
                $guardian_body    = "Your appointment for $child_name ($vaccine_name) has been confirmed for $formatted_date at $formatted_time at Kansenshi Pediatric Clinic.";
                
                $nurse_subject    = "New Booking: $child_name - $vaccine_name";
                $nurse_body       = "$guardian_name has a scheduled $vaccine_name session for $child_name on $formatted_date at $formatted_time.";
                $reminder_type    = "booking";
                $sms_status       = "In-System";
            }

            // 2. Insert In-System Message for Guardian
            $msgGuardianStmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, recipient_role, subject, body, is_read, created_at)
                VALUES (?, ?, 'guardian', ?, ?, 0, NOW())
            ");
            $msgGuardianStmt->execute([$system_sender_id, $guardian_id, $guardian_subject, $guardian_body]);

            // 3. Insert In-System Notification for Nurses
            $msgNurseStmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, recipient_role, subject, body, is_read, created_at)
                VALUES (?, NULL, 'nurse', ?, ?, 0, NOW())
            ");
            $msgNurseStmt->execute([$guardian_id, $nurse_subject, $nurse_body]);

            // 4. Insert into sms_logs (powers Mobile App alerts & Guardian portal SMS history)
            $clean_phone = preg_replace('/[^0-9]/', '', $guardian_phone);
            if (strpos($clean_phone, '0') === 0) {
                $clean_phone = '260' . substr($clean_phone, 1);
            } elseif (strlen($clean_phone) === 9) {
                $clean_phone = '260' . $clean_phone;
            }

            $logStmt = $pdo->prepare("
                INSERT INTO sms_logs (appointment_id, recipient_phone, message, reminder_type, status, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([$appointment_id, $clean_phone ?: $guardian_phone, $guardian_body, $reminder_type, $sms_status]);

            // 5. Dispatch Email to Guardian
            if (!empty($appt['guardian_email'])) {
                $to = $appt['guardian_email'];
                $headers = "From: VaxiCare Clinical Alerts <noreply@vaxicare.mch.health>\r\n" .
                           "Reply-To: support@vaxicare.mch.health\r\n" .
                           "X-Mailer: PHP/" . phpversion() . "\r\n" .
                           "MIME-Version: 1.0\r\n" .
                           "Content-Type: text/plain; charset=UTF-8\r\n";
                @mail($to, $guardian_subject, $guardian_body, $headers);
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Backend Appointment Conflict & Overlap Detection
 * Validates availability for both doctors and nurses within 30-minute consultation buffers.
 */
if (!function_exists('checkAppointmentConflict')) {
    function checkAppointmentConflict($pdo, $appointment_date, $appointment_time, $exclude_appointment_id = null) {
        if (!$pdo || empty($appointment_date) || empty($appointment_time)) {
            return ['has_conflict' => false, 'message' => ''];
        }

        try {
            $exclude_sql = $exclude_appointment_id ? "AND id != " . (int)$exclude_appointment_id : "";
            
            // Check for exact match or overlapping 30-minute clinical window
            $stmt = $pdo->prepare("
                SELECT id, appointment_time, notes, room_number 
                FROM appointments 
                WHERE appointment_date = ? 
                  AND status IN ('scheduled', 'confirmed')
                  $exclude_sql
                  AND (
                      appointment_time = ?
                      OR (appointment_time >= SUBTIME(?, '00:25:00') AND appointment_time <= ADDTIME(?, '00:25:00'))
                  )
                LIMIT 1
            ");
            $stmt->execute([$appointment_date, $appointment_time, $appointment_time, $appointment_time]);
            $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conflict) {
                // Generate 3 available alternative slots on the same date
                $standard_slots = ['09:00:00', '10:00:00', '11:00:00', '12:00:00', '14:00:00', '15:00:00', '16:00:00'];
                $busy_stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND status IN ('scheduled', 'confirmed') $exclude_sql");
                $busy_stmt->execute([$appointment_date]);
                $busy_times = $busy_stmt->fetchAll(PDO::FETCH_COLUMN);

                $available_slots = [];
                foreach ($standard_slots as $slot) {
                    $is_occupied = false;
                    foreach ($busy_times as $bt) {
                        if ($bt === $slot || (strtotime($bt) >= strtotime($slot) - 1500 && strtotime($bt) <= strtotime($slot) + 1500)) {
                            $is_occupied = true;
                            break;
                        }
                    }
                    if (!$is_occupied) {
                        $available_slots[] = $slot;
                    }
                    if (count($available_slots) >= 3) break;
                }

                $time_str = date('h:i A', strtotime($conflict['appointment_time']));
                return [
                    'has_conflict' => true,
                    'conflict_time' => $conflict['appointment_time'],
                    'message' => "This appointment time is unavailable. The doctor or nurse already has another scheduled appointment at {$time_str}. Please select another available time or date.",
                    'available_slots' => $available_slots
                ];
            }

            return ['has_conflict' => false, 'message' => '', 'available_slots' => []];
        } catch (Exception $e) {
            error_log("Conflict check error: " . $e->getMessage());
            return ['has_conflict' => false, 'message' => ''];
        }
    }
}

/**
 * Automated 7-Day, 5-Day, and 2-Day Reminder Dispatch Engine
 * Evaluates upcoming appointments and clinical reviews and logs non-duplicate reminders.
 */
if (!function_exists('dispatchAutomatedReminders')) {
    function dispatchAutomatedReminders($pdo) {
        if (!$pdo) return 0;

        try {
            $today = new DateTime('today');
            $dispatched_count = 0;

            // Fetch all scheduled / confirmed visits
            $stmt = $pdo->query("
                SELECT 
                    a.id as appointment_id, a.appointment_date, a.appointment_time, a.notes,
                    c.name as child_name, c.guardian_id,
                    u.name as guardian_name, u.phone as guardian_phone,
                    v.name as vaccine_name
                FROM appointments a
                JOIN children c ON a.child_id = c.id
                JOIN users u ON c.guardian_id = u.id
                LEFT JOIN vaccines v ON a.vaccine_id = v.id
                WHERE a.status IN ('scheduled', 'confirmed')
                  AND a.appointment_date >= CURDATE()
            ");
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($appointments as $appt) {
                $appt_date = new DateTime($appt['appointment_date']);
                $diff = (int)$today->diff($appt_date)->format('%r%a'); // Signed days difference

                $reminder_type = null;
                if ($diff === 7) {
                    $reminder_type = '7_days';
                } elseif ($diff === 5) {
                    $reminder_type = '5_days';
                } elseif ($diff === 2) {
                    $reminder_type = '2_days';
                }

                if ($reminder_type) {
                    // Check if this specific interval reminder has already been logged
                    $check_stmt = $pdo->prepare("SELECT id FROM sms_logs WHERE appointment_id = ? AND reminder_type = ?");
                    $check_stmt->execute([$appt['appointment_id'], $reminder_type]);
                    
                    if (!$check_stmt->fetch()) {
                        $date_str = date('d M Y', strtotime($appt['appointment_date']));
                        $time_str = date('h:i A', strtotime($appt['appointment_time']));
                        $visit_name = !empty($appt['vaccine_name']) ? $appt['vaccine_name'] : (!empty($appt['notes']) ? $appt['notes'] : 'clinical review');

                        $msg = "Appointment Reminder: Your next {$visit_name} is scheduled for {$date_str} at {$time_str}. Please remember to attend your appointment.";

                        // Phone clean
                        $clean_phone = preg_replace('/[^0-9]/', '', $appt['guardian_phone']);
                        if (strpos($clean_phone, '0') === 0) {
                            $clean_phone = '260' . substr($clean_phone, 1);
                        } elseif (strlen($clean_phone) === 9) {
                            $clean_phone = '260' . $clean_phone;
                        }

                        // 1. Log to sms_logs for Flutter app & notification history
                        $logStmt = $pdo->prepare("
                            INSERT INTO sms_logs (appointment_id, recipient_phone, message, reminder_type, status, sent_at)
                            VALUES (?, ?, ?, ?, 'Sent', NOW())
                        ");
                        $logStmt->execute([$appt['appointment_id'], $clean_phone ?: $appt['guardian_phone'], $msg, $reminder_type]);

                        // 2. Also record in messages table
                        $msgStmt = $pdo->prepare("
                            INSERT INTO messages (sender_id, receiver_id, recipient_role, subject, body, is_read, created_at)
                            VALUES (1, ?, 'guardian', 'Upcoming Clinical Reminder', ?, 0, NOW())
                        ");
                        $msgStmt->execute([$appt['guardian_id'], $msg]);

                        $dispatched_count++;
                    }
                }
            }

            // Check for appointments due within the next 2 hours today
            $twoHourStmt = $pdo->query("
                SELECT 
                    a.id as appointment_id, a.appointment_date, a.appointment_time, a.notes, a.room_number,
                    c.name as child_name, c.guardian_id,
                    u.name as guardian_name, u.phone as guardian_phone, u.email as guardian_email,
                    v.name as vaccine_name
                FROM appointments a
                JOIN children c ON a.child_id = c.id
                JOIN users u ON c.guardian_id = u.id
                LEFT JOIN vaccines v ON a.vaccine_id = v.id
                WHERE a.status IN ('scheduled', 'confirmed')
                  AND a.appointment_date = CURDATE()
                  AND a.appointment_time > CURTIME()
                  AND TIMEDIFF(a.appointment_time, CURTIME()) <= '02:05:00'
            ");
            $twoHourAppts = $twoHourStmt ? $twoHourStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            foreach ($twoHourAppts as $thAppt) {
                // Check if 2_hours reminder already logged
                $cStmt = $pdo->prepare("SELECT id FROM sms_logs WHERE appointment_id = ? AND reminder_type = '2_hours'");
                $cStmt->execute([$thAppt['appointment_id']]);
                if (!$cStmt->fetch()) {
                    $time_str = date('h:i A', strtotime($thAppt['appointment_time']));
                    $visit_name = !empty($thAppt['vaccine_name']) ? $thAppt['vaccine_name'] : (!empty($thAppt['notes']) ? $thAppt['notes'] : 'Clinical Review');
                    $child_name = $thAppt['child_name'];
                    $guardian_name = $thAppt['guardian_name'];
                    $room = !empty($thAppt['room_number']) ? $thAppt['room_number'] : 'Nurse Station 1';

                    $msg = "Urgent Reminder: {$child_name}'s {$visit_name} is in 2 hours at {$time_str} at Kansenshi Pediatric Clinic ({$room}). Please remember your Under-5 Health Passport.";

                    $clean_phone = preg_replace('/[^0-9]/', '', $thAppt['guardian_phone']);
                    if (strpos($clean_phone, '0') === 0) {
                        $clean_phone = '260' . substr($clean_phone, 1);
                    } elseif (strlen($clean_phone) === 9) {
                        $clean_phone = '260' . $clean_phone;
                    }

                    // 1. Log to sms_logs with reminder_type = '2_hours'
                    $logStmt = $pdo->prepare("
                        INSERT INTO sms_logs (appointment_id, recipient_phone, message, reminder_type, status, sent_at)
                        VALUES (?, ?, ?, '2_hours', 'Sent', NOW())
                    ");
                    $logStmt->execute([$thAppt['appointment_id'], $clean_phone ?: $thAppt['guardian_phone'], $msg]);

                    // 2. Insert into messages
                    $msgStmt = $pdo->prepare("
                        INSERT INTO messages (sender_id, receiver_id, recipient_role, subject, body, is_read, created_at)
                        VALUES (1, ?, 'guardian', '🔔 Appointment in 2 Hours', ?, 0, NOW())
                    ");
                    $msgStmt->execute([$thAppt['guardian_id'], $msg]);

                    // 3. Send Email to guardian
                    if (!empty($thAppt['guardian_email'])) {
                        $to = $thAppt['guardian_email'];
                        $subj = "🔔 Urgent: {$child_name}'s Vaccination Appointment in 2 Hours";
                        $headers = "From: VaxiCare Clinical Alerts <noreply@vaxicare.mch.health>\r\n" .
                                   "Reply-To: support@vaxicare.mch.health\r\n" .
                                   "X-Mailer: PHP/" . phpversion() . "\r\n" .
                                   "MIME-Version: 1.0\r\n" .
                                   "Content-Type: text/plain; charset=UTF-8\r\n";
                        @mail($to, $subj, $msg, $headers);
                    }

                    $dispatched_count++;
                }
            }

            return $dispatched_count;
        } catch (Exception $e) {
            error_log("Reminder dispatch error: " . $e->getMessage());
            return 0;
        }
    }
}
