import 'package:flutter/material.dart';
import 'dashboard_screen.dart';
import 'appointments_screen.dart';
import 'messages_screen.dart';
import 'profile_screen.dart';
import '../widgets/custom_bottom_nav_bar.dart';

class MainWrapper extends StatefulWidget {
  final int initialTab;
  const MainWrapper({super.key, this.initialTab = 0});

  @override
  State<MainWrapper> createState() => _MainWrapperState();
}

class _MainWrapperState extends State<MainWrapper> {
  late int _currentIndex;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialTab;
  }

  void _switchToTab(int index) {
    setState(() => _currentIndex = index);
  }

  @override
  Widget build(BuildContext context) {
    final screens = [
      DashboardScreen(onSwitchToAppointments: () => _switchToTab(1)),
      const AppointmentsScreen(),
      const MessagesScreen(),
      const ProfileScreen(),
    ];

    int navBarIndex = 0;
    if (_currentIndex == 1) navBarIndex = 1;
    if (_currentIndex == 2) navBarIndex = 3;
    if (_currentIndex == 3) navBarIndex = 4;

    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: screens,
      ),
      bottomNavigationBar: CustomBottomNavBar(
        currentIndex: navBarIndex,
        onTabSelected: (index) {
          if (index == 0) _switchToTab(0);
          if (index == 1) _switchToTab(1);
          if (index == 3) _switchToTab(2);
          if (index == 4) _switchToTab(3);
        },
      ),
    );
  }
}
