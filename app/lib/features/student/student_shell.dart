import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../auth/sign_out_action.dart';
import '../gradebook/student_grades.dart';
import '../hand_in/student_assignments_page.dart';
import '../mastery/mastery_page.dart';
import '../practice/practice_page.dart';
import '../results/results_page.dart';

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination('ส่งงาน', Icons.upload_file_outlined, Icons.upload_file),
  _Destination(
    'ผลการบ้าน',
    Icons.assignment_turned_in_outlined,
    Icons.assignment_turned_in,
  ),
  _Destination('แบบฝึก', Icons.fitness_center_outlined, Icons.fitness_center),
  _Destination('ทักษะ', Icons.insights_outlined, Icons.insights),
  _Destination('เกรด', Icons.school_outlined, Icons.school),
];

/// The index of "เกรด" (DESIGN §23.9 "เกรดของฉัน").
const _gradesIndex = 4;

const _railBreakpoint = 840.0;

/// Student-side shell: work to hand in (DESIGN §19.6), published results,
/// practice by weak skill, mastery per skill (§9.7, §14.1, §14.2) and the
/// published grades of each course (§23.9).
class StudentShell extends ConsumerStatefulWidget {
  const StudentShell({super.key});

  @override
  ConsumerState<StudentShell> createState() => _StudentShellState();
}

class _StudentShellState extends ConsumerState<StudentShell> {
  int _index = 0;

  /// "เกรด" loads only once opened: most visits never look at grades.
  bool _gradesOpened = false;

  void _select(int i) => setState(() {
    _index = i;
    if (i == _gradesIndex) _gradesOpened = true;
  });

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final wide = MediaQuery.sizeOf(context).width >= _railBreakpoint;

    final pages = <Widget>[
      const StudentAssignmentsPage(),
      const ResultsPage(),
      const PracticePage(),
      MasteryPage(onPractice: () => setState(() => _index = 2)),
      if (_gradesOpened) const MyGradesPage() else const SizedBox.shrink(),
    ];
    final body = IndexedStack(index: _index, children: pages);

    return Scaffold(
      appBar: AppBar(
        title: Text(user == null ? 'EduVision' : 'สวัสดี ${user.name}'),
        actions: [
          IconButton(
            tooltip: 'ออกจากระบบ',
            icon: const Icon(Icons.logout),
            onPressed: () => confirmSignOut(context, ref),
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
    );
  }
}
