import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';

class MessagesScreen extends StatefulWidget {
  const MessagesScreen({super.key});

  @override
  State<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends State<MessagesScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isLoading = true;
  int? _guardianId;

  List<dynamic> _messages = [];
  List<dynamic> _smsAlerts = [];
  int _unreadCount = 0;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _loadAllCommunications();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadAllCommunications() async {
    final prefs = await SharedPreferences.getInstance();
    _guardianId = prefs.getInt('user_id');

    if (_guardianId == null) {
      if (mounted) setState(() => _isLoading = false);
      return;
    }

    try {
      // 1. Fetch Department Messages
      final msgRes = await http.get(Uri.parse('$API_BASE_URL/messages.php?guardian_id=$_guardianId'));
      if (msgRes.statusCode == 200) {
        final msgData = jsonDecode(msgRes.body);
        if (msgData['status'] == 'success') {
          _messages = msgData['messages'] ?? [];
          _unreadCount = msgData['unread_count'] ?? 0;
        }
      }

      // 2. Fetch SMS & In-System Alerts
      final alertRes = await http.get(Uri.parse('$API_BASE_URL/notifications.php?guardian_id=$_guardianId'));
      if (alertRes.statusCode == 200) {
        final alertData = jsonDecode(alertRes.body);
        if (alertData['status'] == 'success') {
          _smsAlerts = alertData['notifications'] ?? [];
        }
      }
    } catch (e) {
      debugPrint('Error loading communications: $e');
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  Future<void> _markAsRead(int messageId) async {
    if (_guardianId == null) return;
    try {
      await http.post(
        Uri.parse('$API_BASE_URL/messages.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'action': 'mark_read',
          'message_id': messageId,
          'guardian_id': _guardianId,
        }),
      );
      _loadAllCommunications();
    } catch (_) {}
  }

  void _showComposeDialog() {
    final subjectController = TextEditingController();
    final bodyController = TextEditingController();
    String selectedRole = 'nurse';
    bool isSending = false;

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
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
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
                const SizedBox(height: 18),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Compose Message',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 20,
                            fontWeight: FontWeight.bold,
                            color: const Color(0xFF111827),
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Direct communication with Kansenshi Clinic team',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 12.5,
                            color: const Color(0xFF6B7280),
                          ),
                        ),
                      ],
                    ),
                    InkWell(
                      onTap: () => Navigator.pop(ctx),
                      borderRadius: BorderRadius.circular(20),
                      child: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF3F4F6),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.close, size: 20, color: Color(0xFF4B5563)),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                Text(
                  'Recipient Department',
                  style: GoogleFonts.plusJakartaSans(
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                    color: const Color(0xFF374151),
                  ),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF9FAFB),
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: const Color(0xFFE5E7EB)),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<String>(
                      isExpanded: true,
                      value: selectedRole,
                      icon: const Icon(CupertinoIcons.chevron_down, size: 16, color: Color(0xFF6B7280)),
                      items: const [
                        DropdownMenuItem(
                          value: 'nurse',
                          child: Text('Pediatric Nursing Station'),
                        ),
                        DropdownMenuItem(
                          value: 'doctor',
                          child: Text('Consulting Pediatrician / Doctor'),
                        ),
                        DropdownMenuItem(
                          value: 'pharmacy',
                          child: Text('Hospital Pharmacy Dispensary'),
                        ),
                        DropdownMenuItem(
                          value: 'admin',
                          child: Text('Clinic Administration Desk'),
                        ),
                      ],
                      onChanged: (val) {
                        if (val != null) setModalState(() => selectedRole = val);
                      },
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Subject',
                  style: GoogleFonts.plusJakartaSans(
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                    color: const Color(0xFF374151),
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: subjectController,
                  decoration: InputDecoration(
                    hintText: 'e.g. Inquiring about next Pentavalent dose',
                    hintStyle: GoogleFonts.plusJakartaSans(color: const Color(0xFF9CA3AF), fontSize: 13.5),
                    filled: true,
                    fillColor: const Color(0xFFF9FAFB),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
                    ),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFF0E7052), width: 1.5),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Message Content',
                  style: GoogleFonts.plusJakartaSans(
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                    color: const Color(0xFF374151),
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: bodyController,
                  maxLines: 4,
                  decoration: InputDecoration(
                    hintText: 'Type your message or inquiry here...',
                    hintStyle: GoogleFonts.plusJakartaSans(color: const Color(0xFF9CA3AF), fontSize: 13.5),
                    filled: true,
                    fillColor: const Color(0xFFF9FAFB),
                    contentPadding: const EdgeInsets.all(16),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
                    ),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16),
                      borderSide: const BorderSide(color: Color(0xFF0E7052), width: 1.5),
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF0E7052),
                      foregroundColor: Colors.white,
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(28),
                      ),
                    ),
                    onPressed: isSending
                        ? null
                        : () async {
                            final subj = subjectController.text.trim();
                            final bdy = bodyController.text.trim();
                            if (subj.isEmpty || bdy.isEmpty) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('Please enter both subject and message details.')),
                              );
                              return;
                            }

                            setModalState(() => isSending = true);

                            try {
                              final res = await http.post(
                                Uri.parse('$API_BASE_URL/messages.php'),
                                headers: {'Content-Type': 'application/json'},
                                body: jsonEncode({
                                  'guardian_id': _guardianId,
                                  'recipient_role': selectedRole,
                                  'subject': subj,
                                  'body': bdy,
                                }),
                              );
                              final respData = jsonDecode(res.body);
                              if (res.statusCode == 201 && respData['status'] == 'success') {
                                if (ctx.mounted) {
                                  Navigator.pop(ctx);
                                }
                                if (mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      backgroundColor: const Color(0xFF0E7052),
                                      content: Text(respData['message'] ?? 'Message delivered to clinic team.'),
                                    ),
                                  );
                                  _loadAllCommunications();
                                }
                              } else {
                                if (mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(content: Text(respData['message'] ?? 'Failed to send message.')),
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
                              if (ctx.mounted) {
                                setModalState(() => isSending = false);
                              }
                            }
                          },
                    child: isSending
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                          )
                        : Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Text(
                                'Dispatch Message',
                                style: GoogleFonts.plusJakartaSans(
                                  fontSize: 15,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                              const SizedBox(width: 8),
                              const Icon(Icons.arrow_forward, size: 18),
                            ],
                          ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _showMessageDetail(Map<String, dynamic> msg) {
    final isUnread = (msg['is_read'] == 0 || msg['is_read'] == false) && msg['sender_id'] != _guardianId;
    if (isUnread) {
      _markAsRead(msg['id']);
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
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
            const SizedBox(height: 18),
            Row(
              children: [
                _buildRoleBadge(msg['sender_role'] ?? 'staff'),
                const Spacer(),
                Text(
                  msg['created_at'] != null ? msg['created_at'].toString().substring(0, 16) : '',
                  style: GoogleFonts.plusJakartaSans(fontSize: 12, color: const Color(0xFF9CA3AF)),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Text(
              msg['subject'] ?? 'Message',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: const Color(0xFF111827),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F8F2),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(CupertinoIcons.person_crop_circle_fill, size: 18, color: Color(0xFF0E7052)),
                ),
                const SizedBox(width: 10),
                Text(
                  'From: ${msg['sender_name'] ?? 'Kansenshi Clinic Team'}',
                  style: GoogleFonts.plusJakartaSans(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: const Color(0xFF0E7052),
                  ),
                ),
              ],
            ),
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Divider(color: Color(0xFFF3F4F6)),
            ),
            Text(
              msg['body'] ?? '',
              style: GoogleFonts.plusJakartaSans(
                fontSize: 14.5,
                height: 1.6,
                color: const Color(0xFF374151),
              ),
            ),
            const SizedBox(height: 28),
            SizedBox(
              width: double.infinity,
              height: 50,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0E7052),
                  foregroundColor: Colors.white,
                  elevation: 0,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
                ),
                onPressed: () {
                  Navigator.pop(context);
                  _showComposeDialog();
                },
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(CupertinoIcons.reply, size: 18),
                    const SizedBox(width: 8),
                    Text(
                      'Reply to Clinic',
                      style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 14.5),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRoleBadge(String role) {
    Color bg = const Color(0xFFE8F8F2);
    Color text = const Color(0xFF0E7052);
    String label = 'Clinical Staff';

    switch (role.toLowerCase()) {
      case 'nurse':
        bg = const Color(0xFFE8F8F2);
        text = const Color(0xFF0E7052);
        label = 'Pediatric Nurse';
        break;
      case 'doctor':
        bg = const Color(0xFFF3E8FF);
        text = const Color(0xFF7E22CE);
        label = 'Pediatrician';
        break;
      case 'pharmacy':
        bg = const Color(0xFFE0F2FE);
        text = const Color(0xFF0284C7);
        label = 'Pharmacy';
        break;
      case 'admin':
        bg = const Color(0xFFFEF3C7);
        text = const Color(0xFFD97706);
        label = 'Admin Desk';
        break;
      case 'guardian':
        bg = const Color(0xFFF3F4F6);
        text = const Color(0xFF4B5563);
        label = 'You (Guardian)';
        break;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        label,
        style: GoogleFonts.plusJakartaSans(color: text, fontWeight: FontWeight.bold, fontSize: 11),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAF9),
      body: SafeArea(
        child: Column(
          children: [
            // Top App Bar matching reference screenshot style
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
              child: Row(
                children: [
                  if (Navigator.canPop(context)) ...[
                    GestureDetector(
                      onTap: () => Navigator.pop(context),
                      child: Container(
                        width: 40,
                        height: 40,
                        margin: const EdgeInsets.only(right: 12),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          shape: BoxShape.circle,
                          border: Border.all(color: const Color(0xFFE5E7EB)),
                        ),
                        child: const Icon(CupertinoIcons.back, size: 20, color: Color(0xFF111827)),
                      ),
                    ),
                  ],
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Clinic Messages',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 22,
                            fontWeight: FontWeight.w800,
                            color: const Color(0xFF111827),
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Inquiries & alerts with Kansenshi staff',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 13,
                            color: const Color(0xFF6B7280),
                          ),
                        ),
                      ],
                    ),
                  ),
                  InkWell(
                    onTap: _showComposeDialog,
                    borderRadius: BorderRadius.circular(24),
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: const Color(0xFFE8F8F2),
                        shape: BoxShape.circle,
                        border: Border.all(color: const Color(0xFFD8F3E5)),
                      ),
                      child: const Icon(
                        CupertinoIcons.pencil_outline,
                        color: Color(0xFF0E7052),
                        size: 20,
                      ),
                    ),
                  ),
                ],
              ),
            ),

            // Modern Pill Segmented Tab Bar
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
              child: Container(
                height: 46,
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: const Color(0xFFF3F4F6),
                  borderRadius: BorderRadius.circular(24),
                ),
                child: TabBar(
                  controller: _tabController,
                  indicator: BoxDecoration(
                    color: const Color(0xFF0E7052),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFF0E7052).withOpacity(0.2),
                        blurRadius: 8,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  labelColor: Colors.white,
                  unselectedLabelColor: const Color(0xFF6B7280),
                  labelStyle: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 13),
                  unselectedLabelStyle: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.w600, fontSize: 13),
                  indicatorSize: TabBarIndicatorSize.tab,
                  dividerColor: Colors.transparent,
                  tabs: [
                    Tab(
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(CupertinoIcons.chat_bubble_2_fill, size: 15),
                          const SizedBox(width: 6),
                          const Text('Direct Messages'),
                          if (_unreadCount > 0) ...[
                            const SizedBox(width: 6),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: const BoxDecoration(
                                color: Color(0xFFEF4444),
                                shape: BoxShape.circle,
                              ),
                              child: Text(
                                '$_unreadCount',
                                style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const Tab(
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(CupertinoIcons.bell_fill, size: 15),
                          SizedBox(width: 6),
                          Text('SMS Alerts'),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // Tab View Body
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator(color: Color(0xFF0E7052)))
                  : TabBarView(
                      controller: _tabController,
                      children: [
                        _buildDepartmentChatTab(),
                        _buildSmsAlertsTab(),
                      ],
                    ),
            ),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFF0E7052),
        foregroundColor: Colors.white,
        elevation: 3,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
        icon: const Icon(CupertinoIcons.paperplane_fill, size: 17),
        label: Text(
          'New Message',
          style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold, fontSize: 13.5),
        ),
        onPressed: _showComposeDialog,
      ),
    );
  }

  Widget _buildDepartmentChatTab() {
    if (_messages.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 76,
                height: 76,
                decoration: const BoxDecoration(
                  color: Color(0xFFE8F8F2),
                  shape: BoxShape.circle,
                ),
                child: const Icon(CupertinoIcons.chat_bubble_2_fill, color: Color(0xFF0E7052), size: 36),
              ),
              const SizedBox(height: 18),
              Text(
                'No Messages Yet',
                style: GoogleFonts.plusJakartaSans(fontSize: 18, fontWeight: FontWeight.bold, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 8),
              Text(
                'Reach out directly to pediatric nurses, attending doctors, pharmacy dispensary, or the clinic desk.',
                textAlign: TextAlign.center,
                style: GoogleFonts.plusJakartaSans(color: const Color(0xFF6B7280), fontSize: 13.5, height: 1.4),
              ),
              const SizedBox(height: 24),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0E7052),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                ),
                icon: const Icon(CupertinoIcons.pencil_outline, size: 18),
                label: Text('Compose Inquiry', style: GoogleFonts.plusJakartaSans(fontWeight: FontWeight.bold)),
                onPressed: _showComposeDialog,
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      color: const Color(0xFF0E7052),
      onRefresh: _loadAllCommunications,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 80),
        itemCount: _messages.length,
        separatorBuilder: (context, index) => const SizedBox(height: 12),
        itemBuilder: (context, index) {
          final msg = _messages[index];
          final isOutgoing = msg['sender_id'] == _guardianId;
          final isUnread = (msg['is_read'] == 0 || msg['is_read'] == false) && !isOutgoing;

          return Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                color: isUnread ? const Color(0xFF0E7052) : const Color(0xFFE5E7EB),
                width: isUnread ? 1.5 : 1,
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.02),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Material(
              color: Colors.transparent,
              child: InkWell(
                borderRadius: BorderRadius.circular(20),
                onTap: () => _showMessageDetail(msg),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          _buildRoleBadge(isOutgoing ? 'guardian' : (msg['sender_role'] ?? 'staff')),
                          Row(
                            children: [
                              if (isUnread) ...[
                                Container(
                                  width: 8,
                                  height: 8,
                                  decoration: const BoxDecoration(
                                    color: Color(0xFF0E7052),
                                    shape: BoxShape.circle,
                                  ),
                                ),
                                const SizedBox(width: 6),
                              ],
                              Text(
                                msg['created_at'] != null ? msg['created_at'].toString().substring(0, 16) : '',
                                style: GoogleFonts.plusJakartaSans(fontSize: 11.5, color: const Color(0xFF9CA3AF)),
                              ),
                            ],
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Text(
                        msg['subject'] ?? 'Untitled Message',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 15,
                          fontWeight: isUnread ? FontWeight.w800 : FontWeight.w700,
                          color: const Color(0xFF111827),
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        msg['body'] ?? '',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 13,
                          color: const Color(0xFF6B7280),
                          height: 1.45,
                        ),
                      ),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Icon(
                            isOutgoing ? CupertinoIcons.arrow_up_right : CupertinoIcons.arrow_down_left,
                            size: 14,
                            color: isOutgoing ? const Color(0xFF9CA3AF) : const Color(0xFF0E7052),
                          ),
                          const SizedBox(width: 6),
                          Text(
                            isOutgoing
                                ? 'To: ${msg['recipient_role']?.toString().toUpperCase() ?? 'CLINIC'}'
                                : 'From: ${msg['sender_name'] ?? 'Clinic Staff'}',
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                              color: isOutgoing ? const Color(0xFF9CA3AF) : const Color(0xFF0E7052),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildSmsAlertsTab() {
    if (_smsAlerts.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 76,
                height: 76,
                decoration: const BoxDecoration(
                  color: Color(0xFFE8F8F2),
                  shape: BoxShape.circle,
                ),
                child: const Icon(CupertinoIcons.bell_slash_fill, color: Color(0xFF0E7052), size: 36),
              ),
              const SizedBox(height: 18),
              Text(
                'No SMS Alerts Yet',
                style: GoogleFonts.plusJakartaSans(fontSize: 18, fontWeight: FontWeight.bold, color: const Color(0xFF111827)),
              ),
              const SizedBox(height: 8),
              Text(
                'Automated reminders (7-day, 3-day, 1-day, and 2-hour warnings) dispatched by clinical nurses will appear here.',
                textAlign: TextAlign.center,
                style: GoogleFonts.plusJakartaSans(color: const Color(0xFF6B7280), fontSize: 13.5, height: 1.4),
              ),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      color: const Color(0xFF0E7052),
      onRefresh: _loadAllCommunications,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 80),
        itemCount: _smsAlerts.length,
        separatorBuilder: (context, index) => const SizedBox(height: 12),
        itemBuilder: (context, index) {
          final alert = _smsAlerts[index];
          return Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: const Color(0xFFE5E7EB)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.02),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: const Color(0xFFE8F8F2),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(CupertinoIcons.device_phone_portrait, color: Color(0xFF0E7052), size: 20),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: const Color(0xFFD8F3E5),
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Text(
                                alert['status'] ?? 'Delivered',
                                style: GoogleFonts.plusJakartaSans(
                                  color: const Color(0xFF0E7052),
                                  fontWeight: FontWeight.bold,
                                  fontSize: 11,
                                ),
                              ),
                            ),
                            Text(
                              alert['sent_at'] != null ? alert['sent_at'].toString().substring(0, 16) : '',
                              style: GoogleFonts.plusJakartaSans(fontSize: 11, color: const Color(0xFF9CA3AF)),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          alert['message'] ?? '',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 13.5,
                            height: 1.45,
                            color: const Color(0xFF374151),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
