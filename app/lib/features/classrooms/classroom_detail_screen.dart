import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../scan/offline_cache_repository.dart';
import '../worksheets/print_flow.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';
import 'classrooms_repository.dart';

class ClassroomDetailScreen extends ConsumerWidget {
  const ClassroomDetailScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classroom = ref.watch(classroomProvider(classroomId));
    final roster = ref.watch(rosterProvider(classroomId));

    return Scaffold(
      appBar: AppBar(
        title: Text(classroom.value?.name ?? 'ห้องเรียน'),
        actions: [
          if (classroom.value case final c?)
            IconButton(
              tooltip: 'แก้ไข',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () =>
                  context.push(AppRoutes.classroomEdit(c.id), extra: c),
            ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'students_add',
        onPressed: () => context.push(AppRoutes.studentsAdd(classroomId)),
        icon: const Icon(Icons.group_add_outlined),
        label: const Text('เพิ่มนักเรียน'),
      ),
      body: AsyncView(
        value: classroom,
        onRetry: () => ref.invalidate(classroomProvider(classroomId)),
        data: (c) => RefreshIndicator(
          onRefresh: () =>
              ref.read(rosterProvider(classroomId).notifier).refresh(),
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
            child: ListView(
              children: [
                _HeaderCard(classroom: c),
                const SizedBox(height: 12),
                _ActionsRow(classroom: c),
                const SizedBox(height: 16),
                Text(
                  'รายชื่อนักเรียน',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                AsyncView(
                  value: roster,
                  onRetry: () =>
                      ref.read(rosterProvider(classroomId).notifier).refresh(),
                  data: (students) {
                    if (students.isEmpty) {
                      return const Card(
                        child: Padding(
                          padding: EdgeInsets.all(24),
                          child: Text(
                            'ยังไม่มีนักเรียน กด "เพิ่มนักเรียน" แล้ววางรายชื่อจากไฟล์ของโรงเรียน',
                            textAlign: TextAlign.center,
                          ),
                        ),
                      );
                    }
                    return Card(
                      clipBehavior: Clip.antiAlias,
                      child: Column(
                        children: [
                          for (final s in students) ...[
                            _StudentTile(student: s),
                            if (s != students.last) const Divider(height: 1),
                          ],
                        ],
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({required this.classroom});

  final Classroom classroom;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Wrap(
          spacing: 24,
          runSpacing: 8,
          children: [
            _Fact(
              label: 'ระดับชั้น',
              value: gradeLevelLabel(classroom.gradeLevel),
            ),
            _Fact(label: 'ปีการศึกษา', value: '${classroom.academicYear}'),
            _Fact(
              label: 'รหัสห้อง (ใช้ login ด้วย PIN)',
              value: classroom.classCode,
              valueStyle: theme.textTheme.titleLarge?.copyWith(
                fontFamily: 'monospace',
                letterSpacing: 2,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value, this.valueStyle});

  final String label;
  final String value;
  final TextStyle? valueStyle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: theme.textTheme.labelMedium),
        Text(value, style: valueStyle ?? theme.textTheme.titleMedium),
      ],
    );
  }
}

class _ActionsRow extends ConsumerWidget {
  const _ActionsRow({required this.classroom});

  final Classroom classroom;

  Future<void> _printCards(BuildContext context, WidgetRef ref) {
    final repo = ref.read(classroomsRepositoryProvider);
    return runPrintFlow(
      context,
      ref,
      title: 'บัตร QR ${classroom.name}',
      fileName: 'login-cards-${classroom.id}.pdf',
      request: () => repo.requestLoginCards(classroom.id),
      poll: repo.loginCardPrint,
    );
  }

  Future<void> _prepareOffline(BuildContext context, WidgetRef ref) async {
    showMessage(context, 'กำลังดาวน์โหลด layout และรายชื่อ…');
    try {
      final summary = await ref
          .read(offlineCacheRepositoryProvider)
          .prepareClassroom(classroom.id);
      if (!context.mounted) return;
      showMessage(
        context,
        'พร้อมสแกนออฟไลน์: นักเรียน ${summary.students} คน, '
        'การบ้าน ${summary.assignments} ชุด, layout ${summary.layoutPages} หน้า',
      );
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        FilledButton.tonalIcon(
          onPressed: () => _printCards(context, ref),
          icon: const Icon(Icons.qr_code_2),
          label: const Text('พิมพ์บัตร QR'),
        ),
        FilledButton.tonalIcon(
          onPressed: () => _prepareOffline(context, ref),
          icon: const Icon(Icons.download_for_offline_outlined),
          label: const Text('เตรียมสแกนออฟไลน์'),
        ),
        OutlinedButton.icon(
          onPressed: () => context.push(
            '${AppRoutes.assignmentNew}?classroom=${classroom.id}',
          ),
          icon: const Icon(Icons.assignment_add),
          label: const Text('สร้างการบ้าน'),
        ),
      ],
    );
  }
}

class _StudentTile extends ConsumerWidget {
  const _StudentTile({required this.student});

  final RosterStudent student;

  Future<void> _reissueCard(BuildContext context, WidgetRef ref) async {
    final ok = await confirm(
      context,
      title: 'ออกบัตรใหม่ให้ ${student.name}?',
      message:
          'บัตร QR เดิมจะใช้เข้าสู่ระบบไม่ได้อีก และนักเรียนจะถูกออกจากระบบทุกเครื่อง',
      confirmLabel: 'ออกบัตรใหม่',
    );
    if (!ok || !context.mounted) return;
    final repo = ref.read(classroomsRepositoryProvider);
    await runPrintFlow(
      context,
      ref,
      title: 'บัตร QR ${student.name}',
      fileName: 'login-card-${student.studentId}.pdf',
      request: () => repo.reissueLoginCard(student.studentId),
      poll: repo.loginCardPrint,
    );
  }

  Future<void> _resetPin(BuildContext context, WidgetRef ref) async {
    final ok = await confirm(
      context,
      title: 'รีเซ็ต PIN ของ ${student.name}?',
      message:
          'PIN เดิมจะใช้ไม่ได้ และนักเรียนจะถูกออกจากระบบทุกเครื่อง PIN ใหม่จะแสดงครั้งเดียว',
      confirmLabel: 'รีเซ็ต PIN',
    );
    if (!ok || !context.mounted) return;
    try {
      final reset = await ref
          .read(classroomsRepositoryProvider)
          .resetPin(student.studentId);
      if (!context.mounted) return;
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('PIN ใหม่ของ ${student.name}'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SelectableText(
                reset.pin,
                style: Theme.of(context).textTheme.displaySmall?.copyWith(
                  fontFamily: 'monospace',
                  letterSpacing: 6,
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'จดไว้ให้นักเรียนตอนนี้ ระบบจะไม่แสดง PIN นี้อีก',
                textAlign: TextAlign.center,
              ),
            ],
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('จดแล้ว'),
            ),
          ],
        ),
      );
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListTile(
      leading: CircleAvatar(
        radius: 18,
        child: Text('${student.studentNumber}'),
      ),
      title: Text(student.name),
      trailing: PopupMenuButton<String>(
        tooltip: 'ตัวเลือก',
        onSelected: (v) => switch (v) {
          'card' => _reissueCard(context, ref),
          'pin' => _resetPin(context, ref),
          _ => null,
        },
        itemBuilder: (context) => const [
          PopupMenuItem(
            value: 'card',
            child: ListTile(
              leading: Icon(Icons.qr_code_2),
              title: Text('ออกบัตร QR ใหม่'),
            ),
          ),
          PopupMenuItem(
            value: 'pin',
            child: ListTile(
              leading: Icon(Icons.password),
              title: Text('รีเซ็ต PIN'),
            ),
          ),
        ],
      ),
    );
  }
}
