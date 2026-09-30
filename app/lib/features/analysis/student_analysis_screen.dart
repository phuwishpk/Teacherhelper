import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'analysis_models.dart';
import 'analysis_repository.dart';
import 'analysis_widgets.dart';

/// Server limits of `PATCH /analyses/{id}` (DESIGN §20.5).
const teacherTextMax = 4000;
const studentTextMax = 2000;

/// `/classrooms/:id/students/:sid/analysis`: the teacher's panel of one
/// student's AI analysis (DESIGN §20.5): strengths and areas by code, both
/// texts, "วิเคราะห์ตอนนี้", edit and approve. The student sees the student
/// text only after approval (or the classroom's auto-share).
class StudentAnalysisScreen extends ConsumerStatefulWidget {
  const StudentAnalysisScreen({
    super.key,
    required this.classroomId,
    required this.studentId,
  });

  final int classroomId;
  final int studentId;

  @override
  ConsumerState<StudentAnalysisScreen> createState() =>
      _StudentAnalysisScreenState();
}

class _StudentAnalysisScreenState extends ConsumerState<StudentAnalysisScreen> {
  bool _running = false;
  bool _saving = false;

  StudentAnalysisKey get _key =>
      (studentId: widget.studentId, classroomId: widget.classroomId);

  StudentAnalysisNotifier get _notifier =>
      ref.read(studentAnalysisProvider(_key).notifier);

  Future<void> _runNow() async {
    setState(() => _running = true);
    try {
      final a = await _notifier.runNow();
      if (!mounted) return;
      showMessage(
        context,
        a.shared && !a.awaitingApproval
            ? 'AI เขียนข้อความแล้ว และแชร์ให้นักเรียนอัตโนมัติ'
            : 'AI เขียนข้อความแล้ว ตรวจแล้วกด "อนุมัติ" เพื่อแชร์ให้นักเรียน',
      );
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _running = false);
    }
  }

  Future<void> _approve() async {
    setState(() => _saving = true);
    try {
      await _notifier.approve();
      if (mounted) {
        showMessage(context, 'อนุมัติแล้ว นักเรียนเห็นข้อความนี้แล้ว');
      }
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _edit(StudentAnalysis a) async {
    final result = await showAnalysisEditor(context, a);
    if (result == null || !mounted) return;
    setState(() => _saving = true);
    try {
      await _notifier.edit(
        teacherText: result.teacherText,
        studentText: result.studentText,
        share: result.share,
      );
      if (mounted) {
        showMessage(
          context,
          result.share ? 'บันทึกและแชร์ให้นักเรียนแล้ว' : 'บันทึกแล้ว',
        );
      }
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _showError(Object e) {
    final code = apiErrorCode(e);
    final message = switch (code) {
      'ai_key_missing' =>
        'ยังไม่ได้ใส่ Gemini API key ใส่ได้ที่หน้าตั้งค่า หรือเขียนข้อความเองก็ได้',
      'ai_key_invalid' =>
        'Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าอีกครั้ง',
      _ when apiStatusCode(e) == 429 =>
        'กดวิเคราะห์บ่อยเกินไป รอสักครู่แล้วลองใหม่',
      _ => apiErrorMessage(e),
    };
    final keyProblem = code == 'ai_key_missing' || code == 'ai_key_invalid';
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          action: keyProblem
              ? SnackBarAction(
                  label: 'ไปตั้งค่า',
                  onPressed: () => context.push(AppRoutes.settings),
                )
              : null,
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    final provider = studentAnalysisProvider(_key);
    final value = ref.watch(provider);
    final roster =
        ref.watch(rosterProvider(widget.classroomId)).value ?? const [];
    final student = roster
        .where((s) => s.studentId == widget.studentId)
        .firstOrNull;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          student == null
              ? 'วิเคราะห์รายคน'
              : '${student.studentNumber}. ${student.name}',
        ),
        actions: [
          IconButton(
            tooltip: 'ทักษะและจุดอ่อน',
            icon: const Icon(Icons.insights_outlined),
            onPressed: () => context.push(
              AppRoutes.studentMastery(widget.classroomId, widget.studentId),
            ),
          ),
        ],
      ),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(provider),
        data: (a) {
          if (a == null) {
            return RefreshIndicator(
              onRefresh: () => ref.refresh(provider.future),
              child: ListView(
                children: const [
                  SizedBox(height: 48),
                  EmptyView(
                    icon: Icons.insights_outlined,
                    title: 'ยังไม่มีข้อมูลให้วิเคราะห์',
                    message:
                        'จะวิเคราะห์ได้หลังเผยแพร่ผลการบ้านที่ผูกตัวชี้วัดของห้องนี้',
                  ),
                ],
              ),
            );
          }
          return RefreshIndicator(
            onRefresh: () => ref.refresh(provider.future),
            child: ContentColumn(
              child: ListView(
                children: [
                  _TextsCard(
                    analysis: a,
                    running: _running,
                    busy: _running || _saving,
                    onRun: _runNow,
                    onEdit: () => _edit(a),
                    onApprove: _approve,
                  ),
                  const SizedBox(height: 8),
                  AnalysisItemsCard(
                    key: const ValueKey('analysis_areas'),
                    title: 'จุดที่ควรพัฒนา',
                    icon: Icons.trending_up,
                    items: a.areas,
                    emptyText: 'ไม่มีตัวชี้วัดที่ต่ำกว่า 75%',
                  ),
                  AnalysisItemsCard(
                    key: const ValueKey('analysis_strengths'),
                    title: 'จุดเด่น',
                    icon: Icons.star_outline,
                    items: a.strengths,
                    emptyText: 'ยังไม่มีตัวชี้วัดที่ถึง 75%',
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 4, 4, 16),
                    child: Text(
                      'จุดเด่นและจุดที่ควรพัฒนาคำนวณจากคะแนนล่าสุด ไม่ได้ใช้ AI',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
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

class _TextsCard extends StatelessWidget {
  const _TextsCard({
    required this.analysis,
    required this.running,
    required this.busy,
    required this.onRun,
    required this.onEdit,
    required this.onApprove,
  });

  final StudentAnalysis analysis;
  final bool running;
  final bool busy;
  final VoidCallback onRun;
  final VoidCallback onEdit;
  final VoidCallback onApprove;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final a = analysis;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'ข้อความจาก AI',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                AnalysisBadge(analysis: a),
              ],
            ),
            const SizedBox(height: 4),
            Text(_statusLine(a), style: muted),
            if (a.stale) ...[
              const SizedBox(height: 8),
              _Note(
                key: const ValueKey('analysis_stale'),
                icon: Icons.update,
                text:
                    'คะแนนเปลี่ยนหลังเขียนข้อความนี้ รอบกลางคืนจะเขียนใหม่ '
                    'หรือกด "วิเคราะห์ตอนนี้"',
              ),
            ],
            if (running) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(),
              const SizedBox(height: 4),
              Text('AI กำลังเขียน อาจใช้เวลาถึงครึ่งนาที', style: muted),
            ],
            const SizedBox(height: 12),
            if (!a.hasText)
              Text(
                a.status == AnalysisStatus.queued
                    ? 'ส่งเข้ารอบกลางคืนแล้ว ข้อความจะขึ้นภายในวันพรุ่งนี้'
                    : 'ยังไม่มีข้อความ รอบกลางคืน (หลังตี 1) จะเขียนให้ '
                          'หรือกด "วิเคราะห์ตอนนี้" หรือเขียนเองก็ได้',
              )
            else ...[
              _TextBlock(
                key: const ValueKey('teacher_text'),
                title: 'ฉบับครู',
                text: a.teacherText,
              ),
              const SizedBox(height: 12),
              _TextBlock(
                key: const ValueKey('student_text'),
                title: 'ฉบับนักเรียน',
                text: a.studentText,
                trailing: a.studentText == null
                    ? null
                    : StatusChip(
                        label: a.awaitingApproval
                            ? 'นักเรียนยังไม่เห็น'
                            : 'นักเรียนเห็นแล้ว',
                        color: a.awaitingApproval
                            ? theme.colorScheme.tertiary
                            : theme.colorScheme.primary,
                      ),
              ),
            ],
            if (a.nextSteps.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text('ขั้นต่อไป (มีแบบฝึก)', style: theme.textTheme.labelLarge),
              const SizedBox(height: 4),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  for (final s in a.nextSteps)
                    Chip(
                      avatar: const Icon(Icons.fitness_center, size: 16),
                      label: Text(s.code),
                    ),
                ],
              ),
            ],
            if (a.showsOlderShared) ...[
              const SizedBox(height: 12),
              ExpansionTile(
                key: const ValueKey('older_shared'),
                tilePadding: EdgeInsets.zero,
                title: const Text('นักเรียนยังเห็นข้อความที่แชร์ไว้ก่อนหน้า'),
                subtitle: a.sharedAt == null
                    ? null
                    : Text('แชร์เมื่อ ${formatThaiDateTime(a.sharedAt!)}'),
                children: [
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: SelectableText(a.sharedStudentText!),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (a.awaitingApproval)
                  FilledButton.icon(
                    key: const ValueKey('approve_analysis'),
                    onPressed: busy ? null : onApprove,
                    icon: const Icon(Icons.check),
                    label: const Text('อนุมัติและแชร์ให้นักเรียน'),
                  ),
                OutlinedButton.icon(
                  key: const ValueKey('edit_analysis'),
                  onPressed: busy ? null : onEdit,
                  icon: const Icon(Icons.edit_outlined),
                  label: Text(a.hasText ? 'แก้ไข' : 'เขียนเอง'),
                ),
                FilledButton.tonalIcon(
                  key: const ValueKey('run_analysis'),
                  onPressed: busy ? null : onRun,
                  icon: const Icon(Icons.auto_awesome_outlined),
                  label: const Text('วิเคราะห์ตอนนี้'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  static String _statusLine(StudentAnalysis a) {
    final parts = <String>[
      if (a.generatedAt != null)
        'เขียนเมื่อ ${formatThaiDateTime(a.generatedAt!)}'
            '${switch (a.generatedVia) {
              'batch' => ' (รอบกลางคืน)',
              'now' => ' (วิเคราะห์ตอนนี้)',
              _ => '',
            }}',
      if (a.status == AnalysisStatus.failed)
        'ครั้งล่าสุด AI เขียนไม่สำเร็จ ลองกด "วิเคราะห์ตอนนี้"',
      if (a.shared && a.sharedAt != null && !a.showsOlderShared)
        'แชร์ให้นักเรียนเมื่อ ${formatThaiDateTime(a.sharedAt!)}'
            '${a.approvedBy == null ? ' (อัตโนมัติ)' : ''}',
    ];
    return parts.isEmpty ? a.status.label : parts.join(' · ');
  }
}

class _TextBlock extends StatelessWidget {
  const _TextBlock({
    super.key,
    required this.title,
    required this.text,
    this.trailing,
  });

  final String title;
  final String? text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(child: Text(title, style: theme.textTheme.labelLarge)),
            ?trailing,
          ],
        ),
        const SizedBox(height: 4),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHigh,
            borderRadius: BorderRadius.circular(8),
          ),
          child: SelectableText(
            text ?? '(ยังไม่มี)',
            style: theme.textTheme.bodyMedium,
          ),
        ),
      ],
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: scheme.onSecondaryContainer),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(color: scheme.onSecondaryContainer),
            ),
          ),
        ],
      ),
    );
  }
}

/// What the editor returns: only the texts that changed (null = keep),
/// and whether to share the student text right away.
typedef AnalysisEdit = ({String? teacherText, String? studentText, bool share});

/// Full-screen editor of both texts. Null when cancelled or nothing to do.
Future<AnalysisEdit?> showAnalysisEditor(
  BuildContext context,
  StudentAnalysis analysis,
) => showDialog<AnalysisEdit>(
  context: context,
  builder: (context) =>
      Dialog.fullscreen(child: _AnalysisEditor(analysis: analysis)),
);

class _AnalysisEditor extends StatefulWidget {
  const _AnalysisEditor({required this.analysis});

  final StudentAnalysis analysis;

  @override
  State<_AnalysisEditor> createState() => _AnalysisEditorState();
}

class _AnalysisEditorState extends State<_AnalysisEditor> {
  final _form = GlobalKey<FormState>();
  late final _teacher = TextEditingController(
    text: widget.analysis.teacherText ?? '',
  );
  late final _student = TextEditingController(
    text: widget.analysis.studentText ?? '',
  );

  @override
  void initState() {
    super.initState();
    _student.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _teacher.dispose();
    _student.dispose();
    super.dispose();
  }

  /// Null = unchanged; an empty field is never sent (the server requires
  /// text in every field it gets).
  String? _changed(TextEditingController c, String? original) {
    final t = c.text.trim();
    if (t.isEmpty || t == (original ?? '').trim()) return null;
    return t;
  }

  void _submit({required bool share}) {
    if (!_form.currentState!.validate()) return;
    final a = widget.analysis;
    final edit = (
      teacherText: _changed(_teacher, a.teacherText),
      studentText: _changed(_student, a.studentText),
      share: share,
    );
    if (!share && edit.teacherText == null && edit.studentText == null) {
      Navigator.of(context).pop();
      return;
    }
    Navigator.of(context).pop(edit);
  }

  String? _required(String? v, String? original, String label) {
    final t = (v ?? '').trim();
    if (t.isEmpty && (original ?? '').trim().isNotEmpty) {
      return 'กรอก$label';
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.analysis;
    final harsh = _student.text.contains('อ่อน');
    return Scaffold(
      appBar: AppBar(
        leading: IconButton(
          tooltip: 'ยกเลิก',
          icon: const Icon(Icons.close),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: const Text('แก้ข้อความวิเคราะห์'),
        actions: [
          TextButton(
            key: const ValueKey('save_analysis'),
            onPressed: () => _submit(share: false),
            child: const Text('บันทึก'),
          ),
        ],
      ),
      body: Form(
        key: _form,
        child: FormColumn(
          children: [
            TextFormField(
              key: const ValueKey('teacher_text_field'),
              controller: _teacher,
              minLines: 4,
              maxLines: 12,
              maxLength: teacherTextMax,
              decoration: const InputDecoration(
                labelText: 'ฉบับครู',
                helperText: 'เห็นเฉพาะครู',
                border: OutlineInputBorder(),
                alignLabelWithHint: true,
              ),
              validator: (v) => _required(v, a.teacherText, 'ข้อความสำหรับครู'),
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('student_text_field'),
              controller: _student,
              minLines: 4,
              maxLines: 10,
              maxLength: studentTextMax,
              decoration: InputDecoration(
                labelText: 'ฉบับนักเรียน',
                helperText: harsh
                    ? 'ฉบับนักเรียนควรให้กำลังใจ ลองเลี่ยงคำว่า "อ่อน"'
                    : 'นักเรียนเห็นหลังครูอนุมัติ',
                helperMaxLines: 2,
                border: const OutlineInputBorder(),
                alignLabelWithHint: true,
              ),
              validator: (v) =>
                  _required(v, a.studentText, 'ข้อความสำหรับนักเรียน'),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              key: const ValueKey('save_and_share_analysis'),
              onPressed: _student.text.trim().isEmpty
                  ? null
                  : () => _submit(share: true),
              icon: const Icon(Icons.send_outlined),
              label: const Text('บันทึกและแชร์ให้นักเรียน'),
            ),
          ],
        ),
      ),
    );
  }
}
