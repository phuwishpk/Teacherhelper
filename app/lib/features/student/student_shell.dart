import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../../core/widgets/async_view.dart';
import 'results_page.dart';

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination(
    'ผลการบ้าน',
    Icons.assignment_turned_in_outlined,
    Icons.assignment_turned_in,
  ),
  _Destination('แบบฝึก', Icons.fitness_center_outlined, Icons.fitness_center),
  _Destination('ทักษะ', Icons.insights_outlined, Icons.insights),
];

const _railBreakpoint = 840.0;

/// Student-side shell: results, practice (Phase 6) and mastery (Phase 6).
class StudentShell extends ConsumerStatefulWidget {
  const StudentShell({super.key});

  @override
  ConsumerState<StudentShell> createState() => _StudentShellState();
}

class _StudentShellState extends ConsumerState<StudentShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final wide = MediaQuery.sizeOf(context).width >= _railBreakpoint;

    final pages = <Widget>[
      const ResultsPage(),
      const EmptyView(
        icon: Icons.fitness_center_outlined,
        title: 'แบบฝึกยังไม่เปิดใช้',
        message:
            'เมื่อครูอนุมัติคลังแบบฝึกแล้ว ระบบจะแนะนำข้อฝึกตามทักษะที่ควรทบทวน',
      ),
      const EmptyView(
        icon: Icons.insights_outlined,
        title: 'ยังไม่มีข้อมูลทักษะ',
        message: 'ความก้าวหน้าของแต่ละทักษะจะแสดงหลังมีผลการบ้านที่เผยแพร่แล้ว',
      ),
    ];
    final body = IndexedStack(index: _index, children: pages);

    return Scaffold(
      appBar: AppBar(
        title: Text(user == null ? 'EduVision' : 'สวัสดี ${user.name}'),
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
                  onDestinationSelected: (i) => setState(() => _index = i),
                  labelType: NavigationRailLabelType.all,
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
              onDestinationSelected: (i) => setState(() => _index = i),
              destinations: [
                for (final d in _destinations)
                  NavigationDestination(
                    icon: Icon(d.icon),
                    selectedIcon: Icon(d.selectedIcon),
                    label: d.label,
                  ),
              ],
            ),
    );
  }
}
