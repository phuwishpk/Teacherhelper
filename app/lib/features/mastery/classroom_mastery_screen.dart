import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../dashboard/heatmap.dart';
import 'mastery_models.dart';
import 'mastery_repository.dart';
import 'mastery_widgets.dart';

/// Student x skill mastery heatmap of a classroom (DESIGN §9.6, §14.3).
/// Tapping a student opens their weaknesses.
class ClassroomMasteryScreen extends ConsumerWidget {
  const ClassroomMasteryScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mastery = ref.watch(classroomMasteryProvider(classroomId));
    final name = ref.watch(classroomProvider(classroomId)).value?.name;
    return Scaffold(
      appBar: AppBar(
        title: Text(name == null ? 'ทักษะของห้อง' : 'ทักษะของห้อง $name'),
      ),
      body: AsyncView(
        value: mastery,
        onRetry: () => ref.invalidate(classroomMasteryProvider(classroomId)),
        data: (m) => RefreshIndicator(
          onRefresh: () =>
              ref.refresh(classroomMasteryProvider(classroomId).future),
          child: m.skills.isEmpty || m.students.isEmpty
              ? ListView(
                  children: const [
                    SizedBox(height: 48),
                    EmptyView(
                      icon: Icons.grid_on_outlined,
                      title: 'ยังไม่มีข้อมูลทักษะ',
                      message:
                          'heatmap จะแสดงหลังเผยแพร่ผลการบ้านที่ติดตัวชี้วัดไว้ '
                          'หรือหลังนักเรียนทำแบบฝึก',
                    ),
                  ],
                )
              : ContentColumn(
                  maxWidth: 1100,
                  child: ListView(
                    children: [
                      _WeakSkillsCard(mastery: m),
                      const SizedBox(height: 12),
                      const MasteryLegend(),
                      const SizedBox(height: 12),
                      MasteryHeatmap(
                        mastery: m,
                        onStudent: (s) => context.push(
                          AppRoutes.studentMastery(classroomId, s.id),
                        ),
                      ),
                      const SizedBox(height: 24),
                    ],
                  ),
                ),
        ),
      ),
    );
  }
}

/// Skills with the lowest class mean: where to reteach first.
class _WeakSkillsCard extends StatelessWidget {
  const _WeakSkillsCard({required this.mastery});

  final ClassroomMastery mastery;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ranked = [
      for (final s in mastery.skills)
        if (mastery.skillMean(s.id) case final mean?) (skill: s, mean: mean),
    ]..sort((a, b) => a.mean.compareTo(b.mean));
    final weak = ranked
        .where((e) => e.mean < MasteryLevel.goodFrom)
        .take(3)
        .toList();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('ทักษะที่ห้องนี้ควรทบทวน', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            if (weak.isEmpty)
              Text(
                ranked.isEmpty
                    ? 'ข้อมูลยังน้อย ต้องมีผลอย่างน้อย 2 ครั้งต่อทักษะ'
                    : 'ทุกทักษะเฉลี่ยอยู่ในระดับเข้าใจดี',
              )
            else
              for (final e in weak)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Icon(
                        MasteryLevel.of(e.mean, 2).icon,
                        size: 18,
                        color: MasteryLevel.of(e.mean, 2).color(context),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          '${e.skill.code} ${e.skill.name}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Text('เฉลี่ย ${percent(e.mean)}'),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

/// The heatmap itself; also used by tests.
class MasteryHeatmap extends StatelessWidget {
  const MasteryHeatmap({super.key, required this.mastery, this.onStudent});

  final ClassroomMastery mastery;
  final ValueChanged<MasteryStudent>? onStudent;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final m = mastery;
    return HeatmapGrid(
      key: const ValueKey('mastery_heatmap'),
      corner: const Text('นักเรียน'),
      cellWidth: 68,
      rows: [
        for (final s in m.students)
          Text(
            s.studentNumber == null ? s.name : '${s.studentNumber}. ${s.name}',
          ),
      ],
      columns: [
        for (final s in m.skills)
          HeatmapColumn(label: s.code, tooltip: '${s.code} ${s.name}'),
      ],
      onRowTap: onStudent == null ? null : (r) => onStudent!(m.students[r]),
      cell: (r, c) {
        final student = m.students[r];
        final skill = m.skills[c];
        final cell = m.cell(student.id, skill.id);
        if (cell == null) {
          return HeatmapCell(
            text: '–',
            fill: theme.colorScheme.surfaceContainerLow,
            textColor: theme.colorScheme.onSurfaceVariant,
            tooltip: '${student.name} · ${skill.code}: ยังไม่มีข้อมูล',
            onTap: onStudent == null ? null : () => onStudent!(student),
          );
        }
        final level = cell.level;
        return HeatmapCell(
          text: percent(cell.value),
          fill: masteryFill(context, level),
          textColor: level == MasteryLevel.tooLittle
              ? theme.colorScheme.onSurfaceVariant
              : theme.colorScheme.onSurface,
          tooltip:
              '${student.name} · ${skill.code}: ${percent(cell.value)} '
              '${level.label} (${cell.nObs} ครั้ง)',
          onTap: onStudent == null ? null : () => onStudent!(student),
        );
      },
    );
  }
}
