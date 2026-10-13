import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import 'timetable_models.dart';
import 'timetable_repository.dart';

/// `/timetable` (DESIGN §29.12): the teacher's week as a grid of days by
/// periods. Own slots open the attendance of that course in that room;
/// colleagues' slots in shared rooms show as busy. "เพิ่มคาบ" adds a slot,
/// a slot's sheet edits, cancels one date or removes it; the term dates
/// of each course are set here too.
class TimetableScreen extends ConsumerStatefulWidget {
  const TimetableScreen({super.key});

  @override
  ConsumerState<TimetableScreen> createState() => _TimetableScreenState();
}

class _TimetableScreenState extends ConsumerState<TimetableScreen> {
  /// Any date of the shown week (null = this week).
  String? _week;

  void _shift(TimetableWeek week, int days) {
    setState(() => _week = isoDate(week.weekStart.add(Duration(days: days))));
  }

  Future<void> _run(Future<void> Function() action, String done) async {
    try {
      await action();
      ref.invalidate(timetableWeekProvider(_week));
      if (mounted) showMessage(context, done);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _addSlot() async {
    final courses = await ref.read(coursesProvider.future);
    if (!mounted) return;
    final draft = await showDialog<_SlotDraft>(
      context: context,
      builder: (context) => _SlotDialog(courses: courses),
    );
    if (draft == null) return;
    await _run(
      () => ref
          .read(timetableRepositoryProvider)
          .addSlot(
            draft.courseId,
            classroomId: draft.classroomId,
            weekday: draft.weekday,
            periodNo: draft.periodNo,
            startsAt: draft.startsAt,
            endsAt: draft.endsAt,
          ),
      'เพิ่มคาบแล้ว',
    );
  }

  Future<void> _editSlot(TimetableSlot slot) async {
    final draft = await showDialog<_SlotDraft>(
      context: context,
      builder: (context) => _SlotDialog(courses: const [], existing: slot),
    );
    if (draft == null) return;
    await _run(
      () => ref
          .read(timetableRepositoryProvider)
          .updateSlot(
            slot.id,
            weekday: draft.weekday,
            periodNo: draft.periodNo,
            clearPeriod: draft.periodNo == null,
            startsAt: draft.startsAt,
            endsAt: draft.endsAt,
            clearTimes: draft.startsAt == null && draft.endsAt == null,
          ),
      'บันทึกคาบแล้ว',
    );
  }

  Future<void> _openSlot(TimetableWeek week, TimetableSlot slot) async {
    final cancelled = week.cancellationOf(slot);
    final date = week.dateOf(slot.weekday);
    final action = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text('${slot.courseCode ?? ''} ${slot.courseName ?? ''}'),
              subtitle: Text(
                [
                  ?slot.classroomName,
                  '${weekdayNames[slot.weekday - 1]} ${shortThaiDate(date)}',
                  if (slot.periodNo != null) 'คาบ ${slot.periodNo}',
                  if (slot.timeLabel.isNotEmpty) slot.timeLabel,
                ].join(' · '),
              ),
            ),
            if (slot.courseId != null && slot.classroomId != null)
              ListTile(
                key: const ValueKey('slot_attendance'),
                leading: const Icon(Icons.how_to_reg_outlined),
                title: const Text('เช็คชื่อรายวิชานี้'),
                onTap: () => Navigator.of(context).pop('attendance'),
              ),
            ListTile(
              key: const ValueKey('slot_cancel'),
              leading: Icon(
                cancelled == null
                    ? Icons.event_busy_outlined
                    : Icons.event_available_outlined,
              ),
              title: Text(
                cancelled == null
                    ? 'ยกเลิกคาบวันที่ ${shortThaiDate(date)}'
                    : 'คืนคาบวันที่ ${shortThaiDate(date)}',
              ),
              onTap: () => Navigator.of(context).pop('cancel'),
            ),
            ListTile(
              key: const ValueKey('slot_edit'),
              leading: const Icon(Icons.edit_outlined),
              title: const Text('แก้ไขคาบ'),
              onTap: () => Navigator.of(context).pop('edit'),
            ),
            ListTile(
              key: const ValueKey('slot_delete'),
              leading: const Icon(Icons.delete_outline),
              title: const Text('ลบคาบออกจากตาราง'),
              onTap: () => Navigator.of(context).pop('delete'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || action == null) return;
    switch (action) {
      case 'attendance':
        context.push(
          AppRoutes.attendance(slot.courseId!, classroomId: slot.classroomId),
        );
      case 'cancel':
        final repo = ref.read(timetableRepositoryProvider);
        await _run(
          () => cancelled == null
              ? repo.cancel(slot.id, isoDate(date))
              : repo.uncancel(cancelled.id),
          cancelled == null ? 'ยกเลิกคาบแล้ว' : 'คืนคาบแล้ว',
        );
      case 'edit':
        await _editSlot(slot);
      case 'delete':
        final ok = await confirm(
          context,
          title: 'ลบคาบนี้ออกจากตาราง?',
          message: 'การเช็คชื่อที่ทำไปแล้วไม่หาย',
          confirmLabel: 'ลบ',
          destructive: true,
        );
        if (ok) {
          await _run(
            () => ref.read(timetableRepositoryProvider).deleteSlot(slot.id),
            'ลบคาบแล้ว',
          );
        }
    }
  }

  Future<void> _setTerm(CourseTerm term) async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 2),
      initialDateRange: term.startsOn != null && term.endsOn != null
          ? DateTimeRange(start: term.startsOn!, end: term.endsOn!)
          : null,
      helpText: 'วันเปิดและปิดภาคเรียน ${term.code}',
    );
    if (range == null) return;
    await _run(
      () => ref
          .read(timetableRepositoryProvider)
          .setTerm(
            term.courseId,
            startsOn: isoDate(range.start),
            endsOn: isoDate(range.end),
          ),
      'บันทึกภาคเรียนแล้ว',
    );
  }

  @override
  Widget build(BuildContext context) {
    final week = ref.watch(timetableWeekProvider(_week));
    return Scaffold(
      appBar: AppBar(title: const Text('ตารางสอน')),
      floatingActionButtonLocation: const ContentFabLocation(),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'slot_add',
        key: const ValueKey('timetable_add'),
        onPressed: _addSlot,
        icon: const Icon(Icons.add),
        label: const Text('เพิ่มคาบ'),
      ),
      body: AsyncView(
        value: week,
        onRetry: () => ref.invalidate(timetableWeekProvider(_week)),
        data: (w) => ContentColumn(
          maxWidth: 1100,
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
          child: ListView(
            children: [
              _WeekBar(
                week: w,
                onShift: (d) => _shift(w, d),
                onToday: () => setState(() => _week = null),
              ),
              const SizedBox(height: 8),
              if (w.slots.isEmpty && w.busy.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 32),
                  child: EmptyView(
                    icon: Icons.calendar_month_outlined,
                    title: 'ยังไม่มีคาบในตาราง',
                    message: 'กด "เพิ่มคาบ" เลือกรายวิชา ห้อง วัน และคาบ',
                  ),
                )
              else
                TimetableGrid(
                  week: w,
                  onTapSlot: (s) => s.own ? _openSlot(w, s) : null,
                ),
              const SizedBox(height: 24),
              Text('ภาคเรียน', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              _TermsCard(
                week: w,
                onPick: _setTerm,
                courses: ref.watch(coursesProvider).value ?? const [],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _WeekBar extends StatelessWidget {
  const _WeekBar({
    required this.week,
    required this.onShift,
    required this.onToday,
  });

  final TimetableWeek week;
  final ValueChanged<int> onShift;
  final VoidCallback onToday;

  @override
  Widget build(BuildContext context) {
    final end = week.weekStart.add(const Duration(days: 6));
    return Row(
      children: [
        IconButton(
          key: const ValueKey('week_prev'),
          tooltip: 'สัปดาห์ก่อน',
          icon: const Icon(Icons.chevron_left),
          onPressed: () => onShift(-7),
        ),
        Expanded(
          child: Text(
            'สัปดาห์ ${shortThaiDate(week.weekStart)} – ${shortThaiDate(end)} ${end.year + 543}',
            key: const ValueKey('week_label'),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
        IconButton(
          key: const ValueKey('week_next'),
          tooltip: 'สัปดาห์ถัดไป',
          icon: const Icon(Icons.chevron_right),
          onPressed: () => onShift(7),
        ),
        TextButton(onPressed: onToday, child: const Text('สัปดาห์นี้')),
      ],
    );
  }
}

/// Days across, periods down. Shared by the teacher's and the student's page.
class TimetableGrid extends StatelessWidget {
  const TimetableGrid({super.key, required this.week, this.onTapSlot});

  final TimetableWeek week;
  final void Function(TimetableSlot slot)? onTapSlot;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final days = week.weekdays;
    final periods = week.periods;
    List<TimetableSlot> at(int day, int? period) => [
      for (final s in [...week.slots, ...week.busy])
        if (s.weekday == day && s.periodNo == period) s,
    ];
    final border = BorderSide(color: scheme.outlineVariant);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Table(
        defaultColumnWidth: const FixedColumnWidth(132),
        columnWidths: const {0: FixedColumnWidth(52)},
        border: TableBorder(
          horizontalInside: border,
          verticalInside: border,
          top: border,
          bottom: border,
          left: border,
          right: border,
        ),
        children: [
          TableRow(
            decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
            children: [
              const SizedBox(height: 36),
              for (final d in days)
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    '${weekdayNames[d - 1]} ${shortThaiDate(week.dateOf(d))}',
                    key: ValueKey('day_$d'),
                    style: theme.textTheme.labelLarge,
                    textAlign: TextAlign.center,
                  ),
                ),
            ],
          ),
          for (final p in periods)
            TableRow(
              children: [
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    p == null ? '–' : '$p',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelLarge,
                  ),
                ),
                for (final d in days)
                  Padding(
                    padding: const EdgeInsets.all(4),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final s in at(d, p))
                          _SlotChip(
                            slot: s,
                            cancelled: week.isCancelled(s),
                            onTap: onTapSlot,
                          ),
                        if (at(d, p).isEmpty) const SizedBox(height: 40),
                      ],
                    ),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

class _SlotChip extends StatelessWidget {
  const _SlotChip({required this.slot, required this.cancelled, this.onTap});

  final TimetableSlot slot;
  final bool cancelled;
  final void Function(TimetableSlot slot)? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final small = Theme.of(context).textTheme.bodySmall;
    final title = slot.own
        ? (slot.courseCode ?? slot.courseName ?? '')
        : 'ไม่ว่าง';
    return Material(
      color: slot.own
          ? scheme.primaryContainer
          : scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        key: ValueKey('slot_${slot.id}'),
        borderRadius: BorderRadius.circular(8),
        onTap: onTap == null ? null : () => onTap!(slot),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: small?.copyWith(
                  fontWeight: FontWeight.w600,
                  decoration: cancelled ? TextDecoration.lineThrough : null,
                  color: slot.own
                      ? scheme.onPrimaryContainer
                      : scheme.onSurfaceVariant,
                ),
              ),
              Text(
                [
                  ?slot.classroomName,
                  if (slot.timeLabel.isNotEmpty) slot.timeLabel,
                  if (cancelled) 'ยกเลิก',
                  ?slot.teacherName,
                ].join(' · '),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: small?.copyWith(
                  color: slot.own
                      ? scheme.onPrimaryContainer
                      : scheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TermsCard extends StatelessWidget {
  const _TermsCard({
    required this.week,
    required this.onPick,
    required this.courses,
  });

  final TimetableWeek week;
  final ValueChanged<CourseTerm> onPick;
  final List<Course> courses;

  @override
  Widget build(BuildContext context) {
    final terms = {for (final t in week.terms) t.courseId: t};
    if (courses.isEmpty) {
      return const Card(child: ListTile(title: Text('ยังไม่มีรายวิชา')));
    }
    return Card(
      child: Column(
        children: [
          for (final c in courses)
            ListTile(
              key: ValueKey('term_${c.id}'),
              leading: const Icon(Icons.date_range_outlined),
              title: Text('${c.code} ${c.name}'),
              subtitle: Text(switch (terms[c.id]) {
                final t? when t.startsOn != null && t.endsOn != null =>
                  '${shortThaiDate(t.startsOn!)} – ${shortThaiDate(t.endsOn!)} ${t.endsOn!.year + 543}',
                _ => 'ยังไม่ตั้งวันเปิด-ปิดภาคเรียน',
              }),
              trailing: const Icon(Icons.edit_calendar_outlined),
              onTap: () => onPick(
                terms[c.id] ?? CourseTerm(courseId: c.id, code: c.code),
              ),
            ),
        ],
      ),
    );
  }
}

class _SlotDraft {
  const _SlotDraft({
    required this.courseId,
    required this.classroomId,
    required this.weekday,
    this.periodNo,
    this.startsAt,
    this.endsAt,
  });

  final int courseId;
  final int classroomId;
  final int weekday;
  final int? periodNo;
  final String? startsAt;
  final String? endsAt;
}

/// "เพิ่มคาบ" (course, room, day, period, times) or "แก้ไขคาบ" (day,
/// period, times of an existing slot).
class _SlotDialog extends StatefulWidget {
  const _SlotDialog({required this.courses, this.existing});

  final List<Course> courses;
  final TimetableSlot? existing;

  @override
  State<_SlotDialog> createState() => _SlotDialogState();
}

class _SlotDialogState extends State<_SlotDialog> {
  final _formKey = GlobalKey<FormState>();
  late int? _courseId =
      widget.existing?.courseId ??
      (widget.courses.length == 1 ? widget.courses.single.id : null);
  late int? _classroomId = widget.existing?.classroomId;
  late int _weekday = widget.existing?.weekday ?? 1;
  late int? _periodNo = widget.existing?.periodNo ?? 1;
  late final _starts = TextEditingController(
    text: widget.existing?.startsAt ?? '',
  );
  late final _ends = TextEditingController(text: widget.existing?.endsAt ?? '');

  @override
  void dispose() {
    _starts.dispose();
    _ends.dispose();
    super.dispose();
  }

  Course? get _course {
    for (final c in widget.courses) {
      if (c.id == _courseId) return c;
    }
    return null;
  }

  String? _time(String? v) {
    final t = (v ?? '').trim();
    if (t.isEmpty) return null;
    return RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(t)
        ? null
        : 'เวลาต้องเป็น ชช:นน เช่น 08:30';
  }

  void _done() {
    if (!_formKey.currentState!.validate()) return;
    final courseId = _courseId;
    final classroomId = _classroomId;
    if (widget.existing == null && (courseId == null || classroomId == null)) {
      return;
    }
    Navigator.of(context).pop(
      _SlotDraft(
        courseId: courseId ?? 0,
        classroomId: classroomId ?? 0,
        weekday: _weekday,
        periodNo: _periodNo,
        startsAt: _starts.text.trim().isEmpty ? null : _starts.text.trim(),
        endsAt: _ends.text.trim().isEmpty ? null : _ends.text.trim(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    return AlertDialog(
      title: Text(editing ? 'แก้ไขคาบ' : 'เพิ่มคาบ'),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (!editing) ...[
                DropdownButtonFormField<int>(
                  key: const ValueKey('slot_course'),
                  initialValue: _courseId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'รายวิชา'),
                  items: [
                    for (final c in widget.courses)
                      DropdownMenuItem(
                        value: c.id,
                        child: Text(
                          '${c.code} ${c.name}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: (v) => setState(() {
                    _courseId = v;
                    _classroomId = null;
                  }),
                  validator: (v) => v == null ? 'เลือกรายวิชา' : null,
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  key: ValueKey('slot_classroom_$_courseId'),
                  initialValue: _classroomId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'ห้องเรียน'),
                  items: [
                    for (final r
                        in _course?.classrooms ?? const <CourseClassroom>[])
                      DropdownMenuItem(value: r.id, child: Text(r.name)),
                  ],
                  onChanged: (v) => setState(() => _classroomId = v),
                  validator: (v) =>
                      v == null ? 'เลือกห้องเรียนที่ผูกกับรายวิชา' : null,
                ),
                const SizedBox(height: 12),
              ],
              DropdownButtonFormField<int>(
                key: const ValueKey('slot_weekday'),
                initialValue: _weekday,
                decoration: const InputDecoration(labelText: 'วัน'),
                items: [
                  for (var d = 1; d <= 7; d++)
                    DropdownMenuItem(
                      value: d,
                      child: Text(weekdayNames[d - 1]),
                    ),
                ],
                onChanged: (v) => setState(() => _weekday = v ?? 1),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                key: const ValueKey('slot_period'),
                initialValue: _periodNo ?? 0,
                decoration: const InputDecoration(labelText: 'คาบ'),
                items: [
                  const DropdownMenuItem(value: 0, child: Text('ไม่ระบุคาบ')),
                  for (var n = 1; n <= 12; n++)
                    DropdownMenuItem(value: n, child: Text('คาบ $n')),
                ],
                onChanged: (v) =>
                    setState(() => _periodNo = (v ?? 0) == 0 ? null : v),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: TextFormField(
                      key: const ValueKey('slot_starts'),
                      controller: _starts,
                      decoration: const InputDecoration(
                        labelText: 'เริ่ม',
                        hintText: '08:30',
                      ),
                      validator: _time,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      key: const ValueKey('slot_ends'),
                      controller: _ends,
                      decoration: const InputDecoration(
                        labelText: 'จบ',
                        hintText: '09:20',
                      ),
                      validator: _time,
                    ),
                  ),
                ],
              ),
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
          key: const ValueKey('slot_save'),
          onPressed: _done,
          child: Text(editing ? 'บันทึก' : 'เพิ่ม'),
        ),
      ],
    );
  }
}
