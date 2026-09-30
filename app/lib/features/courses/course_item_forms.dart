import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/question.dart';
import 'course_models.dart';
import 'indicator_widgets.dart';

/// Saves a draft: calls the API for a saved course, or keeps it in the
/// import being reviewed. Throwing shows the error in the form.
typedef DraftSaver<T> = Future<void> Function(T draft);

int? _hours(String text) => int.tryParse(text.trim());

String? _blankToNull(String text) => text.trim().isEmpty ? null : text.trim();

String? _hoursError(String? v) {
  if (v == null || v.trim().isEmpty) return null;
  final n = int.tryParse(v.trim());
  return n == null || n < 0 || n > 2000 ? 'กรอกจำนวนชั่วโมง 0–2000' : null;
}

/// Asks before deleting, then runs [onDelete] and closes the form.
Future<void> _confirmDelete(
  BuildContext context, {
  required String title,
  required String message,
  required Future<void> Function() onDelete,
}) async {
  final ok = await confirm(
    context,
    title: title,
    message: message,
    confirmLabel: 'ลบ',
    destructive: true,
  );
  if (!ok || !context.mounted) return;
  try {
    await onDelete();
    if (context.mounted) Navigator.of(context).pop();
  } catch (e) {
    if (context.mounted) showMessage(context, apiErrorMessage(e));
  }
}

/// A unit's form (หน่วยการเรียนรู้): title, hours, description and its
/// indicators. [onSave] decides where it goes; the form closes after it.
class UnitFormScreen extends StatefulWidget {
  const UnitFormScreen({
    super.key,
    required this.onSave,
    required this.subjectId,
    this.grade,
    this.initial,
    this.onDelete,
  });

  final UnitDraft? initial;
  final DraftSaver<UnitDraft> onSave;
  final Future<void> Function()? onDelete;
  final int? subjectId;
  final int? grade;

  @override
  State<UnitFormScreen> createState() => _UnitFormScreenState();
}

class _UnitFormScreenState extends State<UnitFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.initial?.title ?? '');
  late final _hoursText = TextEditingController(
    text: widget.initial?.hours?.toString() ?? '',
  );
  late final _description = TextEditingController(
    text: widget.initial?.description ?? '',
  );
  late List<Skill> _indicators = widget.initial?.indicators ?? const [];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _hoursText.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onSave(
        UnitDraft(
          title: _title.text.trim(),
          hours: _hours(_hoursText.text),
          description: _blankToNull(_description.text),
          indicators: _indicators,
          pendingCodes: widget.initial?.pendingCodes ?? const [],
        ),
      );
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onDelete = widget.onDelete;
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.initial == null ? 'เพิ่มหน่วยการเรียนรู้' : 'แก้ไขหน่วย',
        ),
        actions: [
          if (onDelete != null)
            IconButton(
              tooltip: 'ลบหน่วย',
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _confirmDelete(
                context,
                title: 'ลบหน่วยนี้?',
                message:
                    'แผนการสอนในหน่วยนี้ยังอยู่ในรายวิชา แต่จะไม่อยู่ในหน่วยใด',
                onDelete: onDelete,
              ),
            ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            TextFormField(
              controller: _title,
              decoration: const InputDecoration(
                labelText: 'ชื่อหน่วย',
                hintText: 'เช่น เศษส่วน',
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อหน่วย' : null,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _hoursText,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: 'จำนวนชั่วโมง'),
              validator: _hoursError,
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _description,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(labelText: 'คำอธิบาย'),
            ),
            const SizedBox(height: 16),
            IndicatorsField(
              indicators: _indicators,
              pendingCodes: widget.initial?.pendingCodes ?? const [],
              subjectId: widget.subjectId,
              grade: widget.grade,
              onChanged: (v) => setState(() => _indicators = v),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('unit_save'),
              onPressed: _busy ? null : _save,
              child: const Text('บันทึกหน่วย'),
            ),
          ],
        ),
      ),
    );
  }
}

/// A unit a lesson plan can belong to, as the plan form offers it.
class UnitChoice {
  const UnitChoice(this.ref, this.label);

  final UnitRef ref;
  final String label;
}

/// A lesson plan's form (แผนการจัดการเรียนรู้): title, unit, hours, the four
/// parts of a Thai lesson plan, the date it was taught (chart 5 of §20.4)
/// and its indicators.
class LessonPlanFormScreen extends StatefulWidget {
  const LessonPlanFormScreen({
    super.key,
    required this.onSave,
    required this.subjectId,
    this.grade,
    this.initial,
    this.units = const [],
    this.onDelete,
  });

  final PlanDraft? initial;
  final List<UnitChoice> units;
  final DraftSaver<PlanDraft> onSave;
  final Future<void> Function()? onDelete;
  final int? subjectId;
  final int? grade;

  @override
  State<LessonPlanFormScreen> createState() => _LessonPlanFormScreenState();
}

class _LessonPlanFormScreenState extends State<LessonPlanFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final PlanDraft? _initial = widget.initial;
  late final _title = TextEditingController(text: _initial?.title ?? '');
  late final _hoursText = TextEditingController(
    text: _initial?.hours?.toString() ?? '',
  );
  late final _objectives = TextEditingController(
    text: _initial?.objectives ?? '',
  );
  late final _content = TextEditingController(text: _initial?.content ?? '');
  late final _activities = TextEditingController(
    text: _initial?.activities ?? '',
  );
  late final _assessment = TextEditingController(
    text: _initial?.assessment ?? '',
  );
  late UnitRef? _unit = widget.units.any((u) => u.ref == _initial?.unit)
      ? _initial?.unit
      : null;
  late DateTime? _taughtOn = _initial?.taughtOn;
  late List<Skill> _indicators = _initial?.indicators ?? const [];
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [
      _title,
      _hoursText,
      _objectives,
      _content,
      _activities,
      _assessment,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickTaught() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _taughtOn ?? now,
      firstDate: DateTime(now.year - 2),
      lastDate: DateTime(now.year + 1, 12, 31),
    );
    if (picked != null) {
      setState(
        () => _taughtOn = DateTime(picked.year, picked.month, picked.day),
      );
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.onSave(
        PlanDraft(
          title: _title.text.trim(),
          unit: _unit,
          hours: _hours(_hoursText.text),
          objectives: _blankToNull(_objectives.text),
          content: _blankToNull(_content.text),
          activities: _blankToNull(_activities.text),
          assessment: _blankToNull(_assessment.text),
          taughtOn: _taughtOn,
          indicators: _indicators,
          pendingCodes: _initial?.pendingCodes ?? const [],
        ),
      );
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _area(TextEditingController c, String label, String key) => Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: TextFormField(
      key: ValueKey(key),
      controller: c,
      minLines: 2,
      maxLines: 8,
      decoration: InputDecoration(labelText: label, alignLabelWithHint: true),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onDelete = widget.onDelete;
    return Scaffold(
      appBar: AppBar(
        title: Text(_initial == null ? 'เพิ่มแผนการสอน' : 'แผนการสอน'),
        actions: [
          if (onDelete != null)
            IconButton(
              tooltip: 'ลบแผน',
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _confirmDelete(
                context,
                title: 'ลบแผนนี้?',
                message:
                    'การบ้านที่ผูกกับแผนนี้ยังอยู่ในรายวิชาเดิม แต่จะไม่ผูกกับแผนใด',
                onDelete: onDelete,
              ),
            ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            TextFormField(
              key: const ValueKey('plan_title'),
              controller: _title,
              decoration: const InputDecoration(
                labelText: 'ชื่อแผน',
                hintText: 'เช่น การบวกเศษส่วน',
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อแผน' : null,
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<UnitRef?>(
              key: const ValueKey('plan_unit'),
              initialValue: _unit,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'หน่วยการเรียนรู้'),
              items: [
                const DropdownMenuItem<UnitRef?>(
                  value: null,
                  child: Text('ไม่อยู่ในหน่วย'),
                ),
                for (final u in widget.units)
                  DropdownMenuItem<UnitRef?>(
                    value: u.ref,
                    child: Text(u.label, overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _unit = v),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _hoursText,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: 'จำนวนชั่วโมง'),
              validator: _hoursError,
            ),
            const SizedBox(height: 16),
            _area(_objectives, 'จุดประสงค์การเรียนรู้', 'plan_objectives'),
            _area(_content, 'สาระการเรียนรู้', 'plan_content'),
            _area(_activities, 'กิจกรรมการเรียนรู้', 'plan_activities'),
            _area(_assessment, 'การวัดและประเมินผล', 'plan_assessment'),
            IndicatorsField(
              indicators: _indicators,
              pendingCodes: _initial?.pendingCodes ?? const [],
              subjectId: widget.subjectId,
              grade: widget.grade,
              onChanged: (v) => setState(() => _indicators = v),
            ),
            const SizedBox(height: 8),
            ListTile(
              key: const ValueKey('plan_taught_on'),
              contentPadding: EdgeInsets.zero,
              leading: Icon(
                _taughtOn == null
                    ? Icons.radio_button_unchecked
                    : Icons.check_circle,
                color: _taughtOn == null ? null : Colors.green.shade700,
              ),
              title: Text(
                _taughtOn == null
                    ? 'ยังไม่ได้สอน (แตะเพื่อบันทึกวันที่สอน)'
                    : 'สอนแล้ว ${formatThaiDate(_taughtOn!)}',
              ),
              trailing: _taughtOn == null
                  ? null
                  : IconButton(
                      tooltip: 'ล้างวันที่สอน',
                      icon: const Icon(Icons.clear),
                      onPressed: () => setState(() => _taughtOn = null),
                    ),
              onTap: _pickTaught,
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              key: const ValueKey('plan_save'),
              onPressed: _busy ? null : _save,
              child: const Text('บันทึกแผน'),
            ),
          ],
        ),
      ),
    );
  }
}
