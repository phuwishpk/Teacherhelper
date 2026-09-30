import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import 'exam_image_field.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// Longest prompt and option text the server takes.
const kExamMaxPrompt = 2000;
const kExamMaxOptionText = 500;

/// One exam question (DESIGN §22.2–§22.4): the prompt with one image, the
/// mcq options with text and/or an image each, the points, the master key
/// and "ห้ามสลับตัวเลือก" (with the suggestion of §22.5). With
/// [questionId] it edits that question (images upload right away); with
/// [sectionId] it adds a question at the end of that section (images
/// upload after the first save).
class ExamQuestionScreen extends ConsumerWidget {
  const ExamQuestionScreen({
    super.key,
    required this.examId,
    this.questionId,
    this.sectionId,
  }) : assert(questionId != null || sectionId != null);

  final int examId;
  final int? questionId;
  final int? sectionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(examDetailProvider(examId));
    final title = questionId == null ? 'เพิ่มข้อ' : 'แก้ไขข้อ';
    Scaffold message(String text) => Scaffold(
      appBar: AppBar(title: Text(title)),
      body: EmptyView(icon: Icons.search_off, title: text, message: ''),
    );
    return detail.when(
      skipLoadingOnRefresh: true,
      loading: () => Scaffold(
        appBar: AppBar(title: Text(title)),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: Text(title)),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(examDetailProvider(examId)),
        ),
      ),
      data: (d) {
        final qid = questionId;
        final question = qid == null ? null : d.question(qid);
        final section = question != null
            ? d.sectionOf(question.id)
            : d.section(sectionId ?? -1);
        if (section == null || (qid != null && question == null)) {
          return message(qid == null ? 'ไม่พบตอนนี้' : 'ไม่พบข้อนี้');
        }
        return _ExamQuestionForm(
          key: ValueKey('exam_question_form_${question?.id ?? 'new'}'),
          detail: d,
          section: section,
          question: question,
        );
      },
    );
  }
}

class _ExamQuestionForm extends ConsumerStatefulWidget {
  const _ExamQuestionForm({
    super.key,
    required this.detail,
    required this.section,
    this.question,
  });

  final ExamDetail detail;
  final ExamSection section;
  final ExamQuestion? question;

  @override
  ConsumerState<_ExamQuestionForm> createState() => _ExamQuestionFormState();
}

class _ExamQuestionFormState extends ConsumerState<_ExamQuestionForm> {
  final _formKey = GlobalKey<FormState>();
  late final _prompt = TextEditingController(
    text: widget.question?.promptText ?? '',
  );
  late final _points = TextEditingController(
    text: formatPoints(
      widget.question?.maxPoints ?? widget.section.defaultPoints,
    ),
  );
  late final List<TextEditingController> _optionTexts = [
    for (var p = 1; p <= widget.section.choiceCount; p++)
      TextEditingController(text: _optionOf(p)?.text ?? ''),
  ];
  late final _values = TextEditingController(
    text: widget.question?.key?.values.join(', ') ?? '',
  );
  late final Set<int> _selected = {...?widget.question?.key?.options};
  late bool _lock = widget.question?.lockOptions ?? false;

  /// New images of a question not saved yet (position 0 = the prompt).
  final Map<int, PickedDocument> _pending = {};
  bool _busy = false;
  String? _error;

  ExamSection get _section => widget.section;
  ExamQuestion? get _question => widget.question;
  bool get _creating => _question == null;
  bool get _mcq => _section.type == ExamSectionType.mcq;
  bool get _locked => widget.detail.structureLocked;

  ExamOption? _optionOf(int position) =>
      widget.question?.options.where((o) => o.position == position).firstOrNull;

  ExamDetailNotifier get _notifier =>
      ref.read(examDetailProvider(widget.detail.exam.id).notifier);

  @override
  void initState() {
    super.initState();
    for (final c in _optionTexts) {
      c.addListener(_onOptionText);
    }
  }

  @override
  void dispose() {
    _prompt.dispose();
    _points.dispose();
    _values.dispose();
    for (final c in _optionTexts) {
      c.dispose();
    }
    super.dispose();
  }

  bool _suggestedBefore = false;

  void _onOptionText() {
    final now = _lockSuggested;
    if (now != _suggestedBefore) setState(() => _suggestedBefore = now);
  }

  /// "ห้ามสลับตัวเลือก" is suggested by the server or by the texts typed
  /// now ("ถูกทุกข้อ", "ทั้ง ก และ ข"…), until the teacher turns it on.
  bool get _lockSuggested =>
      _mcq &&
      !_lock &&
      ((_question?.lockOptionsSuggested ?? false) ||
          LockOptionsDetector.suggests(_optionTexts.map((c) => c.text)));

  ExamKey? _key() {
    if (_section.type == ExamSectionType.numeric) {
      final parsed = NumericAnswer.parseList(
        _values.text,
        _section.numeric ?? const NumericSpec(digits: 1),
      );
      return parsed.values.isEmpty ? null : ExamKey(values: parsed.values);
    }
    return _selected.isEmpty
        ? null
        : ExamKey(options: _selected.toList()..sort());
  }

  ExamQuestionDraft _draft({bool approve = false}) => ExamQuestionDraft(
    type: _section.type,
    promptText: _prompt.text,
    options: _mcq ? [for (final c in _optionTexts) c.text] : null,
    maxPoints: double.tryParse(_points.text.trim()),
    key: _key(),
    lockOptions: _mcq && (!_locked || _creating) ? _lock : null,
    approve: approve,
  );

  Future<void> _save({bool approve = false}) async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final q = _question;
      if (q != null) {
        await _notifier.updateQuestion(q.id, _draft(approve: approve));
      } else {
        final created = await _notifier.addQuestion(_section.id, _draft());
        await _uploadPending(created);
      }
      if (!mounted) return;
      showMessage(
        context,
        approve ? 'บันทึกและอนุมัติข้อนี้แล้ว' : 'บันทึกข้อแล้ว',
      );
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// The images picked before the question existed.
  Future<void> _uploadPending(ExamQuestion created) async {
    for (final entry in _pending.entries) {
      if (entry.key == 0) {
        await _notifier.setQuestionImage(created.id, entry.value);
      } else {
        final option = created.options
            .where((o) => o.position == entry.key)
            .firstOrNull;
        if (option != null) {
          await _notifier.setOptionImage(option.id, entry.value);
        }
      }
    }
  }

  /// Picks or removes an image: pending while creating, uploaded at once
  /// on a saved question.
  Future<void> _setImage(int position, PickedDocument? file) async {
    final q = _question;
    if (q == null) {
      setState(() {
        if (file == null) {
          _pending.remove(position);
        } else {
          _pending[position] = file;
        }
      });
      return;
    }
    setState(() => _busy = true);
    try {
      if (position == 0) {
        await _notifier.setQuestionImage(q.id, file);
      } else {
        final option = _optionOf(position);
        if (option == null) return;
        await _notifier.setOptionImage(option.id, file);
      }
      if (mounted) {
        showMessage(context, file == null ? 'ลบภาพแล้ว' : 'อัปโหลดภาพแล้ว');
      }
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    final q = _question;
    if (q == null) return;
    final ok = await confirm(
      context,
      title: 'ลบข้อ ${q.position}?',
      message: 'โจทย์ ภาพ และเฉลยของข้อนี้จะถูกลบ เลขข้อถัดไปเลื่อนขึ้น',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await _notifier.deleteQuestion(q.id);
      if (!mounted) return;
      showMessage(context, 'ลบข้อแล้ว');
      context.pop();
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        showMessage(context, apiErrorMessage(e));
      }
    }
  }

  ExamImageKey? _savedImage(int position) {
    final q = _question;
    if (q == null) return null;
    if (position == 0) {
      return q.hasPromptImage ? (option: false, id: q.id) : null;
    }
    final o = _optionOf(position);
    return o != null && o.hasImage ? (option: true, id: o.id) : null;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = _question;
    final number = q?.position ?? ((_section.lastNumber ?? 0) + 1);
    final header = [_section.heading, _section.typeSummary].join(' · ');
    return Scaffold(
      appBar: AppBar(
        title: Text(_creating ? 'เพิ่มข้อ' : 'ข้อ $number'),
        actions: [
          if (q != null && !_locked)
            IconButton(
              key: const ValueKey('exam_question_delete'),
              tooltip: 'ลบข้อ',
              icon: const Icon(Icons.delete_outline),
              onPressed: _busy ? null : _delete,
            ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          maxWidth: 640,
          children: [
            Text(header, style: theme.textTheme.bodySmall),
            if (q != null && !q.approved) ...[
              const SizedBox(height: 8),
              Text(
                q.blank
                    ? 'ข้อว่าง ยังไม่ได้กรอก: ${widget.detail.isManual ? 'ใส่โจทย์' : 'ใส่เฉลย'}'
                          'แล้วข้อนี้จะอนุมัติเอง'
                    : 'ข้อนี้ยังไม่อนุมัติ ตรวจโจทย์และเฉลยแล้วกด "บันทึกและอนุมัติ"',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
            const SizedBox(height: 12),
            TextFormField(
              key: const ValueKey('exam_question_prompt'),
              controller: _prompt,
              decoration: const InputDecoration(
                labelText: 'โจทย์',
                hintText: 'พิมพ์สูตรเป็นข้อความ เช่น x^2 + 3x = 10',
                alignLabelWithHint: true,
              ),
              minLines: 2,
              maxLines: 8,
              maxLength: kExamMaxPrompt,
            ),
            ExamImageField(
              label: 'ภาพโจทย์',
              fieldKey: 'exam_prompt_image',
              savedKey: _savedImage(0),
              pending: _pending[0],
              enabled: !_busy,
              onPick: (f) => _setImage(0, f),
              onRemove: () => _setImage(0, null),
            ),
            if (_mcq) ...[
              const SizedBox(height: 16),
              Text('ตัวเลือก', style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),
              Text(
                'แต่ละตัวเลือกมีข้อความ ภาพ หรือทั้งสองอย่าง ตัวเลือกที่ว่างพิมพ์แค่ป้าย',
                style: theme.textTheme.bodySmall,
              ),
              for (var p = 1; p <= _section.choiceCount; p++)
                _OptionRow(
                  position: p,
                  controller: _optionTexts[p - 1],
                  image: ExamImageField(
                    label: 'ภาพ',
                    fieldKey: 'exam_option_image_$p',
                    compact: true,
                    savedKey: _savedImage(p),
                    pending: _pending[p],
                    enabled: !_busy,
                    onPick: (f) => _setImage(p, f),
                    onRemove: () => _setImage(p, null),
                  ),
                ),
              const SizedBox(height: 8),
              SwitchListTile(
                key: const ValueKey('exam_question_lock'),
                contentPadding: EdgeInsets.zero,
                title: const Text('ห้ามสลับตัวเลือก'),
                subtitle: Text(
                  _locked && !_creating
                      ? 'พิมพ์แล้ว เปลี่ยนได้หลังปลดล็อกโครงสร้าง'
                      : 'ทุกชุดพิมพ์ตัวเลือกข้อนี้ตามลำดับเดิม',
                ),
                value: _lock,
                onChanged: _locked && !_creating
                    ? null
                    : (v) => setState(() => _lock = v),
              ),
              if (_lockSuggested && !(_locked && !_creating))
                Card(
                  key: const ValueKey('exam_lock_suggestion'),
                  color: theme.colorScheme.tertiaryContainer,
                  child: ListTile(
                    leading: const Icon(Icons.lightbulb_outline),
                    title: const Text('แนะนำ: ห้ามสลับตัวเลือก'),
                    subtitle: const Text(
                      'มีตัวเลือกที่อ้างถึงตัวเลือกอื่น เช่น "ถูกทุกข้อ" '
                      'หรือ "ทั้ง ก และ ข" ถ้าสลับจะอ่านผิดความหมาย',
                    ),
                    trailing: TextButton(
                      onPressed: () => setState(() => _lock = true),
                      child: const Text('ห้ามสลับ'),
                    ),
                  ),
                ),
            ],
            const SizedBox(height: 16),
            Text('เฉลย', style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            _keyField(theme),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('exam_question_points'),
              controller: _points,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: const InputDecoration(labelText: 'คะแนนเต็มของข้อ'),
              validator: (v) {
                final n = double.tryParse(v?.trim() ?? '');
                return n == null || n <= 0 || n > 100
                    ? 'คะแนน มากกว่า 0 และไม่เกิน 100'
                    : null;
              },
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('exam_question_save'),
              onPressed: _busy ? null : () => _save(),
              child: Text(_creating ? 'เพิ่มข้อ' : 'บันทึก'),
            ),
            if (q != null && !q.approved) ...[
              const SizedBox(height: 8),
              OutlinedButton.icon(
                key: const ValueKey('exam_question_approve'),
                onPressed: _busy ? null : () => _save(approve: true),
                icon: const Icon(Icons.check),
                label: const Text('บันทึกและอนุมัติข้อนี้'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _keyField(ThemeData theme) {
    switch (_section.type) {
      case ExamSectionType.numeric:
        final spec = _section.numeric ?? const NumericSpec(digits: 1);
        return TextFormField(
          key: const ValueKey('exam_question_values'),
          controller: _values,
          keyboardType: const TextInputType.numberWithOptions(
            signed: true,
            decimal: true,
          ),
          decoration: InputDecoration(
            labelText: 'ค่าที่ยอมรับ',
            hintText: 'เช่น 0.5 หรือหลายค่าคั่นด้วยจุลภาค 0.33, 0.333',
            helperText: 'ช่องตัวเลข ${spec.summary}',
          ),
          validator: (v) => NumericAnswer.parseList(v ?? '', spec).error,
        );
      case ExamSectionType.trueFalse:
      case ExamSectionType.mcq:
        final single = _section.type == ExamSectionType.trueFalse;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                for (var p = 1; p <= _section.choiceCount; p++)
                  FilterChip(
                    key: ValueKey('exam_key_choice_$p'),
                    label: Text(examChoiceLabel(_section.type, p)),
                    selected: _selected.contains(p),
                    onSelected: (on) => setState(() {
                      if (single) _selected.clear();
                      if (on) {
                        _selected.add(p);
                      } else {
                        _selected.remove(p);
                      }
                    }),
                  ),
              ],
            ),
            Text(
              single
                  ? 'เลือกคำตอบที่ถูก'
                  : 'เลือกได้หลายตัว (ฝนตัวใดตัวหนึ่งที่เลือกได้คะแนนเต็ม)',
              style: theme.textTheme.bodySmall,
            ),
          ],
        );
    }
  }
}

class _OptionRow extends StatelessWidget {
  const _OptionRow({
    required this.position,
    required this.controller,
    required this.image,
  });

  final int position;
  final TextEditingController controller;
  final Widget image;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: CircleAvatar(
              radius: 14,
              child: Text(examOptionLabel(position)),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextFormField(
                  key: ValueKey('exam_option_text_$position'),
                  controller: controller,
                  decoration: InputDecoration(
                    labelText: 'ตัวเลือก ${examOptionLabel(position)}',
                  ),
                  maxLength: kExamMaxOptionText,
                  buildCounter:
                      (
                        _, {
                        required currentLength,
                        required isFocused,
                        maxLength,
                      }) => null,
                ),
                image,
              ],
            ),
          ),
        ],
      ),
    );
  }
}
