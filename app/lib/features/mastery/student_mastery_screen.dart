import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'mastery_models.dart';
import 'mastery_page.dart';
import 'mastery_repository.dart';
import 'mastery_widgets.dart';

/// Teacher view of one student: the three weakest skills (DESIGN §14.3
/// "จุดอ่อนรายคน") and every skill below them.
class StudentMasteryScreen extends ConsumerWidget {
  const StudentMasteryScreen({
    super.key,
    required this.classroomId,
    required this.studentId,
  });

  final int classroomId;
  final int studentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mastery = ref.watch(studentMasteryProvider(studentId));
    final roster = ref.watch(rosterProvider(classroomId)).value ?? const [];
    final student = roster.where((s) => s.studentId == studentId).firstOrNull;
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(
          student == null
              ? 'ทักษะของนักเรียน'
              : '${student.studentNumber}. ${student.name}',
        ),
        actions: [
          IconButton(
            tooltip: 'วิเคราะห์รายคน (AI)',
            icon: const Icon(Icons.auto_awesome_outlined),
            onPressed: () =>
                context.push(AppRoutes.studentAnalysis(classroomId, studentId)),
          ),
        ],
      ),
      body: AsyncView(
        value: mastery,
        onRetry: () => ref.invalidate(studentMasteryProvider(studentId)),
        data: (rows) {
          if (rows.isEmpty) {
            return const EmptyView(
              icon: Icons.insights_outlined,
              title: 'ยังไม่มีข้อมูลทักษะ',
              message: 'จะแสดงหลังเผยแพร่ผลการบ้านของนักเรียนคนนี้',
            );
          }
          final sorted = SkillMastery.weakestFirst(rows);
          final weakest = sorted
              .where(
                (m) =>
                    m.level != MasteryLevel.tooLittle &&
                    m.level != MasteryLevel.good,
              )
              .take(3)
              .toList();
          return RefreshIndicator(
            onRefresh: () =>
                ref.refresh(studentMasteryProvider(studentId).future),
            child: ContentColumn(
              child: ListView(
                children: [
                  Card(
                    color: theme.colorScheme.surfaceContainerHigh,
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'จุดอ่อน 3 อันดับ',
                            style: theme.textTheme.titleMedium,
                          ),
                          const SizedBox(height: 8),
                          if (weakest.isEmpty)
                            const Text(
                              'ยังไม่พบทักษะที่ต่ำกว่าระดับเข้าใจดี (หรือข้อมูลยังน้อย)',
                            )
                          else
                            for (final (i, m) in weakest.indexed)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: CircleAvatar(
                                  radius: 14,
                                  child: Text('${i + 1}'),
                                ),
                                title: Text('${m.skill.code} ${m.skill.name}'),
                                subtitle: Text(
                                  '${percent(m.value)} จาก ${m.nObs} ครั้ง',
                                ),
                                trailing: MasteryLevelChip(level: m.level),
                              ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  const MasteryLegend(),
                  const SizedBox(height: 12),
                  for (final m in sorted) SkillMasteryCard(mastery: m),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
