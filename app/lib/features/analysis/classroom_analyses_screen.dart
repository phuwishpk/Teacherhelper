import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'analysis_models.dart';
import 'analysis_repository.dart';
import 'analysis_widgets.dart';

/// `/classrooms/:id/analyses`: every student's AI analysis status in one
/// classroom and the "แชร์ให้นักเรียนอัตโนมัติ" switch (DESIGN §20.5).
class ClassroomAnalysesScreen extends ConsumerWidget {
  const ClassroomAnalysesScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider = classroomAnalysesProvider(classroomId);
    final value = ref.watch(provider);
    final room = ref.watch(classroomProvider(classroomId)).value;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          room == null ? 'วิเคราะห์รายคน' : 'วิเคราะห์รายคน · ${room.name}',
        ),
      ),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(provider),
        data: (data) => RefreshIndicator(
          onRefresh: () => ref.refresh(provider.future),
          child: ContentColumn(
            child: ListView(
              children: [
                _AutoShareCard(classroomId: classroomId, data: data),
                const SizedBox(height: 8),
                if (data.awaitingCount > 0)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 0, 4, 8),
                    child: Text(
                      'รออนุมัติ ${data.awaitingCount} คน นักเรียนยังไม่เห็นข้อความใหม่จนกว่าครูจะอนุมัติ',
                      key: const ValueKey('awaiting_count'),
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ),
                if (data.students.isEmpty)
                  const EmptyView(
                    icon: Icons.group_outlined,
                    title: 'ยังไม่มีนักเรียน',
                    message: 'เพิ่มนักเรียนในห้องก่อน',
                  )
                else
                  Card(
                    child: Column(
                      children: [
                        for (final row in data.students)
                          _StudentRow(classroomId: classroomId, row: row),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _AutoShareCard extends ConsumerStatefulWidget {
  const _AutoShareCard({required this.classroomId, required this.data});

  final int classroomId;
  final ClassroomAnalyses data;

  @override
  ConsumerState<_AutoShareCard> createState() => _AutoShareCardState();
}

class _AutoShareCardState extends ConsumerState<_AutoShareCard> {
  bool _busy = false;

  Future<void> _set(bool value) async {
    setState(() => _busy = true);
    try {
      await ref
          .read(classroomAnalysesProvider(widget.classroomId).notifier)
          .setAutoShare(value);
      if (mounted) {
        showMessage(
          context,
          value
              ? 'เปิดแชร์อัตโนมัติแล้ว ข้อความที่ AI เขียนครั้งต่อไปจะถึงนักเรียนทันที'
              : 'ปิดแชร์อัตโนมัติแล้ว ข้อความใหม่จะรอครูอนุมัติ',
        );
      }
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SwitchListTile(
            key: const ValueKey('auto_share_switch'),
            value: widget.data.autoShare,
            onChanged: _busy ? null : _set,
            title: const Text('แชร์ให้นักเรียนอัตโนมัติ'),
            subtitle: const Text(
              'เปิดแล้วนักเรียนเห็นข้อความใหม่ทันทีโดยไม่ต้องรออนุมัติ '
              'ข้อความที่ร่างไว้ก่อนเปิดยังต้องอนุมัติเอง',
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Text(
              'จุดเด่นและจุดที่ควรพัฒนาคำนวณจากคะแนนทันทีหลังเผยแพร่ผล '
              'ส่วนข้อความจาก AI เขียนรอบกลางคืน (หลังตี 1) เฉพาะคนที่คะแนนเปลี่ยน',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StudentRow extends StatelessWidget {
  const _StudentRow({required this.classroomId, required this.row});

  final int classroomId;
  final ClassroomAnalysisRow row;

  @override
  Widget build(BuildContext context) {
    final a = row.analysis;
    final areas = a?.areas ?? const <AnalysisItem>[];
    final lines = [
      if (areas.isNotEmpty)
        'ควรพัฒนา: ${areas.map((i) => i.skill.code).join(', ')}',
      if (a?.stale ?? false) 'คะแนนเปลี่ยนหลังเขียนข้อความ',
    ];
    return ListTile(
      key: ValueKey('analysis_student_${row.studentId}'),
      leading: CircleAvatar(radius: 18, child: Text('${row.studentNumber}')),
      title: Text(row.name),
      subtitle: lines.isEmpty
          ? null
          : Text(
              lines.join('\n'),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
      trailing: AnalysisBadge(analysis: a),
      onTap: () =>
          context.push(AppRoutes.studentAnalysis(classroomId, row.studentId)),
    );
  }
}
