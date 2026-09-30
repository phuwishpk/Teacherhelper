import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'answer_key.dart';
import 'assignments_providers.dart';
import 'question.dart';
import 'skills_picker.dart';

const _mcqOptions = ['A', 'B', 'C', 'D'];

/// Route target of `/assignments/:id/questions/:qid/edit`. Uses the question
/// handed over as route `extra` when there is one; otherwise (deep link,
/// process restore, a plain `context.go`) it loads the question by id from
/// the assignment, so saving always PATCHes `/questions/{qid}` and never
/// creates a new question.
class QuestionEditScreen extends ConsumerWidget {
  const QuestionEditScreen({
    super.key,
    required this.assignmentId,
    required this.questionId,
    this.initial,
    this.subjectId,
    this.gradeLevel,
    this.freeform,
  });

  final int assignmentId;
  final int questionId;
  final Question? initial;
  final int? subjectId;
  final int? gradeLevel;
  final bool? freeform;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (initial case final q? when q.id == questionId) {
      return QuestionFormScreen(
        assignmentId: assignmentId,
        existing: q,
        subjectId: subjectId,
        gradeLevel: gradeLevel,
        freeform: freeform,
      );
    }
    Widget message(Widget body) => Scaffold(
      appBar: AppBar(title: const Text('แก้ไขข้อ')),
      body: body,
    );
    final detail = ref.watch(assignmentDetailProvider(assignmentId));
    return detail.when(
      skipLoadingOnRefresh: true,
      data: (a) {
        final q = a.questions.where((q) => q.id == questionId).firstOrNull;
        if (q == null) {
          return message(const ErrorView(message: 'ไม่พบคำถามนี้ในการบ้าน'));
        }
        final classroom = ref.watch(classroomProvider(a.classroomId)).value;
        return QuestionFormScreen(
          assignmentId: assignmentId,
          existing: q,
          subjectId: subjectId ?? a.subjectId,
          gradeLevel: gradeLevel ?? classroom?.gradeLevel,
          freeform: freeform ?? a.isFreeform,
        );
      },
      loading: () => message(const Center(child: CircularProgressIndicator())),
      error: (e, _) => message(
        ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(assignmentDetailProvider(assignmentId)),
        ),
      ),
    );
  }
}

/// Add or edit one question of an assignment, including its answer key
/// (DESIGN §8.3), the model answer of an open question (§19.5) and skill
/// tags. In a freeform assignment the answer may stay empty until it is
/// read from a document or drafted by AI; approving the key checks it.
class QuestionFormScreen extends ConsumerStatefulWidget {
  const QuestionFormScreen({
    super.key,
    required this.assignmentId,
    this.existing,
    this.subjectId,
    this.gradeLevel,
    this.freeform,
  });

  final int assignmentId;
  final Question? existing;
  final int? subjectId;
  final int? gradeLevel;

  /// Null: taken from the loaded assignment (a deep link without extras).
  final bool? freeform;

  @override
  ConsumerState<QuestionFormScreen> createState() => _QuestionFormScreenState();
}

class _QuestionFormScreenState extends ConsumerState<QuestionFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late QuestionType _type = widget.existing?.type ?? QuestionType.mcq;
  late final _prompt = TextEditingController(
    text: widget.existing?.promptText ?? '',
  );
  late final _points = TextEditingController(
    text: _numText(widget.existing?.maxPoints ?? 1),
  );
  late final _lines = TextEditingController(
    text: '${widget.existing?.answerLines ?? 3}',
  );
  late bool _numeric = widget.existing?.isNumeric ?? false;
  late String _matchMode = widget.existing?.matchMode ?? 'flexible';
  late String? _mcqCorrect = _readMcq(widget.existing);
  late final _accepted = TextEditingController(
    text: _readAccepted(widget.existing).join('\n'),
  );
  late final _numericValue = TextEditingController(
    text: _readNumeric(widget.existing, 'value'),
  );
  late final _absTol = TextEditingController(
    text: _readNumeric(widget.existing, 'abs_tol'),
  );
  late final _steps = TextEditingController(
    text: widget.existing?.referenceSteps.join('\n') ?? '',
  );
  late final _modelAnswer = TextEditingController(
    text: widget.existing?.modelAnswer ?? '',
  );
  late List<Skill> _skills = List.of(widget.existing?.skills ?? const []);
  bool _busy = false;
  String? _error;

  static String _numText(double v) =>
      v == v.roundToDouble() ? v.toInt().toString() : v.toString();

  static String? _readMcq(Question? q) => q?.type == QuestionType.mcq
      ? (q?.answerKey?['correct'] as String?)
      : null;

  static List<String> _readAccepted(Question? q) {
    final key = q?.answerKey;
    if (key == null) return const [];
    final Object? source;
    if (q!.type == QuestionType.showWork) {
      source = (key['final'] as Map?)?['accepted'];
    } else {
      source = key['accepted'];
    }
    return ((source as List?) ?? const []).map((e) => e.toString()).toList();
  }

  static String _readNumeric(Question? q, String field) {
    final key = q?.answerKey;
    if (key == null) return field == 'abs_tol' ? '0' : '';
    final Map? holder;
    if (q!.type == QuestionType.showWork) {
      holder = key['final'] as Map?;
    } else {
      holder = key;
    }
    final numeric = holder?['numeric'] as Map?;
    final v = numeric?[field];
    if (v == null) return field == 'abs_tol' ? '0' : '';
    return _numText((v as num).toDouble());
  }

  @override
  void dispose() {
    for (final c in [
      _prompt,
      _points,
      _lines,
      _accepted,
      _numericValue,
      _absTol,
      _steps,
      _modelAnswer,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  /// Set in build: from the route extra, else from the loaded assignment.
  bool _freeform = false;

  /// Nothing typed for the answer yet (allowed in a freeform assignment).
  bool get _answerEmpty => switch (_type) {
    QuestionType.mcq => _mcqCorrect == null,
    QuestionType.short || QuestionType.showWork =>
      AnswerKey.splitAccepted(_accepted.text).isEmpty &&
          AnswerKey.splitLines(_steps.text).isEmpty,
    QuestionType.open => true,
  };

  Map<String, dynamic>? _buildAnswerKey() {
    if (_type != QuestionType.open && _freeform && _answerEmpty) return null;
    final numeric = _numeric
        ? double.tryParse(_numericValue.text.trim())
        : null;
    final tol = double.tryParse(_absTol.text.trim()) ?? 0;
    return switch (_type) {
      QuestionType.mcq => AnswerKey.mcq(_mcqCorrect!),
      QuestionType.short => AnswerKey.short(
        accepted: AnswerKey.splitAccepted(_accepted.text),
        numericValue: numeric,
        absTol: tol,
      ),
      QuestionType.showWork => AnswerKey.showWork(
        finalAccepted: AnswerKey.splitAccepted(_accepted.text),
        numericValue: numeric,
        absTol: tol,
        referenceSteps: AnswerKey.splitLines(_steps.text),
      ),
      QuestionType.open => null,
    };
  }

  String? _validateAnswerKey() {
    if (_type != QuestionType.open && _freeform && _answerEmpty) return null;
    switch (_type) {
      case QuestionType.mcq:
        return _mcqCorrect == null ? 'เลือกข้อที่ถูก' : null;
      case QuestionType.short:
      case QuestionType.showWork:
        if (AnswerKey.splitAccepted(_accepted.text).isEmpty) {
          return 'กรอกคำตอบที่ยอมรับอย่างน้อย 1 แบบ';
        }
        if (_numeric && double.tryParse(_numericValue.text.trim()) == null) {
          return 'กรอกค่าตัวเลขของคำตอบ';
        }
        return null;
      case QuestionType.open:
        return null;
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final keyError = _validateAnswerKey();
    if (keyError != null) {
      setState(() => _error = keyError);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final draft = QuestionDraft(
      type: _type,
      promptText: _prompt.text.trim(),
      maxPoints: double.parse(_points.text.trim()),
      answerLines: _type.needsRubric ? int.parse(_lines.text.trim()) : null,
      isNumeric: _type.canBeNumeric && _numeric,
      matchMode: _type == QuestionType.short ? _matchMode : 'flexible',
      answerKey: _buildAnswerKey(),
      skillIds: _skills.map((s) => s.id).toList(),
      position: widget.existing?.position,
      modelAnswer: _type == QuestionType.open
          ? (_modelAnswer.text.trim().isEmpty ? null : _modelAnswer.text.trim())
          : null,
    );
    final notifier = ref.read(
      assignmentDetailProvider(widget.assignmentId).notifier,
    );
    try {
      if (widget.existing case final q?) {
        await notifier.updateQuestion(q.id, draft);
      } else {
        await notifier.addQuestion(draft);
      }
      if (!mounted) return;
      showMessage(context, 'บันทึกข้อแล้ว');
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickSkills() async {
    final picked = await showSkillsPicker(
      context,
      subjectId: widget.subjectId,
      grade: widget.gradeLevel,
      selected: _skills,
    );
    if (picked != null) setState(() => _skills = picked);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final editing = widget.existing != null;
    _freeform =
        widget.freeform ??
        ref
            .watch(assignmentDetailProvider(widget.assignmentId))
            .value
            ?.isFreeform ??
        false;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          editing ? 'แก้ไขข้อ ${widget.existing!.position}' : 'เพิ่มคำถาม',
        ),
        actions: [
          if (editing && widget.existing!.type.needsRubric)
            TextButton.icon(
              onPressed: () => context.push(
                AppRoutes.rubric(widget.assignmentId, widget.existing!.id),
              ),
              icon: const Icon(Icons.rule),
              label: const Text('Rubric'),
            ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          maxWidth: 640,
          children: [
            Text('ประเภทคำถาม', style: theme.textTheme.labelLarge),
            const SizedBox(height: 8),
            SegmentedButton<QuestionType>(
              showSelectedIcon: false,
              segments: [
                for (final t in QuestionType.values)
                  ButtonSegment(value: t, label: Text(t.label)),
              ],
              selected: {_type},
              onSelectionChanged: (s) => setState(() => _type = s.first),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _prompt,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'โจทย์',
                alignLabelWithHint: true,
                hintText:
                    'พิมพ์โจทย์ตามที่จะปรากฏบนใบงาน (ปรนัยให้ใส่ตัวเลือกไว้ในโจทย์)',
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกโจทย์' : null,
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _points,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(labelText: 'คะแนนเต็ม'),
                    validator: (v) {
                      final n = double.tryParse(v?.trim() ?? '');
                      return (n == null || n <= 0) ? 'ต้องมากกว่า 0' : null;
                    },
                  ),
                ),
                if (_type.needsRubric) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _lines,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'จำนวนบรรทัดคำตอบ',
                      ),
                      validator: (v) {
                        final n = int.tryParse(v?.trim() ?? '');
                        return (n == null || n < 1 || n > 20)
                            ? '1–20 บรรทัด'
                            : null;
                      },
                    ),
                  ),
                ],
              ],
            ),
            const SizedBox(height: 16),
            Text('เฉลย', style: theme.textTheme.titleMedium),
            if (_freeform && _type != QuestionType.open) ...[
              const SizedBox(height: 4),
              Text(
                'เว้นว่างได้ ถ้าจะให้ AI อ่านจากรูปหรือไฟล์เฉลย หรือร่างให้ทีหลัง '
                '(ต้องครบก่อนอนุมัติเฉลย)',
                style: theme.textTheme.bodySmall,
              ),
            ],
            const SizedBox(height: 8),
            ..._answerKeyFields(theme),
            const SizedBox(height: 16),
            Text('ตัวชี้วัด / ทักษะ', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final s in _skills)
                  InputChip(
                    label: Text(s.code),
                    tooltip: s.name,
                    onDeleted: () => setState(() => _skills.remove(s)),
                  ),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 18),
                  label: const Text('เลือกทักษะ'),
                  onPressed: _pickSkills,
                ),
              ],
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: Text(editing ? 'บันทึก' : 'เพิ่มข้อ'),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _answerKeyFields(ThemeData theme) {
    switch (_type) {
      case QuestionType.mcq:
        return [
          Wrap(
            spacing: 8,
            children: [
              for (final o in _mcqOptions)
                ChoiceChip(
                  label: Text(o),
                  selected: _mcqCorrect == o,
                  onSelected: (_) => setState(() => _mcqCorrect = o),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text('นักเรียนฝนวงกลม A–D บนใบงาน', style: theme.textTheme.bodySmall),
        ];
      case QuestionType.short:
      case QuestionType.showWork:
        return [
          TextFormField(
            controller: _accepted,
            minLines: 2,
            maxLines: 6,
            keyboardType: TextInputType.multiline,
            decoration: InputDecoration(
              labelText: _type == QuestionType.showWork
                  ? 'คำตอบสุดท้ายที่ยอมรับ (บรรทัดละคำตอบ)'
                  : 'คำตอบที่ยอมรับ (บรรทัดละคำตอบ)',
              alignLabelWithHint: true,
              hintText: _type == QuestionType.showWork
                  ? 'x = 5\nx=5'
                  : 'กรุงเทพมหานคร\nกรุงเทพฯ',
              helperText:
                  'ขึ้นบรรทัดใหม่เพื่อเพิ่มคำตอบ (ใส่จุลภาคในคำตอบได้ เช่น 1,000)',
              helperMaxLines: 2,
            ),
          ),
          const SizedBox(height: 8),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('คำตอบเป็นตัวเลข'),
            subtitle: const Text('ให้โมเดลอ่านตัวเลขในเครื่องช่วยตรวจซ้ำ'),
            value: _numeric,
            onChanged: (v) => setState(() => _numeric = v),
          ),
          if (_numeric)
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    controller: _numericValue,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                      signed: true,
                    ),
                    decoration: const InputDecoration(labelText: 'ค่าตัวเลข'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    controller: _absTol,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'คลาดเคลื่อนได้ ±',
                    ),
                  ),
                ),
              ],
            ),
          if (_type == QuestionType.short) ...[
            const SizedBox(height: 8),
            Text('การเทียบคำตอบ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'flexible', label: Text('ยืดหยุ่น')),
                ButtonSegment(value: 'exact', label: Text('ตรงตัว (สะกดคำ)')),
              ],
              selected: {_matchMode},
              onSelectionChanged: (s) => setState(() => _matchMode = s.first),
            ),
          ],
          if (_type == QuestionType.showWork) ...[
            const SizedBox(height: 12),
            TextFormField(
              controller: _steps,
              minLines: 3,
              maxLines: 8,
              decoration: const InputDecoration(
                labelText: 'ขั้นตอนอ้างอิง (บรรทัดละขั้น)',
                alignLabelWithHint: true,
                hintText: '3x + 5 = 20\n3x = 15\nx = 5',
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'ข้อแสดงวิธีทำต้องอนุมัติ rubric ก่อนสร้าง layout (ทำได้หลังบันทึกข้อ)',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ];
      case QuestionType.open:
        return [
          Card(
            color: theme.colorScheme.surfaceContainerHighest,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Text(
                _freeform
                    ? 'ข้ออัตนัยไม่มีเฉลยตายตัว ระบบตรวจตาม rubric '
                          'หลังบันทึกข้อแล้วให้ AI ร่าง rubric แล้วแก้และอนุมัติก่อนอนุมัติเฉลย'
                    : 'ข้ออัตนัยไม่มีเฉลยตายตัว ระบบตรวจตาม rubric '
                          'หลังบันทึกข้อแล้วให้ AI ร่าง rubric แล้วแก้และอนุมัติก่อนพิมพ์ใบงาน',
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _modelAnswer,
            minLines: 3,
            maxLines: 10,
            maxLength: 4000,
            keyboardType: TextInputType.multiline,
            decoration: const InputDecoration(
              labelText: 'คำตอบตัวอย่างของครู (ไม่บังคับ)',
              alignLabelWithHint: true,
              hintText: 'คำตอบที่ดี หรือประเด็นสำคัญที่ควรมี',
              helperText:
                  'AI ใช้เป็นข้อมูลตั้งต้นเมื่อร่าง rubric และใช้อ้างอิงตอนตรวจ '
                  'คะแนนยังตัดสินตาม rubric',
              helperMaxLines: 3,
            ),
          ),
        ];
    }
  }
}
