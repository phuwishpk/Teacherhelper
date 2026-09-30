import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_page.dart';
import '../assignments/assignments_providers.dart';
import 'exam_import_flow.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exam_section_dialog.dart';

/// Shows [e] as a message; the server's text explains 409s such as
/// `exam_structure_locked` ("ปลดล็อกเพื่อแก้โครงสร้าง").
void showExamError(BuildContext context, Object e) =>
    showMessage(context, apiErrorMessage(e));

/// One exam (DESIGN §22.2–§22.5): settings summary, the key state and its
/// approval, the structure lock, and the sections with their questions.
/// Buttons lead to the key grid, the versions and the print screen.
class ExamScreen extends ConsumerWidget {
  const ExamScreen({super.key, required this.examId});

  final int examId;

  ExamDetailNotifier _notifier(WidgetRef ref) =>
      ref.read(examDetailProvider(examId).notifier);

  Future<void> _addSection(
    BuildContext context,
    WidgetRef ref,
    ExamDetail d,
  ) async {
    if (d.sections.length >= kExamMaxSections) {
      showMessage(context, 'ข้อสอบหนึ่งฉบับมีได้ไม่เกิน $kExamMaxSections ตอน');
      return;
    }
    final draft = await showExamSectionDialog(
      context,
      nextPosition: d.sections.length + 1,
    );
    if (draft == null || !context.mounted) return;
    try {
      await _notifier(ref).addSection(draft);
      if (context.mounted) showMessage(context, 'เพิ่มตอนแล้ว');
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _editSection(
    BuildContext context,
    WidgetRef ref,
    ExamDetail d,
    ExamSection s,
  ) async {
    final draft = await showExamSectionDialog(
      context,
      existing: s,
      structureLocked: d.structureLocked,
    );
    if (draft == null || !context.mounted) return;
    try {
      await _notifier(ref).updateSection(s.id, draft.toUpdateJson(s));
      if (context.mounted) showMessage(context, 'บันทึกตอนแล้ว');
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _moveSection(
    BuildContext context,
    WidgetRef ref,
    ExamSection s,
    int position,
  ) async {
    try {
      await _notifier(ref).updateSection(s.id, {'position': position});
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _deleteSection(
    BuildContext context,
    WidgetRef ref,
    ExamSection s,
  ) async {
    final ok = await confirm(
      context,
      title: 'ลบ${s.heading}?',
      message:
          'ข้อทั้งหมด ${s.questions.length} ข้อในตอนนี้ รวมภาพและเฉลย '
          'จะถูกลบ เลขข้อของตอนถัดไปเลื่อนขึ้น',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await _notifier(ref).deleteSection(s.id);
      if (context.mounted) showMessage(context, 'ลบตอนแล้ว');
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _approveKey(BuildContext context, WidgetRef ref) async {
    try {
      await _notifier(ref).approveKey();
      if (context.mounted) {
        showMessage(context, 'อนุมัติเฉลยแล้ว ข้อสอบพร้อมใช้');
      }
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _unlock(BuildContext context, WidgetRef ref) async {
    final ok = await confirm(
      context,
      title: 'ปลดล็อกเพื่อแก้โครงสร้าง?',
      message:
          'ชุดข้อสอบจะถูกสุ่มใหม่ ต้องพิมพ์เล่มข้อสอบ กระดาษคำตอบ '
          'และกระดาษเฉลยใหม่ทั้งหมด ใช้ได้เฉพาะเมื่อยังไม่มีกระดาษคำตอบที่สแกนแล้ว',
      confirmLabel: 'ปลดล็อก',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await _notifier(ref).unlockStructure();
      if (context.mounted) showMessage(context, 'ปลดล็อกแล้ว แก้โครงสร้างได้');
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    ExamDetail d,
  ) async {
    final ok = await confirm(
      context,
      title: 'ลบข้อสอบ "${d.exam.title}"?',
      message:
          'ลบได้เฉพาะข้อสอบที่ยังเป็นร่างและยังไม่พิมพ์ ตอน ข้อ และภาพจะถูกลบด้วย',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await ref.read(assignmentsProvider.notifier).delete(d.exam.id);
      if (!context.mounted) return;
      showMessage(context, 'ลบข้อสอบแล้ว');
      context.pop();
    } catch (e) {
      if (context.mounted) showExamError(context, e);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(examDetailProvider(examId));
    final d = detail.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(d?.exam.title ?? 'ข้อสอบ'),
        actions: [
          if (d != null) ...[
            IconButton(
              tooltip: 'ตั้งค่าข้อสอบ',
              icon: const Icon(Icons.edit_outlined),
              onPressed: () => context.push(AppRoutes.examEdit(examId)),
            ),
            if (d.exam.isDraft && !d.structureLocked)
              IconButton(
                tooltip: 'ลบข้อสอบ',
                icon: const Icon(Icons.delete_outline),
                onPressed: () => _delete(context, ref, d),
              ),
          ],
        ],
      ),
      floatingActionButton: d == null || d.structureLocked
          ? null
          : FloatingActionButton.extended(
              key: const ValueKey('exam_add_section'),
              heroTag: 'exam_section_new',
              onPressed: () => _addSection(context, ref, d),
              icon: const Icon(Icons.playlist_add),
              label: const Text('เพิ่มตอน'),
            ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(examDetailProvider(examId)),
        data: (d) => RefreshIndicator(
          onRefresh: () => _notifier(ref).refresh(),
          child: ContentColumn(
            padding: EdgeInsets.zero,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              children: [
                _SummaryCard(detail: d),
                if (d.structureLocked)
                  _LockBanner(onUnlock: () => _unlock(context, ref)),
                if (d.sheetOverflow)
                  _Banner(
                    icon: Icons.warning_amber_outlined,
                    color: Theme.of(context).colorScheme.error,
                    text:
                        'ข้อมากเกินกระดาษคำตอบ 2 หน้า ลดจำนวนข้อหรือจำนวนหลักของตอนเติมตัวเลข',
                  ),
                if (d.unapprovedDrafts.isNotEmpty ||
                    d.figuresPending.isNotEmpty)
                  _Banner(
                    icon: Icons.fact_check_outlined,
                    color: Theme.of(context).colorScheme.tertiary,
                    text: [
                      if (d.unapprovedDrafts.isNotEmpty)
                        'ข้อที่อ่านจากไฟล์ยังไม่อนุมัติ '
                            '${d.unapprovedDrafts.length} ข้อ ตรวจและอนุมัติก่อนพิมพ์',
                      if (d.figuresPending.isNotEmpty)
                        'ยังไม่มีภาพประกอบจาก ${d.figuresPending.length} หน้าเอกสาร',
                    ].join(' · '),
                    action: TextButton(
                      key: const ValueKey('exam_open_read_review'),
                      onPressed: () =>
                          context.push(AppRoutes.examReadReview(examId)),
                      child: const Text('ตรวจข้อที่อ่านจากไฟล์'),
                    ),
                  ),
                _KeyCard(detail: d, onApprove: () => _approveKey(context, ref)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    FilledButton.tonalIcon(
                      key: const ValueKey('exam_open_key'),
                      onPressed: d.questionCount == 0
                          ? null
                          : () => context.push(AppRoutes.examAnswerKey(examId)),
                      icon: const Icon(Icons.grid_on_outlined),
                      label: Text(
                        d.isManual ? 'ตารางเฉลย (ไม่บังคับ)' : 'ตารางเฉลย',
                      ),
                    ),
                    FilledButton.tonalIcon(
                      key: const ValueKey('exam_open_versions'),
                      onPressed: d.questionCount == 0
                          ? null
                          : () => context.push(AppRoutes.examVersions(examId)),
                      icon: const Icon(Icons.shuffle),
                      label: Text('ชุดข้อสอบ (${d.exam.versionCount} ชุด)'),
                    ),
                    FilledButton.icon(
                      key: const ValueKey('exam_open_print'),
                      onPressed: d.questionCount == 0
                          ? null
                          : () => context.push(AppRoutes.examPrint(examId)),
                      icon: const Icon(Icons.print_outlined),
                      label: Text(
                        d.isManual
                            ? 'พิมพ์เล่มข้อสอบ'
                            : 'พิมพ์เล่มและกระดาษคำตอบ',
                      ),
                    ),
                    // "กรอกคะแนน": the exam's column of the gradebook (§23.9).
                    if (d.isManual && d.exam.courseId != null)
                      FilledButton.icon(
                        key: const ValueKey('exam_open_gradebook'),
                        onPressed: () => context.push(
                          AppRoutes.gradebook(
                            d.exam.courseId!,
                            classroomId: d.exam.classroomId,
                            column: 'a$examId',
                          ),
                        ),
                        icon: const Icon(Icons.table_chart_outlined),
                        label: const Text('กรอกคะแนน'),
                      ),
                    if (!d.isManual)
                      FilledButton.icon(
                        key: const ValueKey('exam_open_scan'),
                        onPressed: d.keyApproved
                            ? () => context.push(AppRoutes.examScan(examId))
                            : null,
                        icon: const Icon(Icons.document_scanner_outlined),
                        label: const Text('สแกนกระดาษคำตอบ'),
                      ),
                    // Scanned sheets exist only after the key was approved.
                    if (!d.isManual && d.keyApproved)
                      FilledButton.tonalIcon(
                        key: const ValueKey('exam_open_results'),
                        onPressed: () =>
                            context.push(AppRoutes.examResults(examId)),
                        icon: const Icon(Icons.campaign_outlined),
                        label: const Text('ตรวจทานและประกาศผล'),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
                if (d.sections.isEmpty)
                  const EmptyView(
                    icon: Icons.quiz_outlined,
                    title: 'ยังไม่มีตอน',
                    message:
                        'กด "เพิ่มตอน" เลือกชนิดของข้อ (ปรนัย ถูก/ผิด เติมตัวเลข) '
                        'และจำนวนข้อ แล้วกรอกโจทย์และเฉลยทีละข้อ '
                        'หรือให้ AI อ่านไฟล์ข้อสอบเดิม หรือคัดลอกจากข้อสอบเดิม',
                  ),
                for (final s in d.sections)
                  _SectionCard(
                    examId: examId,
                    detail: d,
                    section: s,
                    onEdit: () => _editSection(context, ref, d, s),
                    onDelete: () => _deleteSection(context, ref, s),
                    onMove: (p) => _moveSection(context, ref, s, p),
                  ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      key: const ValueKey('exam_import_file'),
                      onPressed: d.structureLocked
                          ? null
                          : () => runExamImport(context, ref, examId: examId),
                      icon: const Icon(Icons.upload_file),
                      label: const Text('อ่านข้อจากไฟล์ข้อสอบ'),
                    ),
                    OutlinedButton.icon(
                      key: const ValueKey('exam_copy_questions'),
                      onPressed: d.structureLocked
                          ? null
                          : () => context.push(
                              AppRoutes.examCopyQuestions(examId),
                            ),
                      icon: const Icon(Icons.copy_all),
                      label: const Text('คัดลอกจากข้อสอบเดิม'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.detail});

  final ExamDetail detail;

  @override
  Widget build(BuildContext context) {
    final a = detail.exam;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final parts = [
      ?a.classroomName,
      ?(a.courseLabel ?? a.subjectName),
      if (a.dueAt != null) 'สอบ ${formatThaiDate(a.dueAt!.toLocal())}',
      if (a.durationMinutes != null) '${a.durationMinutes} นาที',
    ];
    final full = detail.isManual
        ? a.manualFullMarks
        : (detail.questionCount == 0 ? null : detail.totalPoints);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (parts.isNotEmpty) Text(parts.join(' · ')),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                StatusChip(
                  label: assignmentStatusLabel(a.status),
                  color: assignmentStatusColor(context, a.status),
                ),
                StatusChip(label: detail.gradingMethod.label),
                StatusChip(
                  label: '${detail.questionCount} ข้อ',
                  color: scheme.tertiary,
                ),
                if (full != null)
                  StatusChip(
                    label: 'เต็ม ${formatPoints(full)} คะแนน',
                    color: scheme.tertiary,
                  ),
                StatusChip(
                  label: a.versionCount == 1
                      ? '1 ชุด'
                      : '${a.versionCount} ชุด (ก–${examVersionLabel(a.versionCount)})',
                  color: scheme.tertiary,
                ),
                if (a.showKeyToStudents)
                  StatusChip(label: 'นักเรียนดูเฉลยได้', color: scheme.outline),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({
    required this.icon,
    required this.text,
    this.color,
    this.action,
  });

  final IconData icon;
  final String text;
  final Color? color;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.secondary;
    final a = action;
    return Card(
      color: c.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, color: c),
                const SizedBox(width: 12),
                Expanded(child: Text(text)),
              ],
            ),
            if (a != null) Align(alignment: Alignment.centerRight, child: a),
          ],
        ),
      ),
    );
  }
}

class _LockBanner extends StatelessWidget {
  const _LockBanner({required this.onUnlock});

  final VoidCallback onUnlock;

  @override
  Widget build(BuildContext context) {
    return _Banner(
      icon: Icons.lock_outline,
      text:
          'พิมพ์แล้ว โครงสร้างถูกล็อก (เพิ่ม ลบ ย้ายข้อหรือตอน จำนวนตัวเลือก '
          'จำนวนชุด ห้ามสลับตัวเลือก) ข้อความ ภาพ คะแนน และเฉลยยังแก้ได้',
      action: TextButton(
        key: const ValueKey('exam_unlock'),
        onPressed: onUnlock,
        child: const Text('ปลดล็อกเพื่อแก้โครงสร้าง'),
      ),
    );
  }
}

/// The key state: approved, ready to approve, or what is missing.
class _KeyCard extends StatelessWidget {
  const _KeyCard({required this.detail, required this.onApprove});

  final ExamDetail detail;
  final VoidCallback onApprove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (detail.isManual) {
      return const _Banner(
        icon: Icons.edit_note,
        text:
            'ครูตรวจเอง: ไม่ต้องอนุมัติเฉลย กรอกคะแนนรวมในสมุดคะแนน '
            'ใส่เฉลยไว้ได้ (ไม่บังคับ) เพื่อคัดลอกไปใช้ในข้อสอบอื่น',
      );
    }
    if (detail.keyApproved) {
      return _Banner(
        icon: Icons.verified_outlined,
        color: Colors.green.shade700,
        text: 'อนุมัติเฉลยแล้ว แก้เฉลยได้ แต่เฉลยที่ไม่ครบจะยกเลิกการอนุมัติ',
      );
    }
    if (detail.questionCount == 0) {
      return const _Banner(
        icon: Icons.info_outline,
        text: 'เพิ่มตอนและข้อ กรอกเฉลยให้ครบ แล้วอนุมัติเฉลยก่อนพิมพ์',
      );
    }
    final missing = detail.incomplete;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              detail.keyComplete
                  ? 'เฉลยครบทุกข้อแล้ว อนุมัติเพื่อพิมพ์และสแกน'
                  : 'เฉลยยังไม่ครบ ${missing.length} ข้อ',
              style: theme.textTheme.titleSmall,
            ),
            if (missing.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                [
                  for (final m in missing.take(5)) m.summary,
                  if (missing.length > 5) 'และอีก ${missing.length - 5} ข้อ',
                ].join('\n'),
                style: theme.textTheme.bodySmall,
              ),
            ],
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                key: const ValueKey('exam_approve_key'),
                onPressed: detail.keyComplete ? onApprove : null,
                icon: const Icon(Icons.check),
                label: const Text('อนุมัติเฉลย'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({
    required this.examId,
    required this.detail,
    required this.section,
    required this.onEdit,
    required this.onDelete,
    required this.onMove,
  });

  final int examId;
  final ExamDetail detail;
  final ExamSection section;
  final VoidCallback onEdit;
  final VoidCallback onDelete;
  final ValueChanged<int> onMove;

  @override
  Widget build(BuildContext context) {
    final s = section;
    final theme = Theme.of(context);
    final locked = detail.structureLocked;
    final last = detail.sections.length;
    final full = detail.questionCount >= kExamMaxQuestions;
    return Card(
      key: ValueKey('exam_section_${s.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            title: Text(s.heading, style: theme.textTheme.titleMedium),
            subtitle: Text(
              [
                s.typeSummary,
                ?s.numberRange,
                'ข้อละ ${formatPoints(s.defaultPoints)} คะแนน',
              ].join(' · '),
            ),
            trailing: PopupMenuButton<String>(
              key: ValueKey('exam_section_menu_${s.id}'),
              tooltip: 'เมนูตอน',
              onSelected: (v) => switch (v) {
                'edit' => onEdit(),
                'up' => onMove(s.position - 1),
                'down' => onMove(s.position + 1),
                'delete' => onDelete(),
                _ => null,
              },
              itemBuilder: (_) => [
                const PopupMenuItem(value: 'edit', child: Text('แก้ไขตอน')),
                if (!locked && s.position > 1)
                  const PopupMenuItem(value: 'up', child: Text('ย้ายขึ้น')),
                if (!locked && s.position < last)
                  const PopupMenuItem(value: 'down', child: Text('ย้ายลง')),
                if (!locked)
                  const PopupMenuItem(value: 'delete', child: Text('ลบตอน')),
              ],
            ),
          ),
          if ((s.instructions ?? '').trim().isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text(s.instructions!, style: theme.textTheme.bodySmall),
            ),
          for (final q in s.questions)
            _QuestionTile(examId: examId, question: q),
          if (!locked)
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
                child: TextButton.icon(
                  key: ValueKey('exam_add_question_${s.id}'),
                  onPressed: full
                      ? null
                      : () => context.push(
                          AppRoutes.examQuestionNew(examId, s.id),
                        ),
                  icon: const Icon(Icons.add),
                  label: Text(
                    full
                        ? 'ครบ $kExamMaxQuestions ข้อแล้ว'
                        : 'เพิ่มข้อในตอนนี้',
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _QuestionTile extends StatelessWidget {
  const _QuestionTile({required this.examId, required this.question});

  final int examId;
  final ExamQuestion question;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final scheme = Theme.of(context).colorScheme;
    final prompt = q.promptText.trim();
    final key = q.key;
    final chips = [
      if (q.blank)
        StatusChip(label: 'ยังไม่ได้กรอก', color: scheme.outline)
      else if (!q.approved)
        StatusChip(
          label: q.fromDocument ? 'ร่างจากไฟล์ ยังไม่อนุมัติ' : 'ยังไม่อนุมัติ',
          color: scheme.error,
        ),
      if (q.anyFigurePending)
        StatusChip(label: 'ยังไม่มีภาพประกอบ', color: scheme.outline),
      if (q.lockOptions)
        StatusChip(label: 'ห้ามสลับตัวเลือก', color: scheme.secondary),
      if (q.lockOptionsSuggested)
        StatusChip(label: 'แนะนำห้ามสลับ', color: scheme.tertiary),
    ];
    return ListTile(
      key: ValueKey('exam_question_${q.id}'),
      dense: true,
      leading: CircleAvatar(radius: 16, child: Text('${q.position}')),
      title: Text(
        prompt.isEmpty
            ? (q.hasPromptImage ? '(โจทย์เป็นภาพ)' : '(ยังไม่มีโจทย์)')
            : prompt,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            [
              key == null ? 'ยังไม่มีเฉลย' : 'เฉลย ${key.describe(q.type)}',
              '${formatPoints(q.maxPoints)} คะแนน',
              if (q.hasPromptImage) 'มีภาพ',
            ].join(' · '),
            style: key == null ? TextStyle(color: scheme.error) : null,
          ),
          if (chips.isNotEmpty) ...[
            const SizedBox(height: 4),
            Wrap(spacing: 4, runSpacing: 4, children: chips),
          ],
        ],
      ),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => context.push(AppRoutes.examQuestion(examId, q.id)),
    );
  }
}
