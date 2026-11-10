import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/cupertino.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import 'main_wrapper.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _phoneFocusNode = FocusNode();
  final _passwordFocusNode = FocusNode();

  bool _isLoading = false;
  bool _obscurePassword = true;
  bool _rememberMe = true;
  String? _errorMessage;

  // Pediatric Clinical Emerald Palette
  static const Color _primaryEmerald = Color(0xFF0E7052); // VaxiCare Brand Emerald Green
  static const Color _primaryDark = Color(0xFF094E38);
  static const Color _fieldBg = Color(0xFFE8F3EE); // Soft healthcare sage/mint pill
  static const Color _subtleText = Color(0xFF7A8E83); // Muted clinical text
  static const Color _darkText = Color(0xFF0F172A);

  @override
  void initState() {
    super.initState();
    _loadSavedCredentials();
  }

  Future<void> _loadSavedCredentials() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final savedPhone = prefs.getString('saved_phone');
      final remember = prefs.getBool('remember_me') ?? true;
      if (savedPhone != null && savedPhone.isNotEmpty && remember) {
        setState(() {
          _phoneController.text = savedPhone;
          _rememberMe = true;
        });
      }
    } catch (_) {}
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    _phoneFocusNode.dispose();
    _passwordFocusNode.dispose();
    super.dispose();
  }

  Future<void> _handleLogin() async {
    FocusScope.of(context).unfocus();

    String rawPhone = _phoneController.text.trim();
    final password = _passwordController.text.trim();

    if (rawPhone.isEmpty || password.isEmpty) {
      setState(() {
        _errorMessage = 'Please enter both your phone number and password.';
      });
      return;
    }

    // Clean phone input (remove spaces, hyphens)
    String cleanPhone = rawPhone.replaceAll(RegExp(r'[\s\-]'), '');

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final response = await http.post(
        Uri.parse('$API_BASE_URL/auth.php'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'phone': cleanPhone, 'password': password}),
      );

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['status'] == 'success') {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setInt('user_id', data['user']['id']);
        await prefs.setString('user_name', data['user']['name']);
        await prefs.setString('user_phone', data['user']['phone']);
        if (data['user']['email'] != null && data['user']['email'].toString().isNotEmpty) {
          await prefs.setString('user_email', data['user']['email']);
        }

        // Handle Remember Me preference
        if (_rememberMe) {
          await prefs.setString('saved_phone', cleanPhone);
          await prefs.setBool('remember_me', true);
        } else {
          await prefs.remove('saved_phone');
          await prefs.setBool('remember_me', false);
        }

        if (!mounted) return;
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const MainWrapper()),
        );
      } else {
        setState(() {
          _errorMessage = data['message'] ?? 'Invalid phone number or password. Please verify your credentials.';
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = 'Unable to connect to clinic server. Please check your network connection.';
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showHelpInfo() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        backgroundColor: Colors.white,
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: const BoxDecoration(
                color: Color(0xFFE8F8F2),
                shape: BoxShape.circle,
              ),
              child: const Icon(CupertinoIcons.question_circle_fill, color: _primaryEmerald, size: 22),
            ),
            const SizedBox(width: 12),
            Text(
              'Password Help',
              style: GoogleFonts.plusJakartaSans(
                fontWeight: FontWeight.bold,
                fontSize: 17,
                color: _darkText,
              ),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'To protect your child\'s confidential health records, passwords are assigned during infant registration at the clinic.',
              style: GoogleFonts.plusJakartaSans(fontSize: 13.5, height: 1.5, color: const Color(0xFF4B5563)),
            ),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFE8F8F2),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: const Color(0xFFD8F3E5)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(CupertinoIcons.phone_fill, size: 15, color: _primaryEmerald),
                      const SizedBox(width: 8),
                      Text(
                        'Kansenshi Desk: 0971725900',
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: _primaryEmerald,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Default initial password is password123 (or the PIN provided by your Nurse).',
                    style: GoogleFonts.plusJakartaSans(fontSize: 12, color: const Color(0xFF374151)),
                  ),
                ],
              ),
            ),
          ],
        ),
        actions: [
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: _primaryEmerald,
              foregroundColor: Colors.white,
              elevation: 0,
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
    final media = MediaQuery.of(context);
    final screenHeight = media.size.height;
    final headerHeight = screenHeight * 0.38;

    return GestureDetector(
      onTap: () => FocusScope.of(context).unfocus(),
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SingleChildScrollView(
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // 1. Pediatric Vaccination Header Banner with Wave Clipper
              SizedBox(
                height: headerHeight,
                width: double.infinity,
                child: Stack(
                  children: [
                    // Nurse & Infant Vaccination Photo with Organic Curved Wave
                    ClipPath(
                      clipper: PediatricWaveClipper(),
                      child: Container(
                        height: headerHeight,
                        width: double.infinity,
                        decoration: const BoxDecoration(
                          color: Color(0xFF0A3324),
                        ),
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            Image.asset(
                              'assets/images/login_pediatric_bg.jpg',
                              fit: BoxFit.cover,
                              alignment: Alignment.topCenter,
                              errorBuilder: (ctx, err, stack) {
                                // Fallback to hero banner or gradient
                                return Image.asset(
                                  'assets/images/child_vaccine_banner.jpg',
                                  fit: BoxFit.cover,
                                  alignment: Alignment.topCenter,
                                  errorBuilder: (c, e, s) => Container(
                                    decoration: const BoxDecoration(
                                      gradient: LinearGradient(
                                        begin: Alignment.topRight,
                                        end: Alignment.bottomLeft,
                                        colors: [Color(0xFF064E3B), Color(0xFF0E7052)],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                            // Ambient top & bottom gradient for crisp text and contrast
                            Container(
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  begin: Alignment.topCenter,
                                  end: Alignment.bottomCenter,
                                  colors: [
                                    Colors.black.withOpacity(0.40),
                                    Colors.transparent,
                                    Colors.black.withOpacity(0.20),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    // Top Glassmorphism VaxiCare Branding Pill
                    SafeArea(
                      bottom: false,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                        child: Align(
                          alignment: Alignment.topLeft,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                            decoration: BoxDecoration(
                              color: Colors.white.withOpacity(0.92),
                              borderRadius: BorderRadius.circular(24),
                              border: Border.all(color: Colors.white.withOpacity(0.8), width: 1.2),
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.12),
                                  blurRadius: 12,
                                  offset: const Offset(0, 4),
                                ),
                              ],
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Container(
                                  width: 24,
                                  height: 24,
                                  decoration: const BoxDecoration(
                                    color: _primaryEmerald,
                                    shape: BoxShape.circle,
                                  ),
                                  child: const Center(
                                    child: Icon(
                                      CupertinoIcons.shield_fill,
                                      color: Colors.white,
                                      size: 13,
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                RichText(
                                  text: TextSpan(
                                    children: [
                                      TextSpan(
                                        text: 'Vaxi',
                                        style: GoogleFonts.plusJakartaSans(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 14,
                                          color: const Color(0xFF0F172A),
                                          letterSpacing: -0.3,
                                        ),
                                      ),
                                      TextSpan(
                                        text: 'Care.',
                                        style: GoogleFonts.plusJakartaSans(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 14,
                                          color: _primaryEmerald,
                                          letterSpacing: -0.3,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              // 2. White Card Content Area
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    const SizedBox(height: 6),

                    // VaxiCare Pill Tagline
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE8F8F2),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: const Color(0xFFD8F3E5)),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(CupertinoIcons.heart_fill, size: 12, color: _primaryEmerald),
                          const SizedBox(width: 6),
                          Text(
                            'VaxiCare Pediatric Immunization',
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              color: _primaryEmerald,
                              letterSpacing: 0.2,
                            ),
                          ),
                        ],
                      ),
                    ),

                    const SizedBox(height: 12),

                    // Header Title
                    Text(
                      'Welcome Back',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 30,
                        fontWeight: FontWeight.w800,
                        color: _primaryDark,
                        letterSpacing: -0.6,
                      ),
                    ),

                    const SizedBox(height: 6),

                    // Subtitle
                    Text(
                      'Login to your guardian account',
                      style: GoogleFonts.plusJakartaSans(
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                        color: _subtleText,
                        letterSpacing: 0.1,
                      ),
                    ),

                    const SizedBox(height: 24),

                    // Error Alert Banner (if any)
                    if (_errorMessage != null) ...[
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                        margin: const EdgeInsets.only(bottom: 20),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFDF2F2),
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(color: const Color(0xFFF8B4B4)),
                        ),
                        child: Row(
                          children: [
                            const Icon(
                              CupertinoIcons.exclamationmark_circle_fill,
                              color: Color(0xFFE02424),
                              size: 18,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                _errorMessage!,
                                style: GoogleFonts.plusJakartaSans(
                                  color: const Color(0xFF9B1C1C),
                                  fontSize: 12.5,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],

                    // Input Field 1: Phone Number (VaxiCare Account Login Identifier)
                    Container(
                      height: 54,
                      decoration: BoxDecoration(
                        color: _fieldBg,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: TextField(
                        controller: _phoneController,
                        focusNode: _phoneFocusNode,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.next,
                        onSubmitted: (_) => FocusScope.of(context).requestFocus(_passwordFocusNode),
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: _darkText,
                        ),
                        decoration: InputDecoration(
                          hintText: 'Phone Number (e.g. 097...)',
                          hintStyle: GoogleFonts.plusJakartaSans(
                            color: _subtleText,
                            fontSize: 14,
                            fontWeight: FontWeight.w500,
                          ),
                          prefixIcon: const Padding(
                            padding: EdgeInsets.symmetric(horizontal: 14),
                            child: Icon(
                              Icons.person_rounded,
                              color: _primaryEmerald,
                              size: 20,
                            ),
                          ),
                          prefixIconConstraints: const BoxConstraints(minWidth: 48),
                          border: InputBorder.none,
                          contentPadding: const EdgeInsets.symmetric(vertical: 16),
                        ),
                      ),
                    ),

                    const SizedBox(height: 16),

                    // Input Field 2: Password
                    Container(
                      height: 54,
                      decoration: BoxDecoration(
                        color: _fieldBg,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: TextField(
                        controller: _passwordController,
                        focusNode: _passwordFocusNode,
                        obscureText: _obscurePassword,
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => _handleLogin(),
                        style: GoogleFonts.plusJakartaSans(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w600,
                          color: _darkText,
                        ),
                        decoration: InputDecoration(
                          hintText: '●●●●●●●●',
                          hintStyle: GoogleFonts.plusJakartaSans(
                            color: _subtleText,
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 2.0,
                          ),
                          prefixIcon: const Padding(
                            padding: EdgeInsets.symmetric(horizontal: 14),
                            child: Icon(
                              CupertinoIcons.lock_fill,
                              color: _primaryEmerald,
                              size: 20,
                            ),
                          ),
                          prefixIconConstraints: const BoxConstraints(minWidth: 48),
                          suffixIcon: IconButton(
                            icon: Icon(
                              _obscurePassword ? CupertinoIcons.eye_fill : CupertinoIcons.eye_slash_fill,
                              color: _primaryEmerald,
                              size: 19,
                            ),
                            onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                          ),
                          border: InputBorder.none,
                          contentPadding: const EdgeInsets.symmetric(vertical: 16),
                        ),
                      ),
                    ),

                    const SizedBox(height: 16),

                    // Remember Me & Forgot Password Row
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        // Remember Me Checkbox
                        GestureDetector(
                          onTap: () => setState(() => _rememberMe = !_rememberMe),
                          behavior: HitTestBehavior.opaque,
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                _rememberMe ? Icons.check_circle_rounded : Icons.radio_button_unchecked_rounded,
                                size: 19,
                                color: _rememberMe ? _primaryEmerald : _subtleText,
                              ),
                              const SizedBox(width: 7),
                              Text(
                                'Remember Me',
                                style: GoogleFonts.plusJakartaSans(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w500,
                                  color: _subtleText,
                                ),
                              ),
                            ],
                          ),
                        ),

                        // Forgot Password Link
                        GestureDetector(
                          onTap: _showHelpInfo,
                          behavior: HitTestBehavior.opaque,
                          child: Text(
                            'Forgot Password ?',
                            style: GoogleFonts.plusJakartaSans(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              color: _primaryEmerald,
                            ),
                          ),
                        ),
                      ],
                    ),

                    const SizedBox(height: 30),

                    // 3. Login Button (Solid Emerald Pill)
                    SizedBox(
                      width: double.infinity,
                      height: 54,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: _primaryEmerald,
                          foregroundColor: Colors.white,
                          elevation: 3,
                          shadowColor: _primaryEmerald.withOpacity(0.35),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(28),
                          ),
                        ),
                        onPressed: _isLoading ? null : _handleLogin,
                        child: _isLoading
                            ? const SizedBox(
                                width: 22,
                                height: 22,
                                child: CircularProgressIndicator(
                                  color: Colors.white,
                                  strokeWidth: 2.4,
                                ),
                              )
                            : Text(
                                'Login',
                                style: GoogleFonts.plusJakartaSans(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  letterSpacing: 0.3,
                                ),
                              ),
                      ),
                    ),

                    const SizedBox(height: 28),

                    // 4. Subtle Clinical Footer (No Sign Up as requested)
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(
                          CupertinoIcons.shield_fill,
                          size: 13,
                          color: _subtleText,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          'Kansenshi Pediatric Registry • Under-5 Records',
                          style: GoogleFonts.plusJakartaSans(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w500,
                            color: _subtleText,
                          ),
                        ),
                      ],
                    ),

                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Custom Wave Clipper creating the organic curved slope matching the design
class PediatricWaveClipper extends CustomClipper<Path> {
  @override
  Path getClip(Size size) {
    final path = Path();
    // Starts on the left edge around 88% of header height
    path.lineTo(0, size.height * 0.88);

    // First gentle dip towards center
    final cp1 = Offset(size.width * 0.35, size.height * 1.0);
    final ep1 = Offset(size.width * 0.62, size.height * 0.86);
    path.quadraticBezierTo(cp1.dx, cp1.dy, ep1.dx, ep1.dy);

    // Second smooth curve sweeping upward to the right edge
    final cp2 = Offset(size.width * 0.84, size.height * 0.73);
    final ep2 = Offset(size.width, size.height * 0.77);
    path.quadraticBezierTo(cp2.dx, cp2.dy, ep2.dx, ep2.dy);

    path.lineTo(size.width, 0);
    path.close();
    return path;
  }

  @override
  bool shouldReclip(CustomClipper<Path> oldClipper) => false;
}
