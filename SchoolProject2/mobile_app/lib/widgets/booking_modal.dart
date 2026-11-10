import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import '../utils/theme.dart';
import '../utils/sound_service.dart';
import 'add_child_modal.dart';

Future<bool?> showBookingModal({
  required BuildContext context,
  int? initialChildId,
  int? initialVaccineId,
  VoidCallback? onBookingSuccess,
}) async {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (ctx) => _BookingModalSheet(
      initialChildId: initialChildId,
      initialVaccineId: initialVaccineId,
      onBookingSuccess: onBookingSuccess,
    ),
  );
}

class _BookingModalSheet extends StatefulWidget {
  final int? initialChildId;
  final int? initialVaccineId;
  final VoidCallback? onBookingSuccess;

  const _BookingModalSheet({
    this.initialChildId,
    this.initialVaccineId,
    this.onBookingSuccess,
  });

  @override
  State<_BookingModalSheet> createState() => _BookingModalSheetState();
}

class _BookingModalSheetState extends State<_BookingModalSheet> {
  final PageController _pageController = PageController();
  int _currentStep = 0; // 0 = Child & Vaccine, 1 = Triage, 2 = Schedule & Confirm

  bool _isLoading = true;
  bool _isSubmitting = false;
  int? _guardianId;

  List<dynamic> _children = [];
  List<dynamic> _vaccines = [];
  int? _selectedChildId;
  int? _selectedVaccineId;
  String _currentEligibleStageName = '6 Weeks Milestone';

  // Triage & Symptoms state
  String _childCondition = 'healthy'; // 'healthy', 'mild', 'unwell'
  final Set<String> _selectedSymptoms = {'No adverse symptoms (Healthy & Active)'};
  final TextEditingController _symptomsNotesController = TextEditingController();
  final TextEditingController _temperatureController = TextEditingController(text: '36.8');

  // Date & Time selection
  DateTime _selectedDate = DateTime.now().add(const Duration(days: 1));
  String _selectedTimeSlot = '09:00:00';
  final List<DateTime> _availableDates = [];
  Set<String> _bookedSlots = {};
  bool _isLoadingBookedSlots = false;

  final List<Map<String, String>> _timeSlotsMorning = [
    {'label': '08:30 AM', 'val': '08:30:00'},
    {'label': '09:00 AM', 'val': '09:00:00'},
    {'label': '10:00 AM', 'val': '10:00:00'},
    {'label': '11:30 AM', 'val': '11:30:00'},
  ];

  final List<Map<String, String>> _timeSlotsAfternoon = [
    {'label': '02:00 PM', 'val': '14:00:00'},
    {'label': '03:00 PM', 'val': '15:00:00'},
    {'label': '03:30 PM', 'val': '15:30:00'},
    {'label': '04:15 PM', 'val': '16:15:00'},
  ];

  final List<String> _commonSymptomOptions = [
    'No adverse symptoms (Healthy & Active)',
    'Mild Fever / Warm',
    'Cough / Runny Nose',
    'Poor Feeding / Loss of Appetite',
    'Vomiting / Spitting up',
    'Diarrhea / Loose Stools',
    'Skin Rash / Irritation',
    'Lethargic / Weak / Sleepy',
    'Excessive Crying / Fussy',
  ];

  @override
  void initState() {
    super.initState();
    _initClinicDates();
    _selectedChildId = widget.initialChildId;
    _selectedVaccineId = widget.initialVaccineId;
    _loadInitialData();
    _fetchBookedSlotsForDate(_selectedDate);
  }

  @override
  void dispose() {
    _pageController.dispose();
    _symptomsNotesController.dispose();
    _temperatureController.dispose();
    super.dispose();
  }

  void _initClinicDates() {
    DateTime candidate = DateTime.now().add(const Duration(days: 1));
    while (_availableDates.length < 14) {
      if (candidate.weekday != DateTime.sunday) {
        _availableDates.add(candidate);
      }
      candidate = candidate.add(const Duration(days: 1));
    }
  }

  Future<void> _loadInitialData() async {
    final prefs = await SharedPreferences.getInstance();
    _guardianId = prefs.getInt('user_id');

    if (_guardianId == null) {
      if (mounted) setState(() => _isLoading = false);
      return;
    }

    try {
      // 1. Fetch Children
      final cRes = await http.get(Uri.parse('$API_BASE_URL/children.php?guardian_id=$_guardianId'));
      if (cRes.statusCode == 200) {
        final cData = jsonDecode(cRes.body);
        if (cData['status'] == 'success') {
          _children = cData['children'] ?? [];
        }
      }

      if (_children.isNotEmpty && _selectedChildId == null) {
        _selectedChildId = _children[0]['id'];
      }

      // 2. Fetch Sequential Vaccines for the selected child
      await _fetchVaccinesForChild(_selectedChildId);
    } catch (e) {
      debugPrint('Error loading booking modal data: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _fetchVaccinesForChild(int? childId) async {
    if (childId == null) return;
    try {
      final vRes = await http.get(Uri.parse('$API_BASE_URL/vaccines.php?child_id=$childId'));
      if (vRes.statusCode == 200) {
        final vData = jsonDecode(vRes.body);
        if (vData['status'] == 'success') {
          if (mounted) {
            setState(() {
              _vaccines = vData['vaccines'] ?? [];
              _currentEligibleStageName = vData['current_eligible_stage_name'] ?? '6 Weeks Milestone';

              // Automatically select the first eligible bookable vaccine
              final eligible = _vaccines.where((v) => v['is_selectable'] == true).toList();
              if (eligible.isNotEmpty) {
                final hasCurrent = eligible.any((v) => v['id'] == _selectedVaccineId);
                if (!hasCurrent) {
                  _selectedVaccineId = eligible[0]['id'];
                }
              } else {
                _selectedVaccineId = null;
              }
            });
          }
        }
      }
    } catch (e) {
      debugPrint('Error fetching vaccines for child: $e');
    }
  }

  bool _isSlotBooked(String val) {
    final prefix = val.length >= 5 ? val.substring(0, 5) : val;
    return _bookedSlots.any((b) => b == val || (b.length >= 5 && b.substring(0, 5) == prefix));
  }

  Future<void> _fetchBookedSlotsForDate(DateTime date) async {
    final dateStr =
        "${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}";
    if (mounted) setState(() => _isLoadingBookedSlots = true);
    try {
      final res = await http.get(Uri.parse('$API_BASE_URL/appointments.php?action=booked_slots&date=$dateStr'));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 'success' && data['booked_slots'] != null) {
          final List<dynamic> raw = data['booked_slots'];
          if (mounted) {
            setState(() {
              _bookedSlots = raw.map((e) => e.toString().trim()).toSet();
              // If currently selected slot is booked, auto-select first open slot
              if (_isSlotBooked(_selectedTimeSlot)) {
                final allSlots = [..._timeSlotsMorning, ..._timeSlotsAfternoon];
                final available = allSlots.where((s) => !_isSlotBooked(s['val']!)).toList();
                if (available.isNotEmpty) {
                  _selectedTimeSlot = available[0]['val']!;
                }
              }
            });
          }
        }
      }
    } catch (e) {
      debugPrint('Error fetching booked slots: $e');
    } finally {
      if (mounted) setState(() => _isLoadingBookedSlots = false);
    }
  }

  void _goToStep(int step) {
    if (step < 0 || step > 2) return;
    setState(() => _currentStep = step);
    _pageController.animateToPage(
      step,
      duration: const Duration(milliseconds: 320),
      curve: Curves.easeInOutCubic,
    );
  }

  Map<String, dynamic>? _getSelectedChild() {
    if (_selectedChildId == null) return null;
    return _children.firstWhere(
      (c) => c['id'] == _selectedChildId,
      orElse: () => null,
    );
  }

  Map<String, dynamic>? _getSelectedVaccine() {
    if (_selectedVaccineId == null) return null;
    return _vaccines.firstWhere(
      (v) => v['id'] == _selectedVaccineId,
      orElse: () => null,
    );
  }

  Future<void> _submitBooking() async {
    if (_selectedChildId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a child.')),
      );
      _goToStep(0);
      return;
    }

    if (_selectedVaccineId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select an eligible vaccine from the active milestone.')),
      );
      _goToStep(0);
      return;
    }

    if (_isSlotBooked(_selectedTimeSlot)) {
      SoundService.playPopSound();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: const Color(0xFFDC2626),
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          content: Row(
            children: const [
              Icon(CupertinoIcons.exclamationmark_triangle_fill, color: Colors.white, size: 20),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'This spot is booked, try another day or time.',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.white),
                ),
              ),
            ],
          ),
          duration: const Duration(seconds: 4),
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final dateStr =
        "${_selectedDate.year}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.day.toString().padLeft(2, '0')}";

    // Combine symptoms list and notes
    String symptomsSummary = _selectedSymptoms.join(', ');
    if (_symptomsNotesController.text.trim().isNotEmpty) {
      symptomsSummary += " — Notes: ${_symptomsNotesController.text.trim()}";
    }

    double? temp = double.tryParse(_temperatureController.text.trim());

    try {
      final res = await http.post(
        Uri.parse('$API_BASE_URL/appointments.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'action': 'book',
          'child_id': _selectedChildId,
          'vaccine_id': _selectedVaccineId,
          'appointment_date': dateStr,
          'appointment_time': _selectedTimeSlot,
          'child_condition': _childCondition,
          'symptoms': symptomsSummary,
          'temperature': temp,
        }),
      );

      final data = jsonDecode(res.body);

      if (res.statusCode == 201 && data['status'] == 'success') {
        if (mounted) {
          Navigator.pop(context, true);
          widget.onBookingSuccess?.call();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              backgroundColor: AppTheme.emeraldGreen,
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('✅ Appointment Booked Successfully!', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5)),
                  const SizedBox(height: 3),
                  Text(
                    data['triage_notice'] ?? 'Administered by pediatric clinic nurse. Triage logged.',
                    style: const TextStyle(fontSize: 12),
                  ),
                ],
              ),
              duration: const Duration(seconds: 4),
            ),
          );
        }
      } else {
        if (mounted) {
          final isConflict = res.statusCode == 409 ||
              (data['message'] != null && data['message'].toString().contains('This spot is booked'));

          if (isConflict) {
            SoundService.playPopSound();
            _fetchBookedSlotsForDate(_selectedDate);
            showDialog(
              context: context,
              builder: (ctx) => AlertDialog(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                title: Row(
                  children: const [
                    Icon(CupertinoIcons.exclamationmark_circle_fill, color: Color(0xFFDC2626), size: 24),
                    SizedBox(width: 8),
                    Text('Spot Unavailable', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                content: const Text(
                  'This spot is booked, try another day or time.',
                  style: TextStyle(fontSize: 14, color: Color(0xFF374151), height: 1.4),
                ),
                actions: [
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppTheme.emeraldGreen,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: () => Navigator.pop(ctx),
                    child: const Text('Choose Another Slot', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            );
          }

          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              backgroundColor: isConflict ? const Color(0xFFDC2626) : AppTheme.dangerRose,
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              content: Text(
                isConflict ? 'This spot is booked, try another day or time.' : (data['message'] ?? 'Failed to book session. Please verify sequence.'),
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              duration: const Duration(seconds: 4),
            ),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Network error. Check connection.')),
        );
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    final screenHeight = MediaQuery.of(context).size.height;

    return Container(
      height: screenHeight * 0.88,
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      child: Column(
        children: [
          // 1. Top Drag Handle & Title Header
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 16, 8),
            child: Column(
              children: [
                Center(
                  child: Container(
                    width: 44,
                    height: 5,
                    decoration: BoxDecoration(
                      color: const Color(0xFFE5E7EB),
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Book Vaccination Session',
                          style: GoogleFonts.spaceGrotesk(
                            fontSize: 18,
                            fontWeight: FontWeight.w800,
                            color: AppTheme.darkNavy,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          _getStepSubtitle(),
                          style: GoogleFonts.inter(
                            fontSize: 12,
                            fontWeight: FontWeight.w500,
                            color: AppTheme.textMuted,
                          ),
                        ),
                      ],
                    ),
                    IconButton(
                      icon: const Icon(CupertinoIcons.xmark_circle_fill, color: Color(0xFF9CA3AF), size: 26),
                      onPressed: () => Navigator.pop(context),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // 2. Premium Step Progress Bar
          _buildStepProgressBar(),

          const Divider(height: 1, color: Color(0xFFF3F4F6)),

          // 3. Step Pages (Scrollable Content)
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppTheme.emeraldGreen))
                : PageView(
                    controller: _pageController,
                    physics: const NeverScrollableScrollPhysics(),
                    children: [
                      _buildStep1ChildAndVaccine(),
                      _buildStep2TriageAndHealth(),
                      _buildStep3ScheduleAndReview(),
                    ],
                  ),
          ),

          // 4. Fixed Bottom Action Bar (Back / Next / Confirm)
          _buildBottomActionBar(bottomInset),
        ],
      ),
    );
  }

  String _getStepSubtitle() {
    switch (_currentStep) {
      case 0:
        return 'Step 1 of 3: Select Patient & Vaccine';
      case 1:
        return 'Step 2 of 3: Child Health & Triage';
      case 2:
        return 'Step 3 of 3: Choose Date, Time & Confirm';
      default:
        return '';
    }
  }

  // --- STEP PROGRESS BAR ---
  Widget _buildStepProgressBar() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
      child: Row(
        children: [
          _buildStepItem(0, '1. Vaccine', CupertinoIcons.eyedropper_full),
          _buildStepConnector(0),
          _buildStepItem(1, '2. Triage', CupertinoIcons.heart_fill),
          _buildStepConnector(1),
          _buildStepItem(2, '3. Schedule', CupertinoIcons.calendar_today),
        ],
      ),
    );
  }

  Widget _buildStepItem(int stepIndex, String title, IconData icon) {
    final bool isCompleted = _currentStep > stepIndex;
    final bool isActive = _currentStep == stepIndex;

    Color bg = const Color(0xFFF3F4F6);
    Color fg = const Color(0xFF9CA3AF);
    Color borderColor = Colors.transparent;

    if (isCompleted) {
      bg = AppTheme.mintHero;
      fg = AppTheme.emeraldGreen;
      borderColor = AppTheme.emeraldGreen.withAlpha(60);
    } else if (isActive) {
      bg = AppTheme.emeraldGreen;
      fg = Colors.white;
      borderColor = AppTheme.emeraldGreen;
    }

    return Expanded(
      child: GestureDetector(
        onTap: () {
          // Allow going back to earlier steps directly
          if (stepIndex < _currentStep) {
            _goToStep(stepIndex);
          } else if (stepIndex == 1 && _selectedVaccineId != null) {
            _goToStep(1);
          }
        },
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 8),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: borderColor),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                isCompleted ? CupertinoIcons.checkmark_alt : icon,
                size: 13,
                color: fg,
              ),
              const SizedBox(width: 5),
              Flexible(
                child: Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.spaceGrotesk(
                    fontSize: 11.5,
                    fontWeight: isActive || isCompleted ? FontWeight.bold : FontWeight.w600,
                    color: fg,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildStepConnector(int stepIndex) {
    final bool isPassed = _currentStep > stepIndex;
    return Container(
      width: 14,
      height: 2,
      margin: const EdgeInsets.symmetric(horizontal: 4),
      color: isPassed ? AppTheme.emeraldGreen : const Color(0xFFE5E7EB),
    );
  }

  // --- STEP 1: PATIENT & VACCINE SELECTION ---
  Widget _buildStep1ChildAndVaccine() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Select Child Section
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Select Child / Patient',
                style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
              ),
              if (_children.isNotEmpty)
                Text(
                  '${_children.length} Registered',
                  style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.w600, color: AppTheme.textMuted),
                ),
            ],
          ),
          const SizedBox(height: 10),

          if (_children.isEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: const Color(0xFFFEF2F2),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFFECACA)),
              ),
              child: Row(
                children: [
                  const Icon(CupertinoIcons.info_circle, color: AppTheme.dangerRose),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'No children registered. Please register your child to begin booking.',
                      style: GoogleFonts.inter(fontSize: 12, color: AppTheme.dangerRose),
                    ),
                  ),
                  TextButton(
                    onPressed: () async {
                      if (_guardianId != null) {
                        await showAddChildModal(context: context, guardianId: _guardianId!);
                        _loadInitialData();
                      }
                    },
                    child: const Text('Add Child', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            )
          else
            SizedBox(
              height: 72,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  ..._children.map((c) {
                    final isSelected = c['id'] == _selectedChildId;
                    return GestureDetector(
                      onTap: () {
                        setState(() => _selectedChildId = c['id']);
                        _fetchVaccinesForChild(c['id']);
                      },
                      child: Container(
                        width: 150,
                        margin: const EdgeInsets.only(right: 10),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(
                          color: isSelected ? AppTheme.mintHero : Colors.white,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(
                            color: isSelected ? AppTheme.emeraldGreen : const Color(0xFFE5E7EB),
                            width: isSelected ? 1.8 : 1,
                          ),
                          boxShadow: isSelected
                              ? [BoxShadow(color: AppTheme.emeraldGreen.withAlpha(30), blurRadius: 6, offset: const Offset(0, 2))]
                              : null,
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                color: isSelected ? AppTheme.emeraldGreen : const Color(0xFFF3F4F6),
                                shape: BoxShape.circle,
                              ),
                              child: Icon(
                                CupertinoIcons.person_solid,
                                size: 18,
                                color: isSelected ? Colors.white : AppTheme.textMuted,
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Text(
                                    c['name'] ?? 'Child',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: GoogleFonts.inter(
                                      fontSize: 12.5,
                                      fontWeight: isSelected ? FontWeight.bold : FontWeight.w600,
                                      color: isSelected ? AppTheme.darkNavy : AppTheme.textMain,
                                    ),
                                  ),
                                  const SizedBox(height: 1),
                                  Text(
                                    c['gender'] ?? 'Infant',
                                    style: GoogleFonts.inter(fontSize: 10.5, color: AppTheme.textMuted),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  }),
                  // Add child pill
                  GestureDetector(
                    onTap: () async {
                      if (_guardianId != null) {
                        await showAddChildModal(context: context, guardianId: _guardianId!);
                        _loadInitialData();
                      }
                    },
                    child: Container(
                      width: 100,
                      decoration: BoxDecoration(
                        color: const Color(0xFFF9FAFB),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: const Color(0xFFE5E7EB), style: BorderStyle.solid),
                      ),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(CupertinoIcons.add_circled, size: 20, color: AppTheme.emeraldGreen),
                          const SizedBox(height: 4),
                          Text('+ Add', style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),

          const SizedBox(height: 22),

          // Vaccine Selection Header
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Sequential Milestone Schedule',
                style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3.5),
                decoration: BoxDecoration(
                  color: AppTheme.mintHero,
                  borderRadius: BorderRadius.circular(100),
                  border: Border.all(color: AppTheme.emeraldGreen.withAlpha(60)),
                ),
                child: Text(
                  _currentEligibleStageName,
                  style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'Vaccines must be completed in order. Future milestones are greyed out until earlier stages are done.',
            style: GoogleFonts.inter(fontSize: 11.5, color: AppTheme.textMuted),
          ),
          const SizedBox(height: 12),

          // Vaccines List
          ..._vaccines.map((v) {
            final isSelectable = v['is_selectable'] == true;
            final isSelected = v['id'] == _selectedVaccineId;
            final status = v['status'] ?? 'locked';

            Color cardBg = Colors.white;
            Color borderColor = const Color(0xFFE5E7EB);
            Widget statusBadge;

            if (status == 'completed') {
              cardBg = const Color(0xFFF9FAFB);
              statusBadge = Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(6)),
                child: const Text('✓ Done', style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen)),
              );
            } else if (status == 'scheduled') {
              cardBg = const Color(0xFFFFFBEB);
              borderColor = const Color(0xFFFCD34D);
              statusBadge = Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(6)),
                child: const Text('Scheduled', style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: Color(0xFFB45309))),
              );
            } else if (status == 'birth_administered') {
              cardBg = const Color(0xFFF0FDF4);
              statusBadge = Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                decoration: BoxDecoration(color: const Color(0xFFDCFCE7), borderRadius: BorderRadius.circular(6)),
                child: const Text('Birth / Ward', style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.bold, color: Color(0xFF15803D))),
              );
            } else if (isSelectable) {
              if (isSelected) {
                cardBg = AppTheme.mintHero;
                borderColor = AppTheme.emeraldGreen;
              }
              statusBadge = Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: isSelected ? AppTheme.emeraldGreen : const Color(0xFFDCFCE7),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  isSelected ? 'Selected' : 'Ready',
                  style: TextStyle(
                    fontSize: 10.5,
                    fontWeight: FontWeight.bold,
                    color: isSelected ? Colors.white : AppTheme.emeraldGreen,
                  ),
                ),
              );
            } else {
              // LOCKED
              cardBg = const Color(0xFFF9FAFB);
              borderColor = const Color(0xFFF3F4F6);
              statusBadge = Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2.5),
                decoration: BoxDecoration(color: const Color(0xFFE5E7EB), borderRadius: BorderRadius.circular(6)),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: const [
                    Icon(CupertinoIcons.lock_fill, size: 10, color: Color(0xFF6B7280)),
                    SizedBox(width: 3),
                    Text('Locked', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF6B7280))),
                  ],
                ),
              );
            }

            return Opacity(
              opacity: isSelectable ? 1.0 : (status == 'completed' ? 0.8 : 0.55),
              child: GestureDetector(
                onTap: isSelectable
                    ? () {
                        setState(() => _selectedVaccineId = v['id']);
                      }
                    : () {
                        if (status == 'locked') {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(v['lock_reason'] ?? 'Please complete earlier milestone vaccines first.'),
                              duration: const Duration(seconds: 2),
                            ),
                          );
                        } else if (status == 'birth_administered') {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Birth vaccines (BCG & OPV 0) are administered directly at delivery.'),
                              duration: Duration(seconds: 2),
                            ),
                          );
                        }
                      },
                child: Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: cardBg,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: isSelected ? AppTheme.emeraldGreen : borderColor,
                      width: isSelected ? 1.8 : 1,
                    ),
                  ),
                  child: Row(
                    children: [
                      Container(
                        width: 32,
                        height: 32,
                        decoration: BoxDecoration(
                          color: isSelected
                              ? AppTheme.emeraldGreen
                              : (isSelectable ? AppTheme.mintHero : const Color(0xFFE5E7EB)),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Icon(
                          isSelected
                              ? CupertinoIcons.check_mark
                              : (isSelectable ? CupertinoIcons.eyedropper_full : CupertinoIcons.lock_fill),
                          size: 16,
                          color: isSelected ? Colors.white : (isSelectable ? AppTheme.emeraldGreen : const Color(0xFF9CA3AF)),
                        ),
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
                                    v['name'] ?? '',
                                    style: GoogleFonts.inter(
                                      fontWeight: isSelected ? FontWeight.bold : FontWeight.w600,
                                      fontSize: 13,
                                      color: isSelectable ? AppTheme.darkNavy : const Color(0xFF6B7280),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 6),
                                statusBadge,
                              ],
                            ),
                            const SizedBox(height: 2),
                            Text(
                              '${v['stage_title'] ?? ''} • ${v['status_label'] ?? ''}',
                              style: GoogleFonts.inter(
                                fontSize: 11,
                                color: isSelectable ? AppTheme.textMuted : const Color(0xFF9CA3AF),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),
        ],
      ),
    );
  }

  // --- STEP 2: PRE-VACCINE CLINICAL TRIAGE ---
  Widget _buildStep2TriageAndHealth() {
    final selectedChild = _getSelectedChild();
    final childName = selectedChild != null ? selectedChild['name'] : 'Child';
    final double? tempVal = double.tryParse(_temperatureController.text.trim());
    final bool isHighFever = tempVal != null && tempVal >= 38.5;
    final bool isMildFever = tempVal != null && tempVal >= 37.5 && tempVal < 38.5;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Banner Notice
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppTheme.mintHero,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.emeraldGreen.withAlpha(60)),
            ),
            child: Row(
              children: [
                const Icon(CupertinoIcons.heart_fill, color: AppTheme.emeraldGreen, size: 22),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Pre-Vaccine Health Triage',
                        style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Help the clinic nurse assess $childName\'s wellbeing before vaccination.',
                        style: GoogleFonts.inter(fontSize: 11.5, color: const Color(0xFF065F46)),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // 1. General Wellbeing Status (3 Tactile Cards)
          Text(
            'General Wellbeing Status',
            style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              _buildConditionCard('healthy', '🟢 Healthy', 'Playful & Active', Colors.green),
              const SizedBox(width: 8),
              _buildConditionCard('mild', '🟡 Mild', 'Teething / Slight cold', Colors.orange),
              const SizedBox(width: 8),
              _buildConditionCard('unwell', '🔴 Unwell', 'Fever / Needs attention', Colors.red),
            ],
          ),
          const SizedBox(height: 20),

          // 2. Body Temperature Input
          Text(
            'Child Body Temperature (°C)',
            style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF9FAFB),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: isHighFever ? AppTheme.dangerRose : (isMildFever ? Colors.orange : const Color(0xFFE5E7EB)),
                width: isHighFever ? 1.5 : 1,
              ),
            ),
            child: Column(
              children: [
                Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        color: isHighFever ? const Color(0xFFFEE2E2) : AppTheme.mintHero,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Icon(
                        CupertinoIcons.thermometer,
                        color: isHighFever ? AppTheme.dangerRose : AppTheme.emeraldGreen,
                        size: 22,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextFormField(
                        controller: _temperatureController,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        onChanged: (val) => setState(() {}),
                        style: GoogleFonts.spaceGrotesk(fontSize: 18, fontWeight: FontWeight.bold),
                        decoration: const InputDecoration(
                          hintText: 'e.g. 36.8',
                          border: InputBorder.none,
                          contentPadding: EdgeInsets.zero,
                          suffixText: '°C',
                        ),
                      ),
                    ),
                    // Quick stepper buttons
                    IconButton(
                      icon: const Icon(CupertinoIcons.minus_circle, size: 24, color: Color(0xFF9CA3AF)),
                      onPressed: () {
                        double cur = double.tryParse(_temperatureController.text) ?? 36.8;
                        cur = (cur - 0.1);
                        _temperatureController.text = cur.toStringAsFixed(1);
                        setState(() {});
                      },
                    ),
                    IconButton(
                      icon: const Icon(CupertinoIcons.plus_circle, size: 24, color: AppTheme.emeraldGreen),
                      onPressed: () {
                        double cur = double.tryParse(_temperatureController.text) ?? 36.8;
                        cur = (cur + 0.1);
                        _temperatureController.text = cur.toStringAsFixed(1);
                        setState(() {});
                      },
                    ),
                  ],
                ),
                if (isHighFever) ...[
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(color: const Color(0xFFFEF2F2), borderRadius: BorderRadius.circular(8)),
                    child: Row(
                      children: const [
                        Icon(CupertinoIcons.exclamationmark_triangle_fill, size: 14, color: AppTheme.dangerRose),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'High fever detected (≥ 38.5°C). Critical triage referral will alert the attending nurse and pediatrician.',
                            style: TextStyle(fontSize: 11, color: AppTheme.dangerRose, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 20),

          // 3. Observed Symptoms Chips
          Text(
            'Observed Symptoms / Behavior',
            style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: _commonSymptomOptions.map((sym) {
              final isChecked = _selectedSymptoms.contains(sym);
              return FilterChip(
                label: Text(
                  sym,
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: isChecked ? FontWeight.bold : FontWeight.normal,
                    color: isChecked ? Colors.white : const Color(0xFF374151),
                  ),
                ),
                selected: isChecked,
                selectedColor: AppTheme.emeraldGreen,
                checkmarkColor: Colors.white,
                backgroundColor: const Color(0xFFF3F4F6),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                onSelected: (bool selected) {
                  setState(() {
                    if (sym == 'No adverse symptoms (Healthy & Active)') {
                      _selectedSymptoms.clear();
                      if (selected) _selectedSymptoms.add(sym);
                    } else {
                      _selectedSymptoms.remove('No adverse symptoms (Healthy & Active)');
                      if (selected) {
                        _selectedSymptoms.add(sym);
                      } else {
                        _selectedSymptoms.remove(sym);
                      }
                      if (_selectedSymptoms.isEmpty) {
                        _selectedSymptoms.add('No adverse symptoms (Healthy & Active)');
                      }
                    }
                  });
                },
              );
            }).toList(),
          ),
          const SizedBox(height: 20),

          // 4. Notes / Concerns
          Text(
            'Feeding & Medication Observations',
            style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _symptomsNotesController,
            maxLines: 2,
            decoration: InputDecoration(
              hintText: 'Enter any current medications, appetite changes, or notes for the nurse...',
              hintStyle: GoogleFonts.inter(fontSize: 12, color: const Color(0xFF9CA3AF)),
              filled: true,
              fillColor: const Color(0xFFF9FAFB),
              contentPadding: const EdgeInsets.all(12),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildConditionCard(String key, String title, String subtitle, Color color) {
    final isSelected = _childCondition == key;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => _childCondition = key),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          decoration: BoxDecoration(
            color: isSelected ? color.withAlpha(25) : const Color(0xFFF9FAFB),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: isSelected ? color : const Color(0xFFE5E7EB),
              width: isSelected ? 1.8 : 1,
            ),
          ),
          child: Column(
            children: [
              Text(
                title,
                textAlign: TextAlign.center,
                style: GoogleFonts.spaceGrotesk(
                  fontSize: 12,
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.w600,
                  color: isSelected ? color : const Color(0xFF374151),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                subtitle,
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                  fontSize: 10,
                  color: isSelected ? color : const Color(0xFF9CA3AF),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // --- STEP 3: SCHEDULE, ROOM & CONFIRMATION ---
  Widget _buildStep3ScheduleAndReview() {
    final selectedChild = _getSelectedChild();
    final selectedVaccine = _getSelectedVaccine();
    final dateFormatted = "${_selectedDate.day} ${_getMonthName(_selectedDate.month)} ${_selectedDate.year}";

    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // 1. Select Date
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Select Appointment Date',
                style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
              ),
              Text(
                dateFormatted,
                style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
              ),
            ],
          ),
          const SizedBox(height: 10),

          SizedBox(
            height: 72,
            child: ListView.builder(
              scrollDirection: Axis.horizontal,
              itemCount: _availableDates.length,
              itemBuilder: (context, i) {
                final date = _availableDates[i];
                final isSelected = date.year == _selectedDate.year &&
                    date.month == _selectedDate.month &&
                    date.day == _selectedDate.day;

                final dayName = _getWeekdayShort(date.weekday);
                final monthName = _getMonthShort(date.month);

                return GestureDetector(
                  onTap: () {
                    setState(() => _selectedDate = date);
                    _fetchBookedSlotsForDate(date);
                  },
                  child: Container(
                    width: 58,
                    margin: const EdgeInsets.only(right: 8),
                    decoration: BoxDecoration(
                      color: isSelected ? AppTheme.emeraldGreen : Colors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: isSelected ? AppTheme.emeraldGreen : const Color(0xFFE5E7EB),
                      ),
                      boxShadow: isSelected
                          ? [BoxShadow(color: AppTheme.emeraldGreen.withAlpha(40), blurRadius: 6, offset: const Offset(0, 2))]
                          : null,
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          dayName,
                          style: GoogleFonts.inter(
                            fontSize: 10.5,
                            fontWeight: FontWeight.w600,
                            color: isSelected ? Colors.white70 : AppTheme.textMuted,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          "${date.day}",
                          style: GoogleFonts.spaceGrotesk(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: isSelected ? Colors.white : AppTheme.darkNavy,
                          ),
                        ),
                        Text(
                          monthName,
                          style: GoogleFonts.inter(
                            fontSize: 9.5,
                            color: isSelected ? Colors.white70 : AppTheme.textMuted,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
          const SizedBox(height: 20),

          // 2. Select Time Slot
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Select Time Slot',
                style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
              ),
              if (_isLoadingBookedSlots)
                const SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.emeraldGreen),
                ),
            ],
          ),
          const SizedBox(height: 8),

          Text('Morning Slots', style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.bold, color: AppTheme.textMuted)),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _timeSlotsMorning.map((slot) => _buildTimeSlotChip(slot)).toList(),
          ),
          const SizedBox(height: 12),

          Text('Afternoon Slots', style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.bold, color: AppTheme.textMuted)),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _timeSlotsAfternoon.map((slot) => _buildTimeSlotChip(slot)).toList(),
          ),
          const SizedBox(height: 22),

          // 3. Clinical Provider & Facility Card
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: const Color(0xFFF9FAFB),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: const Color(0xFFE5E7EB)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: const [
                    Icon(CupertinoIcons.building_2_fill, size: 16, color: AppTheme.emeraldGreen),
                    SizedBox(width: 8),
                    Text(
                      'Kansenshi Pediatric Clinic (Ndola)',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppTheme.darkNavy),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: const [
                    Icon(CupertinoIcons.person_badge_plus, size: 15, color: Color(0xFF0E7052)),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Location: Nurse Station 1 & 2 (Immunization Clinic)',
                        style: TextStyle(fontSize: 12, color: Color(0xFF374151), fontWeight: FontWeight.w500),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: const [
                    Icon(CupertinoIcons.info_circle, size: 15, color: Color(0xFF6B7280)),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Routine injections administered by registered nurses. Doctor triage review applies for critical symptoms.',
                        style: TextStyle(fontSize: 11, color: Color(0xFF6B7280)),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),

          // 4. Booking Summary Card (Receipt-Style)
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppTheme.mintHero,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppTheme.emeraldGreen.withAlpha(70)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Review Booking Summary', style: GoogleFonts.spaceGrotesk(fontSize: 13, fontWeight: FontWeight.bold, color: AppTheme.darkNavy)),
                    const Icon(CupertinoIcons.check_mark_circled_solid, color: AppTheme.emeraldGreen, size: 18),
                  ],
                ),
                const SizedBox(height: 10),
                _buildSummaryRow('Patient:', selectedChild?['name'] ?? 'Not selected'),
                _buildSummaryRow('Vaccine:', selectedVaccine?['name'] ?? 'Not selected'),
                _buildSummaryRow('Milestone:', selectedVaccine?['stage_title'] ?? _currentEligibleStageName),
                _buildSummaryRow('Date & Time:', '$dateFormatted at ${_getTimeSlotLabel(_selectedTimeSlot)}'),
                _buildSummaryRow('Health Triage:', '${_childCondition.toUpperCase()} • ${_temperatureController.text}°C'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTimeSlotChip(Map<String, String> slot) {
    final val = slot['val']!;
    final isBooked = _isSlotBooked(val);
    final isSelected = _selectedTimeSlot == val && !isBooked;

    return GestureDetector(
      onTap: () {
        if (isBooked) {
          SoundService.playPopSound();
          ScaffoldMessenger.of(context).hideCurrentSnackBar();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              backgroundColor: const Color(0xFFDC2626),
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              content: Row(
                children: const [
                  Icon(CupertinoIcons.exclamationmark_triangle_fill, color: Colors.white, size: 20),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'This spot is booked, try another day or time.',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.white),
                    ),
                  ),
                ],
              ),
              duration: const Duration(seconds: 4),
            ),
          );
          return;
        }
        setState(() => _selectedTimeSlot = val);
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
        decoration: BoxDecoration(
          color: isBooked
              ? const Color(0xFFFEE2E2)
              : (isSelected ? AppTheme.emeraldGreen : Colors.white),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: isBooked
                ? const Color(0xFFFCA5A5)
                : (isSelected ? AppTheme.emeraldGreen : const Color(0xFFE5E7EB)),
            width: isSelected ? 1.5 : 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              slot['label']!,
              style: GoogleFonts.inter(
                fontSize: 12,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                decoration: isBooked ? TextDecoration.lineThrough : null,
                color: isBooked
                    ? const Color(0xFFDC2626)
                    : (isSelected ? Colors.white : const Color(0xFF374151)),
              ),
            ),
            if (isBooked) ...[
              const SizedBox(width: 6),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                decoration: BoxDecoration(
                  color: const Color(0xFFDC2626),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Text(
                  'Booked',
                  style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.bold, color: Colors.white),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildSummaryRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: GoogleFonts.inter(fontSize: 11.5, color: const Color(0xFF065F46))),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.bold, color: AppTheme.darkNavy),
            ),
          ),
        ],
      ),
    );
  }

  // --- FIXED BOTTOM ACTION BAR ---
  Widget _buildBottomActionBar(double bottomInset) {
    final bool canProceedStep0 = _selectedChildId != null && _selectedVaccineId != null;

    return Container(
      padding: EdgeInsets.fromLTRB(20, 12, 20, 16 + bottomInset),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Color(0xFFF3F4F6))),
      ),
      child: Row(
        children: [
          if (_currentStep > 0) ...[
            OutlinedButton(
              style: OutlinedButton.styleFrom(
                foregroundColor: const Color(0xFF374151),
                side: const BorderSide(color: Color(0xFFD1D5DB)),
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              onPressed: () => _goToStep(_currentStep - 1),
              child: const Text('← Back', style: TextStyle(fontWeight: FontWeight.bold)),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.emeraldGreen,
                foregroundColor: Colors.white,
                elevation: 0,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              onPressed: _currentStep == 0
                  ? (canProceedStep0 ? () => _goToStep(1) : null)
                  : (_currentStep == 1
                      ? () => _goToStep(2)
                      : (_isSubmitting ? null : _submitBooking)),
              child: _isSubmitting
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                    )
                  : Text(
                      _currentStep == 0
                          ? 'Continue to Triage (Step 2) →'
                          : (_currentStep == 1 ? 'Continue to Schedule (Step 3) →' : 'Confirm & Book Session'),
                      style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  String _getTimeSlotLabel(String val) {
    for (var s in [..._timeSlotsMorning, ..._timeSlotsAfternoon]) {
      if (s['val'] == val) return s['label']!;
    }
    return val;
  }

  String _getWeekdayShort(int day) {
    const d = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return d[day - 1];
  }

  String _getMonthShort(int month) {
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return m[month - 1];
  }

  String _getMonthName(int month) {
    const m = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return m[month - 1];
  }
}
