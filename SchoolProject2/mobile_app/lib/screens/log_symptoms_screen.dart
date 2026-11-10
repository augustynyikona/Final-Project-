import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import '../widgets/custom_bottom_nav_bar.dart';

class LogSymptomsScreen extends StatefulWidget {
  final int? preselectedChildId;
  const LogSymptomsScreen({super.key, int? preselectedChildId, int? preSelectedChildId})
      : preselectedChildId = preselectedChildId ?? preSelectedChildId;

  @override
  State<LogSymptomsScreen> createState() => _LogSymptomsScreenState();
}

class _LogSymptomsScreenState extends State<LogSymptomsScreen> {
  bool _isLoading = true;
  bool _isSubmitting = false;
  int? _guardianId;

  List<dynamic> _children = [];
  int? _selectedChildId;

  final _tempController = TextEditingController();
  final _heartRateController = TextEditingController();
  final _weightController = TextEditingController();
  final _notesController = TextEditingController();

  final List<String> _commonSymptoms = [
    'High Fever',
    'Injection Site Swelling',
    'Skin Rash / Redness',
    'Severe Irritability',
    'Vomiting / Nausea',
    'Lethargy / Excessive Sleepiness',
    'Persistent Cough',
    'Loss of Appetite',
    'Diarrhea',
  ];
  final Set<String> _selectedSymptoms = {};

  List<dynamic> _pastVitals = [];

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    _tempController.dispose();
    _heartRateController.dispose();
    _weightController.dispose();
    _notesController.dispose();
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
      // 1. Fetch Children
      final cRes = await http.get(Uri.parse('$API_BASE_URL/children.php?guardian_id=$_guardianId'));
      if (cRes.statusCode == 200) {
        final cData = jsonDecode(cRes.body);
        if (cData['status'] == 'success') {
          _children = cData['children'] ?? [];
          if (_children.isNotEmpty) {
            if (widget.preselectedChildId != null &&
                _children.any((c) => c['id'] == widget.preselectedChildId)) {
              _selectedChildId = widget.preselectedChildId;
            } else {
              _selectedChildId = _children[0]['id'];
            }
          }
        }
      }

      // 2. Fetch past vitals
      final vRes = await http.get(Uri.parse('$API_BASE_URL/symptoms.php?guardian_id=$_guardianId'));
      if (vRes.statusCode == 200) {
        final vData = jsonDecode(vRes.body);
        if (vData['status'] == 'success') {
          _pastVitals = vData['vitals'] ?? [];
        }
      }
    } catch (e) {
      debugPrint('Error loading symptoms data: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _submitVitals() async {
    if (_selectedChildId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a child.')),
      );
      return;
    }

    final tempVal = double.tryParse(_tempController.text.trim());
    final hrVal = int.tryParse(_heartRateController.text.trim());
    final wtVal = double.tryParse(_weightController.text.trim());

    // Build combined symptoms string
    final customNotes = _notesController.text.trim();
    final allSymptomsList = [..._selectedSymptoms];
    if (customNotes.isNotEmpty) {
      allSymptomsList.add(customNotes);
    }

    if (allSymptomsList.isEmpty && tempVal == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter temperature or select observed symptoms.')),
      );
      return;
    }

    final combinedSymptoms = allSymptomsList.join(', ');

    setState(() => _isSubmitting = true);

    try {
      final res = await http.post(
        Uri.parse('$API_BASE_URL/symptoms.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'child_id': _selectedChildId,
          'temperature': tempVal,
          'heart_rate': hrVal,
          'weight_kg': wtVal,
          'symptoms': combinedSymptoms.isEmpty ? 'Routine checkup vitals' : combinedSymptoms,
        }),
      );

      final respData = jsonDecode(res.body);

      if (res.statusCode == 201 && respData['status'] == 'success') {
        final isCritical = respData['is_critical'] == true;

        if (!mounted) return;

        // Clear inputs
        _tempController.clear();
        _heartRateController.clear();
        _weightController.clear();
        _notesController.clear();
        _selectedSymptoms.clear();

        _showSuccessFeedback(isCritical);
        _loadData();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(respData['message'] ?? 'Failed to record vitals.')),
        );
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

  void _showSuccessFeedback(bool isCritical) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        title: Row(
          children: [
            Icon(
              isCritical ? CupertinoIcons.exclamationmark_triangle_fill : CupertinoIcons.checkmark_circle_fill,
              color: isCritical ? const Color(0xFFEF4444) : const Color(0xFF0E7052),
              size: 28,
            ),
            const SizedBox(width: 10),
            Text(
              isCritical ? 'Critical Alert Sent' : 'Vitals Transmitted',
              style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 17),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isCritical
                  ? 'Your child’s temperature is elevated (>=38.5°C). The Pediatric Nursing Station and Consulting Doctors at Kansenshi Clinic have been immediately alerted through the Clinical Triage System.'
                  : 'Your observation and vitals have been successfully recorded in the digital Under-5 clinic chart.',
              style: GoogleFonts.plusJakartaSans(fontSize: 13.5, height: 1.5, color: const Color(0xFF374151)),
            ),
            if (isCritical) ...[
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFFEE2E2),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  'Advice: Keep child hydrated, apply cool compress, and bring child to Kansenshi Clinic immediately if lethargic or convulsing.',
                  style: GoogleFonts.plusJakartaSans(fontSize: 12, color: const Color(0xFFB91C1C), fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ],
        ),
        actions: [
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF0E7052),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            ),
            onPressed: () => Navigator.pop(ctx),
            child: Text('Understood', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Scaffold(
        backgroundColor: Color(0xFFF8FAF9),
        body: Center(child: CircularProgressIndicator(color: Color(0xFF0E7052))),
      );
    }

    if (_children.isEmpty) {
      return Scaffold(
        backgroundColor: const Color(0xFFF8FAF9),
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              children: [
                Row(
                  children: [
                    InkWell(
                      onTap: () => Navigator.pop(context),
                      borderRadius: BorderRadius.circular(20),
                      child: Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          shape: BoxShape.circle,
                          border: Border.all(color: const Color(0xFFE5E7EB)),
                        ),
                        child: const Icon(CupertinoIcons.arrow_left, size: 20, color: Color(0xFF111827)),
                      ),
                    ),
                  ],
                ),
                const Spacer(),
                Container(
                  width: 80,
                  height: 80,
                  decoration: const BoxDecoration(
                    color: Color(0xFFE8F8F2),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(CupertinoIcons.person_badge_plus_fill, size: 40, color: Color(0xFF0E7052)),
                ),
                const SizedBox(height: 20),
                Text(
                  'No Children Registered',
                  style: GoogleFonts.plusJakartaSans(fontSize: 20, fontWeight: FontWeight.bold, color: const Color(0xFF111827)),
                ),
                const SizedBox(height: 8),
                Text(
                  'Please enroll a dependent child at Kansenshi Clinic before transmitting clinical vitals.',
                  textAlign: TextAlign.center,
                  style: GoogleFonts.plusJakartaSans(color: const Color(0xFF6B7280), fontSize: 13.5),
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF0E7052),
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                  ),
                  onPressed: () => Navigator.pop(context),
                  child: Text('Return to Home', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold)),
                ),
                const Spacer(),
              ],
            ),
          ),
        ),
      );
    }

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAF9),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Top Navigation Row
              Row(
                children: [
                  InkWell(
                    onTap: () => Navigator.pop(context),
                    borderRadius: BorderRadius.circular(20),
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                        border: Border.all(color: const Color(0xFFE5E7EB)),
                      ),
                      child: const Icon(CupertinoIcons.arrow_left, size: 20, color: Color(0xFF111827)),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Log Symptoms & Vitals',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                          color: const Color(0xFF111827),
                        ),
                      ),
                      Text(
                        'Direct pediatric clinical triage integration',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 12.5,
                          color: const Color(0xFF6B7280),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 20),

              // Clinical Integration Hero Pill Banner
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFFE8F8F2),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: const Color(0xFFD8F3E5)),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(CupertinoIcons.shield_fill, color: Color(0xFF0E7052), size: 20),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Text(
                        'Nurse & Doctor Triage Link: Vitals recorded here immediately alert clinical staff if fever or distress occurs.',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 12.5,
                          color: const Color(0xFF0E7052),
                          fontWeight: FontWeight.w600,
                          height: 1.4,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),

              // 1. Select Child Section
              Text(
                'Select Dependent Child',
                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 15, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 10),
              SizedBox(
                height: 52,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: _children.length,
                  separatorBuilder: (context, index) => const SizedBox(width: 10),
                  itemBuilder: (ctx, i) {
                    final c = _children[i];
                    final isSel = c['id'] == _selectedChildId;
                    return InkWell(
                      onTap: () => setState(() => _selectedChildId = c['id']),
                      borderRadius: BorderRadius.circular(26),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        decoration: BoxDecoration(
                          color: isSel ? const Color(0xFF0E7052) : Colors.white,
                          borderRadius: BorderRadius.circular(26),
                          border: Border.all(
                            color: isSel ? const Color(0xFF0E7052) : const Color(0xFFE5E7EB),
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              CupertinoIcons.person_crop_circle_fill,
                              size: 18,
                              color: isSel ? Colors.white : const Color(0xFF0E7052),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              c['name'] ?? 'Child',
                              style: GoogleFonts.plusJakartaSans(
                                color: isSel ? Colors.white : const Color(0xFF111827),
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(height: 24),

              // 2. Physical Vitals Inputs (Temperature & Weight)
              Text(
                'Physical Measurements',
                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 15, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: const Color(0xFFE5E7EB)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(CupertinoIcons.thermometer, size: 16, color: Color(0xFF0E7052)),
                              const SizedBox(width: 6),
                              Text(
                                'Temperature',
                                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 13, color: const Color(0xFF374151)),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          TextField(
                            controller: _tempController,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            style: GoogleFonts.plusJakartaSans(fontSize: 16, fontWeight: FontWeight.bold),
                            decoration: InputDecoration(
                              hintText: '37.0',
                              suffixText: '°C',
                              suffixStyle: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, color: const Color(0xFF9CA3AF)),
                              isDense: true,
                              contentPadding: const EdgeInsets.symmetric(vertical: 8),
                              border: InputBorder.none,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: const Color(0xFFE5E7EB)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(CupertinoIcons.circle_grid_hex, size: 16, color: Color(0xFF0E7052)),
                              const SizedBox(width: 6),
                              Text(
                                'Weight',
                                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 13, color: const Color(0xFF374151)),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          TextField(
                            controller: _weightController,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            style: GoogleFonts.plusJakartaSans(fontSize: 16, fontWeight: FontWeight.bold),
                            decoration: InputDecoration(
                              hintText: '8.5',
                              suffixText: 'kg',
                              suffixStyle: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, color: const Color(0xFF9CA3AF)),
                              isDense: true,
                              contentPadding: const EdgeInsets.symmetric(vertical: 8),
                              border: InputBorder.none,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 24),

              // 3. Observed Symptoms Chips
              Text(
                'Observed Clinical Symptoms',
                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 15, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: _commonSymptoms.map((sym) {
                  final isSelected = _selectedSymptoms.contains(sym);
                  final isFever = sym.contains('Fever');
                  return FilterChip(
                    label: Text(sym),
                    selected: isSelected,
                    selectedColor: isFever ? const Color(0xFFFEE2E2) : const Color(0xFFE8F8F2),
                    checkmarkColor: isFever ? const Color(0xFFEF4444) : const Color(0xFF0E7052),
                    backgroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(20),
                      side: BorderSide(
                        color: isSelected
                            ? (isFever ? const Color(0xFFEF4444) : const Color(0xFF0E7052))
                            : const Color(0xFFE5E7EB),
                      ),
                    ),
                    labelStyle: GoogleFonts.plusJakartaSans(
                      fontSize: 12.5,
                      fontWeight: isSelected ? FontWeight.bold : FontWeight.w600,
                      color: isSelected
                          ? (isFever ? const Color(0xFFEF4444) : const Color(0xFF0E7052))
                          : const Color(0xFF4B5563),
                    ),
                    onSelected: (selected) {
                      setState(() {
                        if (selected) {
                          _selectedSymptoms.add(sym);
                        } else {
                          _selectedSymptoms.remove(sym);
                        }
                      });
                    },
                  );
                }).toList(),
              ),
              const SizedBox(height: 24),

              // 4. Clinical Notes Textarea
              Text(
                'Additional Clinical Observations',
                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 15, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _notesController,
                maxLines: 3,
                decoration: InputDecoration(
                  hintText: 'e.g. Swelling started 4 hours after injection, child is feeding well...',
                  hintStyle: GoogleFonts.plusJakartaSans(color: const Color(0xFF9CA3AF), fontSize: 13.5),
                  filled: true,
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.all(16),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(20), borderSide: const BorderSide(color: Color(0xFFE5E7EB))),
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(20), borderSide: const BorderSide(color: Color(0xFFE5E7EB))),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(20), borderSide: const BorderSide(color: Color(0xFF0E7052), width: 1.5)),
                ),
              ),
              const SizedBox(height: 26),

              // Transmit Button
              SizedBox(
                width: double.infinity,
                height: 54,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF0E7052),
                    foregroundColor: Colors.white,
                    elevation: 0,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
                  ),
                  onPressed: _isSubmitting ? null : _submitVitals,
                  child: _isSubmitting
                      ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                      : Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(
                              'Transmit Vitals to Clinic',
                              style: GoogleFonts.plusJakartaSans(fontSize: 15, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(width: 8),
                            const Icon(Icons.arrow_forward, size: 18),
                          ],
                        ),
                ),
              ),
              const SizedBox(height: 34),

              // Recent Vitals & History List
              Text(
                'Recent Vitals History',
                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 16, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 12),
              if (_pastVitals.isEmpty)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: const Color(0xFFE5E7EB)),
                  ),
                  child: Center(
                    child: Text(
                      'No previous vitals logged for this account.',
                      style: GoogleFonts.plusJakartaSans(color: const Color(0xFF9CA3AF), fontSize: 13),
                    ),
                  ),
                )
              else
                ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: _pastVitals.length,
                  separatorBuilder: (context, index) => const SizedBox(height: 10),
                  itemBuilder: (ctx, i) {
                    final v = _pastVitals[i];
                    final isCrit = v['is_critical'] == 1 || v['is_critical'] == true;
                    return Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: isCrit ? const Color(0xFFEF4444) : const Color(0xFFE5E7EB),
                          width: isCrit ? 1.5 : 1,
                        ),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                v['child_name'] ?? 'Child',
                                style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 14.5, color: const Color(0xFF111827)),
                              ),
                              if (isCrit)
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(color: const Color(0xFFFEE2E2), borderRadius: BorderRadius.circular(8)),
                                  child: Text('CRITICAL ALERT', style: GoogleFonts.plusJakartaSans(color: const Color(0xFFEF4444), fontWeight: FontWeight.bold, fontSize: 10)),
                                ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              if (v['temperature'] != null) ...[
                                const Icon(CupertinoIcons.thermometer, size: 14, color: Color(0xFF0E7052)),
                                const SizedBox(width: 4),
                                Text('${v['temperature']}°C', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.w700, fontSize: 13, color: const Color(0xFF374151))),
                                const SizedBox(width: 14),
                              ],
                              if (v['weight_kg'] != null) ...[
                                const Icon(CupertinoIcons.circle_grid_hex, size: 14, color: Color(0xFF0E7052)),
                                const SizedBox(width: 4),
                                Text('${v['weight_kg']} kg', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.w700, fontSize: 13, color: const Color(0xFF374151))),
                                const SizedBox(width: 14),
                              ],
                              Text(
                                v['created_at'] != null ? v['created_at'].toString().substring(0, 16) : '',
                                style: GoogleFonts.plusJakartaSans(fontSize: 11, color: const Color(0xFF9CA3AF)),
                              ),
                            ],
                          ),
                          if (v['symptoms'] != null && v['symptoms'].toString().isNotEmpty) ...[
                            const SizedBox(height: 8),
                            Text(
                              v['symptoms'],
                              style: GoogleFonts.plusJakartaSans(fontSize: 12.5, color: const Color(0xFF6B7280)),
                            ),
                          ],
                        ],
                      ),
                    );
                  },
                ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: const CustomBottomNavBar(currentIndex: -1),
    );
  }
}
