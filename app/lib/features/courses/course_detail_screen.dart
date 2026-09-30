import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/skills_picker.dart';
import 'course_document_flow.dart';
import 'course_item_forms.dart';
import 'course_models.dart';
import 'courses_providers.dart';
import 'courses_repository.dart';
import 'indicator_widgets.dart';

/// `/courses/:id`: a course with its classrooms, indicators, units and
/// lesson plans (DESIGN §20.1). Units and plans are added in forms or read
/// from lesson-plan documents; a plan is marked taught here (chart 5).
class CourseDetailScreen extends ConsumerWidget {
  const CourseDetailScreen({super.key, required this.courseId});

  final int courseId;

  CoursesRepository _repo(WidgetRef ref) => ref.read(coursesRepositoryProvider);

  Future<void> _delete(BuildContext context, WidgetRef ref, Course c) async {
    final ok = await confirm(
      context,
      title: 'ลบรายวิชา ${c.title}?',
      message: 'หน่วยและแผนการสอนทั้งหมดของรายวิชานี้จะถูกลบด้วย',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await _repo(ref).delete(c.id);
      invalidateCourses(ref);
      if (!context.mounted) return;
      showMessage(context, 'ลบรายวิชาแล้ว');
      context.pop();
    } catch (e) {
      if (!context.mounted) return;
      showMessage(
        context,
        apiErrorCode(e) == 'course_in_use'
            ? 'ลบไม่ได้ เพราะมีการบ้านใช้รายวิชานี้อยู่'
            : apiErrorMessage(e),
      );
    }
  }

  Future<void> _editIndicators(
    BuildContext context,
    WidgetRef ref,
    Course c,
  ) async {
    final picked = await showSkillsPicker(
      context,
      subjectId: c.subjectId,
      grade: c.gradeLevel,
      selected: c.indicators,
    );
    if (picked == null || !context.mounted) return;
    try {
      await _repo(ref).setIndicators(c.id, [for (final s in picked) s.id]);
      invalidateCourses(ref);
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _unitForm(
    BuildContext context,
    WidgetRef ref,
    Course c, [
    CourseUnit? unit,
  ]) async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => UnitFormScreen(
          initial: unit?.toDraft(),
          subjectId: c.subjectId,
          grade: c.gradeLevel,
          onSave: (draft) async {
            unit == null
                ? await _repo(ref).addUnit(c.id, draft)
                : await _repo(ref).updateUnit(unit.id, draft);
          },
          onDelete: unit == null ? null : () => _repo(ref).deleteUnit(unit.id),
        ),
      ),
    );
    invalidateCourses(ref);
  }

  Future<void> _planForm(
    BuildContext context,
    WidgetRef ref,
    Course c, {
    LessonPlan? plan,
    int? unitId,
  }) async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => LessonPlanFormScreen(
          initial:
              plan?.toDraft() ??
              (unitId == null
                  ? null
                  : PlanDraft(title: '', unit: UnitRef.existing(unitId))),
          units: [
            for (final u in c.units)
              UnitChoice(UnitRef.existing(u.id), u.label),
          ],
          subjectId: c.subjectId,
          grade: c.gradeLevel,
          onSave: (draft) async {
            plan == null
                ? await _repo(ref).addPlan(c.id, draft)
                : await _repo(ref).updatePlan(plan.id, draft);
          },
          onDelete: plan == null ? null : () => _repo(ref).deletePlan(plan.id),
        ),
      ),
    );
    invalidateCourses(ref);
  }

  Future<void> _toggleTaught(
    BuildContext context,
    WidgetRef ref,
    LessonPlan plan,
  ) async {
    final today = DateTime.now();
    final draft = plan.taughtOn == null
        ? plan.toDraft().copyWith(
            taughtOn: DateTime(today.year, today.month, today.day),
          )
        : plan.toDraft().copyWith(clearTaughtOn: true);
    try {
      await _repo(ref).updatePlan(plan.id, draft);
      invalidateCourses(ref);
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _importPlans(
    BuildContext context,
    WidgetRef ref,
    Course c,
  ) async {
    final saved = await runCourseDocumentImport(
      context,
      ref,
      purpose: CourseDocumentPurpose.lessonPlan,
      course: c,
    );
    if (saved != null) invalidateCourses(ref);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final course = ref.watch(courseDetailProvider(courseId));
    final c = course.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.code ?? 'รายวิชา'),
        actions: [
          if (c != null) ...[
            IconButton(
              tooltip: 'แก้ไขรายวิชา',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () =>
                  context.push(AppRoutes.courseEdit(c.id), extra: c),
            ),
            PopupMenuButton<String>(
              tooltip: 'ตัวเลือก',
              onSelected: (v) => switch (v) {
                'import' => _importPlans(context, ref, c),
                'delete' => _delete(context, ref, c),
                _ => null,
              },
              itemBuilder: (context) => [
                const PopupMenuItem(
                  value: 'import',
                  child: ListTile(
                    leading: Icon(Icons.auto_awesome_outlined),
                    title: Text('นำเข้าแผนการสอนจากเอกสาร'),
                  ),
                ),
                PopupMenuItem(
                  value: 'delete',
                  enabled: c.assignmentCount == 0,
                  child: const ListTile(
                    leading: Icon(Icons.delete_outline),
                    title: Text('ลบรายวิชา'),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
      body: AsyncView(
        value: course,
        onRetry: () => ref.invalidate(courseDetailProvider(courseId)),
        data: (c) => RefreshIndicator(
          onRefresh: () => ref.refresh(courseDetailProvider(courseId).future),
          child: ContentColumn(
            padding: EdgeInsets.zero,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                _HeaderCard(course: c),
                const SizedBox(height: 12),
                _Section(
                  title: 'ตัวชี้วัดของรายวิชา (${c.indicators.length})',
                  action: TextButton.icon(
                    key: const ValueKey('course_edit_indicators'),
                    onPressed: () => _editIndicators(context, ref, c),
                    icon: const Icon(Icons.checklist),
                    label: const Text('เลือกตัวชี้วัด'),
                  ),
                  child: IndicatorChips(indicators: c.indicators),
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    FilledButton.tonalIcon(
                      key: const ValueKey('course_add_unit'),
                      onPressed: () => _unitForm(context, ref, c),
                      icon: const Icon(Icons.add),
                      label: const Text('เพิ่มหน่วย'),
                    ),
                    FilledButton.tonalIcon(
                      key: const ValueKey('course_add_plan'),
                      onPressed: () => _planForm(context, ref, c),
                      icon: const Icon(Icons.add),
                      label: const Text('เพิ่มแผนการสอน'),
                    ),
                    FilledButton.icon(
                      key: const ValueKey('course_charts'),
                      onPressed: () =>
                          context.push(AppRoutes.courseCharts(c.id)),
                      icon: const Icon(Icons.insights_outlined),
                      label: const Text('กราฟและความคืบหน้า'),
                    ),
                    OutlinedButton.icon(
                      key: const ValueKey('course_import_plans'),
                      onPressed: () => _importPlans(context, ref, c),
                      icon: const Icon(Icons.auto_awesome_outlined),
                      label: const Text('นำเข้าแผนจากเอกสาร'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (c.units.isEmpty && c.lessonPlans.isEmpty)
                  const Card(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text(
                        'ยังไม่มีหน่วยหรือแผนการสอน (ไม่บังคับ) '
                        'เพิ่มเอง หรือให้ AI อ่านจากแผนการสอนที่มีอยู่',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ),
                for (final u in c.units)
                  _UnitCard(
                    unit: u,
                    plans: c.plansOf(u.id),
                    onEdit: () => _unitForm(context, ref, c, u),
                    onAddPlan: () => _planForm(context, ref, c, unitId: u.id),
                    onOpenPlan: (p) => _planForm(context, ref, c, plan: p),
                    onToggleTaught: (p) => _toggleTaught(context, ref, p),
                  ),
                if (c.plansOf(null) case final loose when loose.isNotEmpty)
                  _UnitCard(
                    plans: loose,
                    onOpenPlan: (p) => _planForm(context, ref, c, plan: p),
                    onToggleTaught: (p) => _toggleTaught(context, ref, p),
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
  const _HeaderCard({required this.course});

  final Course course;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = course;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(c.title, style: theme.textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(
              [
                ?c.subjectName,
                c.termLabel,
                if (c.hours != null) '${c.hours} ชั่วโมง',
              ].join(' · '),
            ),
            if (c.description case final d?) ...[
              const SizedBox(height: 8),
              Text(d, style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 12),
            Text('ห้องเรียน', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            if (c.classrooms.isEmpty)
              Text(
                'ยังไม่ผูกห้องเรียน กด "แก้ไขรายวิชา" เพื่อเลือกห้อง',
                style: theme.textTheme.bodySmall,
              )
            else
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final r in c.classrooms)
                    ActionChip(
                      avatar: const Icon(Icons.groups_outlined, size: 18),
                      label: Text(r.name),
                      onPressed: () => context.push(AppRoutes.classroom(r.id)),
                    ),
                ],
              ),
            if (c.assignmentCount > 0) ...[
              const SizedBox(height: 8),
              Text(
                'การบ้านที่ใช้รายวิชานี้ ${c.assignmentCount} งาน',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child, this.action});

  final String title;
  final Widget child;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              ?action,
            ],
          ),
          child,
        ],
      ),
    ),
  );
}

/// A unit and its plans, or ([unit] null) the plans outside any unit.
class _UnitCard extends StatelessWidget {
  const _UnitCard({
    required this.plans,
    required this.onOpenPlan,
    required this.onToggleTaught,
    this.unit,
    this.onEdit,
    this.onAddPlan,
  });

  final CourseUnit? unit;
  final List<LessonPlan> plans;
  final VoidCallback? onEdit;
  final VoidCallback? onAddPlan;
  final ValueChanged<LessonPlan> onOpenPlan;
  final ValueChanged<LessonPlan> onToggleTaught;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final u = unit;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            key: u == null ? null : ValueKey('unit_${u.id}'),
            title: Text(
              u?.label ?? 'แผนที่ไม่อยู่ในหน่วย',
              style: theme.textTheme.titleMedium,
            ),
            subtitle: u == null
                ? null
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (u.hours != null) Text('${u.hours} ชั่วโมง'),
                      const SizedBox(height: 4),
                      IndicatorChips(indicators: u.indicators),
                    ],
                  ),
            trailing: u == null
                ? null
                : IconButton(
                    tooltip: 'แก้ไขหน่วย',
                    icon: const Icon(Icons.edit_outlined),
                    onPressed: onEdit,
                  ),
          ),
          for (final p in plans) ...[
            const Divider(height: 1),
            ListTile(
              key: ValueKey('plan_${p.id}'),
              contentPadding: const EdgeInsets.only(left: 32, right: 8),
              title: Text(p.label),
              subtitle: Text(
                [
                  if (p.hours != null) '${p.hours} ชั่วโมง',
                  'ตัวชี้วัด ${p.indicators.length}',
                  if (p.taughtOn != null)
                    'สอนแล้ว ${formatThaiDate(p.taughtOn!)}',
                ].join(' · '),
              ),
              onTap: () => onOpenPlan(p),
              trailing: IconButton(
                key: ValueKey('plan_taught_${p.id}'),
                tooltip: p.taughtOn == null
                    ? 'ทำเครื่องหมายว่าสอนแล้ววันนี้'
                    : 'ยกเลิกเครื่องหมายสอนแล้ว',
                icon: Icon(
                  p.taughtOn == null
                      ? Icons.radio_button_unchecked
                      : Icons.check_circle,
                  color: p.taughtOn == null ? null : Colors.green.shade700,
                ),
                onPressed: () => onToggleTaught(p),
              ),
            ),
          ],
          if (onAddPlan != null) ...[
            const Divider(height: 1),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: Padding(
                padding: const EdgeInsets.only(left: 24),
                child: TextButton.icon(
                  onPressed: onAddPlan,
                  icon: const Icon(Icons.add),
                  label: const Text('เพิ่มแผนในหน่วยนี้'),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
