import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import '../classrooms/one_time_pins_view.dart';
import '../courses/course_models.dart';
import '../courses/courses_providers.dart';
import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';
import 'roster_sync_dialog.dart';

/// Highest student number the server accepts on import
/// (`ClassroomImporter::MAX_STUDENT_NUMBER`).
const maxImportStudentNumber = 255;

/// Preview of importing one Google Classroom course (DESIGN §19.2, §24.10).
/// When an open room of the school already holds most of the course's
/// students, the first choice is "ผูกคอร์สนี้กับห้อง … ที่มีอยู่" (at once
/// for its homeroom teacher, otherwise a course request). "สร้างห้องใหม่"
/// shows name, grade, year and the numbered students, which the teacher may
/// renumber or take out; students the school already has are ticked to use
/// their account. The one-time PINs of new accounts follow, then the screen
/// pops with the classroom's id (nothing after a request).
class ClassroomImportScreen extends ConsumerWidget {
  const ClassroomImportScreen({super.key, required this.courseId});

  final String courseId;

  static const _title = 'นำเข้าจาก Google Classroom';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen(googleImportPreviewProvider(courseId), (_, next) {
      if (next case AsyncError(
        :final error,
      ) when isGoogleReconnectError(error)) {
        ref.read(googleStatusProvider.notifier).markNeedsReconnect();
      }
    });
    return switch (ref.watch(googleImportPreviewProvider(courseId))) {
      AsyncData(:final value) => _ImportForm(
        key: ValueKey(courseId),
        preview: value,
      ),
      AsyncError(:final error) => Scaffold(
        appBar: AppBar(title: const Text(_title)),
        body: ErrorView(
          message: googleErrorMessage(error),
          onRetry: apiErrorCode(error) == 'course_already_linked'
              ? null
              : () => ref.invalidate(googleImportPreviewProvider(courseId)),
        ),
      ),
      _ => Scaffold(
        appBar: AppBar(title: const Text(_title)),
        body: const Center(child: CircularProgressIndicator()),
      ),
    };
  }
}

/// The two ways to bring a course in when the server suggests a room.
enum _ImportChoice { linkExisting, createNew }

class _ImportForm extends ConsumerStatefulWidget {
  const _ImportForm({super.key, required this.preview});

  final ClassroomImportPreview preview;

  @override
  ConsumerState<_ImportForm> createState() => _ImportFormState();
}

class _ImportFormState extends ConsumerState<_ImportForm> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.preview.suggestedName);
  late final _year = TextEditingController(
    text: '${widget.preview.academicYear}',
  );
  late int? _grade = widget.preview.gradeLevelGuess;

  /// Student number fields by Google user id, in the proposed order.
  late final Map<String, TextEditingController> _numbers = {
    for (final s in widget.preview.students)
      s.googleUserId: TextEditingController(text: '${s.proposedNumber}'),
  };

  /// Accounts taken out (test accounts, parents): not created, and kept
  /// out of later roster syncs.
  final _removed = <String>{};

  /// Accounts that join with the student the school already has (DESIGN
  /// §24.10); every match starts ticked (§24.24), unticking creates a new
  /// account.
  late final Set<String> _useExisting = {
    for (final s in widget.preview.students)
      if (s.match != null) s.googleUserId,
  };

  late _ImportChoice _choice = widget.preview.suggestedClassroom == null
      ? _ImportChoice.createNew
      : _ImportChoice.linkExisting;

  /// The teacher's course for the linked room ("รายวิชา"); until the
  /// teacher picks, the one course already bound to the room is used.
  bool _courseChosen = false;
  int? _appCourseId;

  bool _busy = false;
  String? _error;
  ClassroomImportResult? _result;

  List<ImportPreviewStudent> get _kept => [
    for (final s in widget.preview.students)
      if (!_removed.contains(s.googleUserId)) s,
  ];

  @override
  void dispose() {
    _name.dispose();
    _year.dispose();
    for (final c in _numbers.values) {
      c.dispose();
    }
    super.dispose();
  }

  int? _numberOf(String googleUserId) {
    final n = int.tryParse(_numbers[googleUserId]!.text.trim());
    return n != null && n >= 1 && n <= maxImportStudentNumber ? n : null;
  }

  /// Why a kept student's number cannot be used, by Google user id.
  Map<String, String> _numberErrors() {
    final kept = _kept;
    final counts = <int, int>{};
    for (final s in kept) {
      if (_numberOf(s.googleUserId) case final n?) {
        counts[n] = (counts[n] ?? 0) + 1;
      }
    }
    return {
      for (final s in kept)
        if (_numberOf(s.googleUserId) case final n)
          if (n == null)
            s.googleUserId: 'เลขที่ต้องเป็น 1–$maxImportStudentNumber'
          else if (counts[n]! > 1)
            s.googleUserId: 'เลขที่ $n ซ้ำกับคนอื่น',
    };
  }

  /// Google user id -> existing student for the kept, ticked matches.
  Map<String, int> _existingIds() => {
    for (final s in _kept)
      if (s.match case final m? when _useExisting.contains(s.googleUserId))
        s.googleUserId: m.studentId,
  };

  int? _courseFor(List<Course> courses, SuggestedClassroom room) {
    if (_courseChosen) return _appCourseId;
    final bound = [
      for (final c in courses)
        if (c.classroomIds.contains(room.id)) c,
    ];
    return bound.length == 1 ? bound.single.id : null;
  }

  /// The link happens at once (no request): the teacher owns the room or
  /// [courseId] is taught there already (DESIGN §24.24).
  bool _linksAtOnce(SuggestedClassroom room, List<Course> courses, int? id) =>
      room.ownedByMe ||
      courses.any((c) => c.id == id && c.classroomIds.contains(room.id));

  void _remove(String googleUserId) =>
      setState(() => _removed.add(googleUserId));

  void _restore(String googleUserId) =>
      setState(() => _removed.remove(googleUserId));

  /// Numbers the kept students 1..N, keeping the order of their current
  /// numbers (closes the gaps left by removed accounts).
  void _renumber() {
    final order = widget.preview.students;
    final kept = _kept
      ..sort((a, b) {
        final na = _numberOf(a.googleUserId) ?? maxImportStudentNumber + 1;
        final nb = _numberOf(b.googleUserId) ?? maxImportStudentNumber + 1;
        return na != nb
            ? na.compareTo(nb)
            : order.indexOf(a) - order.indexOf(b);
      });
    setState(() {
      for (var i = 0; i < kept.length; i++) {
        _numbers[kept[i].googleUserId]!.text = '${i + 1}';
      }
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _numberErrors().isNotEmpty) {
      return;
    }
    final preview = widget.preview;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(googleClassroomRepositoryProvider)
          .importClassroom(
            ClassroomImportRequest(
              courseId: preview.courseId,
              name: _name.text.trim(),
              gradeLevel: _grade!,
              academicYear: int.parse(_year.text.trim()),
              numbers: {
                for (final s in _kept)
                  s.googleUserId: _numberOf(s.googleUserId)!,
              },
              removed: [
                for (final s in preview.students)
                  if (_removed.contains(s.googleUserId)) s.googleUserId,
              ],
              existing: _existingIds(),
            ),
          );
      ref.read(classroomsProvider.notifier).addCreated(result.classroom);
      if (!mounted) return;
      if (!result.students.any((s) => s.hasPin)) {
        final kept = result.students.where((s) => s.existing).length;
        showMessage(
          context,
          'สร้างห้อง ${result.classroom.name} แล้ว'
          '${kept == 0 ? '' : ' นักเรียน $kept คนใช้ PIN และบัตร QR เดิมได้'}',
        );
        context.pop(result.classroom.id);
        return;
      }
      setState(() => _result = result);
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (apiErrorCode(e) == 'course_already_linked') {
        ref.invalidate(googleCoursesProvider);
      }
      if (mounted) setState(() => _error = googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// `POST /google/courses/{course_id}/link-existing` (DESIGN §24.10).
  Future<void> _link(SuggestedClassroom room) async {
    final courses = ref.read(coursesProvider).value ?? const <Course>[];
    final appCourseId = _courseFor(courses, room);
    if (!room.ownedByMe && appCourseId == null) {
      setState(() => _error = 'เลือกรายวิชาของคุณที่จะสอนห้อง ${room.name}');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(googleClassroomRepositoryProvider)
          .linkExisting(
            widget.preview.courseId,
            classroomId: room.id,
            appCourseId: appCourseId,
          );
      ref.invalidate(coursesProvider);
      if (!mounted) return;
      switch (result) {
        case LinkRequested():
          showMessage(
            context,
            'ส่งคำขอผูกรายวิชากับห้อง ${room.name} แล้ว '
            'เมื่อ${room.homeroomTeacher?.name ?? 'ครูประจำชั้น'}อนุมัติ '
            'คอร์สจะผูกกับห้องให้เอง',
          );
          context.pop();
        case LinkedExisting(
          :final classroom,
          :final roster,
          :final rosterError,
        ):
          ref.read(classroomsProvider.notifier).addCreated(classroom);
          ref.invalidate(rosterProvider(classroom.id));
          ref.invalidate(googleCoursesProvider);
          if (rosterError != null) {
            showMessage(
              context,
              'ผูกคอร์สกับห้อง ${classroom.name} แล้ว แต่ซิงก์รายชื่อไม่สำเร็จ: '
              '$rosterError กด "ซิงก์รายชื่อ" ในหน้าห้องอีกครั้ง',
            );
          } else if (roster == null || roster.unchanged) {
            showMessage(context, 'ผูกคอร์สกับห้อง ${classroom.name} แล้ว');
          } else {
            // The dialog is modal; no spinner behind it.
            setState(() => _busy = false);
            await showRosterSyncResult(context, roster);
          }
          if (mounted) context.pop(classroom.id);
      }
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (apiErrorCode(e) == 'course_already_linked') {
        ref.invalidate(googleCoursesProvider);
      }
      if (mounted) setState(() => _error = googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "สร้างรายวิชาใหม่" for the link: the course form pops the new course.
  Future<void> _createCourse(SuggestedClassroom room) async {
    final created = await context.push<Course>(
      AppRoutes.courseNewFor(room.ownedByMe ? room.id : null, pick: true),
    );
    ref.invalidate(coursesProvider);
    if (created != null && mounted) {
      setState(() {
        _courseChosen = true;
        _appCourseId = created.id;
      });
    }
  }

  Future<void> _leavePins(int classroomId) async {
    final ok = await confirmLeavePins(context);
    if (ok && mounted) context.pop(classroomId);
  }

  @override
  Widget build(BuildContext context) {
    if (_result case final result?) {
      final id = result.classroom.id;
      final pins = [
        for (final s in result.students)
          if (s.hasPin) s,
      ];
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leavePins(id);
        },
        child: OneTimePinsView(
          title: 'สร้างห้อง ${result.classroom.name} แล้ว',
          enrolled: pins,
          onCopy: () => copyPins(context, pins),
          onDone: () => context.pop(id),
        ),
      );
    }

    final theme = Theme.of(context);
    final preview = widget.preview;
    final room = preview.suggestedClassroom;
    final linking = room != null && _choice == _ImportChoice.linkExisting;

    return Scaffold(
      appBar: AppBar(title: const Text(ClassroomImportScreen._title)),
      body: Form(
        key: _formKey,
        child: FormColumn(
          maxWidth: 640,
          children: [
            Card(
              color: theme.colorScheme.secondaryContainer,
              child: ListTile(
                leading: const Icon(Icons.school_outlined),
                title: Text([preview.name, ?preview.section].join(' · ')),
                subtitle: const Text(
                  'ชื่อและอีเมลของนักเรียนมาจาก Google Classroom '
                  'นักเรียนที่มีบัญชีในโรงเรียนอยู่แล้วใช้บัญชีเดิม',
                ),
              ),
            ),
            const SizedBox(height: 16),
            if (room != null) ...[
              _choiceCard(theme, room),
              const SizedBox(height: 16),
            ],
            if (linking) ..._linkForm(theme, room) else ..._createForm(theme),
          ],
        ),
      ),
    );
  }

  /// "ผูกคอร์สนี้กับห้อง … ที่มีอยู่" first, "สร้างห้องใหม่" second
  /// (DESIGN §24.10).
  Widget _choiceCard(ThemeData theme, SuggestedClassroom room) {
    final teacher = room.ownedByMe
        ? 'ห้องของคุณ'
        : 'ครูประจำชั้น ${room.homeroomTeacher?.name ?? '-'}';
    return Card(
      child: RadioGroup<_ImportChoice>(
        groupValue: _choice,
        onChanged: (v) {
          if (!_busy && v != null) {
            setState(() {
              _choice = v;
              _error = null;
            });
          }
        },
        child: Column(
          children: [
            RadioListTile<_ImportChoice>(
              key: const ValueKey('import_choice_link'),
              value: _ImportChoice.linkExisting,
              title: Text('ผูกคอร์สนี้กับห้อง ${room.name} ที่มีอยู่'),
              subtitle: Text(
                'ปี ${room.academicYear} · $teacher · นักเรียนในคอร์ส '
                '${room.matched} คนอยู่ในห้องนี้ (${room.coverageLabel})',
              ),
            ),
            const Divider(height: 1),
            const RadioListTile<_ImportChoice>(
              key: ValueKey('import_choice_create'),
              value: _ImportChoice.createNew,
              title: Text('สร้างห้องใหม่'),
              subtitle: Text('สร้างห้องจากคอร์สนี้พร้อมรายชื่อนักเรียน'),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _linkForm(ThemeData theme, SuggestedClassroom room) {
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final courses = ref.watch(coursesProvider);
    final list = courses.value ?? const <Course>[];
    final courseId = _courseFor(list, room);
    final atOnce = _linksAtOnce(room, list, courseId);
    final teacher = room.homeroomTeacher?.name ?? 'ครูประจำชั้น';
    return [
      Text('รายวิชาของคุณในห้องนี้', style: theme.textTheme.titleSmall),
      const SizedBox(height: 8),
      switch (courses) {
        AsyncError(:final error) => ErrorView(
          message: apiErrorMessage(error),
          onRetry: () => ref.invalidate(coursesProvider),
        ),
        AsyncData() => DropdownButtonFormField<int?>(
          // Rebuilt when a new course arrives so it shows as picked.
          key: ValueKey('import_app_course_${list.length}'),
          initialValue: list.any((c) => c.id == courseId) ? courseId : null,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'รายวิชา',
            helperText: room.ownedByMe
                ? 'ไม่บังคับ งานของรายวิชาที่เลือกจะโพสต์ลงคอร์สนี้'
                : 'งานของรายวิชานี้จะโพสต์และซิงก์ผ่านคอร์สนี้',
          ),
          hint: const Text('เลือกรายวิชา'),
          items: [
            if (room.ownedByMe)
              const DropdownMenuItem<int?>(child: Text('ไม่ระบุรายวิชา')),
            for (final c in list)
              DropdownMenuItem<int?>(
                value: c.id,
                child: Text(
                  c.classroomIds.contains(room.id)
                      ? '${c.title} (สอนห้องนี้อยู่แล้ว)'
                      : c.title,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: _busy
              ? null
              : (id) => setState(() {
                  _courseChosen = true;
                  _appCourseId = id;
                  _error = null;
                }),
        ),
        _ => const LinearProgressIndicator(),
      },
      Align(
        alignment: AlignmentDirectional.centerStart,
        child: TextButton.icon(
          key: const ValueKey('import_create_course'),
          onPressed: _busy ? null : () => _createCourse(room),
          icon: const Icon(Icons.add),
          label: const Text('สร้างรายวิชาใหม่'),
        ),
      ),
      const SizedBox(height: 8),
      Text(
        room.ownedByMe
            ? 'ผูกได้ทันทีแล้วซิงก์รายชื่อ นักเรียนที่อยู่ในห้องแล้วจับคู่กับบัญชีเดิม '
                  'คนที่ยังไม่อยู่ในห้องจะถูกเพิ่มเข้าห้อง'
            : atOnce
            ? 'รายวิชานี้สอนห้อง ${room.name} อยู่แล้ว ผูกคอร์สได้ทันที '
                  'แล้วจับคู่รายชื่อกับนักเรียนในห้อง (ไม่เพิ่มหรือเอานักเรียนออก)'
            : 'ห้องนี้เป็นของ$teacher ระบบจะส่งคำขอผูกรายวิชา '
                  'เมื่ออนุมัติแล้วคอร์สจะผูกกับห้องและจับคู่รายชื่อให้เอง',
        style: muted,
      ),
      if (_error != null) ...[
        const SizedBox(height: 16),
        Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
      ],
      const SizedBox(height: 24),
      FilledButton.icon(
        key: const ValueKey('import_link_existing'),
        onPressed: _busy ? null : () => _link(room),
        icon: _busy
            ? const SizedBox.square(
                dimension: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Icon(atOnce ? Icons.link : Icons.send_outlined),
        label: Text(
          atOnce ? 'ผูกกับห้อง ${room.name}' : 'ส่งคำขอผูกกับห้อง ${room.name}',
        ),
      ),
    ];
  }

  List<Widget> _createForm(ThemeData theme) {
    final preview = widget.preview;
    final kept = _kept;
    final errors = _numberErrors();
    final reused = _existingIds().length;
    final anyMatch = preview.students.any((s) => s.match != null);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return [
      TextFormField(
        key: const ValueKey('import_name'),
        controller: _name,
        maxLength: 100,
        decoration: const InputDecoration(labelText: 'ชื่อห้อง'),
        validator: (v) =>
            (v == null || v.trim().isEmpty) ? 'กรอกชื่อห้อง' : null,
      ),
      const SizedBox(height: 8),
      DropdownButtonFormField<int>(
        key: const ValueKey('import_grade'),
        initialValue: _grade,
        hint: const Text('เลือกระดับชั้น'),
        decoration: InputDecoration(
          labelText: 'ระดับชั้น',
          helperText: preview.gradeLevelGuess == null
              ? 'เดาระดับชั้นจากชื่อคอร์สไม่ได้ กรุณาเลือก'
              : null,
        ),
        items: [
          for (var level = 1; level <= 12; level++)
            DropdownMenuItem(value: level, child: Text(gradeLevelLabel(level))),
        ],
        validator: (v) => v == null ? 'เลือกระดับชั้น' : null,
        onChanged: (v) => setState(() => _grade = v),
      ),
      const SizedBox(height: 16),
      TextFormField(
        key: const ValueKey('import_year'),
        controller: _year,
        keyboardType: TextInputType.number,
        decoration: const InputDecoration(labelText: 'ปีการศึกษา (พ.ศ.)'),
        validator: (v) {
          final n = int.tryParse(v?.trim() ?? '');
          if (n == null || n < 2500 || n > 2700) {
            return 'กรอกปี พ.ศ. เช่น ${currentThaiYear()}';
          }
          return null;
        },
      ),
      const SizedBox(height: 24),
      Row(
        children: [
          Expanded(
            child: Text(
              reused == 0
                  ? 'นักเรียน ${kept.length} คน'
                  : 'นักเรียน ${kept.length} คน · ใช้บัญชีเดิม $reused คน',
              style: theme.textTheme.titleMedium,
            ),
          ),
          if (kept.isNotEmpty)
            TextButton.icon(
              key: const ValueKey('import_renumber'),
              onPressed: _busy ? null : _renumber,
              icon: const Icon(Icons.format_list_numbered),
              label: const Text('เรียงเลขที่ใหม่'),
            ),
        ],
      ),
      Text(
        'เลขที่เรียงตามชื่อ (ไม่นับคำนำหน้า) แก้เลขที่ได้ '
        'และกด "เอาออก" สำหรับบัญชีที่ไม่ใช่นักเรียน เช่น บัญชีทดสอบ'
        '${anyMatch ? ' คนที่ติ๊ก "ใช้บัญชีเดิม" ใช้ PIN และบัตร QR เดิม '
                  'เอาติ๊กออกถ้าไม่ใช่คนเดียวกัน (ระบบจะสร้างบัญชีใหม่)' : ''}',
        style: muted,
      ),
      const SizedBox(height: 8),
      if (kept.isEmpty)
        const Card(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Text(
              'ไม่มีนักเรียนที่จะเพิ่ม สร้างห้องได้ แล้วค่อยกด "ซิงก์รายชื่อ" '
              'เมื่อนักเรียนเข้าคอร์สแล้ว',
              textAlign: TextAlign.center,
            ),
          ),
        )
      else
        Card(
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              for (final s in kept) ...[
                _StudentRow(
                  student: s,
                  controller: _numbers[s.googleUserId]!,
                  error: errors[s.googleUserId],
                  enabled: !_busy,
                  useExisting: _useExisting.contains(s.googleUserId),
                  showNewLabel: anyMatch,
                  onChanged: () => setState(() {}),
                  onRemove: () => _remove(s.googleUserId),
                  onUseExisting: (v) => setState(() {
                    if (v) {
                      _useExisting.add(s.googleUserId);
                    } else {
                      _useExisting.remove(s.googleUserId);
                    }
                  }),
                ),
                if (s != kept.last) const Divider(height: 1),
              ],
            ],
          ),
        ),
      if (_removed.isNotEmpty) ...[
        const SizedBox(height: 16),
        Text(
          'เอาออกแล้ว ${_removed.length} บัญชี',
          style: theme.textTheme.titleSmall,
        ),
        const SizedBox(height: 4),
        Card(
          child: Column(
            children: [
              for (final s in preview.students)
                if (_removed.contains(s.googleUserId))
                  ListTile(
                    dense: true,
                    leading: const Icon(Icons.person_off_outlined),
                    title: Text(s.name),
                    subtitle: s.email == null ? null : Text(s.email!),
                    trailing: TextButton(
                      key: ValueKey('import_restore_${s.googleUserId}'),
                      onPressed: _busy ? null : () => _restore(s.googleUserId),
                      child: const Text('นำกลับ'),
                    ),
                  ),
            ],
          ),
        ),
      ],
      if (_error != null) ...[
        const SizedBox(height: 16),
        Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
      ],
      if (errors.isNotEmpty) ...[
        const SizedBox(height: 16),
        Text(
          'แก้เลขที่ที่ซ้ำหรือไม่ถูกต้องก่อนสร้างห้อง',
          style: TextStyle(color: theme.colorScheme.error),
        ),
      ],
      const SizedBox(height: 24),
      FilledButton.icon(
        key: const ValueKey('import_create'),
        onPressed: _busy || errors.isNotEmpty ? null : _submit,
        icon: _busy
            ? const SizedBox.square(
                dimension: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.add_home_work_outlined),
        label: const Text('สร้างห้อง'),
      ),
    ];
  }
}

class _StudentRow extends StatelessWidget {
  const _StudentRow({
    required this.student,
    required this.controller,
    required this.error,
    required this.enabled,
    required this.useExisting,
    required this.showNewLabel,
    required this.onChanged,
    required this.onRemove,
    required this.onUseExisting,
  });

  final ImportPreviewStudent student;
  final TextEditingController controller;
  final String? error;
  final bool enabled;

  /// The matched account is ticked to be used.
  final bool useExisting;

  /// Some account of the course matched: say which rows get a new account.
  final bool showNewLabel;
  final VoidCallback onChanged;
  final VoidCallback onRemove;
  final ValueChanged<bool> onUseExisting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final match = student.match;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 64,
            child: TextField(
              key: ValueKey('import_number_${student.googleUserId}'),
              controller: controller,
              enabled: enabled,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              inputFormatters: [
                FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(3),
              ],
              decoration: InputDecoration(
                isDense: true,
                labelText: 'เลขที่',
                // Red outline only; the message is under the name.
                error: error == null ? null : const SizedBox.shrink(),
              ),
              onChanged: (_) => onChanged(),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(student.name),
                if (student.email case final email?) Text(email, style: small),
                if (error case final message?)
                  Text(
                    message,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.error,
                    ),
                  ),
                if (match != null)
                  _MatchTile(
                    key: ValueKey('import_match_${student.googleUserId}'),
                    googleUserId: student.googleUserId,
                    match: match,
                    value: useExisting,
                    enabled: enabled,
                    onChanged: onUseExisting,
                  )
                else if (showNewLabel)
                  Text('บัญชีใหม่ (ได้ PIN ใหม่)', style: small),
              ],
            ),
          ),
          IconButton(
            key: ValueKey('import_remove_${student.googleUserId}'),
            tooltip: 'เอาออก',
            onPressed: enabled ? onRemove : null,
            icon: const Icon(Icons.person_remove_outlined),
          ),
        ],
      ),
    );
  }
}

/// "ใช้บัญชีเดิม" of a Classroom account the server matched to a student
/// of the school, with why and the rooms the student is in (DESIGN §24.10).
class _MatchTile extends StatelessWidget {
  const _MatchTile({
    super.key,
    required this.googleUserId,
    required this.match,
    required this.value,
    required this.enabled,
    required this.onChanged,
  });

  final String googleUserId;
  final ImportMatch match;
  final bool value;
  final bool enabled;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final weak = match.matchedBy == ImportMatchKind.name;
    final small = theme.textTheme.bodySmall?.copyWith(
      color: weak ? theme.colorScheme.tertiary : theme.colorScheme.primary,
    );
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox.square(
            dimension: 32,
            child: Checkbox(
              key: ValueKey('import_existing_$googleUserId'),
              value: value,
              onChanged: enabled ? (v) => onChanged(v ?? false) : null,
            ),
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'ใช้บัญชีเดิม: ${match.name}',
                    style: theme.textTheme.bodyMedium,
                  ),
                  Text(match.matchedBy.label, style: small),
                  Text(
                    match.classesLabel,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
