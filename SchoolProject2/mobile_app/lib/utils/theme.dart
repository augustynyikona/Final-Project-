import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppTheme {
  // Canonical VaxiCare Guardian Web Design System Tokens
  static const Color primaryBlue = Color(0xFF4318FF); // --gd-primary
  static const Color primaryDark = Color(0xFF3311DB); // --gd-primary-hover
  static const Color primarySoft = Color(0xFFEEF2FF); // --gd-primary-soft
  
  static const Color secondaryCyan = Color(0xFF00B5D8); // --gd-secondary
  static const Color secondarySoft = Color(0xFFE0F7FA);
  
  static const Color successMint = Color(0xFF05CD99); // --gd-success
  static const Color successSoft = Color(0xFFE6F8F3);
  
  static const Color warningAmber = Color(0xFFFFB547); // --gd-warning
  static const Color warningSoft = Color(0xFFFFF8EC);
  
  static const Color dangerRose = Color(0xFFE11D48); // --gd-danger
  static const Color dangerSoft = Color(0xFFFFE4E6);
  
  static const Color backgroundLight = Color(0xFFF8FAF9); // Clean healthcare canvas
  static const Color darkNavy = Color(0xFF111C44); // --gd-sidebar-bg
  static const Color surfaceWhite = Colors.white; // --gd-card-bg

  // Reference UI/UX Theme Tokens (from shared modern healthcare design)
  static const Color emeraldGreen = Color(0xFF0E7052); // Active buttons, selected chips
  static const Color emeraldDark = Color(0xFF0A5840);
  static const Color mintHero = Color(0xFFE8F8F2); // Hero banner background
  static const Color mintSoft = Color(0xFFD8F3E5);
  static const Color searchBarBg = Color(0xFFF3F4F6);
  
  static const Color textMain = Color(0xFF0F172A); // --gd-text-main
  static const Color textMuted = Color(0xFF64748B); // --gd-text-muted
  static const Color textLight = Color(0xFF94A3B8); // --gd-text-light
  static const Color borderLight = Color(0xFFE2E8F0); // --gd-border

  static final ThemeData lightTheme = ThemeData(
    brightness: Brightness.light,
    primaryColor: emeraldGreen,
    scaffoldBackgroundColor: backgroundLight,
    colorScheme: const ColorScheme.light(
      primary: emeraldGreen,
      secondary: emeraldDark,
      surface: surfaceWhite,
      error: dangerRose,
      onPrimary: Colors.white,
      onSecondary: Colors.white,
      onSurface: textMain,
      onError: Colors.white,
    ),
    textTheme: GoogleFonts.interTextTheme(ThemeData.light().textTheme).copyWith(
      displayLarge: GoogleFonts.spaceGrotesk(fontSize: 32, fontWeight: FontWeight.bold, color: textMain),
      displayMedium: GoogleFonts.spaceGrotesk(fontSize: 28, fontWeight: FontWeight.bold, color: textMain),
      displaySmall: GoogleFonts.spaceGrotesk(fontSize: 24, fontWeight: FontWeight.w700, color: textMain),
      headlineMedium: GoogleFonts.spaceGrotesk(fontSize: 20, fontWeight: FontWeight.w700, color: textMain),
      titleLarge: GoogleFonts.spaceGrotesk(fontSize: 18, fontWeight: FontWeight.w600, color: textMain),
      bodyLarge: GoogleFonts.inter(fontSize: 15, color: textMain, height: 1.5),
      bodyMedium: GoogleFonts.inter(fontSize: 13.5, color: textMuted, height: 1.4),
      bodySmall: GoogleFonts.inter(fontSize: 12, color: textLight),
      labelLarge: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600, color: primaryBlue),
    ),
    appBarTheme: AppBarTheme(
      backgroundColor: backgroundLight,
      elevation: 0,
      centerTitle: false,
      iconTheme: const IconThemeData(color: darkNavy),
      titleTextStyle: GoogleFonts.spaceGrotesk(
        color: darkNavy,
        fontSize: 19,
        fontWeight: FontWeight.w700,
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: primaryBlue,
        foregroundColor: Colors.white,
        elevation: 0,
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 15),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
        ),
        textStyle: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: primaryBlue,
        side: const BorderSide(color: primaryBlue, width: 1.5),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 15),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
        ),
        textStyle: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w600),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: surfaceWhite,
      contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: borderLight, width: 1.5),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: borderLight, width: 1.5),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: primaryBlue, width: 2),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: dangerRose, width: 1.5),
      ),
      labelStyle: GoogleFonts.inter(color: textMuted, fontSize: 14),
      hintStyle: GoogleFonts.inter(color: textLight, fontSize: 14),
    ),
    cardTheme: CardThemeData(
      color: surfaceWhite,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: const BorderSide(color: borderLight, width: 1.2),
      ),
      margin: EdgeInsets.zero,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      backgroundColor: surfaceWhite,
      selectedItemColor: primaryBlue,
      unselectedItemColor: textLight,
      selectedLabelStyle: TextStyle(fontWeight: FontWeight.w700, fontSize: 11),
      unselectedLabelStyle: TextStyle(fontWeight: FontWeight.w500, fontSize: 11),
      type: BottomNavigationBarType.fixed,
      elevation: 12,
    ),
  );
}
