import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import 'under5_card_screen.dart';
import 'log_symptoms_screen.dart';
import '../widgets/add_child_modal.dart';
import '../widgets/custom_bottom_nav_bar.dart';

class RecordsScreen extends StatefulWidget {
  const RecordsScreen({super.key});

  @override
  State<RecordsScreen> createState() => _RecordsScreenState();
}

class _RecordsScreenState extends State<RecordsScreen> {
  bool _isLoading = true;
  List<dynamic> _children = [];
  int? _guardianId;

  @override
  void initState() {
    super.initState();
    _fetchChildren();
  }

  Future<void> _fetchChildren() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _guardianId = prefs.getInt('user_id');

      if (_guardianId == null) {
        if (mounted) setState(() => _isLoading = false);
        return;
      }

      final response = await http.get(Uri.parse('$API_BASE_URL/children.php?guardian_id=$_guardianId'));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success') {
          setState(() {
            _children = data['children'] ?? [];
            _isLoading = false;
          });
        }
      }
    } catch (e) {
      debugPrint('Error fetching records: $e');
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showAddChildModal() {
    if (_guardianId == null) return;
    showAddChildModal(
      context: context,
      guardianId: _guardianId!,
      onChildAdded: (_) => _fetchChildren(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAF9),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF0E7052)))
          : SafeArea(
              child: RefreshIndicator(
                onRefresh: _fetchChildren,
                color: const Color(0xFF0E7052),
                child: SingleChildScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Top Navigation Header
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
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Under-5 Children Records',
                                  style: GoogleFonts.plusJakartaSans(
                                    fontSize: 20,
                                    fontWeight: FontWeight.w800,
                                    color: const Color(0xFF111827),
                                  ),
                                ),
                                Text(
                                  'MoH Under-5 digital immunization cards',
                                  style: GoogleFonts.plusJakartaSans(
                                    fontSize: 12.5,
                                    color: const Color(0xFF6B7280),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          InkWell(
                            onTap: _showAddChildModal,
                            borderRadius: BorderRadius.circular(20),
                            child: Container(
                              width: 44,
                              height: 44,
                              decoration: BoxDecoration(
                                color: const Color(0xFFE8F8F2),
                                shape: BoxShape.circle,
                                border: Border.all(color: const Color(0xFFD8F3E5)),
                              ),
                              child: const Icon(
                                CupertinoIcons.person_badge_plus,
                                color: Color(0xFF0E7052),
                                size: 20,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 20),

                      if (_children.isEmpty)
                        _buildEmptyState()
                      else
                        ListView.separated(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          itemCount: _children.length,
                          separatorBuilder: (context, index) => const SizedBox(height: 14),
                          itemBuilder: (context, index) {
                            final child = _children[index];
                            return _buildChildCard(child);
                          },
                        ),
                    ],
                  ),
                ),
              ),
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFF0E7052),
        foregroundColor: Colors.white,
        elevation: 3,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
        icon: const Icon(CupertinoIcons.plus, size: 18),
        label: Text('Enroll Dependent', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 13.5)),
        onPressed: _showAddChildModal,
      ),
      bottomNavigationBar: const CustomBottomNavBar(currentIndex: -1),
    );
  }

  Widget _buildEmptyState() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 60, horizontal: 24),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFE5E7EB)),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 76,
            height: 76,
            decoration: const BoxDecoration(color: Color(0xFFE8F8F2), shape: BoxShape.circle),
            child: const Icon(CupertinoIcons.person_3_fill, size: 38, color: Color(0xFF0E7052)),
          ),
          const SizedBox(height: 20),
          Text(
            'No Children Profiles Found',
            style: GoogleFonts.plusJakartaSans(fontSize: 18, fontWeight: FontWeight.bold, color: const Color(0xFF111827)),
          ),
          const SizedBox(height: 8),
          Text(
            'Register your child to track their official Under-5 immunization card, growth monitoring, and clinical sessions at Kansenshi Clinic.',
            textAlign: TextAlign.center,
            style: GoogleFonts.plusJakartaSans(color: const Color(0xFF6B7280), fontSize: 13, height: 1.4),
          ),
          const SizedBox(height: 24),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF0E7052),
              foregroundColor: Colors.white,
              elevation: 0,
              padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 12),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
            ),
            onPressed: _showAddChildModal,
            icon: const Icon(CupertinoIcons.add, size: 16),
            label: Text('Enroll Dependent Now', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  Widget _buildChildCard(Map<String, dynamic> child) {
    final name = child['name'] ?? 'Child Profile';
    final dob = child['date_of_birth'] ?? '';
    final gender = child['gender'] ?? 'N/A';
    final childId = child['id'];

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE5E7EB)),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.02), blurRadius: 10, offset: const Offset(0, 3)),
        ],
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () {
          if (childId != null) {
            Navigator.push(context, MaterialPageRoute(builder: (_) => Under5CardScreen(childId: childId, childName: name)));
          }
        },
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 52,
                    height: 52,
                    decoration: const BoxDecoration(
                      color: Color(0xFFE8F8F2),
                      shape: BoxShape.circle,
                    ),
                    child: Center(
                      child: Text(
                        name.isNotEmpty ? name[0].toUpperCase() : 'C',
                        style: GoogleFonts.plusJakartaSans(fontSize: 22, fontWeight: FontWeight.bold, color: const Color(0xFF0E7052)),
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(name, style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 16, color: const Color(0xFF111827))),
                        const SizedBox(height: 3),
                        Row(
                          children: [
                            const Icon(CupertinoIcons.calendar, size: 13, color: Color(0xFF9CA3AF)),
                            const SizedBox(width: 4),
                            Text('Born: $dob  •  $gender', style: GoogleFonts.plusJakartaSans(fontSize: 12.5, color: const Color(0xFF6B7280))),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const Icon(CupertinoIcons.chevron_right, size: 18, color: Color(0xFF9CA3AF)),
                ],
              ),
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 14),
                child: Divider(color: Color(0xFFF3F4F6)),
              ),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: const Color(0xFF0E7052),
                        side: const BorderSide(color: Color(0xFF0E7052), width: 1.2),
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                      ),
                      icon: const Icon(CupertinoIcons.doc_text_fill, size: 16),
                      label: Text('Under-5 Card', style: GoogleFonts.plusJakartaSans(fontSize: 12.5, fontWeight: FontWeight.bold)),
                      onPressed: () {
                        if (childId != null) {
                          Navigator.push(context, MaterialPageRoute(builder: (_) => Under5CardScreen(childId: childId, childName: name)));
                        }
                      },
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFFE8F8F2),
                        foregroundColor: const Color(0xFF0E7052),
                        elevation: 0,
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                      ),
                      icon: const Icon(CupertinoIcons.thermometer, size: 16),
                      label: Text('Log Vitals', style: GoogleFonts.plusJakartaSans(fontSize: 12.5, fontWeight: FontWeight.bold)),
                      onPressed: () {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => LogSymptomsScreen(preselectedChildId: childId)));
                      },
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
