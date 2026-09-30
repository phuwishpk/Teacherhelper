import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/question.dart';
import '../assignments/skills_picker.dart';
import '../classrooms/classrooms_providers.dart';
import 'course_item_forms.dart';
import 'course_models.dart';
import 'courses_providers.dart';
import 'courses_repository.dart';
import 'indicator_widgets.dart';

/// Waits for a course document to be read (polling `GET
/// /document-extractions/{id}` while the queue worker runs it), then shows
/// what was read as an editable form: the course, the indicators matched by
/// code (unmatched ones are picked or added by the teacher), the units and
/// the lesson plans. "บันทึก" sends it to `POST /courses/import` in one
/// transaction and pops the saved [Course] (DESIGN §20.1).
class CourseImportScreen extends ConsumerStatefulWidget {
  const CourseImportScreen({
    super.key,
    required this.extraction,
    required this.purpose,
    this.course,
  });

  final CourseExtraction extraction;
  final CourseDocumentPurpose purpose;

  /// Lesson plans are added to this course; null creates a new course.
  final Course? course;

  @override
  ConsumerState<CourseImportScreen> createState() => _CourseImportScreenState();
}

class _CourseImportScreenState extends ConsumerState<CourseImportScreen> {
  late CourseExtraction _extraction = widget.extraction;
  Timer? _poll;
  bool _review = false;

  final _formKey = GlobalKey<FormState>();
  final _code = TextEditingController();
  final _name = TextEditingController();
  final _year = TextEditingController();
  final _hoursText = TextEditingController();
  final _description = TextEditingController();
  int? _subjectId;
  String? _subjectCode;
  int? _grade;
  int _semester = 1;
  final _classroomIds = <int>{};

  /// Every read code and the indicator it resolves to (null = not found).
  final _resolved = <String, Skill?>{};
  List<Skill> _courseIndicators = [];
  List<String> _coursePending = [];
  final _units = <UnitDraft>[];
  final _plans = <PlanDraft>[];
  bool _saving = false;
  String? _error;

  Course? get _target => widget.course;

  @override
  void initState() {
    super.initState();
    if (_extraction.done) {
      _startReview();
    } else if (!_extraction.failed) {
      _schedulePoll();
    }
  }

  @override
  void dispose() {
    _poll?.cancel();
    for (final c in [_code, _name, _year, _hoursText, _description]) {
      c.dispose();
    }
    super.dispose();
  }

  void _schedulePoll() {
    _poll?.cancel();
    _poll = Timer(ref.read(courseExtractionPollIntervalProvider), () async {
      try {
        final next = await ref
            .read(coursesRepositoryProvider)
            .extraction(_extraction.id);
        if (!mounted) return;
        setState(() => _extraction = next);
        if (next.done) {
          _startReview();
        } else if (!next.failed) {
          _schedulePoll();
        }
      } catch (_) {
        // A network blip: ask again at the next tick.
        if (mounted) _schedulePoll();
      }
    });
  }

  /// Fills the form from what was read.
  void _startReview() {
    final result = _extraction.result!;
    _resolved
      ..clear()
      ..addAll(_extraction.matchMap);
    (List<Skill>, List<String>) split(Iterable<String> codes) {
      final skills = <Skill>[];
      final pending = <String>[];
      for (final code in codes) {
        final skill = _resolved[code];
        if (skill == null) {
          if (!pending.contains(code)) pending.add(code);
        } else if (!skills.contains(skill)) {
          skills.add(skill);
        }
      }
      return (skills, pending);
    }

    final read = result.course;
    final target = _target;
    if (target == null) {
      _code.text = read.code ?? '';
      _name.text = read.name ?? '';
      _year.text = '${read.academicYear ?? currentThaiYear()}';
      _hoursText.text = read.hours?.toString() ?? '';
      _description.text = read.description ?? '';
      _grade = read.gradeLevel;
      _semester = read.semester ?? 1;
      _subjectCode = read.subjectCode;
    }
    final (skills, pending) = split(result.indicators.map((i) => i.code));
    _courseIndicators = skills;
    _coursePending = pending;

    // Units read again for a course that has them already are not created
    // twice: a plan of such a unit goes to the existing one.
    final existingByTitle = {
      for (final u in target?.units ?? const <CourseUnit>[])
        u.title.trim().toLowerCase(): u,
    };
    final unitRefs = <int, UnitRef>{};
    _units.clear();
    for (final u in result.units) {
      final existing = existingByTitle[u.title.trim().toLowerCase()];
      if (existing != null) {
        unitRefs[u.position] = UnitRef.existing(existing.id);
        continue;
      }
      final (s, p) = split(u.indicatorCodes);
      unitRefs[u.position] = UnitRef.draft(_units.length);
      _units.add(
        UnitDraft(
          title: u.title,
          hours: u.hours,
          description: u.description,
          indicators: s,
          pendingCodes: p,
        ),
      );
    }
    _plans
      ..clear()
      ..addAll([
        for (final p in result.lessonPlans)
          () {
            final (s, pend) = split(p.indicatorCodes);
            return PlanDraft(
              title: p.title,
              unit: unitRefs[p.unitPosition],
              hours: p.hours,
              objectives: p.objectives,
              content: p.content,
              activities: p.activities,
              assessment: p.assessment,
              indicators: s,
              pendingCodes: pend,
            );
          }(),
      ]);
    setState(() => _review = true);
  }

  int? get _effectiveSubjectId {
    if (_target case final t?) return t.subjectId;
    if (_subjectId != null) return _subjectId;
    final code = _subjectCode;
    if (code == null) return null;
    final subjects = ref.read(subjectsProvider).value ?? const <Subject>[];
    return subjects.where((s) => s.code == code).firstOrNull?.id;
  }

  int? get _effectiveGrade => _target?.gradeLevel ?? _grade;

  List<String> get _unresolvedCodes => [
    for (final e in _resolved.entries)
      if (e.value == null) e.key,
  ];

  /// A read code now points at [skill]: every draft waiting for it takes it.
  void _resolve(String code, Skill skill) {
    setState(() {
      _resolved[code] = skill;
      List<Skill> add(List<Skill> list) =>
          list.contains(skill) ? list : [...list, skill];
      if (_coursePending.contains(code)) {
        _coursePending = [..._coursePending]..remove(code);
        _courseIndicators = add(_courseIndicators);
      }
      for (var i = 0; i < _units.length; i++) {
        final u = _units[i];
        if (u.pendingCodes.contains(code)) {
          _units[i] = u.copyWith(
            indicators: add(u.indicators),
            pendingCodes: [...u.pendingCodes]..remove(code),
          );
        }
      }
      for (var i = 0; i < _plans.length; i++) {
        final p = _plans[i];
        if (p.pendingCodes.contains(code)) {
          _plans[i] = p.copyWith(
            indicators: add(p.indicators),
            pendingCodes: [...p.pendingCodes]..remove(code),
          );
        }
      }
    });
  }

  String? _textOf(String code) => _extraction.result?.indicators
      .where((i) => i.code == code)
      .firstOrNull
      ?.text;

  Future<void> _pickFor(String code) async {
    final picked = await showSkillsPicker(
      context,
      subjectId: _effectiveSubjectId,
      grade: _effectiveGrade,
    );
    if (picked != null && picked.isNotEmpty) _resolve(code, picked.first);
  }

  Future<void> _addFor(String code) async {
    final added = await showAddIndicatorDialog(
      context,
      subjectId: _effectiveSubjectId,
      grade: _effectiveGrade,
      code: code,
      name: _textOf(code),
    );
    if (added != null) _resolve(code, added);
  }

  List<UnitChoice> get _unitChoices => [
    for (final u in _target?.units ?? const <CourseUnit>[])
      UnitChoice(UnitRef.existing(u.id), u.label),
    for (var i = 0; i < _units.length; i++)
      UnitChoice(UnitRef.draft(i), 'หน่วยใหม่: ${_units[i].title}'),
  ];

  String _unitLabel(UnitRef? ref) {
    if (ref == null) return 'ไม่อยู่ในหน่วย';
    return _unitChoices.where((c) => c.ref == ref).firstOrNull?.label ??
        'ไม่อยู่ในหน่วย';
  }

  Future<void> _editUnit(int? index) => Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => UnitFormScreen(
        initial: index == null ? null : _units[index],
        subjectId: _effectiveSubjectId,
        grade: _effectiveGrade,
        onSave: (draft) async => setState(
          () => index == null ? _units.add(draft) : _units[index] = draft,
        ),
        onDelete: index == null ? null : () async => _removeUnit(index),
      ),
    ),
  );

  /// Plans of the removed unit leave it; later draft indexes shift down.
  void _removeUnit(int index) => setState(() {
    _units.removeAt(index);
    for (var i = 0; i < _plans.length; i++) {
      final draft = _plans[i].unit?.draftIndex;
      if (draft == null) continue;
      if (draft == index) {
        _plans[i] = _plans[i].copyWith(clearUnit: true);
      } else if (draft > index) {
        _plans[i] = _plans[i].copyWith(unit: UnitRef.draft(draft - 1));
      }
    }
  });

  Future<void> _editPlan(int? index) => Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => LessonPlanFormScreen(
        initial: index == null ? null : _plans[index],
        units: _unitChoices,
        subjectId: _effectiveSubjectId,
        grade: _effectiveGrade,
        onSave: (draft) async => setState(
          () => index == null ? _plans.add(draft) : _plans[index] = draft,
        ),
        onDelete: index == null
            ? null
            : () async => setState(() => _plans.removeAt(index)),
      ),
    ),
  );

  Future<void> _save() async {
    if (_target == null && !(_formKey.currentState?.validate() ?? false)) {
      setState(() => _error = 'กรอกข้อมูลรายวิชาให้ครบ');
      return;
    }
    if (_target != null && _units.isEmpty && _plans.isEmpty) {
      setState(() => _error = 'ไม่มีหน่วยหรือแผนการสอนให้บันทึก');
      return;
    }
    final pending = _unresolvedCodes;
    if (pending.isNotEmpty) {
      final ok = await confirm(
        context,
        title: 'ยังมีตัวชี้วัดที่ไม่พบ ${pending.length} รหัส',
        message:
            '${pending.join(', ')} จะไม่ถูกบันทึก '
            'เลือกหรือเพิ่มตัวชี้วัดเหล่านี้ก่อน หรือบันทึกต่อโดยไม่มีตัวชี้วัดเหล่านี้',
        confirmLabel: 'บันทึกต่อ',
      );
      if (!ok || !mounted) return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final target = _target;
      final course = await ref
          .read(coursesRepositoryProvider)
          .import(
            CourseImport(
              extractionId: _extraction.id,
              courseId: target?.id,
              course: target != null
                  ? null
                  : CourseDraft(
                      code: _code.text.trim(),
                      name: _name.text.trim(),
                      subjectId: _effectiveSubjectId!,
                      gradeLevel: _grade!,
                      semester: _semester,
                      academicYear: int.parse(_year.text.trim()),
                      hours: int.tryParse(_hoursText.text.trim()),
                      description: _description.text.trim().isEmpty
                          ? null
                          : _description.text.trim(),
                    ),
              classroomIds: _classroomIds.toList(),
              indicators: _courseIndicators,
              units: _units,
              lessonPlans: _plans,
            ),
          );
      invalidateCourses(ref);
      if (!mounted) return;
      showMessage(
        context,
        target == null
            ? 'สร้างรายวิชาจากเอกสารแล้ว'
            : 'เพิ่มหน่วยและแผนการสอนจากเอกสารแล้ว',
      );
      Navigator.of(context).pop(course);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = _target == null
        ? 'ตรวจรายวิชาที่อ่านได้'
        : 'ตรวจแผนการสอนที่อ่านได้';
    if (!_review) {
      return Scaffold(
        appBar: AppBar(title: Text(title)),
        body: _extraction.failed
            ? _FailedView(message: _extraction.error)
            : const _WaitingView(),
      );
    }
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    _error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              FilledButton.icon(
                key: const ValueKey('course_import_save'),
                onPressed: _saving ? null : _save,
                icon: const Icon(Icons.check),
                label: Text(
                  _target == null
                      ? 'ยืนยันและสร้างรายวิชา'
                      : 'ยืนยันและเพิ่มลงรายวิชา',
                ),
              ),
            ],
          ),
        ),
      ),
      body: Form(
        key: _formKey,
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            children: [
              _notesCard(context),
              if (_target == null) ...[
                _courseCard(context),
                const SizedBox(height: 12),
              ] else
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.menu_book_outlined),
                    title: Text(_target!.title),
                    subtitle: const Text(
                      'หน่วยและแผนจะต่อท้ายของเดิมในรายวิชานี้',
                    ),
                  ),
                ),
              _indicatorsCard(context),
              const SizedBox(height: 12),
              _unitsCard(context),
              const SizedBox(height: 12),
              _plansCard(context),
            ],
          ),
        ),
      ),
    );
  }

  Widget _notesCard(BuildContext context) {
    final notes = _extraction.result?.notesTh ?? '';
    final theme = Theme.of(context);
    return Card(
      color: theme.colorScheme.secondaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'AI อ่านแล้ว ตรวจและแก้ให้ถูกต้องก่อนบันทึก ยังไม่มีอะไรถูกบันทึกจนกว่าจะกดยืนยัน',
              style: TextStyle(color: theme.colorScheme.onSecondaryContainer),
            ),
            if (notes.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                'หมายเหตุจาก AI: $notes',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSecondaryContainer,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _courseCard(BuildContext context) {
    final theme = Theme.of(context);
    final subjects = ref.watch(subjectsProvider);
    final classrooms = ref.watch(classroomsProvider);
    final subjectId = _effectiveSubjectId;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('รายวิชา', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            TextFormField(
              key: const ValueKey('import_course_code'),
              controller: _code,
              decoration: const InputDecoration(labelText: 'รหัสวิชา'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกรหัสวิชา' : null,
            ),
            TextFormField(
              key: const ValueKey('import_course_name'),
              controller: _name,
              decoration: const InputDecoration(labelText: 'ชื่อรายวิชา'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อรายวิชา' : null,
            ),
            DropdownButtonFormField<int>(
              key: ValueKey('import_course_subject_$subjectId'),
              initialValue: subjectId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'กลุ่มสาระ'),
              items: [
                for (final s in subjects.value ?? const <Subject>[])
                  DropdownMenuItem(value: s.id, child: Text(s.name)),
              ],
              onChanged: (v) => setState(() => _subjectId = v),
              validator: (v) => v == null ? 'เลือกกลุ่มสาระ' : null,
            ),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    key: const ValueKey('import_course_grade'),
                    initialValue: _grade,
                    decoration: const InputDecoration(labelText: 'ชั้น'),
                    items: [
                      for (var g = 1; g <= 12; g++)
                        DropdownMenuItem(
                          value: g,
                          child: Text(gradeLevelLabel(g)),
                        ),
                    ],
                    onChanged: (v) => setState(() => _grade = v),
                    validator: (v) => v == null ? 'เลือกชั้น' : null,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextFormField(
                    key: const ValueKey('import_course_year'),
                    controller: _year,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'ปีการศึกษา (พ.ศ.)',
                    ),
                    validator: (v) {
                      final y = int.tryParse(v?.trim() ?? '');
                      return y == null || y < 2500 || y > 2700
                          ? 'กรอกปี พ.ศ.'
                          : null;
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            SegmentedButton<int>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 1, label: Text('ภาค 1')),
                ButtonSegment(value: 2, label: Text('ภาค 2')),
                ButtonSegment(value: 0, label: Text('ทั้งปี')),
              ],
              selected: {_semester},
              onSelectionChanged: (s) => setState(() => _semester = s.first),
            ),
            TextFormField(
              controller: _hoursText,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: 'จำนวนชั่วโมง'),
            ),
            TextFormField(
              controller: _description,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'คำอธิบายรายวิชา',
                alignLabelWithHint: true,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'ห้องเรียนที่ใช้รายวิชานี้',
              style: theme.textTheme.labelLarge,
            ),
            const SizedBox(height: 4),
            AsyncView(
              value: classrooms,
              data: (list) => Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final c in list)
                    FilterChip(
                      label: Text(c.name),
                      selected: _classroomIds.contains(c.id),
                      onSelected: (v) => setState(
                        () => v
                            ? _classroomIds.add(c.id)
                            : _classroomIds.remove(c.id),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _indicatorsCard(BuildContext context) {
    final theme = Theme.of(context);
    final codes = _resolved.keys.toList();
    final unresolved = _unresolvedCodes.length;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'ตัวชี้วัดที่อ่านได้ (${codes.length})',
              style: theme.textTheme.titleMedium,
            ),
            if (unresolved > 0)
              Text(
                'ไม่พบ $unresolved รหัสในหลักสูตรของโรงเรียน '
                'เลือกตัวที่ตรงกัน หรือเพิ่มเป็นตัวชี้วัดของโรงเรียน',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            for (final code in codes)
              _IndicatorMatchTile(
                code: code,
                text: _textOf(code),
                skill: _resolved[code],
                onPick: () => _pickFor(code),
                onAdd: () => _addFor(code),
              ),
            const Divider(),
            IndicatorsField(
              label: 'ตัวชี้วัดของรายวิชา',
              indicators: _courseIndicators,
              pendingCodes: _coursePending,
              subjectId: _effectiveSubjectId,
              grade: _effectiveGrade,
              onChanged: (v) => setState(() => _courseIndicators = v),
            ),
          ],
        ),
      ),
    );
  }

  Widget _unitsCard(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    _target == null
                        ? 'หน่วยการเรียนรู้ (${_units.length})'
                        : 'หน่วยใหม่ (${_units.length})',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                TextButton.icon(
                  onPressed: () => _editUnit(null),
                  icon: const Icon(Icons.add),
                  label: const Text('เพิ่มหน่วย'),
                ),
              ],
            ),
            if (_units.isEmpty)
              Text('ไม่มีหน่วย', style: theme.textTheme.bodySmall),
            for (var i = 0; i < _units.length; i++)
              _DraftTile(
                key: ValueKey('import_unit_$i'),
                title:
                    'หน่วยที่ ${i + 1 + (_target?.units.length ?? 0)} ${_units[i].title}',
                subtitle: _units[i].hours == null
                    ? null
                    : '${_units[i].hours} ชั่วโมง',
                indicators: _units[i].indicators,
                pendingCodes: _units[i].pendingCodes,
                onTap: () => _editUnit(i),
              ),
          ],
        ),
      ),
    );
  }

  Widget _plansCard(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'แผนการสอน (${_plans.length})',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                TextButton.icon(
                  onPressed: () => _editPlan(null),
                  icon: const Icon(Icons.add),
                  label: const Text('เพิ่มแผน'),
                ),
              ],
            ),
            if (_plans.isEmpty)
              Text('ไม่มีแผนการสอน', style: theme.textTheme.bodySmall),
            for (var i = 0; i < _plans.length; i++)
              _DraftTile(
                key: ValueKey('import_plan_$i'),
                title: _plans[i].title,
                subtitle: [
                  _unitLabel(_plans[i].unit),
                  if (_plans[i].hours != null) '${_plans[i].hours} ชั่วโมง',
                ].join(' · '),
                indicators: _plans[i].indicators,
                pendingCodes: _plans[i].pendingCodes,
                onTap: () => _editPlan(i),
              ),
          ],
        ),
      ),
    );
  }
}

class _WaitingView extends StatelessWidget {
  const _WaitingView();

  @override
  Widget build(BuildContext context) => const Center(
    child: Padding(
      padding: EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          CircularProgressIndicator(),
          SizedBox(height: 16),
          Text(
            'AI กำลังอ่านเอกสาร อาจใช้เวลา 1–2 นาที',
            textAlign: TextAlign.center,
          ),
          SizedBox(height: 8),
          Text(
            'ถ้าปิดหน้านี้ไปก่อน แนบไฟล์เดิมอีกครั้งภายหลังได้โดยไม่เสียค่าใช้จ่ายเพิ่ม',
            textAlign: TextAlign.center,
          ),
        ],
      ),
    ),
  );
}

class _FailedView extends StatelessWidget {
  const _FailedView({this.message});

  final String? message;

  @override
  Widget build(BuildContext context) => ErrorView(
    message:
        message ?? 'AI อ่านเอกสารไม่สำเร็จ ลองแนบไฟล์ใหม่ หรือกรอกในฟอร์มเอง',
  );
}

class _IndicatorMatchTile extends StatelessWidget {
  const _IndicatorMatchTile({
    required this.code,
    required this.skill,
    required this.onPick,
    required this.onAdd,
    this.text,
  });

  final String code;
  final String? text;
  final Skill? skill;
  final VoidCallback onPick;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final s = skill;
    if (s != null) {
      return ListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        leading: Icon(Icons.check_circle, color: Colors.green.shade700),
        title: Row(
          children: [
            Flexible(child: Text(s.code == code ? code : '$code → ${s.code}')),
            if (s.sourceLabel case final label?) ...[
              const SizedBox(width: 6),
              TeacherAddedLabel(label: label),
            ],
          ],
        ),
        subtitle: Text(s.name, maxLines: 2, overflow: TextOverflow.ellipsis),
      );
    }
    return ListTile(
      key: ValueKey('unmatched_$code'),
      contentPadding: EdgeInsets.zero,
      dense: true,
      leading: Icon(Icons.help_outline, color: theme.colorScheme.error),
      title: Text('$code (ไม่พบ)'),
      subtitle: text == null
          ? null
          : Text(text!, maxLines: 2, overflow: TextOverflow.ellipsis),
      trailing: Wrap(
        children: [
          IconButton(
            tooltip: 'เลือกตัวชี้วัดที่ตรงกัน',
            icon: const Icon(Icons.search),
            onPressed: onPick,
          ),
          IconButton(
            key: ValueKey('add_indicator_$code'),
            tooltip: 'เพิ่มเป็นตัวชี้วัดของโรงเรียน',
            icon: const Icon(Icons.add_circle_outline),
            onPressed: onAdd,
          ),
        ],
      ),
    );
  }
}

class _DraftTile extends StatelessWidget {
  const _DraftTile({
    super.key,
    required this.title,
    required this.indicators,
    required this.pendingCodes,
    required this.onTap,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final List<Skill> indicators;
  final List<String> pendingCodes;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => ListTile(
    contentPadding: EdgeInsets.zero,
    onTap: onTap,
    title: Text(title),
    subtitle: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (subtitle != null) Text(subtitle!),
        const SizedBox(height: 4),
        IndicatorChips(indicators: indicators, pendingCodes: pendingCodes),
      ],
    ),
    trailing: const Icon(Icons.edit_outlined),
  );
}
