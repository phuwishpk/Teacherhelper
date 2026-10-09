import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/adaptive_shell.dart';
import '../auth/sign_out_action.dart';
import '../hand_in/student_assignments_page.dart';
import '../mastery/mastery_page.dart';
import '../practice/practice_page.dart';
import '../results/results_page.dart';
import 'my_subjects_page.dart';

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination('วิชาของฉัน', Icons.menu_book_outlined, Icons.menu_book),
  _Destination('ส่งงาน', Icons.upload_file_outlined, Icons.upload_file),
  _Destination(
    'ผลการบ้าน',
    Icons.assignment_turned_in_outlined,
    Icons.assignment_turned_in,
  ),
  _Destination('แบบฝึก', Icons.fitness_center_outlined, Icons.fitness_center),
  _Destination('ทักษะ', Icons.insights_outlined, Icons.insights),
];

/// The index of "แบบฝึก".
const _practiceIndex = 3;

/// Student-side shell: "วิชาของฉัน" first, one card per course of every
/// classroom with its grade (DESIGN §24.11, §24.13; every grade under
/// "เกรดทั้งหมด", §23.9), then work to hand in (§19.6) and published results
/// of every classroom grouped by subject, practice by weak skill and
/// mastery per skill (§9.7, §14.1, §14.2).
class StudentShell extends ConsumerStatefulWidget {
  const StudentShell({super.key});

  @override
  ConsumerState<StudentShell> createState() => _StudentShellState();
}

class _StudentShellState extends ConsumerState<StudentShell> {
  int _index = 0;

  void _select(int i) => setState(() => _index = i);

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);

    final pages = <Widget>[
      const MySubjectsPage(),
      const StudentAssignmentsPage(),
      const ResultsPage(),
      const PracticePage(),
      MasteryPage(onPractice: () => setState(() => _index = _practiceIndex)),
    ];

    return AdaptiveShell(
      title: user == null ? 'Krucheck' : 'สวัสดี ${user.name}',
      accountName: user?.name,
      accountCaption: user?.schoolName,
      destinations: [
        for (final d in _destinations)
          ShellDestination(d.label, d.icon, d.selectedIcon),
      ],
      selectedIndex: _index,
      onSelected: _select,
      actions: [
        ShellAction(
          key: const ValueKey('student_account_button'),
          tooltip: 'บัญชีของฉัน',
          icon: Icons.account_circle_outlined,
          onPressed: () => context.push(AppRoutes.studentAccount),
        ),
        ShellAction(
          tooltip: 'ออกจากระบบ',
          icon: Icons.logout,
          onPressed: () => confirmSignOut(context, ref),
        ),
      ],
      body: IndexedStack(index: _index, children: pages),
    );
  }
}
