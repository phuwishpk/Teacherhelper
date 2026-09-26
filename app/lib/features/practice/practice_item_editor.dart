import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key.dart';
import 'practice_models.dart';
import 'practice_repository.dart';

/// Opens the editor of a bank item; resolves to the saved item, or null.
Future<PracticeItem?> showPracticeItemEditor(
  BuildContext context,
  PracticeItem item,
) => Navigator.of(context).push<PracticeItem>(
  MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => PracticeItemEditor(item: item),
  ),
);

/// Edit an AI draft (or an approved item) before students see it: prompt,
/// choices, answer key and explanation (DESIGN §14.1 "ครูแก้และอนุมัติ").
class PracticeItemEditor extends ConsumerStatefulWidget {
  const PracticeItemEditor({super.key, required this.item});

  final PracticeItem item;

  @override
  ConsumerState<PracticeItemEditor> createState() => _PracticeItemEditorState();
}

class _PracticeItemEditorState extends ConsumerState<PracticeItemEditor> {
  final _form = GlobalKey<FormState>();
  late final _prompt = TextEditingController(text: widget.item.promptText);
  late final _accepted = TextEditingController(
    text: widget.item.acceptedAnswers.join('\n'),
  );
  late final _value = TextEditingController(
    text: _num(widget.item.numericValue),
  );
  late final _tolerance = TextEditingController(
    text: _num(widget.item.numericTolerance),
  );
  late final _explanation = TextEditingController(
    text: widget.item.explanation,
  );
  late final List<TextEditingController> _options = [
    for (final o in widget.item.options) TextEditingController(text: o.text),
  ];
  late String? _correct = widget.item.correctOption;
  bool _saving = false;

  PracticeAnswerType get _type => widget.item.answerType;

  static String _num(double? v) {
    if (v == null) return '';
    return v == v.roundToDouble() ? v.toInt().toString() : v.toString();
  }

  @override
  void initState() {
    super.initState();
    if (_type == PracticeAnswerType.mcq) {
      while (_options.length < 2) {
        _options.add(TextEditingController());
      }
    }
  }

  @override
  void dispose() {
    for (final c in [
      _prompt,
      _accepted,
      _value,
      _tolerance,
      _explanation,
      ..._options,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  List<PracticeOption> get _optionValues => [
    for (var i = 0; i < _options.length; i++)
      PracticeOption(
        key: PracticeOption.letterFor(i),
        text: _options[i].text.trim(),
      ),
  ];

  /// Removes choice [i]; the letters after it move up, and so does the
  /// correct answer when it was one of them.
  void _removeOption(int i) {
    final correctIndex = [
      for (var j = 0; j < _options.length; j++) PracticeOption.letterFor(j),
    ].indexOf(_correct ?? '');
    _options.removeAt(i).dispose();
    if (correctIndex == i) {
      _correct = null;
    } else if (correctIndex > i) {
      _correct = PracticeOption.letterFor(correctIndex - 1);
    }
  }

  Map<String, dynamic> _answerKey() => switch (_type) {
    PracticeAnswerType.mcq => AnswerKey.mcq(_correct!),
    PracticeAnswerType.short => AnswerKey.short(
      accepted: AnswerKey.splitAccepted(_accepted.text),
    ),
    PracticeAnswerType.numeric => AnswerKey.short(
      accepted: AnswerKey.splitAccepted(_accepted.text),
      numericValue: double.tryParse(_value.text.trim()),
      absTol: double.tryParse(_tolerance.text.trim()) ?? 0,
    ),
  };

  String? _checkAnswer() {
    switch (_type) {
      case PracticeAnswerType.mcq:
        if (_optionValues.where((o) => o.text.isNotEmpty).length < 2) {
          return 'ต้องมีตัวเลือกอย่างน้อย 2 ข้อ';
        }
        if (_optionValues.any((o) => o.text.isEmpty)) {
          return 'ลบตัวเลือกที่ว่างออก';
        }
        if (_correct == null || !_optionValues.any((o) => o.key == _correct)) {
          return 'เลือกคำตอบที่ถูก';
        }
      case PracticeAnswerType.short:
        if (AnswerKey.splitAccepted(_accepted.text).isEmpty) {
          return 'ใส่คำตอบที่ยอมรับอย่างน้อย 1 แบบ';
        }
      case PracticeAnswerType.numeric:
        if (double.tryParse(_value.text.trim()) == null &&
            AnswerKey.splitAccepted(_accepted.text).isEmpty) {
          return 'ใส่ค่าตัวเลขที่ถูก หรือคำตอบที่ยอมรับ';
        }
    }
    return null;
  }

  Future<void> _save({required bool approve}) async {
    if (!_form.currentState!.validate()) return;
    final problem = _checkAnswer();
    if (problem != null) {
      showMessage(context, problem);
      return;
    }
    setState(() => _saving = true);
    try {
      final saved = await ref
          .read(practiceBankRepositoryProvider)
          .update(
            widget.item.id,
            PracticeItemPatch(
              promptText: _prompt.text.trim(),
              options: _type == PracticeAnswerType.mcq ? _optionValues : null,
              answerKey: _answerKey(),
              explanation: _explanation.text.trim(),
              status: approve ? PracticeItemStatus.approved : null,
            ),
          );
      if (mounted) Navigator.of(context).pop(saved);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final theme = Theme.of(context);
    String? required(String? v) =>
        (v == null || v.trim().isEmpty) ? 'ต้องกรอก' : null;
    return Scaffold(
      appBar: AppBar(
        title: Text('แก้ไขข้อฝึก (${item.answerType.label})'),
        actions: [
          TextButton(
            onPressed: _saving ? null : () => _save(approve: false),
            child: const Text('บันทึก'),
          ),
        ],
      ),
      body: Form(
        key: _form,
        child: FormColumn(
          children: [
            Text(
              '${item.skill.code} ${item.skill.name}',
              style: theme.textTheme.labelLarge,
            ),
            const SizedBox(height: 12),
            TextFormField(
              key: const ValueKey('editor_prompt'),
              controller: _prompt,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(labelText: 'โจทย์'),
              validator: required,
            ),
            const SizedBox(height: 16),
            if (_type == PracticeAnswerType.mcq) ...[
              Text(
                'ตัวเลือก (แตะวงกลมเพื่อเลือกข้อที่ถูก)',
                style: theme.textTheme.labelLarge,
              ),
              const SizedBox(height: 8),
              for (var i = 0; i < _options.length; i++)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      IconButton(
                        tooltip: 'ข้อที่ถูก',
                        onPressed: () => setState(
                          () => _correct = PracticeOption.letterFor(i),
                        ),
                        icon: Icon(
                          _correct == PracticeOption.letterFor(i)
                              ? Icons.check_circle
                              : Icons.radio_button_unchecked,
                          color: _correct == PracticeOption.letterFor(i)
                              ? Colors.green.shade700
                              : null,
                        ),
                      ),
                      Expanded(
                        child: TextFormField(
                          controller: _options[i],
                          decoration: InputDecoration(
                            labelText:
                                'ตัวเลือก ${PracticeOption.letterFor(i)}',
                          ),
                        ),
                      ),
                      if (_options.length > 2)
                        IconButton(
                          tooltip: 'ลบตัวเลือก',
                          onPressed: () => setState(() => _removeOption(i)),
                          icon: const Icon(Icons.remove_circle_outline),
                        ),
                    ],
                  ),
                ),
              if (_options.length < 6)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () =>
                        setState(() => _options.add(TextEditingController())),
                    icon: const Icon(Icons.add),
                    label: const Text('เพิ่มตัวเลือก'),
                  ),
                ),
            ] else ...[
              if (_type == PracticeAnswerType.numeric) ...[
                Row(
                  children: [
                    Expanded(
                      child: TextFormField(
                        controller: _value,
                        keyboardType: const TextInputType.numberWithOptions(
                          signed: true,
                          decimal: true,
                        ),
                        decoration: const InputDecoration(
                          labelText: 'ค่าที่ถูก',
                        ),
                        validator: (v) =>
                            v != null &&
                                v.trim().isNotEmpty &&
                                double.tryParse(v.trim()) == null
                            ? 'ต้องเป็นตัวเลข'
                            : null,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextFormField(
                        controller: _tolerance,
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        decoration: const InputDecoration(
                          labelText: 'คลาดเคลื่อนได้ ±',
                        ),
                        validator: (v) =>
                            v != null &&
                                v.trim().isNotEmpty &&
                                (double.tryParse(v.trim()) ?? -1) < 0
                            ? 'ต้องเป็นตัวเลข ≥ 0'
                            : null,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
              ],
              TextFormField(
                key: const ValueKey('editor_accepted'),
                controller: _accepted,
                minLines: 1,
                maxLines: 5,
                decoration: const InputDecoration(
                  labelText: 'คำตอบที่ยอมรับ (บรรทัดละ 1 แบบ)',
                ),
              ),
            ],
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('editor_explanation'),
              controller: _explanation,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'คำอธิบาย (นักเรียนเห็นเมื่อตอบผิด)',
              ),
              validator: required,
            ),
            const SizedBox(height: 24),
            if (item.status != PracticeItemStatus.approved)
              FilledButton.icon(
                onPressed: _saving ? null : () => _save(approve: true),
                icon: const Icon(Icons.verified_outlined),
                label: const Text('บันทึกและอนุมัติ'),
              ),
          ],
        ),
      ),
    );
  }
}
