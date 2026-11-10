import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import '../utils/theme.dart';
import 'appointments_screen.dart';
import 'log_symptoms_screen.dart';
import 'messages_screen.dart';
import 'under5_card_screen.dart';
import 'invoices_screen.dart';
import 'records_screen.dart';
import '../widgets/add_child_modal.dart';
import '../widgets/booking_modal.dart';
import '../services/urgent_alert_service.dart';

class DashboardScreen extends StatefulWidget {
  final VoidCallback? onSwitchToAppointments;
  const DashboardScreen({super.key, this.onSwitchToAppointments});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _isLoading = true;
  int? _guardianId;
  String _guardianName = 'Guardian';
  Map<String, dynamic>? _nextAppointment;
  List<dynamic> _children = [];
  List<dynamic> _allVaccines = [];
  Map<String, dynamic> _stats = {
    'children_count': 0,
    'upcoming_appointments': 0,
    'completed_vaccines': 0,
    'unread_messages': 0,
    'unread_notifications': 0,
    'pending_invoices': 0,
  };

  final TextEditingController _searchController = TextEditingController();
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    _fetchDashboardData();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _fetchDashboardData() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final guardianId = prefs.getInt('user_id');

      if (guardianId == null) {
        if (mounted) setState(() => _isLoading = false);
        return;
      }

      final response = await http.get(Uri.parse('$API_BASE_URL/dashboard.php?guardian_id=$guardianId'));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success') {
          setState(() {
            _guardianId = guardianId;
            _guardianName = data['guardian']?['name'] ?? 'Guardian';
            _nextAppointment = data['next_appointment'];
            _children = data['children'] ?? [];
            _stats = data['stats'] ?? _stats;
            _isLoading = false;
          });
        }
      }

      // Also fetch vaccines catalog for instant real-time search
      try {
        final vRes = await http.get(Uri.parse('$API_BASE_URL/vaccines.php'));
        if (vRes.statusCode == 200) {
          final vData = jsonDecode(vRes.body);
          if (vData['status'] == 'success') {
            setState(() {
              _allVaccines = vData['vaccines'] ?? [];
            });
          }
        }
      } catch (err) {
        debugPrint('Error fetching vaccines catalog: $err');
      }
    } catch (e) {
      debugPrint('Error fetching dashboard: $e');
      if (mounted) setState(() => _isLoading = false);
    } finally {
      if (mounted) {
        UrgentAlertService.checkForUrgentAlerts(
          context,
          onActionPressed: widget.onSwitchToAppointments,
        );
      }
    }
  }

  void _showRescheduleModal(Map<String, dynamic> appt) {
    DateTime selectedDate = DateTime.tryParse(appt['appointment_date'] ?? '') ?? DateTime.now().add(const Duration(days: 1));
    TimeOfDay selectedTime = const TimeOfDay(hour: 9, minute: 0);
    bool isSubmitting = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Container(
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          padding: EdgeInsets.only(
            top: 24,
            left: 20,
            right: 20,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 24,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Reschedule Appointment', style: GoogleFonts.spaceGrotesk(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.darkNavy)),
                  IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                'Adjust appointment date and session timing for ${appt['child_name'] ?? 'child'}.',
                style: GoogleFonts.inter(fontSize: 13, color: AppTheme.textMuted),
              ),
              const SizedBox(height: 20),

              // Date Picker
              Text('Preferred Date', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: () async {
                  final picked = await showDatePicker(
                    context: ctx,
                    initialDate: selectedDate,
                    firstDate: DateTime.now(),
                    lastDate: DateTime.now().add(const Duration(days: 90)),
                  );
                  if (picked != null) {
                    setModalState(() => selectedDate = picked);
                  }
                },
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  decoration: BoxDecoration(
                    color: AppTheme.backgroundLight,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: AppTheme.borderLight),
                  ),
                  child: Row(
                    children: [
                      const Icon(CupertinoIcons.calendar, size: 18, color: AppTheme.emeraldGreen),
                      const SizedBox(width: 10),
                      Text(
                        "${selectedDate.year}-${selectedDate.month.toString().padLeft(2, '0')}-${selectedDate.day.toString().padLeft(2, '0')}",
                        style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Time Picker
              Text('Preferred Session Time', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: () async {
                  final picked = await showTimePicker(context: ctx, initialTime: selectedTime);
                  if (picked != null) {
                    setModalState(() => selectedTime = picked);
                  }
                },
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  decoration: BoxDecoration(
                    color: AppTheme.backgroundLight,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: AppTheme.borderLight),
                  ),
                  child: Row(
                    children: [
                      const Icon(CupertinoIcons.clock, size: 18, color: AppTheme.emeraldGreen),
                      const SizedBox(width: 10),
                      Text(
                        selectedTime.format(context),
                        style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 24),

              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.emeraldGreen,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          setModalState(() => isSubmitting = true);
                          final dateStr = "${selectedDate.year}-${selectedDate.month.toString().padLeft(2, '0')}-${selectedDate.day.toString().padLeft(2, '0')}";
                          final timeStr = "${selectedTime.hour.toString().padLeft(2, '0')}:${selectedTime.minute.toString().padLeft(2, '0')}:00";

                          try {
                            final res = await http.post(
                              Uri.parse('$API_BASE_URL/appointments.php'),
                              headers: {'Content-Type': 'application/json'},
                              body: jsonEncode({
                                'action': 'reschedule',
                                'appointment_id': appt['appointment_id'],
                                'appointment_date': dateStr,
                                'appointment_time': timeStr,
                              }),
                            );
                            if (res.statusCode == 200) {
                              if (ctx.mounted) {
                                Navigator.pop(ctx);
                              }
                              if (mounted) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(
                                    backgroundColor: AppTheme.emeraldGreen,
                                    content: Text('Appointment successfully rescheduled! Clinical team notified in-system.'),
                                  ),
                                );
                                _fetchDashboardData();
                              }
                            }
                          } catch (_) {
                            if (mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('Failed to reschedule. Check network connection.')),
                              );
                            }
                          } finally {
                            if (ctx.mounted) {
                              setModalState(() => isSubmitting = false);
                            }
                          }
                        },
                  child: Text(isSubmitting ? 'Rescheduling...' : 'Confirm Reschedule', style: GoogleFonts.inter(fontWeight: FontWeight.bold)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(
        backgroundColor: AppTheme.backgroundLight,
        body: Center(child: CircularProgressIndicator(color: AppTheme.emeraldGreen)),
      );
    }

    final firstName = _guardianName.split(' ').first;

    return Scaffold(
      backgroundColor: AppTheme.backgroundLight,
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _fetchDashboardData,
          color: AppTheme.emeraldGreen,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // 1. Top Header Bar (Matching Screenshot)
                _buildHeader(firstName),
                const SizedBox(height: 18),

                // 2. Search Input Bar (Matching Screenshot)
                _buildSearchBar(),
                const SizedBox(height: 20),

                // If Search query is entered, render real-time live search results!
                if (_searchQuery.isNotEmpty) ...[
                  _buildRealtimeSearchResults(),
                ] else ...[
                  // 3. Featured Hero Card (Matching Screenshot Mint Card)
                  _buildHeroBanner(),
                  const SizedBox(height: 24),

                  // 4. Quick Access (4 Rounded Squircle Cards Matching Screenshot)
                  _buildQuickAccess(),
                  const SizedBox(height: 26),

                  // 5. Upcoming Appointment Card (Matching Screenshot)
                  _buildUpcomingAppointment(),
                  const SizedBox(height: 26),

                  // 6. Registered Children Section
                  _buildChildrenSection(_children),
                  const SizedBox(height: 24),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildHeader(String firstName) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Hello, $firstName',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 22,
                fontWeight: FontWeight.w800,
                color: const Color(0xFF111827),
              ),
            ),
            const SizedBox(height: 3),
            Text(
              'Take care of your child\'s health, it\'s the real wealth.',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 12.5,
                color: const Color(0xFF6B7280),
              ),
            ),
          ],
        ),
        // Notification bell with unread badge
        GestureDetector(
          onTap: () {
            Navigator.push(context, MaterialPageRoute(builder: (_) => const MessagesScreen()));
          },
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  border: Border.all(color: const Color(0xFFE5E7EB)),
                  boxShadow: [
                    BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 8, offset: const Offset(0, 2)),
                  ],
                ),
                child: const Icon(CupertinoIcons.bell, color: Color(0xFF111827), size: 21),
              ),
              if ((_stats['unread_messages'] ?? 0) > 0 || (_stats['unread_notifications'] ?? 0) > 0)
                Positioned(
                  top: 2,
                  right: 2,
                  child: Container(
                    width: 10,
                    height: 10,
                    decoration: const BoxDecoration(
                      color: Color(0xFF0E7052),
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildSearchBar() {
    return Container(
      height: 48,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: const Color(0xFFF3F4F6),
        borderRadius: BorderRadius.circular(100),
      ),
      child: Row(
        children: [
          const Icon(CupertinoIcons.search, color: Color(0xFF9CA3AF), size: 19),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: _searchController,
              onChanged: (val) => setState(() => _searchQuery = val.trim()),
              style: GoogleFonts.plusJakartaSans(fontSize: 13, color: const Color(0xFF111827)),
              decoration: InputDecoration(
                hintText: 'Search doctors, symptoms, vaccines...',
                hintStyle: GoogleFonts.plusJakartaSans(fontSize: 13, color: const Color(0xFF9CA3AF)),
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                contentPadding: EdgeInsets.zero,
                isDense: true,
                filled: false,
              ),
            ),
          ),
          if (_searchQuery.isNotEmpty)
            GestureDetector(
              onTap: () => setState(() {
                _searchController.clear();
                _searchQuery = '';
              }),
              child: const Icon(CupertinoIcons.clear_circled_solid, color: Color(0xFF9CA3AF), size: 18),
            )
          else
            const Icon(CupertinoIcons.slider_horizontal_3, color: Color(0xFF6B7280), size: 18),
        ],
      ),
    );
  }

  Widget _buildHeroBanner() {
    return Container(
      width: double.infinity,
      height: 195,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0E7052).withOpacity(0.08),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(24),
        child: Stack(
          children: [
            // Full background image
            Positioned.fill(
              child: Image.asset(
                'assets/images/child_vaccine_banner.jpg',
                fit: BoxFit.cover,
                alignment: const Alignment(0.4, 0.0),
                errorBuilder: (ctx, error, stackTrace) => Container(color: const Color(0xFF0E7052)),
              ),
            ),
            // Dark gradient overlay for text contrast
            Positioned.fill(
              child: Container(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                    colors: [
                      const Color(0xFF071E16).withOpacity(0.96),
                      const Color(0xFF071E16).withOpacity(0.85),
                      const Color(0xFF071E16).withOpacity(0.40),
                      const Color(0xFF071E16).withOpacity(0.10),
                    ],
                    stops: const [0.0, 0.42, 0.70, 1.0],
                  ),
                ),
              ),
            ),
            // Banner content
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0E7052).withOpacity(0.85),
                      borderRadius: BorderRadius.circular(100),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 6,
                          height: 6,
                          decoration: const BoxDecoration(
                            color: Color(0xFF4ADE80),
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          'Kansenshi Clinic • 24/7 Care',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  SizedBox(
                    width: 195,
                    child: Text(
                      'Schedule Child Immunization',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 16.5,
                        fontWeight: FontWeight.w800,
                        color: Colors.white,
                        height: 1.2,
                      ),
                    ),
                  ),
                  const SizedBox(height: 4),
                  SizedBox(
                    width: 185,
                    child: Text(
                      'Protect your infant with scheduled doses.',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 11.5,
                        color: Colors.white.withOpacity(0.85),
                        height: 1.3,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: const Color(0xFF0E7052),
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 9),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                      elevation: 0,
                    ),
                    onPressed: () {
                      if (widget.onSwitchToAppointments != null) {
                        widget.onSwitchToAppointments!();
                      } else {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => const AppointmentsScreen()));
                      }
                    },
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          'Book Now',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 12.5,
                            fontWeight: FontWeight.bold,
                            color: const Color(0xFF0E7052),
                          ),
                        ),
                        const SizedBox(width: 5),
                        const Icon(Icons.arrow_forward, size: 13, color: Color(0xFF0E7052)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildQuickAccess() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Quick Access',
          style: GoogleFonts.spaceGrotesk(
            fontSize: 16,
            fontWeight: FontWeight.bold,
            color: AppTheme.textMain,
          ),
        ),
        const SizedBox(height: 14),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            _buildQuickAccessCard(
              icon: CupertinoIcons.calendar_today,
              label: 'Vaccines',
              color: AppTheme.emeraldGreen,
              onTap: () {
                if (widget.onSwitchToAppointments != null) {
                  widget.onSwitchToAppointments!();
                } else {
                  Navigator.push(context, MaterialPageRoute(builder: (_) => const AppointmentsScreen()));
                }
              },
            ),
            _buildQuickAccessCard(
              icon: CupertinoIcons.doc_text_fill,
              label: 'Under-5 Card',
              color: AppTheme.primaryBlue,
              onTap: () {
                Navigator.push(context, MaterialPageRoute(builder: (_) => const RecordsScreen()));
              },
            ),
            _buildQuickAccessCard(
              icon: CupertinoIcons.heart_circle_fill,
              label: 'Log Vitals',
              color: AppTheme.dangerRose,
              onTap: () {
                Navigator.push(context, MaterialPageRoute(builder: (_) => const LogSymptomsScreen()));
              },
            ),
            _buildQuickAccessCard(
              icon: CupertinoIcons.bandage_fill,
              label: 'Pharmacy',
              color: AppTheme.secondaryCyan,
              onTap: () {
                Navigator.push(context, MaterialPageRoute(builder: (_) => const InvoicesScreen()));
              },
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildQuickAccessCard({
    required IconData icon,
    required String label,
    required Color color,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        children: [
          Container(
            width: 68,
            height: 68,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: AppTheme.borderLight),
              boxShadow: [
                BoxShadow(color: Colors.black.withAlpha(5), blurRadius: 10, offset: const Offset(0, 2)),
              ],
            ),
            child: Center(
              child: Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: color.withAlpha(20),
                  shape: BoxShape.circle,
                ),
                child: Icon(icon, color: color, size: 22),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppTheme.textMain,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildUpcomingAppointment() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'Upcoming Appointment',
              style: GoogleFonts.spaceGrotesk(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppTheme.textMain,
              ),
            ),
            GestureDetector(
              onTap: () {
                if (widget.onSwitchToAppointments != null) {
                  widget.onSwitchToAppointments!();
                } else {
                  Navigator.push(context, MaterialPageRoute(builder: (_) => const AppointmentsScreen()));
                }
              },
              child: Text(
                'See All →',
                style: GoogleFonts.inter(
                  fontSize: 12.5,
                  fontWeight: FontWeight.bold,
                  color: AppTheme.emeraldGreen,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_nextAppointment == null ||
            !['scheduled', 'confirmed'].contains((_nextAppointment!['status'] ?? '').toString().toLowerCase()))
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: AppTheme.borderLight),
            ),
            child: Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: const BoxDecoration(
                    color: AppTheme.mintHero,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(CupertinoIcons.calendar, color: AppTheme.emeraldGreen, size: 24),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'No Scheduled Session',
                        style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 14, color: AppTheme.textMain),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Book your infant\'s next vaccine dose.',
                        style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted),
                      ),
                    ],
                  ),
                ),
                TextButton(
                  onPressed: () {
                    Navigator.push(context, MaterialPageRoute(builder: (_) => const AppointmentsScreen()));
                  },
                  child: Text('Book Dose', style: GoogleFonts.inter(fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen)),
                ),
              ],
            ),
          )
        else
          GestureDetector(
            onTap: () => _showRescheduleModal(_nextAppointment!),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppTheme.borderLight),
                boxShadow: [
                  BoxShadow(color: Colors.black.withAlpha(6), blurRadius: 12, offset: const Offset(0, 4)),
                ],
              ),
              child: Column(
                children: [
                  Row(
                    children: [
                      CircleAvatar(
                        radius: 24,
                        backgroundColor: AppTheme.mintHero,
                        child: Text(
                          (_nextAppointment!['child_name'] ?? 'C').toString().substring(0, 1).toUpperCase(),
                          style: GoogleFonts.spaceGrotesk(color: AppTheme.emeraldGreen, fontWeight: FontWeight.bold, fontSize: 18),
                        ),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _nextAppointment!['child_name'] ?? 'Child Profile',
                              style: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.bold, color: AppTheme.textMain),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              _nextAppointment!['vaccine_name'] ?? 'Pediatric Vaccination',
                              style: GoogleFonts.inter(fontSize: 12.5, color: AppTheme.textMuted),
                            ),
                          ],
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppTheme.mintHero,
                          borderRadius: BorderRadius.circular(100),
                        ),
                        child: Text(
                          _nextAppointment!['status']?.toString().toUpperCase() ?? 'SCHEDULED',
                          style: GoogleFonts.spaceGrotesk(
                            fontSize: 10.5,
                            fontWeight: FontWeight.bold,
                            color: AppTheme.emeraldGreen,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  const Divider(height: 1, color: AppTheme.borderLight),
                  const SizedBox(height: 10),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          const Icon(CupertinoIcons.calendar, size: 14, color: AppTheme.emeraldGreen),
                          const SizedBox(width: 6),
                          Text(
                            "${_nextAppointment!['appointment_date'] ?? ''} • ${_nextAppointment!['appointment_time'] ?? ''}",
                            style: GoogleFonts.inter(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppTheme.textMain),
                          ),
                        ],
                      ),
                      Row(
                        children: [
                          Text(
                            'Reschedule',
                            style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                          ),
                          const Icon(CupertinoIcons.chevron_forward, size: 12, color: AppTheme.emeraldGreen),
                        ],
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildChildrenSection(List<dynamic> children) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'Registered Children',
              style: GoogleFonts.spaceGrotesk(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppTheme.textMain,
              ),
            ),
            GestureDetector(
              onTap: () {
                if (_guardianId != null) {
                  showAddChildModal(
                    context: context,
                    guardianId: _guardianId!,
                    onChildAdded: (_) => _fetchDashboardData(),
                  );
                }
              },
              child: Row(
                children: [
                  const Icon(CupertinoIcons.plus_circle_fill, size: 15, color: AppTheme.emeraldGreen),
                  const SizedBox(width: 4),
                  Text(
                    'Add Child',
                    style: GoogleFonts.inter(
                      fontSize: 12.5,
                      fontWeight: FontWeight.bold,
                      color: AppTheme.emeraldGreen,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (children.isEmpty)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: AppTheme.borderLight),
            ),
            child: Column(
              children: [
                Container(
                  width: 52,
                  height: 52,
                  decoration: const BoxDecoration(
                    color: AppTheme.mintHero,
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(CupertinoIcons.person_badge_plus, size: 26, color: AppTheme.emeraldGreen),
                ),
                const SizedBox(height: 12),
                Text('No Children Profiles Found', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold, fontSize: 15, color: AppTheme.textMain)),
                const SizedBox(height: 6),
                Text(
                  'Add your child to start tracking immunizations, access Under-5 clinic records, and log clinical symptoms.',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(fontSize: 12.5, color: AppTheme.textMuted, height: 1.4),
                ),
                const SizedBox(height: 16),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.emeraldGreen,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                  ),
                  icon: const Icon(CupertinoIcons.plus, size: 16),
                  label: Text('Add Child Now', style: GoogleFonts.inter(fontWeight: FontWeight.bold)),
                  onPressed: () {
                    if (_guardianId != null) {
                      showAddChildModal(
                        context: context,
                        guardianId: _guardianId!,
                        onChildAdded: (_) => _fetchDashboardData(),
                      );
                    }
                  },
                ),
              ],
            ),
          )
        else
          ...children.map((child) {
            final childName = child['name'] ?? 'Child Profile';
            final dob = child['date_of_birth'] ?? '';
            final gender = child['gender'] ?? 'N/A';
            final childId = child['id'];

            return Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppTheme.borderLight),
                boxShadow: [
                  BoxShadow(color: Colors.black.withAlpha(5), blurRadius: 10, offset: const Offset(0, 2)),
                ],
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 22,
                    backgroundColor: AppTheme.mintHero,
                    child: Text(
                      childName.isNotEmpty ? childName[0].toUpperCase() : 'C',
                      style: GoogleFonts.spaceGrotesk(color: AppTheme.emeraldGreen, fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(childName, style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 14.5, color: AppTheme.textMain)),
                        const SizedBox(height: 2),
                        Text('DOB: $dob  •  $gender', style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted)),
                      ],
                    ),
                  ),
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconButton(
                        icon: const Icon(CupertinoIcons.doc_text_fill, color: AppTheme.primaryBlue, size: 20),
                        tooltip: 'Under-5 Card',
                        onPressed: () {
                          Navigator.push(context, MaterialPageRoute(builder: (_) => Under5CardScreen(childId: childId, childName: childName)));
                        },
                      ),
                      IconButton(
                        icon: const Icon(CupertinoIcons.heart_fill, color: AppTheme.dangerRose, size: 20),
                        tooltip: 'Log Symptoms',
                        onPressed: () {
                          Navigator.push(context, MaterialPageRoute(builder: (_) => LogSymptomsScreen(preSelectedChildId: childId)));
                        },
                      ),
                    ],
                  ),
                ],
              ),
            );
          }),
      ],
    );
  }

  // --- REAL-TIME LIVE SEARCH IMPLEMENTATION ---
  Widget _buildRealtimeSearchResults() {
    final q = _searchQuery.toLowerCase();

    // 1. Children matches
    final matchedChildren = _children.where((c) {
      final name = (c['name'] ?? '').toString().toLowerCase();
      final gender = (c['gender'] ?? '').toString().toLowerCase();
      final dob = (c['date_of_birth'] ?? '').toString().toLowerCase();
      return name.contains(q) || gender.contains(q) || dob.contains(q);
    }).toList();

    // 2. Vaccines matches
    final matchedVaccines = _allVaccines.where((v) {
      final name = (v['name'] ?? '').toString().toLowerCase();
      final stage = (v['stage_title'] ?? v['stage'] ?? '').toString().toLowerCase();
      final desc = (v['description'] ?? '').toString().toLowerCase();
      final mfg = (v['manufacturer'] ?? '').toString().toLowerCase();
      return name.contains(q) || stage.contains(q) || desc.contains(q) || mfg.contains(q);
    }).toList();

    // 3. Appointment matches
    final List<Map<String, dynamic>> matchedAppointments = [];
    if (_nextAppointment != null) {
      final vName = (_nextAppointment!['vaccine_name'] ?? '').toString().toLowerCase();
      final cName = (_nextAppointment!['child_name'] ?? '').toString().toLowerCase();
      final room = (_nextAppointment!['room_number'] ?? '').toString().toLowerCase();
      final notes = (_nextAppointment!['notes'] ?? '').toString().toLowerCase();
      if (vName.contains(q) || cName.contains(q) || room.contains(q) || notes.contains(q) || 'appointment'.contains(q) || 'session'.contains(q)) {
        matchedAppointments.add(_nextAppointment!);
      }
    }

    // 4. Clinical Symptoms / Triage matches
    const symptomKeywords = ['fever', 'cough', 'cold', 'temp', 'vomit', 'diarrhea', 'rash', 'unwell', 'sick', 'cry', 'pain', 'feed', 'appetite', 'lethargic', 'triage'];
    final bool hasSymptomMatch = symptomKeywords.any((sym) => q.contains(sym));

    // 5. Staff / Department matches
    const staffKeywords = ['doctor', 'nurse', 'pediatrician', 'pharmacy', 'clinic', 'hospital', 'staff', 'station', 'prescription'];
    final bool hasStaffMatch = staffKeywords.any((st) => q.contains(st));

    final int totalMatches = matchedChildren.length +
        matchedVaccines.length +
        matchedAppointments.length +
        (hasSymptomMatch ? 1 : 0) +
        (hasStaffMatch ? 1 : 0);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Summary & Clear bar
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'Search Results ($totalMatches found)',
              style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
            ),
            GestureDetector(
              onTap: () {
                _searchController.clear();
                setState(() => _searchQuery = '');
              },
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: const Color(0xFFF3F4F6),
                  borderRadius: BorderRadius.circular(100),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: const [
                    Icon(CupertinoIcons.xmark, size: 12, color: Color(0xFF6B7280)),
                    SizedBox(width: 4),
                    Text('Clear', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF6B7280))),
                  ],
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),

        if (totalMatches == 0) ...[
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(26),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFE5E7EB)),
            ),
            child: Column(
              children: [
                Container(
                  width: 52,
                  height: 52,
                  decoration: const BoxDecoration(
                    color: Color(0xFFF3F4F6),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(CupertinoIcons.search, size: 26, color: Color(0xFF9CA3AF)),
                ),
                const SizedBox(height: 12),
                Text(
                  'No direct results for "$_searchQuery"',
                  style: GoogleFonts.spaceGrotesk(fontSize: 14.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
                ),
                const SizedBox(height: 4),
                Text(
                  'Try searching for vaccines (BCG, Polio, Rota), child name, symptoms (fever, cough), or clinic staff.',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted),
                ),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    _buildSearchSuggestionChip('BCG'),
                    _buildSearchSuggestionChip('Polio'),
                    _buildSearchSuggestionChip('Rota 1'),
                    _buildSearchSuggestionChip('Fever'),
                    _buildSearchSuggestionChip('Nurse Station'),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
        ],

        // 1. Children Matches
        if (matchedChildren.isNotEmpty) ...[
          _buildSearchCategoryHeader('👶 Matching Children (${matchedChildren.length})'),
          ...matchedChildren.map((c) => _buildChildSearchCard(c)),
          const SizedBox(height: 16),
        ],

        // 2. Vaccines Matches
        if (matchedVaccines.isNotEmpty) ...[
          _buildSearchCategoryHeader('💉 Vaccines & Milestones (${matchedVaccines.length})'),
          ...matchedVaccines.map((v) => _buildVaccineSearchCard(v)),
          const SizedBox(height: 16),
        ],

        // 3. Appointments Matches
        if (matchedAppointments.isNotEmpty) ...[
          _buildSearchCategoryHeader('📅 Upcoming Sessions'),
          ...matchedAppointments.map((a) => _buildAppointmentSearchCard(a)),
          const SizedBox(height: 16),
        ],

        // 4. Clinical Triage Match
        if (hasSymptomMatch) ...[
          _buildSearchCategoryHeader('🩺 Clinical Health & Triage Guidance'),
          _buildSymptomGuidanceCard(q),
          const SizedBox(height: 16),
        ],

        // 5. Staff Match
        if (hasStaffMatch) ...[
          _buildSearchCategoryHeader('👨‍⚕️ Clinical Team & Facility'),
          _buildStaffDepartmentCard(),
          const SizedBox(height: 16),
        ],
      ],
    );
  }

  Widget _buildSearchCategoryHeader(String title) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8, top: 4),
      child: Text(
        title,
        style: GoogleFonts.spaceGrotesk(fontSize: 13, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
      ),
    );
  }

  Widget _buildSearchSuggestionChip(String label) {
    return ActionChip(
      label: Text(label, style: const TextStyle(fontSize: 11, color: AppTheme.emeraldGreen, fontWeight: FontWeight.bold)),
      backgroundColor: AppTheme.mintHero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
      side: BorderSide.none,
      onPressed: () {
        _searchController.text = label;
        setState(() => _searchQuery = label);
      },
    );
  }

  Widget _buildChildSearchCard(Map<String, dynamic> c) {
    final childName = c['name'] ?? 'Child';
    final dob = c['date_of_birth'] ?? '';
    final gender = c['gender'] ?? 'N/A';
    final childId = c['id'];

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE5E7EB)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: AppTheme.mintHero,
            child: Text(
              childName.isNotEmpty ? childName[0].toUpperCase() : 'C',
              style: GoogleFonts.spaceGrotesk(color: AppTheme.emeraldGreen, fontWeight: FontWeight.bold, fontSize: 15),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(childName, style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13.5, color: AppTheme.darkNavy)),
                Text('DOB: $dob • $gender', style: GoogleFonts.inter(fontSize: 11, color: AppTheme.textMuted)),
              ],
            ),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.emeraldGreen,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              elevation: 0,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () {
              Navigator.push(context, MaterialPageRoute(builder: (_) => Under5CardScreen(childId: childId, childName: childName)));
            },
            child: const Text('Under-5 Card', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  Widget _buildVaccineSearchCard(Map<String, dynamic> v) {
    final name = v['name'] ?? 'Vaccine';
    final stage = v['stage_title'] ?? v['stage'] ?? 'Immunization';
    final desc = v['description'] ?? '';
    final vId = v['id'];

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE5E7EB)),
      ),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: AppTheme.mintHero,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(CupertinoIcons.eyedropper_full, size: 18, color: AppTheme.emeraldGreen),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        name,
                        style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.darkNavy),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(6)),
                      child: Text(stage, style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen)),
                    ),
                  ],
                ),
                if (desc.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    desc,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(fontSize: 11, color: AppTheme.textMuted),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          OutlinedButton(
            style: OutlinedButton.styleFrom(
              foregroundColor: AppTheme.emeraldGreen,
              side: const BorderSide(color: AppTheme.emeraldGreen),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () {
              showBookingModal(context: context, initialVaccineId: vId, onBookingSuccess: _fetchDashboardData);
            },
            child: const Text('Book', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  Widget _buildAppointmentSearchCard(Map<String, dynamic> a) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE5E7EB)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                a['vaccine_name'] ?? 'Vaccination Session',
                style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13.5, color: AppTheme.darkNavy),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(6)),
                child: Text(
                  a['status'] ?? 'Scheduled',
                  style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '📅 ${a['appointment_date']} at ${a['appointment_time']} • 📍 ${a['room_number'] ?? 'Nurse Station'}',
            style: GoogleFonts.inter(fontSize: 11.5, color: AppTheme.textMuted),
          ),
        ],
      ),
    );
  }

  Widget _buildSymptomGuidanceCard(String query) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFFECACA)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: const [
              Icon(CupertinoIcons.heart_fill, size: 18, color: AppTheme.dangerRose),
              SizedBox(width: 8),
              Text('Pediatric Health Advisory', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.dangerRose)),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'If your infant has a fever (≥ 38.5°C), lethargy, or poor feeding, enter these in the Pre-Vaccine Triage section when booking. The nurse and pediatrician will be automatically alerted.',
            style: GoogleFonts.inter(fontSize: 11.5, color: const Color(0xFF991B1B)),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.dangerRose,
                  foregroundColor: Colors.white,
                  elevation: 0,
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () {
                  showBookingModal(context: context, onBookingSuccess: _fetchDashboardData);
                },
                child: const Text('Book with Triage', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 8),
              OutlinedButton(
                style: OutlinedButton.styleFrom(
                  foregroundColor: const Color(0xFF991B1B),
                  side: const BorderSide(color: Color(0xFFF87171)),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () {
                  Navigator.push(context, MaterialPageRoute(builder: (_) => const LogSymptomsScreen()));
                },
                child: const Text('Log Vitals', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStaffDepartmentCard() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE5E7EB)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: const Color(0xFFE8F8F2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(CupertinoIcons.building_2_fill, color: Color(0xFF0E7052), size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Kansenshi Clinic Clinical Team',
                  style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.darkNavy),
                ),
                Text(
                  'Pediatric Nurses • Attending Doctors • Pharmacy',
                  style: GoogleFonts.inter(fontSize: 11, color: AppTheme.textMuted),
                ),
              ],
            ),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.emeraldGreen,
              foregroundColor: Colors.white,
              elevation: 0,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () {
              Navigator.push(context, MaterialPageRoute(builder: (_) => const MessagesScreen()));
            },
            child: const Text('Message', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }
}
