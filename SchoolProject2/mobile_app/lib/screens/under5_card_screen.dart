import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import '../utils/constants.dart';
import '../utils/theme.dart';
import 'log_symptoms_screen.dart';
import '../widgets/custom_bottom_nav_bar.dart';
import '../widgets/booking_modal.dart';

class Under5CardScreen extends StatefulWidget {
  final int childId;
  final String? childName;
  const Under5CardScreen({super.key, required this.childId, this.childName});

  @override
  State<Under5CardScreen> createState() => _Under5CardScreenState();
}

class _Under5CardScreenState extends State<Under5CardScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _cardData;

  @override
  void initState() {
    super.initState();
    _fetchUnder5Card();
  }

  Future<void> _fetchUnder5Card() async {
    try {
      final res = await http.get(Uri.parse('$API_BASE_URL/under5_card.php?child_id=${widget.childId}'));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['status'] == 'success') {
          if (mounted) {
            setState(() {
              _cardData = data;
              _isLoading = false;
            });
          }
        }
      }
    } catch (e) {
      debugPrint('Error loading under5 card: $e');
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _openBookingModal({int? initialVaccineId}) {
    showBookingModal(
      context: context,
      initialChildId: widget.childId,
      initialVaccineId: initialVaccineId,
      onBookingSuccess: () {
        _fetchUnder5Card();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        backgroundColor: AppTheme.backgroundLight,
        appBar: AppBar(title: Text('Under-5 Health Card', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold))),
        body: const Center(child: CircularProgressIndicator(color: AppTheme.emeraldGreen)),
      );
    }

    if (_cardData == null) {
      return Scaffold(
        backgroundColor: AppTheme.backgroundLight,
        appBar: AppBar(title: Text('Under-5 Health Card', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold))),
        body: const Center(child: Text('Unable to load card records.')),
      );
    }

    final child = _cardData!['child'] ?? {};
    final progress = _cardData!['progress_percent'] ?? 0;
    final milestones = _cardData!['milestones'] as List<dynamic>? ?? [];
    final nextReview = _cardData!['next_review'] as Map<String, dynamic>?;
    final growthLogs = _cardData!['growth_logs'] as List<dynamic>? ?? [];
    final childName = child['name'] ?? widget.childName ?? 'Child Profile';

    final hasScheduledReview = nextReview?['has_scheduled'] == true;

    return Scaffold(
      backgroundColor: AppTheme.backgroundLight,
      appBar: AppBar(
        title: Text('Under-5 Health Record', style: GoogleFonts.spaceGrotesk(fontSize: 18, fontWeight: FontWeight.bold, color: AppTheme.darkNavy)),
        actions: [
          IconButton(
            icon: const Icon(CupertinoIcons.heart_fill, color: AppTheme.dangerRose),
            tooltip: 'Log Symptoms & Triage',
            onPressed: () {
              Navigator.push(context, MaterialPageRoute(builder: (_) => LogSymptomsScreen(preSelectedChildId: widget.childId)));
            },
          ),
          IconButton(
            icon: const Icon(CupertinoIcons.calendar_badge_plus, color: AppTheme.emeraldGreen),
            tooltip: 'Book Next Review',
            onPressed: () => _openBookingModal(initialVaccineId: nextReview?['primary_vaccine_id']),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: AppTheme.emeraldGreen,
        onRefresh: _fetchUnder5Card,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // 1. Child Profile Card
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(24),
                  border: Border.all(color: AppTheme.borderLight),
                  boxShadow: [
                    BoxShadow(color: Colors.black.withAlpha(6), blurRadius: 14, offset: const Offset(0, 4)),
                  ],
                ),
                child: Column(
                  children: [
                    Stack(
                      alignment: Alignment.topRight,
                      children: [
                        Container(
                          width: 80,
                          height: 80,
                          decoration: const BoxDecoration(
                            color: AppTheme.mintHero,
                            shape: BoxShape.circle,
                          ),
                          child: Center(
                            child: Text(
                              childName.isNotEmpty ? childName[0].toUpperCase() : 'C',
                              style: GoogleFonts.spaceGrotesk(fontSize: 34, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                            ),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.all(5),
                          decoration: const BoxDecoration(color: AppTheme.emeraldGreen, shape: BoxShape.circle),
                          child: const Icon(CupertinoIcons.checkmark_seal_fill, size: 14, color: Colors.white),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),

                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppTheme.mintHero,
                        borderRadius: BorderRadius.circular(100),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(CupertinoIcons.star_fill, size: 12, color: AppTheme.emeraldGreen),
                          const SizedBox(width: 4),
                          Text(
                            'Official MoH Under-5 Passport',
                            style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),

                    Text(
                      childName,
                      style: GoogleFonts.spaceGrotesk(fontSize: 22, fontWeight: FontWeight.bold, color: AppTheme.textMain),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'Kansenshi Pediatric Clinic • Child ID: #CHD-${child['id']}',
                      style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted),
                    ),
                    const SizedBox(height: 16),

                    // Stats Row
                    Container(
                      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
                      decoration: BoxDecoration(
                        color: AppTheme.backgroundLight,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: AppTheme.borderLight),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          _buildStatMetric('DOB', child['date_of_birth'] ?? 'N/A'),
                          Container(width: 1, height: 26, color: AppTheme.borderLight),
                          _buildStatMetric('Age', '${_cardData!['age_weeks'] ?? 0} weeks'),
                          Container(width: 1, height: 26, color: AppTheme.borderLight),
                          _buildStatMetric('Doses', '${_cardData!['completed_count'] ?? 0}/${_cardData!['total_target_vaccines'] ?? 0}'),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // 2. NEXT REVIEW CARD (Crucial User Requirement)
              if (nextReview != null) ...[
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: hasScheduledReview
                          ? [const Color(0xFFE0F2FE), const Color(0xFFBAE6FD)]
                          : [const Color(0xFFE8F8F2), const Color(0xFFD1F2E2)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: BorderRadius.circular(22),
                    border: Border.all(
                      color: hasScheduledReview ? const Color(0xFF7DD3FC) : const Color(0xFFA7F3D0),
                      width: 1.5,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: (hasScheduledReview ? Colors.blue : const Color(0xFF0E7052)).withOpacity(0.08),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: Colors.white,
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Icon(
                                  hasScheduledReview ? CupertinoIcons.calendar_today : CupertinoIcons.clock_fill,
                                  size: 18,
                                  color: hasScheduledReview ? Colors.blue.shade700 : AppTheme.emeraldGreen,
                                ),
                              ),
                              const SizedBox(width: 10),
                              Text(
                                hasScheduledReview ? 'CONFIRMED NEXT REVIEW' : 'UPCOMING NEXT REVIEW',
                                style: GoogleFonts.spaceGrotesk(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w800,
                                  color: hasScheduledReview ? Colors.blue.shade900 : const Color(0xFF065F46),
                                  letterSpacing: 0.5,
                                ),
                              ),
                            ],
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(100),
                            ),
                            child: Text(
                              hasScheduledReview ? 'Booked' : (nextReview['is_overdue'] == true ? 'Due Now' : 'Sequential'),
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: hasScheduledReview ? Colors.blue.shade700 : AppTheme.emeraldGreen,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),

                      Text(
                        nextReview['service_title'] ?? 'Next Review Milestone',
                        style: GoogleFonts.spaceGrotesk(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: AppTheme.darkNavy,
                        ),
                      ),
                      const SizedBox(height: 4),

                      if (hasScheduledReview) ...[
                        Text(
                          '📅 Date: ${nextReview['date_formatted']} at ${nextReview['time_formatted']}',
                          style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600, color: Colors.blue.shade900),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '📍 Location: ${nextReview['room_number'] ?? 'Nurse Station (Immunization Clinic)'}',
                          style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '🩺 Care Team: Administered by clinic nurse. Clinical triage performed upon arrival.',
                          style: GoogleFonts.inter(fontSize: 11.5, color: Colors.blue.shade800),
                        ),
                      ] else ...[
                        Text(
                          'Target Review Date: ${nextReview['due_date_formatted']} (${nextReview['target_age']})',
                          style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600, color: const Color(0xFF047857)),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Injections are given by pediatric nurses. Guardians must log child triage symptoms during booking.',
                          style: GoogleFonts.inter(fontSize: 11.5, color: const Color(0xFF065F46), height: 1.3),
                        ),
                        const SizedBox(height: 14),

                        SizedBox(
                          width: double.infinity,
                          height: 44,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppTheme.emeraldGreen,
                              foregroundColor: Colors.white,
                              elevation: 0,
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            ),
                            icon: const Icon(CupertinoIcons.calendar_badge_plus, size: 16),
                            label: Text(
                              nextReview['action_label'] ?? 'Book Next Review',
                              style: GoogleFonts.spaceGrotesk(fontSize: 13.5, fontWeight: FontWeight.bold),
                            ),
                            onPressed: () => _openBookingModal(initialVaccineId: nextReview['primary_vaccine_id']),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),
              ],

              // 3. Immunization Progress Summary
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: AppTheme.borderLight),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('Immunization Schedule Completion', style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13.5)),
                        Text('$progress%', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold, fontSize: 16, color: AppTheme.emeraldGreen)),
                      ],
                    ),
                    const SizedBox(height: 10),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: LinearProgressIndicator(
                        value: progress / 100.0,
                        minHeight: 10,
                        backgroundColor: AppTheme.borderLight,
                        valueColor: const AlwaysStoppedAnimation<Color>(AppTheme.emeraldGreen),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      '${_cardData!['completed_count']} of ${_cardData!['total_target_vaccines']} scheduled doses successfully administered.',
                      style: GoogleFonts.inter(fontSize: 12, color: AppTheme.textMuted),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 22),

              // 4. Sequential Milestones List (With Greyed Out States)
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Sequential Vaccination Milestones', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold, fontSize: 16)),
                  Text('Strict Order', style: GoogleFonts.inter(fontSize: 11.5, fontWeight: FontWeight.bold, color: AppTheme.textMuted)),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'At birth, baby receives BCG & OPV 0 (0-13 days). Booking starts at 6 Weeks. Subsequent milestones remain locked until earlier stages are completed.',
                style: GoogleFonts.inter(fontSize: 11.5, color: AppTheme.textMuted),
              ),
              const SizedBox(height: 14),

              ...milestones.map((m) {
                final stageName = m['stage'] ?? '';
                final targetAge = m['target_age'] ?? '';
                final isCompleted = m['is_completed'] == true;
                final isCurrentEligible = m['is_current_eligible'] == true;
                final isLocked = m['is_locked'] == true;
                final isBirth = m['is_birth'] == true;
                final vaccinesDetailed = (m['vaccines_detailed'] as List<dynamic>?) ?? [];

                Color cardBg = Colors.white;
                Color borderColor = AppTheme.borderLight;
                Widget leadingBadge;

                if (isCompleted) {
                  cardBg = Colors.white;
                  borderColor = AppTheme.emeraldGreen.withAlpha(80);
                  leadingBadge = Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(10)),
                    child: const Icon(CupertinoIcons.checkmark_seal_fill, color: AppTheme.emeraldGreen, size: 20),
                  );
                } else if (isBirth) {
                  cardBg = const Color(0xFFF0FDF9);
                  borderColor = const Color(0xFF99F6E4);
                  leadingBadge = Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(color: const Color(0xFF0D9488), borderRadius: BorderRadius.circular(10)),
                    child: const Icon(CupertinoIcons.heart_fill, color: Colors.white, size: 18),
                  );
                } else if (isCurrentEligible) {
                  cardBg = const Color(0xFFF0FDF4);
                  borderColor = AppTheme.emeraldGreen;
                  leadingBadge = Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(color: AppTheme.emeraldGreen, borderRadius: BorderRadius.circular(10)),
                    child: const Icon(CupertinoIcons.clock_fill, color: Colors.white, size: 18),
                  );
                } else {
                  // GREYED OUT
                  cardBg = Colors.grey.shade50;
                  borderColor = Colors.grey.shade200;
                  leadingBadge = Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(10)),
                    child: Icon(CupertinoIcons.lock_fill, color: Colors.grey.shade400, size: 18),
                  );
                }

                return Opacity(
                  opacity: isLocked ? 0.65 : 1.0,
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 12),
                    decoration: BoxDecoration(
                      color: cardBg,
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(color: borderColor, width: isCurrentEligible ? 1.5 : 1),
                    ),
                    child: ExpansionTile(
                      initiallyExpanded: isCurrentEligible || (isBirth && !isCompleted),
                      shape: const Border(),
                      tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                      leading: leadingBadge,
                      title: Row(
                        children: [
                          Expanded(
                            child: Text(
                              stageName,
                              style: GoogleFonts.inter(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                                color: isLocked ? Colors.grey.shade600 : AppTheme.darkNavy,
                              ),
                            ),
                          ),
                          if (isCompleted)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(6)),
                              child: const Text('Complete', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: AppTheme.emeraldGreen)),
                            )
                          else if (isBirth)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: const Color(0xFFCCFBF1), borderRadius: BorderRadius.circular(6)),
                              child: const Text('Birth / Ward', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF0F766E))),
                            )
                          else if (isCurrentEligible)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: AppTheme.emeraldGreen, borderRadius: BorderRadius.circular(6)),
                              child: const Text('Next Review', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.white)),
                            )
                          else
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(6)),
                              child: Text('Locked', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Colors.grey.shade600)),
                            ),
                        ],
                      ),
                      subtitle: Text(
                        'Target Age: $targetAge • Provider: ${m['provider'] ?? 'Nurse'}',
                        style: GoogleFonts.inter(fontSize: 11.5, color: isLocked ? Colors.grey.shade500 : AppTheme.textMuted),
                      ),
                      children: [
                        Padding(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              ...vaccinesDetailed.map((v) {
                                final isVaxDone = v['is_completed'] == true;
                                final vName = v['name'] ?? '';

                                return Container(
                                  margin: const EdgeInsets.only(top: 8),
                                  padding: const EdgeInsets.all(12),
                                  decoration: BoxDecoration(
                                    color: isVaxDone
                                        ? AppTheme.mintHero
                                        : (isLocked ? Colors.grey.shade100 : Colors.white),
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(
                                      color: isVaxDone ? AppTheme.emeraldGreen.withAlpha(60) : AppTheme.borderLight,
                                    ),
                                  ),
                                  child: Row(
                                    children: [
                                      Icon(
                                        isVaxDone
                                            ? CupertinoIcons.checkmark_seal_fill
                                            : (isLocked ? CupertinoIcons.lock_fill : CupertinoIcons.circle),
                                        color: isVaxDone
                                            ? AppTheme.emeraldGreen
                                            : (isLocked ? Colors.grey.shade400 : AppTheme.textLight),
                                        size: 18,
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              vName,
                                              style: GoogleFonts.inter(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 13,
                                                color: isVaxDone
                                                    ? AppTheme.textMain
                                                    : (isLocked ? Colors.grey.shade500 : AppTheme.darkNavy),
                                              ),
                                            ),
                                            const SizedBox(height: 2),
                                            Text(
                                              isVaxDone
                                                  ? 'Administered & Verified by Clinic Nurse'
                                                  : (isLocked ? 'Locked: Complete prior stage first' : 'Eligible for in-app review booking'),
                                              style: GoogleFonts.inter(
                                                fontSize: 11,
                                                color: isVaxDone
                                                    ? AppTheme.emeraldGreen
                                                    : (isLocked ? Colors.grey.shade400 : AppTheme.textMuted),
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                  ),
                                );
                              }),

                              if (isCurrentEligible && !isBirth) ...[
                                const SizedBox(height: 12),
                                SizedBox(
                                  width: double.infinity,
                                  height: 40,
                                  child: OutlinedButton.icon(
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: AppTheme.emeraldGreen,
                                      side: const BorderSide(color: AppTheme.emeraldGreen),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                    ),
                                    icon: const Icon(CupertinoIcons.calendar_badge_plus, size: 16),
                                    label: Text(
                                      'Book $stageName',
                                      style: GoogleFonts.spaceGrotesk(fontSize: 12.5, fontWeight: FontWeight.bold),
                                    ),
                                    onPressed: () => _openBookingModal(initialVaccineId: m['vaccine_ids']?[0]),
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }),

              const SizedBox(height: 20),

              // 5. Growth History
              Text('Physical Growth Records', style: GoogleFonts.spaceGrotesk(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 10),
              if (growthLogs.isEmpty)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: AppTheme.borderLight),
                  ),
                  child: Center(
                    child: Text(
                      'Physical growth measurements are recorded during nurse triage checkups.',
                      style: GoogleFonts.inter(color: AppTheme.textLight, fontSize: 12.5),
                    ),
                  ),
                )
              else
                ...growthLogs.map((g) {
                  return Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: AppTheme.borderLight),
                    ),
                    child: Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(color: AppTheme.mintHero, borderRadius: BorderRadius.circular(12)),
                          child: const Icon(CupertinoIcons.graph_circle_fill, color: AppTheme.emeraldGreen, size: 20),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('Weight: ${g['weight_kg']} kg  •  Height: ${g['height_cm']} cm', style: GoogleFonts.inter(fontWeight: FontWeight.bold, fontSize: 13.5)),
                              const SizedBox(height: 2),
                              Text('Recorded on: ${g['record_date']}', style: GoogleFonts.inter(fontSize: 11.5, color: AppTheme.textMuted)),
                            ],
                          ),
                        ),
                      ],
                    ),
                  );
                }),
              const SizedBox(height: 24),

              // Bottom Action CTAs
              Row(
                children: [
                  Expanded(
                    child: SizedBox(
                      height: 50,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppTheme.emeraldGreen,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                        ),
                        icon: const Icon(CupertinoIcons.calendar_badge_plus, size: 18),
                        label: Text('Book Next Review', style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold)),
                        onPressed: () => _openBookingModal(initialVaccineId: nextReview?['primary_vaccine_id']),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    height: 50,
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppTheme.dangerRose,
                        side: const BorderSide(color: AppTheme.dangerRose),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
                      ),
                      icon: const Icon(CupertinoIcons.heart_fill, size: 16),
                      label: Text('Log Symptoms', style: GoogleFonts.spaceGrotesk(fontSize: 13, fontWeight: FontWeight.bold)),
                      onPressed: () {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => LogSymptomsScreen(preSelectedChildId: widget.childId)));
                      },
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
            ],
          ),
        ),
      ),
      bottomNavigationBar: const CustomBottomNavBar(currentIndex: -1),
    );
  }

  Widget _buildStatMetric(String label, String value) {
    return Column(
      children: [
        Text(value, style: GoogleFonts.spaceGrotesk(fontSize: 14, fontWeight: FontWeight.bold, color: AppTheme.textMain)),
        const SizedBox(height: 2),
        Text(label, style: GoogleFonts.inter(fontSize: 11, color: AppTheme.textMuted)),
      ],
    );
  }
}
