import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'exam_models.dart';

/// "เพิ่มตอน" / "แก้ไขตอน" (DESIGN §22.2, §22.15): title, instructions,
/// type (new sections only), option count (mcq 2–6), the digit block of a
/// numeric section, the default points and, for a new section, how many
/// blank questions to create. Structural fields are read-only while the
/// structure is locked.
Future<ExamSectionDraft?> showExamSectionDialog(
  BuildContext context, {
  ExamSection? existing,
  bool structureLocked = false,
  int nextPosition = 1,
}) => showDialog<ExamSectionDraft>(
  context: context,
  builder: (_) => ExamSectionDialog(
    existing: existing,
    structureLocked: structureLocked,
    nextPosition: nextPosition,
  ),
);

class ExamSectionDialog extends StatefulWidget {
  const ExamSectionDialog({
    super.key,
    this.existing,
    this.structureLocked = false,
    this.nextPosition = 1,
  });

  final ExamSection? existing;
  final bool structureLocked;
  final int nextPosition;

  @override
  State<ExamSectionDialog> createState() => _ExamSectionDialogState();
}

class _ExamSectionDialogState extends State<ExamSectionDialog> {
  final _formKey = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.existing?.title);
  late final _instructions = TextEditingController(
    text: widget.existing?.instructions,
  );
  late final _points = TextEditingController(
    text: formatPoints(widget.existing?.defaultPoints ?? 1),
  );
  late final _count = TextEditingController(text: '10');
  late ExamSectionType _type = widget.existing?.type ?? ExamSectionType.mcq;
  late int _options = widget.existing?.optionCount ?? 4;
  late int _digits = widget.existing?.numeric?.digits ?? 2;
  late bool _negative = widget.existing?.numeric?.allowNegative ?? false;
  late bool _decimal = widget.existing?.numeric?.allowDecimal ?? false;

  bool get _creating => widget.existing == null;

  bool get _structureEditable => !widget.structureLocked;

  @override
  void dispose() {
    _title.dispose();
    _instructions.dispose();
    _points.dispose();
    _count.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    Navigator.of(context).pop(
      ExamSectionDraft(
        type: _type,
        title: _title.text,
        instructions: _instructions.text,
        optionCount: _type == ExamSectionType.mcq ? _options : null,
        numeric: _type == ExamSectionType.numeric
            ? NumericSpec(
                digits: _digits,
                allowNegative: _negative,
                allowDecimal: _decimal,
              )
            : null,
        defaultPoints: double.parse(_points.text.trim()),
        questionCount: _creating ? int.parse(_count.text.trim()) : 0,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final position = widget.existing?.position ?? widget.nextPosition;
    return AlertDialog(
      title: Text(
        _creating ? 'เพิ่มตอนที่ $position' : 'แก้ไขตอนที่ $position',
      ),
      scrollable: true,
      content: SizedBox(
        width: 420,
        child: Form(
          key: _formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                key: const ValueKey('section_title'),
                controller: _title,
                decoration: const InputDecoration(
                  labelText: 'ชื่อตอน (ไม่บังคับ)',
                  hintText: 'เช่น ปรนัย',
                ),
                maxLength: 255,
              ),
              TextFormField(
                key: const ValueKey('section_instructions'),
                controller: _instructions,
                decoration: const InputDecoration(
                  labelText: 'คำชี้แจง (ไม่บังคับ)',
                  hintText: 'เช่น เลือกคำตอบที่ถูกที่สุดเพียงข้อเดียว',
                ),
                minLines: 1,
                maxLines: 4,
                maxLength: 2000,
              ),
              const SizedBox(height: 8),
              Text('ชนิดของข้อ', style: theme.textTheme.labelLarge),
              const SizedBox(height: 8),
              SegmentedButton<ExamSectionType>(
                key: const ValueKey('section_type'),
                showSelectedIcon: false,
                segments: [
                  for (final t in ExamSectionType.values)
                    ButtonSegment(value: t, label: Text(t.label)),
                ],
                selected: {_type},
                onSelectionChanged: _creating
                    ? (s) => setState(() => _type = s.first)
                    : null,
              ),
              if (!_creating)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    'เปลี่ยนชนิดไม่ได้ ให้ลบตอนแล้วสร้างใหม่',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              const SizedBox(height: 12),
              if (_type == ExamSectionType.mcq) ...[
                Text('จำนวนตัวเลือก', style: theme.textTheme.labelLarge),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  children: [
                    for (var n = 2; n <= 6; n++)
                      ChoiceChip(
                        key: ValueKey('section_options_$n'),
                        label: Text('$n (ก–${kExamOptionLabels[n - 1]})'),
                        selected: _options == n,
                        onSelected: _structureEditable
                            ? (_) => setState(() => _options = n)
                            : null,
                      ),
                  ],
                ),
                if (!_creating && _structureEditable)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      'ลดจำนวนตัวเลือกจะลบตัวเลือกท้ายและเอาออกจากเฉลย',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
              ],
              if (_type == ExamSectionType.trueFalse)
                Text(
                  'ข้อถูก/ผิดมี 2 วงเสมอ (ถ ผ)',
                  style: theme.textTheme.bodySmall,
                ),
              if (_type == ExamSectionType.numeric) ...[
                Text('จำนวนหลัก', style: theme.textTheme.labelLarge),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  children: [
                    for (var n = 1; n <= kExamMaxDigits; n++)
                      ChoiceChip(
                        key: ValueKey('section_digits_$n'),
                        label: Text('$n'),
                        selected: _digits == n,
                        onSelected: _structureEditable
                            ? (_) => setState(() => _digits = n)
                            : null,
                      ),
                  ],
                ),
                SwitchListTile(
                  key: const ValueKey('section_negative'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('มีช่องเครื่องหมายลบ'),
                  value: _negative,
                  onChanged: _structureEditable
                      ? (v) => setState(() => _negative = v)
                      : null,
                ),
                SwitchListTile(
                  key: const ValueKey('section_decimal'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('มีจุดทศนิยม'),
                  subtitle: const Text('เพิ่มหนึ่งคอลัมน์สำหรับจุด'),
                  value: _decimal,
                  onChanged: _structureEditable
                      ? (v) => setState(() => _decimal = v)
                      : null,
                ),
              ],
              if (widget.structureLocked)
                Text(
                  'พิมพ์แล้ว ค่าที่เป็นโครงสร้างแก้ไม่ได้จนกว่าจะปลดล็อก',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('section_points'),
                controller: _points,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'คะแนนต่อข้อ',
                  helperText: 'แก้รายข้อได้ในหน้าข้อ',
                ),
                validator: (v) {
                  final n = double.tryParse(v?.trim() ?? '');
                  return n == null || n <= 0 || n > 100
                      ? 'คะแนนต่อข้อ มากกว่า 0 และไม่เกิน 100'
                      : null;
                },
              ),
              if (_creating) ...[
                const SizedBox(height: 12),
                TextFormField(
                  key: const ValueKey('section_count'),
                  controller: _count,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(
                    labelText: 'จำนวนข้อ',
                    helperText: 'สร้างข้อว่างรอกรอกโจทย์และเฉลย',
                  ),
                  validator: (v) {
                    final n = int.tryParse(v?.trim() ?? '');
                    return n == null || n < 0 || n > kExamMaxBlankQuestions
                        ? 'จำนวนข้อ 0–$kExamMaxBlankQuestions'
                        : null;
                  },
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('section_submit'),
          onPressed: _submit,
          child: Text(_creating ? 'เพิ่มตอน' : 'บันทึก'),
        ),
      ],
    );
  }
}
