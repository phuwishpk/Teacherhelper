import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../worksheets/print_flow.dart';
import 'assignment.dart';
import 'assignments_page.dart';
import 'assignments_providers.dart';
import 'assignments_repository.dart';
import 'question.dart';

class AssignmentDetailScreen extends ConsumerWidget {
  const AssignmentDetailScreen({super.key, required this.assignmentId});

  final int assignmentId;

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    Assignment a,
  ) async {
    final ok = await confirm(
      context,
      title: 'ลบการบ้าน "${a.title}"?',
      message: 'ลบได้เฉพาะการบ้านที่ยังเป็นร่าง คำถามทั้งหมดจะถูกลบด้วย',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await ref.read(assignmentsProvider.notifier).delete(a.id);
      if (!context.mounted) return;
      showMessage(context, 'ลบการบ้านแล้ว');
      context.pop();
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _createLayout(BuildContext context, WidgetRef ref) async {
    try {
      final layout = await ref
          .read(assignmentDetailProvider(assignmentId).notifier)
          .createLayout();
      if (!context.mounted) return;
      showMessage(
        context,
        'สร้าง layout เวอร์ชัน ${layout.version} แล้ว (${layout.pages.length} หน้า) พิมพ์ใบงานได้เลย',
      );
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _printWorksheets(
    BuildContext context,
    WidgetRef ref,
    Assignment a,
  ) {
    final repo = ref.read(assignmentsRepositoryProvider);
    return runPrintFlow(
      context,
      ref,
      title: 'ใบงาน ${a.title}',
      fileName: 'worksheets-${a.id}-v${a.currentLayoutVersion}.pdf',
      request: () => repo.requestWorksheets(a.id),
      poll: repo.worksheetPrint,
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(assignmentDetailProvider(assignmentId));
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: Text(detail.value?.title ?? 'การบ้าน'),
        actions: [
          if (detail.value case final a?) ...[
            IconButton(
              tooltip: 'แก้ไข',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () =>
                  context.push(AppRoutes.assignmentEdit(a.id), extra: a),
            ),
            if (a.isDraft)
              IconButton(
                tooltip: 'ลบ',
                icon: const Icon(Icons.delete_outline),
                onPressed: () => _delete(context, ref, a),
              ),
          ],
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'question_new',
        onPressed: () {
          final a = detail.value;
          context.push(
            AppRoutes.questionNew(assignmentId),
            extra: a == null ? null : QuestionFormArgs.forAssignment(ref, a),
          );
        },
        icon: const Icon(Icons.add),
        label: const Text('เพิ่มคำถาม'),
      ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(assignmentDetailProvider(assignmentId)),
        data: (a) {
          final classroom = ref.watch(classroomProvider(a.classroomId)).value;
          final missingRubrics = a.questions
              .where(
                (q) =>
                    q.type.needsRubric &&
                    q.rubricStatus != RubricStatus.approved,
              )
              .length;
          return RefreshIndicator(
            onRefresh: () => ref
                .read(assignmentDetailProvider(assignmentId).notifier)
                .refresh(),
            child: ContentColumn(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              child: ListView(
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Wrap(
                        spacing: 16,
                        runSpacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          StatusChip(
                            label: assignmentStatusLabel(a.status),
                            color: assignmentStatusColor(context, a.status),
                          ),
                          Text(
                            classroom?.name ??
                                a.classroomName ??
                                'ห้อง #${a.classroomId}',
                          ),
                          if (a.subjectName != null) Text(a.subjectName!),
                          Text('ตรวจแบบ${a.strictness.label}'),
                          if (a.dueAt != null)
                            Text('ส่ง ${formatThaiDate(a.dueAt!)}'),
                          Text(
                            a.currentLayoutVersion == null
                                ? 'ยังไม่มี layout'
                                : 'layout v${a.currentLayoutVersion}',
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('ใบงาน', style: theme.textTheme.titleMedium),
                          const SizedBox(height: 8),
                          if (a.questions.isEmpty)
                            const Text('เพิ่มคำถามก่อน จึงจะสร้าง layout ได้')
                          else if (missingRubrics > 0)
                            Text(
                              'มี $missingRubrics ข้อ (แสดงวิธีทำ/อัตนัย) ที่ยังไม่อนุมัติ rubric '
                              'ต้องอนุมัติให้ครบก่อนสร้าง layout',
                              style: TextStyle(color: theme.colorScheme.error),
                            )
                          else if (a.currentLayoutVersion == null)
                            const Text('พร้อมสร้าง layout แล้ว')
                          else
                            const Text(
                              'ถ้าแก้คำถามหลังพิมพ์ ให้สร้าง layout ใหม่ก่อนพิมพ์อีกครั้ง',
                            ),
                          const SizedBox(height: 12),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              FilledButton.tonalIcon(
                                onPressed:
                                    a.questions.isEmpty || missingRubrics > 0
                                    ? null
                                    : () => _createLayout(context, ref),
                                icon: const Icon(Icons.grid_on),
                                label: Text(
                                  a.currentLayoutVersion == null
                                      ? 'สร้าง layout'
                                      : 'สร้าง layout ใหม่',
                                ),
                              ),
                              FilledButton.icon(
                                onPressed: a.currentLayoutVersion == null
                                    ? null
                                    : () => _printWorksheets(context, ref, a),
                                icon: const Icon(Icons.print_outlined),
                                label: const Text('พิมพ์ใบงาน'),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'คำถาม (${a.questions.length} ข้อ · รวม ${_totalPoints(a)} คะแนน)',
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  if (a.questions.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: Text(
                          'ยังไม่มีคำถาม กด "เพิ่มคำถาม" เพื่อเริ่ม',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  for (final q in a.questions)
                    _QuestionTile(
                      question: q,
                      onTap: () => context.push(
                        AppRoutes.questionEdit(a.id, q.id),
                        extra: QuestionFormArgs.forAssignment(ref, a, q),
                      ),
                      onRubric: q.type.needsRubric
                          ? () => context.push(AppRoutes.rubric(a.id, q.id))
                          : null,
                      onDelete: () async {
                        final ok = await confirm(
                          context,
                          title: 'ลบข้อ ${q.position}?',
                          message: 'ถ้าพิมพ์ใบงานไปแล้ว ต้องสร้าง layout ใหม่',
                          confirmLabel: 'ลบ',
                          destructive: true,
                        );
                        if (!ok) return;
                        try {
                          await ref
                              .read(
                                assignmentDetailProvider(assignmentId).notifier,
                              )
                              .deleteQuestion(q.id);
                        } catch (e) {
                          if (context.mounted) {
                            showMessage(context, apiErrorMessage(e));
                          }
                        }
                      },
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  static String _totalPoints(Assignment a) {
    final total = a.questions.fold<double>(0, (s, q) => s + q.maxPoints);
    return total == total.roundToDouble()
        ? total.toInt().toString()
        : total.toString();
  }
}

/// Extra data passed to the question form so it can filter the skill search
/// by the assignment's subject and the classroom's grade.
class QuestionFormArgs {
  const QuestionFormArgs({
    required this.subjectId,
    required this.gradeLevel,
    this.question,
  });

  final int subjectId;
  final int? gradeLevel;
  final Question? question;

  static QuestionFormArgs forAssignment(
    WidgetRef ref,
    Assignment a, [
    Question? q,
  ]) {
    final classroom = ref.read(classroomProvider(a.classroomId)).value;
    return QuestionFormArgs(
      subjectId: a.subjectId,
      gradeLevel: classroom?.gradeLevel,
      question: q,
    );
  }
}

class _QuestionTile extends StatelessWidget {
  const _QuestionTile({
    required this.question,
    required this.onTap,
    required this.onDelete,
    this.onRubric,
  });

  final Question question;
  final VoidCallback onTap;
  final VoidCallback onDelete;
  final VoidCallback? onRubric;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final theme = Theme.of(context);
    return Card(
      child: ListTile(
        onTap: onTap,
        leading: CircleAvatar(radius: 16, child: Text('${q.position}')),
        title: Text(q.promptText, maxLines: 2, overflow: TextOverflow.ellipsis),
        subtitle: Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('${q.type.label} · ${q.maxPoints} คะแนน'),
            if (q.isNumeric) const Text('ตัวเลข'),
            if (q.skills.isNotEmpty)
              Text(
                q.skills.map((s) => s.code).join(', '),
                style: theme.textTheme.bodySmall,
              ),
            if (q.type.needsRubric)
              InkWell(
                onTap: onRubric,
                child: StatusChip(
                  label: 'rubric: ${q.rubricStatus.label}',
                  color: q.rubricStatus == RubricStatus.approved
                      ? Colors.green.shade700
                      : theme.colorScheme.error,
                ),
              ),
          ],
        ),
        trailing: IconButton(
          tooltip: 'ลบข้อ',
          icon: const Icon(Icons.delete_outline),
          onPressed: onDelete,
        ),
      ),
    );
  }
}
