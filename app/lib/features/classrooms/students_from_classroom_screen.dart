import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';
import 'one_time_pins_view.dart';

/// "นำนักเรียนจากห้องเดิม" (DESIGN §24.6, §24.25): pick a room the teacher
/// has (usually last year's "ห้องเก่า"), tick its students (everyone by
/// default), choose the numbers and whether to keep the PINs, then
/// `POST /classrooms/{id}/students/from-classroom`. Every student keeps
/// their one account, so history and scores carry over.
class StudentsFromClassroomScreen extends ConsumerStatefulWidget {
  const StudentsFromClassroomScreen({super.key, required this.classroomId});

  final int classroomId;

  @override
  ConsumerState<StudentsFromClassroomScreen> createState() =>
      _StudentsFromClassroomScreenState();
}

class _StudentsFromClassroomScreenState
    extends ConsumerState<StudentsFromClassroomScreen> {
  static const _title = 'นำนักเรียนจากห้องเดิม';

  Classroom? _source;

  /// Students of [_source] the teacher unticked (everyone starts ticked).
  final _unticked = <int>{};
  CopyNumbering _numbering = CopyNumbering.keep;
  bool _newPins = false;
  bool _busy = false;
  String? _error;
  List<String> _errorDetails = const [];

  /// Set once the server accepted: the rows with new PINs to show once.
  StudentsCopyResult? _result;

  /// Rooms to copy from: every open or closed room of the teacher but this
  /// one, the newest year first and "ห้องเก่า" before open rooms.
  List<Classroom> _sources(List<Classroom> open, List<Classroom> closed) {
    final seen = <int>{widget.classroomId};
    return [
      for (final c in [...closed, ...open])
        if (seen.add(c.id)) c,
    ]..sort((a, b) {
      if (a.academicYear != b.academicYear) {
        return b.academicYear.compareTo(a.academicYear);
      }
      if (a.isClosed != b.isClosed) return a.isClosed ? -1 : 1;
      return a.name.compareTo(b.name);
    });
  }

  void _pickSource(Classroom source) => setState(() {
    _source = source;
    _unticked.clear();
    _error = null;
    _errorDetails = const [];
  });

  Set<int> _inThisRoom() => {
    for (final s
        in ref.read(rosterProvider(widget.classroomId)).value ??
            const <RosterStudent>[])
      s.studentId,
  };

  List<int> _selected(List<RosterStudent> students) {
    final here = _inThisRoom();
    return [
      for (final s in students)
        if (!here.contains(s.studentId) && !_unticked.contains(s.studentId))
          s.studentId,
    ];
  }

  Future<void> _submit(List<int> ids) async {
    final source = _source!;
    setState(() {
      _busy = true;
      _error = null;
      _errorDetails = const [];
    });
    try {
      final result = await ref
          .read(rosterProvider(widget.classroomId).notifier)
          .copyFrom(
            source.id,
            studentIds: ids,
            numbering: _numbering,
            newPins: _newPins,
          );
      if (!mounted) return;
      if (result.withPins.isNotEmpty) {
        setState(() => _result = result);
        return;
      }
      final messenger = ScaffoldMessenger.of(context);
      context.pop();
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(_doneText(result))));
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = apiErrorMessage(e);
          _errorDetails = [
            for (final m in apiErrorMessages(e))
              if (m != _error) m,
          ];
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "นำนักเรียน 30 คนเข้าห้องแล้ว ใช้ PIN และบัตร QR เดิมได้ (ข้าม 1 คน)".
  static String _doneText(StudentsCopyResult result) => [
    'นำนักเรียน ${result.enrolled.length} คนเข้าห้องแล้ว',
    if (result.withPins.isEmpty) 'ใช้รหัสผ่านและบัตร QR เดิมได้',
    if (result.skipped.isNotEmpty) '(ข้าม ${result.skipped.length} คน)',
  ].join(' ');

  Future<void> _leavePins() async {
    final ok = await confirmLeavePins(context);
    if (ok && mounted) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    if (_result case final result?) {
      final pins = result.withPins;
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leavePins();
        },
        child: OneTimePinsView(
          title: _doneText(result),
          enrolled: pins,
          onCopy: () => copyPins(context, pins),
          onDone: () => context.pop(),
        ),
      );
    }
    final source = _source;
    return PopScope(
      // Back from the student list returns to the room list first.
      canPop: source == null || _busy,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => _source = null);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(source == null ? _title : 'จาก ${source.name}'),
        ),
        body: source == null ? _sourceList(context) : _studentList(source),
      ),
    );
  }

  Widget _sourceList(BuildContext context) {
    final theme = Theme.of(context);
    final open = ref.watch(classroomsProvider);
    final closed = ref.watch(closedClassroomsProvider);
    return ContentColumn(
      child: ListView(
        padding: const EdgeInsets.only(bottom: 24),
        children: [
          Card(
            color: theme.colorScheme.secondaryContainer,
            child: const ListTile(
              leading: Icon(Icons.info_outline),
              title: Text(
                'เลือกห้องที่จะนำรายชื่อมา เช่น ห้องของปีการศึกษาก่อน',
              ),
              subtitle: Text(
                'นักเรียนใช้บัญชีเดิม ประวัติและคะแนนต่อเนื่อง '
                'นักเรียนจากห้องของครูท่านอื่นให้ใช้ "เพิ่มนักเรียน → เลือกนักเรียนที่มีอยู่"',
              ),
            ),
          ),
          const SizedBox(height: 8),
          switch ((open, closed)) {
            (AsyncData(value: final o), AsyncData(value: final c)) =>
              _sourceCards(_sources(o, c)),
            (AsyncError(:final error), _) ||
            (_, AsyncError(:final error)) => ErrorView(
              message: apiErrorMessage(error),
              onRetry: () {
                ref.invalidate(classroomsProvider);
                ref.invalidate(closedClassroomsProvider);
              },
            ),
            _ => const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            ),
          },
        ],
      ),
    );
  }

  Widget _sourceCards(List<Classroom> rooms) {
    if (rooms.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Text(
          'ยังไม่มีห้องอื่นให้นำรายชื่อมา',
          textAlign: TextAlign.center,
        ),
      );
    }
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (final c in rooms) ...[
            ListTile(
              key: ValueKey('copy_source_${c.id}'),
              leading: Icon(
                c.isClosed ? Icons.inventory_2_outlined : Icons.groups_outlined,
              ),
              title: Text(c.name),
              subtitle: Text(
                [
                  'ปี ${c.academicYear}',
                  if (c.studentCount case final n?) '$n คน',
                  if (c.isClosed) 'ห้องเก่า',
                  if (c.isSubject) c.myRole.label,
                ].join(' · '),
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _pickSource(c),
            ),
            if (c != rooms.last) const Divider(height: 1),
          ],
        ],
      ),
    );
  }

  Widget _studentList(Classroom source) {
    final roster = ref.watch(rosterProvider(source.id));
    // Who is in this room already (cannot be picked again).
    final target = ref.watch(rosterProvider(widget.classroomId));
    return AsyncView(
      value: roster,
      onRetry: () => ref.invalidate(rosterProvider(source.id)),
      data: (students) {
        if (target.isLoading && !target.hasValue) {
          return const Center(child: CircularProgressIndicator());
        }
        return _studentForm(source, students);
      },
    );
  }

  Widget _studentForm(Classroom source, List<RosterStudent> students) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final here = _inThisRoom();
    final selectable = [
      for (final s in students)
        if (!here.contains(s.studentId)) s.studentId,
    ];
    final selected = _selected(students);
    final allTicked = selected.length == selectable.length;
    return FormColumn(
      maxWidth: 640,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'เลือกแล้ว ${selected.length} จาก ${students.length} คน',
                style: theme.textTheme.titleMedium,
              ),
            ),
            if (selectable.isNotEmpty)
              TextButton(
                key: const ValueKey('copy_toggle_all'),
                onPressed: _busy
                    ? null
                    : () => setState(() {
                        if (allTicked) {
                          _unticked.addAll(selectable);
                        } else {
                          _unticked.clear();
                        }
                      }),
                child: Text(allTicked ? 'ไม่เลือกเลย' : 'เลือกทั้งหมด'),
              ),
          ],
        ),
        const SizedBox(height: 4),
        if (students.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Text('ห้องนี้ไม่มีนักเรียน', textAlign: TextAlign.center),
            ),
          )
        else
          Card(
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (final s in students)
                  CheckboxListTile(
                    key: ValueKey('copy_student_${s.studentId}'),
                    dense: true,
                    controlAffinity: ListTileControlAffinity.leading,
                    value:
                        !here.contains(s.studentId) &&
                        !_unticked.contains(s.studentId),
                    onChanged: _busy || here.contains(s.studentId)
                        ? null
                        : (v) => setState(() {
                            if (v ?? false) {
                              _unticked.remove(s.studentId);
                            } else {
                              _unticked.add(s.studentId);
                            }
                          }),
                    title: Text('${s.studentNumber}. ${s.name}'),
                    subtitle: here.contains(s.studentId)
                        ? const Text('อยู่ในห้องนี้แล้ว')
                        : s.studentCode == null
                        ? null
                        : Text('เลขประจำตัว ${s.studentCode}'),
                  ),
              ],
            ),
          ),
        const SizedBox(height: 20),
        Text('เลขที่ในห้องนี้', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        SegmentedButton<CopyNumbering>(
          key: const ValueKey('copy_numbering'),
          segments: [
            for (final n in CopyNumbering.values)
              ButtonSegment(value: n, label: Text(n.label)),
          ],
          selected: {_numbering},
          onSelectionChanged: _busy
              ? null
              : (s) => setState(() => _numbering = s.single),
        ),
        const SizedBox(height: 4),
        Text(switch (_numbering) {
          CopyNumbering.keep =>
            'ใช้เลขที่จากห้อง ${source.name} เลขที่ที่ซ้ำกับคนในห้องนี้จะไปต่อท้าย',
          CopyNumbering.sorted =>
            'เรียงตามชื่อ (ไม่นับคำนำหน้า) ต่อจากเลขที่มากที่สุดของห้องนี้',
        }, style: muted),
        const SizedBox(height: 20),
        Text('รหัสผ่านสำหรับเข้าสู่ระบบ', style: theme.textTheme.titleSmall),
        RadioGroup<bool>(
          groupValue: _newPins,
          onChanged: (v) {
            if (!_busy) setState(() => _newPins = v ?? false);
          },
          child: const Column(
            children: [
              RadioListTile<bool>(
                key: ValueKey('copy_pin_keep'),
                contentPadding: EdgeInsets.zero,
                value: false,
                title: Text('ใช้รหัสผ่านและบัตร QR เดิม'),
                subtitle: Text('นักเรียนเข้าสู่ระบบได้ทันทีด้วยรหัสห้องนี้'),
              ),
              RadioListTile<bool>(
                key: ValueKey('copy_pin_new'),
                contentPadding: EdgeInsets.zero,
                value: true,
                title: Text('ออกรหัสผ่านใหม่ให้ทุกคน'),
                subtitle: Text(
                  'รหัสผ่านเดิมใช้ไม่ได้และนักเรียนต้องเข้าสู่ระบบใหม่ '
                  'รหัสผ่านใหม่แสดงครั้งเดียว บัตร QR เดิมยังใช้ได้',
                ),
              ),
            ],
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          for (final d in _errorDetails)
            Text(d, style: TextStyle(color: theme.colorScheme.error)),
        ],
        const SizedBox(height: 24),
        FilledButton.icon(
          key: const ValueKey('copy_submit'),
          onPressed: _busy || selected.isEmpty ? null : () => _submit(selected),
          icon: _busy
              ? const SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.group_add_outlined),
          label: Text('นำนักเรียน ${selected.length} คนเข้าห้อง'),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _source = null),
          child: const Text('เลือกห้องอื่น'),
        ),
      ],
    );
  }
}
