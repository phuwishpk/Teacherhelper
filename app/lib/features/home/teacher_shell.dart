import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'dashboard_page.dart';
import 'placeholder_page.dart';

/// Teacher-side navigation shell: a bottom NavigationBar on phones and a
/// NavigationRail from tablet width up (the review queue is meant for
/// tablets, DESIGN §13). Only the dashboard has content in M0; the other
/// destinations are empty states until Phase 2 adds real data.
class TeacherShell extends ConsumerStatefulWidget {
  const TeacherShell({super.key});

  @override
  ConsumerState<TeacherShell> createState() => _TeacherShellState();
}

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination('หน้าหลัก', Icons.home_outlined, Icons.home),
  _Destination('ห้องเรียน', Icons.groups_outlined, Icons.groups),
  _Destination('การบ้าน', Icons.assignment_outlined, Icons.assignment),
  _Destination('ตรวจทาน', Icons.rate_review_outlined, Icons.rate_review),
];

/// Material 3 "expanded" breakpoint: rail instead of bottom bar.
const _railBreakpoint = 840.0;

class _TeacherShellState extends ConsumerState<TeacherShell> {
  int _index = 0;

  void _select(int index) => setState(() => _index = index);

  @override
  Widget build(BuildContext context) {
    final user = switch (ref.watch(sessionProvider)) {
      SignedIn(:final user) => user,
      _ => null,
    };
    final wide = MediaQuery.sizeOf(context).width >= _railBreakpoint;

    final pages = <Widget>[
      DashboardPage(user: user, onNavigate: _select),
      const PlaceholderPage(
        icon: Icons.groups_outlined,
        title: 'ยังไม่มีห้องเรียน',
        message:
            'สร้างห้องเรียน เพิ่มรายชื่อนักเรียน แล้วพิมพ์บัตร QR สำหรับเข้าสู่ระบบ',
        actionLabel: 'สร้างห้องเรียน',
      ),
      const PlaceholderPage(
        icon: Icons.assignment_outlined,
        title: 'ยังไม่มีการบ้าน',
        message: 'สร้างการบ้าน เลือกตัวชี้วัด แล้วพิมพ์ใบงานแยกรายนักเรียน',
        actionLabel: 'สร้างการบ้าน',
      ),
      const PlaceholderPage(
        icon: Icons.rate_review_outlined,
        title: 'ไม่มีงานรอตรวจทาน',
        message:
            'เมื่อสแกนใบงานแล้ว ระบบจะตรวจให้ก่อนและเรียงข้อที่ควรดูไว้ที่นี่ '
            'นักเรียนจะเห็นผลหลังคุณกดเผยแพร่',
      ),
    ];
    final body = IndexedStack(index: _index, children: pages);

    final scanButton = wide
        ? FloatingActionButton(
            tooltip: 'สแกนใบงาน',
            onPressed: () => showNotYet(context, 'สแกนใบงาน'),
            child: const Icon(Icons.document_scanner_outlined),
          )
        : FloatingActionButton.extended(
            onPressed: () => showNotYet(context, 'สแกนใบงาน'),
            icon: const Icon(Icons.document_scanner_outlined),
            label: const Text('สแกนใบงาน'),
          );

    return Scaffold(
      appBar: AppBar(
        title: const Text('EduVision'),
        actions: [
          IconButton(
            tooltip: 'ออกจากระบบ',
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(sessionProvider.notifier).signOut(),
          ),
        ],
      ),
      body: wide
          ? Row(
              children: [
                NavigationRail(
                  selectedIndex: _index,
                  onDestinationSelected: _select,
                  labelType: NavigationRailLabelType.all,
                  leading: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: scanButton,
                  ),
                  destinations: [
                    for (final d in _destinations)
                      NavigationRailDestination(
                        icon: Icon(d.icon),
                        selectedIcon: Icon(d.selectedIcon),
                        label: Text(d.label),
                      ),
                  ],
                ),
                const VerticalDivider(width: 1),
                Expanded(child: body),
              ],
            )
          : body,
      bottomNavigationBar: wide
          ? null
          : NavigationBar(
              selectedIndex: _index,
              onDestinationSelected: _select,
              destinations: [
                for (final d in _destinations)
                  NavigationDestination(
                    icon: Icon(d.icon),
                    selectedIcon: Icon(d.selectedIcon),
                    label: d.label,
                  ),
              ],
            ),
      floatingActionButton: wide || _index != 0 ? null : scanButton,
    );
  }
}
