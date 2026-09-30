import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_picker.dart';
import 'answer_key_models.dart';
import 'answer_key_providers.dart';
import 'answer_key_repository.dart';
import 'assignment.dart';
import 'assignment_detail_screen.dart';
import 'assignments_providers.dart';
import 'document_read_screen.dart';
import 'key_document_sources.dart';
import 'question.dart';

/// "เฉลย" of an assignment (DESIGN §19.5, §19.11): three ways to give the
/// key (type it, photograph it, attach a file), AI drafting when the
/// teacher has none, then a review of every question (edit, rubric) and
/// "อนุมัติเฉลย". Nothing is graded before the key is approved.
class AnswerKeyScreen extends ConsumerStatefulWidget {
  const AnswerKeyScreen({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  ConsumerState<AnswerKeyScreen> createState() => _AnswerKeyScreenState();
}

class _AnswerKeyScreenState extends ConsumerState<AnswerKeyScreen> {
  bool _busy = false;

  /// What the last read or draft did (shown until the next one).
  KeyRequestResult? _last;

  int get _id => widget.assignmentId;

  AnswerKeyNotifier get _notifier => ref.read(answerKeyProvider(_id).notifier);

  Future<void> _run(Future<void> Function() task) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await task();
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Uploads the files, then the read screen (range + cost) sends them.
  Future<void> _readFrom(
    List<PickedDocument> files, {
    KeyRequestKind kind = KeyRequestKind.read,
  }) async {
    if (files.isEmpty) return;
    final docs = await ref.read(answerKeyRepositoryProvider).upload(files);
    if (!mounted) return;
    await _openReadScreen(kind, docs);
  }

  Future<void> _openReadScreen(
    KeyRequestKind kind,
    List<SourceDocument> docs,
  ) async {
    final result = await Navigator.of(context).push<KeyRequestResult>(
      MaterialPageRoute(
        builder: (_) =>
            DocumentReadScreen(assignmentId: _id, kind: kind, documents: docs),
      ),
    );
    if (result == null || !mounted) return;
    _notifier.accept(result);
    setState(() => _last = result);
    showMessage(
      context,
      result.cached
          ? 'เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย เติมเฉลยให้แล้ว'
          : kind == KeyRequestKind.read
          ? 'ส่งให้ AI อ่านเฉลยแล้ว รอสักครู่'
          : 'ส่งให้ AI ร่างเฉลยแล้ว รอสักครู่',
    );
  }

  Future<void> _photos() => _run(() async {
    final files = await Navigator.of(context).push<List<PickedDocument>>(
      MaterialPageRoute(builder: (_) => const KeyPhotoScreen()),
    );
    if (files != null && mounted) await _readFrom(files);
  });

  Future<void> _file() => _run(() async {
    final files = await ref.read(documentFilePickerProvider).pick();
    if (mounted) await _readFrom(files.take(kMaxDocumentFiles).toList());
  });

  Future<void> _aiDraft() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const ListTile(
              title: Text('ไม่มีเฉลย ให้ AI ร่าง'),
              subtitle: Text(
                'AI ร่างคำตอบจากโจทย์ เฉลยจะมีป้าย "AI ร่าง ไม่มีคำตอบของครู" '
                'ต้องตรวจทุกข้อก่อนอนุมัติ',
              ),
            ),
            ListTile(
              leading: const Icon(Icons.notes),
              title: const Text('ร่างจากโจทย์ที่พิมพ์ไว้'),
              onTap: () => Navigator.of(context).pop('typed'),
            ),
            ListTile(
              leading: const Icon(Icons.attach_file),
              title: const Text('แนบใบโจทย์ (PDF หรือรูป)'),
              onTap: () => Navigator.of(context).pop('file'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || choice == null) return;
    await _run(() async {
      if (choice == 'typed') {
        await _openReadScreen(KeyRequestKind.draft, const []);
      } else {
        final files = await ref.read(documentFilePickerProvider).pick();
        if (mounted) {
          await _readFrom(
            files.take(kMaxDocumentFiles).toList(),
            kind: KeyRequestKind.draft,
          );
        }
      }
    });
  }

  Future<void> _typed(Assignment? a) async {
    await context.push(
      AppRoutes.questionNew(_id),
      extra: a == null ? null : QuestionFormArgs.forAssignment(ref, a),
    );
    if (mounted) ref.invalidate(answerKeyProvider(_id));
  }

  Future<void> _edit(Assignment? a, Question q) async {
    await context.push(
      AppRoutes.questionEdit(_id, q.id),
      extra: a == null ? null : QuestionFormArgs.forAssignment(ref, a, q),
    );
    if (mounted) ref.invalidate(answerKeyProvider(_id));
  }

  Future<void> _rubric(Question q) async {
    await context.push(AppRoutes.rubric(_id, q.id));
    if (mounted) ref.invalidate(answerKeyProvider(_id));
  }

  Future<void> _approve(AnswerKeyState key, Assignment? a) async {
    int? courseId;
    if (a != null && a.needsCourse) {
      // A mirror of Classroom website courseWork has no course until the
      // teacher picks one here (DESIGN §19.3, §20.1, 422 course_required).
      courseId = await showDialog<int>(
        context: context,
        builder: (_) => CoursePickerDialog(classroomId: a.classroomId),
      );
      if (courseId == null || !mounted) return;
    } else {
      final ok = await confirm(
        context,
        title: 'อนุมัติเฉลย?',
        message: key.mode == AssignmentMode.freeform
            ? 'หลังอนุมัติ การบ้านจะพร้อมใช้ ระบบเริ่มตรวจงานที่ส่งมาด้วยเฉลยนี้'
            : 'ระบบจะตรวจงานที่ส่งมาด้วยเฉลยนี้',
        confirmLabel: 'อนุมัติ',
      );
      if (!ok || !mounted) return;
    }
    await _run(() async {
      await _notifier.approve(courseId: courseId);
      if (mounted) showMessage(context, 'อนุมัติเฉลยแล้ว');
    });
  }

  @override
  Widget build(BuildContext context) {
    final value = ref.watch(answerKeyProvider(_id));
    final assignment = ref.watch(assignmentDetailProvider(_id)).value;
    final key = value.value;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          assignment == null ? 'เฉลย' : 'เฉลย · ${assignment.title}',
          overflow: TextOverflow.ellipsis,
        ),
      ),
      bottomNavigationBar: key == null
          ? null
          : _ApproveBar(
              answerKey: key,
              busy: _busy,
              onApprove: () => _approve(key, assignment),
            ),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(answerKeyProvider(_id)),
        data: (key) {
          final locked = _busy || key.reading || key.closed;
          return RefreshIndicator(
            onRefresh: _notifier.refresh,
            child: ContentColumn(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              child: ListView(
                children: [
                  _StatusCard(answerKey: key),
                  if (assignment != null && assignment.fromClassroomWeb) ...[
                    const SizedBox(height: 12),
                    ClassroomWebKeyNote(assignment: assignment),
                  ],
                  if (key.keyOrigin case final origin?
                      when origin != KeyOrigin.teacher) ...[
                    const SizedBox(height: 12),
                    KeyOriginBanner(origin: origin),
                  ],
                  if (key.extraction case final extraction?) ...[
                    const SizedBox(height: 12),
                    _ExtractionCard(extraction: extraction),
                  ],
                  if (_last case final last?)
                    if (!key.reading && last.applied != null) ...[
                      const SizedBox(height: 12),
                      _AppliedCard(result: last),
                    ],
                  const SizedBox(height: 12),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'เพิ่มเฉลย',
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'AI อ่านรูปหรือไฟล์ครั้งเดียว แล้วเติมเฉลยให้ทุกข้อเป็นร่าง '
                            'ไฟล์ที่รับ: PDF, JPEG, PNG, HEIC, WebP (Word หรือ Google Docs ให้บันทึกเป็น PDF ก่อน)',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                          const SizedBox(height: 12),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              OutlinedButton.icon(
                                onPressed: locked
                                    ? null
                                    : () => _typed(assignment),
                                icon: const Icon(Icons.keyboard_outlined),
                                label: const Text('พิมพ์เอง'),
                              ),
                              OutlinedButton.icon(
                                onPressed: locked ? null : _photos,
                                icon: const Icon(Icons.photo_camera_outlined),
                                label: const Text('ถ่ายรูป'),
                              ),
                              OutlinedButton.icon(
                                onPressed: locked ? null : _file,
                                icon: const Icon(Icons.attach_file),
                                label: const Text('แนบไฟล์'),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          TextButton.icon(
                            onPressed: locked ? null : _aiDraft,
                            icon: const Icon(Icons.auto_awesome_outlined),
                            label: const Text('ไม่มีเฉลย ให้ AI ร่าง'),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'ตรวจทานเฉลย (${key.questions.length} ข้อ)',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  if (key.questions.isEmpty)
                    const Card(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: Text(
                          'ยังไม่มีข้อ พิมพ์เอง ถ่ายรูป หรือแนบไฟล์เฉลย '
                          'AI จะสร้างข้อให้จากเฉลย',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  for (final q in key.questions)
                    _KeyQuestionCard(
                      question: q,
                      aiDrafted: key.keyOrigin == KeyOrigin.aiDraft,
                      onEdit: key.closed ? null : () => _edit(assignment, q),
                      onRubric: q.type.needsRubric && !key.closed
                          ? () => _rubric(q)
                          : null,
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Where the key of a Classroom website mirror came from (DESIGN §19.3):
/// AI drafted it from the courseWork, and attached Google Docs could not
/// be read.
class ClassroomWebKeyNote extends StatelessWidget {
  const ClassroomWebKeyNote({super.key, required this.assignment});

  final Assignment assignment;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final materials = assignment.googleLink?.materials ?? const [];
    final unreadable = materials.where((m) => !m.supported).toList();
    return Card(
      key: const ValueKey('classroom_web_key_note'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('งานใหม่จาก Classroom', style: theme.textTheme.titleSmall),
            const SizedBox(height: 4),
            const Text(
              'ครูสร้างงานนี้ในเว็บ Classroom AI ร่างข้อและเฉลยจากชื่องาน คำอธิบาย '
              'และไฟล์ PDF หรือรูปที่แนบในงาน ตรวจทุกข้อ แก้ให้ถูก แล้วอนุมัติ '
              'งานที่นักเรียนส่งมาก่อนอนุมัติรอตรวจอยู่',
            ),
            if (unreadable.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                'อ่านไม่ได้: ${unreadable.map((m) => m.title).join(', ')}',
                style: theme.textTheme.bodySmall,
              ),
              if (unreadable.any((m) => m.isGoogleDoc))
                Text(
                  'อ่านไฟล์ Google Docs ไม่ได้ บันทึกเป็น PDF แล้วแนบในแอป หรือพิมพ์เฉลยเอง',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

/// The label of a key AI wrote (DESIGN §19.5): "AI ร่าง ไม่มีคำตอบของครู"
/// for a draft, and a review reminder for a key read from the teacher's
/// files.
class KeyOriginBanner extends StatelessWidget {
  const KeyOriginBanner({super.key, required this.origin});

  final KeyOrigin origin;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final draft = origin == KeyOrigin.aiDraft;
    final bg = draft ? scheme.errorContainer : scheme.secondaryContainer;
    final fg = draft ? scheme.onErrorContainer : scheme.onSecondaryContainer;
    return Card(
      key: const ValueKey('key_origin_banner'),
      color: bg,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Icon(
              draft
                  ? Icons.smart_toy_outlined
                  : Icons.document_scanner_outlined,
              color: fg,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    draft
                        ? 'AI ร่าง ไม่มีคำตอบของครู'
                        : 'AI อ่านจากเอกสารของครู',
                    style: TextStyle(color: fg, fontWeight: FontWeight.bold),
                  ),
                  Text(
                    draft
                        ? 'คำตอบทุกข้อ AI คิดเอง ตรวจและแก้ให้ถูกก่อนอนุมัติ'
                        : 'ตรวจว่า AI อ่านเฉลยถูกทุกข้อก่อนอนุมัติ',
                    style: TextStyle(color: fg),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusCard extends StatelessWidget {
  const _StatusCard({required this.answerKey});

  final AnswerKeyState answerKey;

  @override
  Widget build(BuildContext context) {
    final key = answerKey;
    final approvedAt = key.keyApprovedAt;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 12,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                StatusChip(
                  label: approvedAt == null
                      ? 'ยังไม่อนุมัติเฉลย'
                      : 'อนุมัติเฉลยแล้ว',
                  color: approvedAt == null
                      ? Theme.of(context).colorScheme.secondary
                      : Colors.green.shade700,
                ),
                Text(key.mode.label),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              approvedAt != null
                  ? 'อนุมัติเมื่อ ${formatThaiDateTime(approvedAt)}'
                  : key.mode == AssignmentMode.freeform
                  ? 'ระบบยังไม่ตรวจงานที่ส่งมาจนกว่าจะอนุมัติเฉลย นักเรียนยังไม่เห็นการบ้านนี้'
                  : 'ระบบยังไม่ตรวจงานที่ส่งมาจนกว่าจะอนุมัติเฉลย',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _ExtractionCard extends StatelessWidget {
  const _ExtractionCard({required this.extraction});

  final KeyExtraction extraction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final what = extraction.isDraft ? 'ร่างเฉลย' : 'อ่านเฉลย';
    if (extraction.isQueued) {
      return Card(
        key: const ValueKey('extraction_queued'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('AI กำลัง$what...'),
              const SizedBox(height: 8),
              const LinearProgressIndicator(),
              const SizedBox(height: 8),
              Text(
                'ใช้เวลาประมาณ 1–2 นาที ปิดหน้านี้แล้วกลับมาดูทีหลังได้',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      );
    }
    if (extraction.isFailed) {
      return Card(
        color: theme.colorScheme.errorContainer,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(
            '$whatไม่สำเร็จ: ${extraction.error ?? 'ไม่ทราบสาเหตุ'} '
            'ลองส่งใหม่อีกครั้ง',
            style: TextStyle(color: theme.colorScheme.onErrorContainer),
          ),
        ),
      );
    }
    final notes = extraction.notesTh;
    if (notes == null || notes.trim().isEmpty) return const SizedBox.shrink();
    return Card(
      child: ListTile(
        leading: const Icon(Icons.info_outline),
        title: const Text('หมายเหตุจาก AI'),
        subtitle: Text(notes),
      ),
    );
  }
}

class _AppliedCard extends StatelessWidget {
  const _AppliedCard({required this.result});

  final KeyRequestResult result;

  @override
  Widget build(BuildContext context) {
    final applied = result.applied!;
    final lines = <String>[
      if (result.cached) 'เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย',
      if (applied.created > 0) 'สร้างข้อใหม่ ${applied.created} ข้อ',
      if (applied.filled > 0) 'เติมเฉลย ${applied.filled} ข้อ',
      for (final s in applied.skipped) s.label,
    ];
    if (lines.isEmpty) return const SizedBox.shrink();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [for (final l in lines) Text(l)],
        ),
      ),
    );
  }
}

/// Short text of a question's key for the review list.
String keySummary(Question q) {
  final key = q.answerKey;
  List<String> accepted(Object? list) =>
      ((list as List?) ?? const []).map((e) => e.toString()).toList();
  switch (q.type) {
    case QuestionType.mcq:
      final correct = key?['correct'];
      return correct == null ? 'ยังไม่มีเฉลย' : 'ตอบ $correct';
    case QuestionType.short:
      final list = accepted(key?['accepted']);
      return list.isEmpty ? 'ยังไม่มีเฉลย' : 'ตอบ ${list.join(' / ')}';
    case QuestionType.showWork:
      final list = accepted((key?['final'] as Map?)?['accepted']);
      if (list.isEmpty) return 'ยังไม่มีคำตอบสุดท้าย';
      final steps = q.referenceSteps.length;
      return 'คำตอบสุดท้าย ${list.join(' / ')}'
          '${steps > 0 ? ' · ขั้นตอนอ้างอิง $steps ขั้น' : ''}';
    case QuestionType.open:
      final model = q.modelAnswer?.trim();
      return model == null || model.isEmpty
          ? 'ยังไม่มีคำตอบตัวอย่าง (ตรวจตาม rubric)'
          : 'คำตอบตัวอย่าง: $model';
  }
}

class _KeyQuestionCard extends StatelessWidget {
  const _KeyQuestionCard({
    required this.question,
    required this.aiDrafted,
    this.onEdit,
    this.onRubric,
  });

  final Question question;
  final bool aiDrafted;
  final VoidCallback? onEdit;
  final VoidCallback? onRubric;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final theme = Theme.of(context);
    final complete = q.keyComplete;
    return Card(
      key: ValueKey('key_question_${q.position}'),
      child: InkWell(
        onTap: onEdit,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(radius: 16, child: Text('${q.position}')),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      q.promptText.isEmpty ? '(ไม่มีโจทย์)' : q.promptText,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${q.type.label} · ${_points(q.maxPoints)} คะแนน',
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      keySummary(q),
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        StatusChip(
                          label: complete ? 'ครบ' : 'ยังไม่ครบ',
                          color: complete
                              ? Colors.green.shade700
                              : theme.colorScheme.error,
                        ),
                        if (aiDrafted)
                          StatusChip(
                            label: 'AI ร่าง',
                            color: theme.colorScheme.tertiary,
                          ),
                        if (q.type.needsRubric)
                          ActionChip(
                            avatar: const Icon(Icons.rule, size: 18),
                            label: Text('rubric: ${q.rubricStatus.label}'),
                            onPressed: onRubric,
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              if (onEdit != null)
                const Padding(
                  padding: EdgeInsets.only(left: 4),
                  child: Icon(Icons.edit_outlined, size: 20),
                ),
            ],
          ),
        ),
      ),
    );
  }

  static String _points(double v) =>
      v == v.roundToDouble() ? v.toInt().toString() : v.toString();
}

class _ApproveBar extends StatelessWidget {
  const _ApproveBar({
    required this.answerKey,
    required this.busy,
    required this.onApprove,
  });

  final AnswerKeyState answerKey;
  final bool busy;
  final VoidCallback onApprove;

  @override
  Widget build(BuildContext context) {
    final key = answerKey;
    final theme = Theme.of(context);
    final String note;
    if (key.closed) {
      note = 'การบ้านนี้ปิดแล้ว';
    } else if (key.reading) {
      note = 'รอ AI ทำเสร็จก่อน แล้วตรวจทานทุกข้อ';
    } else if (key.questions.isEmpty) {
      note = 'เพิ่มข้อก่อนอนุมัติเฉลย';
    } else if (!key.keyComplete) {
      note = key.incompleteQuestions.isEmpty
          ? 'เฉลยยังไม่ครบ'
          : 'ยังไม่ครบ: ข้อ ${key.incompleteQuestions.join(', ')} '
                '(ใส่คำตอบ หรืออนุมัติ rubric)';
    } else if (key.approved) {
      note = 'อนุมัติเฉลยแล้ว';
    } else {
      note = 'เฉลยครบทุกข้อแล้ว';
    }
    final canApprove =
        !busy &&
        !key.closed &&
        !key.reading &&
        !key.approved &&
        key.keyComplete &&
        key.questions.isNotEmpty;
    return SafeArea(
      child: Material(
        elevation: 4,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  note,
                  style: TextStyle(
                    color: key.keyComplete || key.questions.isEmpty
                        ? null
                        : theme.colorScheme.error,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              FilledButton.icon(
                key: const ValueKey('approve_key'),
                onPressed: canApprove ? onApprove : null,
                icon: const Icon(Icons.verified_outlined),
                label: const Text('อนุมัติเฉลย'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
