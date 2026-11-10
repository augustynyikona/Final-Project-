import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import '../utils/theme.dart';
import '../widgets/add_child_modal.dart';
import '../widgets/custom_bottom_nav_bar.dart';
import '../widgets/booking_modal.dart';
import '../services/urgent_alert_service.dart';

class AppointmentsScreen extends StatefulWidget {
  final bool showBottomNav;
  const AppointmentsScreen({super.key, this.showBottomNav = false});

  @override
  State<AppointmentsScreen> createState() => _AppointmentsScreenState();
}

class _AppointmentsScreenState extends State<AppointmentsScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isLoading = true;
  int? _guardianId;
  List<dynamic> _appointments = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    final prefs = await SharedPreferences.getInstance();
    _guardianId = prefs.getInt('user_id');

    if (_guardianId == null) {
      if (mounted) setState(() => _isLoading = false);
      return;
    }

    try {
      // Fetch Appointments
      final aRes = await http.get(Uri.parse('$API_BASE_URL/appointments.php?guardian_id=$_guardianId'));
      if (aRes.statusCode == 200) {
        final aData = jsonDecode(aRes.body);
        if (aData['status'] == 'success') {
          _appointments = aData['appointments'] ?? [];
        }
      }
    } catch (e) {
      debugPrint('Error loading appointments data: $e');
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
        UrgentAlertService.checkForUrgentAlerts(context, onActionPressed: () {
          _loadData();
        });
      }
    }
  }

  Future<void> _showBookingModal({int? initialChildId}) async {
    final booked = await showBookingModal(
      context: context,
      initialChildId: initialChildId,
      onBookingSuccess: () {
        _loadData();
      },
    );
    if (booked == true) {
      _loadData();
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
                  Text('Reschedule Appointment', style: GoogleFonts.spaceGrotesk(fontSize: 18, fontWeight: FontWeight.bold)),
                  IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                ],
              ),
              const SizedBox(height: 16),
              Text('Select New Date', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              InkWell(
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
                  padding: const EdgeInsets.all(14),
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
                        style: GoogleFonts.inter(fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text('Select New Session Time', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              InkWell(
                onTap: () async {
                  final picked = await showTimePicker(context: ctx, initialTime: selectedTime);
                  if (picked != null) {
                    setModalState(() => selectedTime = picked);
                  }
                },
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: AppTheme.backgroundLight,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: AppTheme.borderLight),
                  ),
                  child: Row(
                    children: [
                      const Icon(CupertinoIcons.clock, size: 18, color: AppTheme.emeraldGreen),
                      const SizedBox(width: 10),
                      Text(selectedTime.format(context), style: GoogleFonts.inter(fontWeight: FontWeight.bold)),
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
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                  ),
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          setModalState(() => isSubmitting = true);
                          final dateStr =
                              "${selectedDate.year}-${selectedDate.month.toString().padLeft(2, '0')}-${selectedDate.day.toString().padLeft(2, '0')}";
                          final timeStr =
                              "${selectedTime.hour.toString().padLeft(2, '0')}:${selectedTime.minute.toString().padLeft(2, '0')}:00";

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
                                    content: Text('Appointment successfully rescheduled! Clinical team notified.'),
                                  ),
                                );
                                _loadData();
                              }
                            }
                          } catch (_) {
                            if (mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('Failed to reschedule.')),
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

  Future<void> _cancelAppointment(int appointmentId) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text('Cancel Appointment', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold)),
        content: Text('Are you sure you want to cancel this vaccination appointment?', style: GoogleFonts.inter(fontSize: 13.5)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('No, Keep')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppTheme.dangerRose),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Yes, Cancel'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      try {
        final res = await http.post(
          Uri.parse('$API_BASE_URL/appointments.php'),
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode({
            'action': 'cancel',
            'appointment_id': appointmentId,
          }),
        );
        if (res.statusCode == 200) {
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Appointment has been cancelled.')),
            );
            _loadData();
          }
        }
      } catch (_) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Failed to cancel appointment.')),
          );
        }
      }
    }
  }

  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'scheduled':
      case 'confirmed':
        return AppTheme.emeraldGreen;
      case 'completed':
        return AppTheme.primaryBlue;
      case 'cancelled':
      case 'no-show':
        return AppTheme.dangerRose;
      case 'rescheduled':
        return AppTheme.warningAmber;
      default:
        return AppTheme.textMuted;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.backgroundLight,
      appBar: AppBar(
        title: Text(
          'Vaccination Sessions',
          style: GoogleFonts.spaceGrotesk(fontSize: 19, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
        ),
        actions: [
          IconButton(
            icon: const Icon(CupertinoIcons.person_badge_plus, color: AppTheme.emeraldGreen),
            tooltip: 'Register Child',
            onPressed: () async {
              if (_guardianId != null) {
                final newChild = await showAddChildModal(
                  context: context,
                  guardianId: _guardianId!,
                );
                if (newChild != null) {
                  _loadData();
                }
              }
            },
          ),
          IconButton(
            icon: const Icon(CupertinoIcons.calendar_badge_plus, color: AppTheme.emeraldGreen),
            tooltip: 'Book Appointment',
            onPressed: () => _showBookingModal(),
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          labelColor: AppTheme.emeraldGreen,
          unselectedLabelColor: AppTheme.textLight,
          indicatorColor: AppTheme.emeraldGreen,
          indicatorWeight: 3,
          labelStyle: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13),
          tabs: const [
            Tab(text: 'All'),
            Tab(text: 'Upcoming'),
            Tab(text: 'Completed'),
            Tab(text: 'Cancelled'),
          ],
        ),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppTheme.emeraldGreen))
          : TabBarView(
              controller: _tabController,
              children: [
                _buildAppointmentsList(_appointments),
                _buildAppointmentsList(_appointments.where((a) => ['scheduled', 'confirmed'].contains(a['status'].toString().toLowerCase())).toList()),
                _buildAppointmentsList(_appointments.where((a) => a['status'].toString().toLowerCase() == 'completed').toList()),
                _buildAppointmentsList(_appointments.where((a) => ['cancelled', 'rescheduled', 'no-show'].contains(a['status'].toString().toLowerCase())).toList()),
              ],
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppTheme.emeraldGreen,
        foregroundColor: Colors.white,
        icon: const Icon(CupertinoIcons.add),
        label: Text('Book Session', style: GoogleFonts.inter(fontWeight: FontWeight.bold)),
        onPressed: () => _showBookingModal(),
      ),
      bottomNavigationBar: widget.showBottomNav ? const CustomBottomNavBar(currentIndex: 1) : null,
    );
  }

  Widget _buildAppointmentsList(List<dynamic> list) {
    if (list.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 72,
                height: 72,
                decoration: const BoxDecoration(color: AppTheme.mintHero, shape: BoxShape.circle),
                child: const Icon(CupertinoIcons.calendar, size: 36, color: AppTheme.emeraldGreen),
              ),
              const SizedBox(height: 18),
              Text(
                'No Appointments in this tab',
                style: GoogleFonts.spaceGrotesk(fontSize: 17, fontWeight: FontWeight.bold, color: AppTheme.textMain),
              ),
              const SizedBox(height: 6),
              Text(
                'Schedule upcoming vaccination dates to protect your child against preventable illnesses.',
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(color: AppTheme.textMuted, fontSize: 13),
              ),
              const SizedBox(height: 20),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.emeraldGreen,
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                ),
                onPressed: () => _showBookingModal(),
                icon: const Icon(CupertinoIcons.add, size: 16),
                label: Text('Book Immunization Session', style: GoogleFonts.inter(fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadData,
      color: AppTheme.emeraldGreen,
      child: ListView.separated(
        padding: const EdgeInsets.all(18),
        itemCount: list.length,
        separatorBuilder: (context, index) => const SizedBox(height: 14),
        itemBuilder: (context, index) {
          final apt = list[index];
          final status = apt['status']?.toString() ?? 'scheduled';
          final statusColor = _getStatusColor(status);
          final isUpcoming = ['scheduled', 'confirmed'].contains(status.toLowerCase());

          return Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: AppTheme.borderLight),
              boxShadow: [
                BoxShadow(color: Colors.black.withAlpha(5), blurRadius: 10, offset: const Offset(0, 2)),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    CircleAvatar(
                      radius: 22,
                      backgroundColor: AppTheme.mintHero,
                      child: Text(
                        (apt['child_name'] ?? 'C').toString().substring(0, 1).toUpperCase(),
                        style: GoogleFonts.spaceGrotesk(color: AppTheme.emeraldGreen, fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            apt['child_name'] ?? 'Child Profile',
                            style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 14.5, color: AppTheme.textMain),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            apt['vaccine_name'] ?? 'Pediatric Vaccine',
                            style: GoogleFonts.inter(fontSize: 12.5, color: AppTheme.textMuted),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: statusColor.withAlpha(20),
                        borderRadius: BorderRadius.circular(100),
                      ),
                      child: Text(
                        status.toUpperCase(),
                        style: GoogleFonts.spaceGrotesk(
                          fontSize: 10.5,
                          fontWeight: FontWeight.bold,
                          color: statusColor,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                const Divider(height: 1, color: AppTheme.borderLight),
                const SizedBox(height: 8),

                // Clinical Provider & Room Info
                Row(
                  children: [
                    const Icon(CupertinoIcons.building_2_fill, size: 13, color: AppTheme.emeraldGreen),
                    const SizedBox(width: 5),
                    Expanded(
                      child: Text(
                        apt['room_number'] ?? 'Nurse Station 1 (Immunization Clinic)',
                        style: GoogleFonts.inter(fontSize: 11.5, color: AppTheme.darkNavy, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),

                // Triage Symptoms Notes if recorded
                if (apt['notes'] != null && apt['notes'].toString().trim().isNotEmpty) ...[
                  Container(
                    width: double.infinity,
                    margin: const EdgeInsets.only(top: 4, bottom: 6),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: apt['notes'].toString().contains('CRITICAL') ? const Color(0xFFFEF2F2) : AppTheme.mintHero,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: apt['notes'].toString().contains('CRITICAL') ? const Color(0xFFFCA5A5) : AppTheme.emeraldGreen.withAlpha(40),
                      ),
                    ),
                    child: Text(
                      apt['notes'].toString(),
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: apt['notes'].toString().contains('CRITICAL') ? AppTheme.dangerRose : const Color(0xFF065F46),
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                ],

                const SizedBox(height: 6),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Row(
                      children: [
                        const Icon(CupertinoIcons.calendar, size: 14, color: AppTheme.emeraldGreen),
                        const SizedBox(width: 6),
                        Text(
                          "${apt['appointment_date']} • ${apt['appointment_time']}",
                          style: GoogleFonts.inter(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppTheme.textMain),
                        ),
                      ],
                    ),
                    if (isUpcoming)
                      Row(
                        children: [
                          GestureDetector(
                            onTap: () => _showRescheduleModal(apt),
                            child: Text(
                              'Reschedule',
                              style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                            ),
                          ),
                          const SizedBox(width: 14),
                          GestureDetector(
                            onTap: () => _cancelAppointment(apt['appointment_id']),
                            child: Text(
                              'Cancel',
                              style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.dangerRose),
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
