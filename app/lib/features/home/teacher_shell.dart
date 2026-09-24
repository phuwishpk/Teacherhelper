import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../assignments/assignments_page.dart';
import '../classrooms/classrooms_page.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'dashboard_page.dart';

/// Teacher-side navigation shell: a bottom NavigationBar on phones and a
/// NavigationRail from tablet width up (the review queue is meant for
/// tablets, DESIGN §13). The review tab stays an empty state until Phase 4.
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
    final user = ref.watch(currentUserProvider);
    final wide = MediaQuery.sizeOf(context).width >= _railBreakpoint;
    final queueOpen = ref.watch(uploadQueueOpenCountProvider);

    final pages = <Widget>[
      DashboardPage(user: user, onNavigate: _select),
      const ClassroomsPage(),
      const AssignmentsPage(),
      const EmptyView(
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
            heroTag: 'scan_fab',
            tooltip: 'สแกนใบงาน',
            onPressed: () => context.push(AppRoutes.scan),
            child: const Icon(Icons.document_scanner_outlined),
          )
        : FloatingActionButton.extended(
            heroTag: 'scan_fab',
            onPressed: () => context.push(AppRoutes.scan),
            icon: const Icon(Icons.document_scanner_outlined),
            label: const Text('สแกนใบงาน'),
          );

    return Scaffold(
      appBar: AppBar(
        title: const Text('EduVision'),
        actions: [
          IconButton(
            tooltip: 'คิวอัปโหลด',
            icon: Badge.count(
              count: queueOpen,
              isLabelVisible: queueOpen > 0,
              child: const Icon(Icons.cloud_upload_outlined),
            ),
            onPressed: () => context.push(AppRoutes.uploadQueue),
          ),
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
