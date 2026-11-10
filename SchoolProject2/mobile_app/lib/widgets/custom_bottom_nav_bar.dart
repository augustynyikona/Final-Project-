import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../screens/main_wrapper.dart';
import '../screens/log_symptoms_screen.dart';
import '../screens/invoices_screen.dart';
import 'add_child_modal.dart';

class CustomBottomNavBar extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int>? onTabSelected;

  const CustomBottomNavBar({
    super.key,
    required this.currentIndex,
    this.onTabSelected,
  });

  void _handleTabTap(BuildContext context, int index) {
    if (index == 2) {
      _showQuickActionSheet(context);
      return;
    }

    if (onTabSelected != null) {
      onTabSelected!(index);
    } else {
      // Map index (0: Home, 1: Appointments, 3: Messages, 4: Profile) to MainWrapper tab (0, 1, 2, 3)
      int targetMainTab = 0;
      if (index == 1) targetMainTab = 1;
      if (index == 3) targetMainTab = 2;
      if (index == 4) targetMainTab = 3;

      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(
          builder: (_) => MainWrapper(initialTab: targetMainTab),
        ),
        (route) => false,
      );
    }
  }

  void _showQuickActionSheet(BuildContext context) async {
    final prefs = await SharedPreferences.getInstance();
    final guardianId = prefs.getInt('user_id');

    if (!context.mounted) return;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: const Color(0xFFE5E7EB),
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
            ),
            const SizedBox(height: 18),
            Text(
              'Quick Actions',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: const Color(0xFF111827),
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Choose a clinical service to continue',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 12.5,
                color: const Color(0xFF6B7280),
              ),
            ),
            const SizedBox(height: 20),

            _buildActionTile(
              context: ctx,
              icon: CupertinoIcons.calendar_badge_plus,
              color: const Color(0xFF0E7052),
              title: 'Book Immunization Session',
              subtitle: 'Schedule child vaccination at Kansenshi Clinic',
              onTap: () {
                Navigator.pop(ctx);
                _handleTabTap(context, 1);
              },
            ),
            const SizedBox(height: 10),

            _buildActionTile(
              context: ctx,
              icon: CupertinoIcons.person_badge_plus,
              color: const Color(0xFF2563EB),
              title: 'Register Dependent Child',
              subtitle: 'Generate official Under-5 health profile',
              onTap: () {
                Navigator.pop(ctx);
                if (guardianId != null) {
                  showAddChildModal(
                    context: context,
                    guardianId: guardianId,
                  );
                }
              },
            ),
            const SizedBox(height: 10),

            _buildActionTile(
              context: ctx,
              icon: CupertinoIcons.heart_circle_fill,
              color: const Color(0xFFEF4444),
              title: 'Log Symptoms & Vitals',
              subtitle: 'Record temperature, weight, and triage alerts',
              onTap: () {
                Navigator.pop(ctx);
                Navigator.push(context, MaterialPageRoute(builder: (_) => const LogSymptomsScreen()));
              },
            ),
            const SizedBox(height: 10),

            _buildActionTile(
              context: ctx,
              icon: CupertinoIcons.doc_plaintext,
              color: const Color(0xFF0284C7),
              title: 'Pharmacy Bills & Invoices',
              subtitle: 'Check dispensary statements and payment status',
              onTap: () {
                Navigator.pop(ctx);
                Navigator.push(context, MaterialPageRoute(builder: (_) => const InvoicesScreen()));
              },
            ),
            const SizedBox(height: 16),
          ],
        ),
      ),
    );
  }

  Widget _buildActionTile({
    required BuildContext context,
    required IconData icon,
    required Color color,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xFFF9FAFB),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFFE5E7EB)),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withOpacity(0.12),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: GoogleFonts.plusJakartaSans(
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: const Color(0xFF111827),
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    style: GoogleFonts.plusJakartaSans(
                      fontSize: 12,
                      color: const Color(0xFF6B7280),
                    ),
                  ),
                ],
              ),
            ),
            const Icon(CupertinoIcons.chevron_right, size: 16, color: Color(0xFF9CA3AF)),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        border: const Border(
          top: BorderSide(color: Color(0xFFE5E7EB), width: 1),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _buildNavItem(
                context: context,
                index: 0,
                icon: CupertinoIcons.house_fill,
                label: 'Home',
                isSelected: currentIndex == 0,
              ),
              _buildNavItem(
                context: context,
                index: 1,
                icon: CupertinoIcons.calendar,
                label: 'Appointments',
                isSelected: currentIndex == 1,
              ),
              // Floating Action Button Center (+)
              GestureDetector(
                onTap: () => _handleTabTap(context, 2),
                child: Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    color: const Color(0xFF0E7052),
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF0E7052).withOpacity(0.35),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: const Center(
                    child: Icon(
                      CupertinoIcons.add,
                      color: Colors.white,
                      size: 24,
                    ),
                  ),
                ),
              ),
              _buildNavItem(
                context: context,
                index: 3,
                icon: CupertinoIcons.chat_bubble_2,
                label: 'Messages',
                isSelected: currentIndex == 3,
              ),
              _buildNavItem(
                context: context,
                index: 4,
                icon: CupertinoIcons.person,
                label: 'Profile',
                isSelected: currentIndex == 4,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem({
    required BuildContext context,
    required int index,
    required IconData icon,
    required String label,
    required bool isSelected,
  }) {
    final activeColor = const Color(0xFF0E7052);
    final inactiveColor = const Color(0xFF9CA3AF);

    return InkWell(
      onTap: () => _handleTabTap(context, index),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: 22,
              color: isSelected ? activeColor : inactiveColor,
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: GoogleFonts.plusJakartaSans(
                fontSize: 11,
                fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
                color: isSelected ? activeColor : inactiveColor,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
