import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import '../utils/theme.dart';
import '../utils/sound_service.dart';

class UrgentAlertService {
  static bool _isDialogShowing = false;

  /// Checks for any urgent notifications (due in 2 hours or rescheduled by nurse)
  /// and shows a high-priority dialog with a distinctive pop-up chime sound.
  static Future<void> checkForUrgentAlerts(BuildContext context, {VoidCallback? onActionPressed}) async {
    if (_isDialogShowing) return;

    try {
      final prefs = await SharedPreferences.getInstance();
      final guardianId = prefs.getInt('user_id');
      if (guardianId == null || guardianId <= 0) return;

      final res = await http.get(
        Uri.parse('$API_BASE_URL/appointments.php?action=check_urgent_alerts&guardian_id=$guardianId'),
      );

      if (res.statusCode != 200) return;
      final data = jsonDecode(res.body);

      if (data['status'] == 'success' && data['has_alert'] == true) {
        final String alertId = data['alert_id'] ?? '';
        final String alertType = data['alert_type'] ?? '';
        final String lastDismissed = prefs.getString('dismissed_urgent_alert_$guardianId') ?? '';

        // If this exact alert was already dismissed by guardian recently, avoid redundant modal pop
        if (lastDismissed == alertId) return;

        // Play pop chime sound + haptics
        await SoundService.playPopSound();

        if (!context.mounted) return;

        _isDialogShowing = true;

        final is2Hours = alertType == '2_hours';
        final title = data['alert_title'] ?? (is2Hours ? '🔔 Appointment in 2 Hours!' : '⚠️ Appointment Rescheduled');
        final message = data['alert_message'] ?? '';
        final childName = data['child_name'] ?? 'Child';
        final vaccineName = data['vaccine_name'] ?? 'Vaccination';
        final apptTime = data['appointment_time'] ?? '';
        final apptDate = data['appointment_date'] ?? 'Today';
        final location = data['room_number'] ?? 'Nurse Station 1 & 2 (Immunization Clinic)';

        await showDialog(
          context: context,
          barrierDismissible: false,
          builder: (dialogCtx) => AlertDialog(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
            contentPadding: const EdgeInsets.all(22),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Top Header with Icon and Badge
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 48,
                      height: 48,
                      decoration: BoxDecoration(
                        color: is2Hours ? const Color(0xFFFEF3C7) : const Color(0xFFEDE9FE),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(
                          color: is2Hours ? const Color(0xFFFDE68A) : const Color(0xFFDDD6FE),
                        ),
                      ),
                      child: Icon(
                        is2Hours ? CupertinoIcons.bell_fill : CupertinoIcons.clock_fill,
                        color: is2Hours ? const Color(0xFFD97706) : const Color(0xFF7C3AED),
                        size: 26,
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: is2Hours ? const Color(0xFFFFFBEB) : const Color(0xFFF5F3FF),
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(
                                color: is2Hours ? const Color(0xFFFCD34D) : const Color(0xFFC4B5FD),
                              ),
                            ),
                            child: Text(
                              is2Hours ? 'URGENT REMINDER • 2 HOURS' : 'NURSE RESCHEDULE NOTICE',
                              style: TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w800,
                                color: is2Hours ? const Color(0xFFB45309) : const Color(0xFF6D28D9),
                                letterSpacing: 0.5,
                              ),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            title,
                            style: GoogleFonts.spaceGrotesk(
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                              color: AppTheme.darkNavy,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Message Text
                Text(
                  message,
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    color: const Color(0xFF374151),
                    height: 1.45,
                  ),
                ),
                const SizedBox(height: 16),

                // Details Card
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF9FAFB),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xFFE5E7EB)),
                  ),
                  child: Column(
                    children: [
                      _buildAlertDetailRow(CupertinoIcons.person_fill, 'Patient', childName),
                      const SizedBox(height: 6),
                      _buildAlertDetailRow(CupertinoIcons.eyedropper_full, 'Vaccine', vaccineName),
                      const SizedBox(height: 6),
                      _buildAlertDetailRow(
                        CupertinoIcons.calendar,
                        'Date & Time',
                        is2Hours ? 'Today at $apptTime' : '$apptDate at $apptTime',
                      ),
                      const SizedBox(height: 6),
                      _buildAlertDetailRow(CupertinoIcons.building_2_fill, 'Location', location),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Actions
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: const Color(0xFF6B7280),
                          side: const BorderSide(color: Color(0xFFD1D5DB)),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                        ),
                        onPressed: () async {
                          await prefs.setString('dismissed_urgent_alert_$guardianId', alertId);
                          if (dialogCtx.mounted) Navigator.pop(dialogCtx);
                        },
                        child: const Text('Dismiss', style: TextStyle(fontWeight: FontWeight.w600)),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: is2Hours ? const Color(0xFFD97706) : AppTheme.emeraldGreen,
                          foregroundColor: Colors.white,
                          elevation: 0,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                        ),
                        onPressed: () async {
                          await prefs.setString('dismissed_urgent_alert_$guardianId', alertId);
                          if (dialogCtx.mounted) Navigator.pop(dialogCtx);
                          onActionPressed?.call();
                        },
                        child: Text(
                          is2Hours ? 'View Session' : 'View Schedule',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );

        _isDialogShowing = false;
      }
    } catch (e) {
      debugPrint('Error in checkForUrgentAlerts: $e');
      _isDialogShowing = false;
    }
  }

  static Widget _buildAlertDetailRow(IconData icon, String label, String value) {
    return Row(
      children: [
        Icon(icon, size: 14, color: const Color(0xFF6B7280)),
        const SizedBox(width: 8),
        Text(
          '$label: ',
          style: GoogleFonts.inter(fontSize: 11.5, color: const Color(0xFF6B7280), fontWeight: FontWeight.w500),
        ),
        Expanded(
          child: Text(
            value,
            textAlign: TextAlign.right,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
          ),
        ),
      ],
    );
  }
}

