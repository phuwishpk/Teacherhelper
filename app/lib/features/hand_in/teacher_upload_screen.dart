import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import '../home/teacher_attention.dart' show teacherAttentionProvider;
import '../review/review_repository.dart';
import 'hand_in_files.dart';
import 'hand_in_models.dart';
import 'hand_in_repository.dart';

/// What "อัปโหลดรูปเพื่อตรวจ" opens with: an assignment (from its detail
/// page) and/or files (photos the scan screen found no marker or QR on).
class TeacherUploadArgs {
  const TeacherUploadArgs({this.assignmentId, this.files = const []});

  final int? assignmentId;
  final List<PickedDocument> files;
}

/// "อัปโหลดรูปเพื่อตรวจ" (DESIGN §19.6): the teacher hands in a student's
/// work from files through the whole-page path. Subject -> assignment of
/// that subject (its classroom and how many handed in) -> student of that
/// classroom (search by name or number, "ส่งแล้ว" on those who did) -> 1–5
/// images or PDFs -> "ส่งตรวจ". Works in the Chrome preview too: nothing
/// here needs the native pipeline.
class TeacherUploadScreen extends ConsumerStatefulWidget {
  const TeacherUploadScreen({super.key, this.args = const TeacherUploadArgs()});

  final TeacherUploadArgs args;

  @override
  ConsumerState<TeacherUploadScreen> createState() =>
      _TeacherUploadScreenState();
}

/// Subject key of assignments without a subject (Classroom website work
/// before its key is approved, §19.3).
const _noSubject = -1;

/// Assignments a teacher can upload work for: everything but a worksheet
/// draft (no printed sheet yet). A freeform draft is kept until its key is
/// approved (`waiting_key`).
bool canUploadFor(Assignment a) => !a.isDraft || a.isFreeform;

int subjectKeyOf(Assignment a) => a.subjectId ?? _noSubject;

/// Roster rows matching [query]: part of the name, or the student number.
List<RosterStudent> filterStudents(List<RosterStudent> roster, String query) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return roster;
  return [
    for (final s in roster)
      if (s.name.toLowerCase().contains(q) || '${s.studentNumber}' == q) s,
  ];
}

class _TeacherUploadScreenState extends ConsumerState<TeacherUploadScreen> {
  int? _subject;
  Assignment? _assignment;
  RosterStudent? _student;
  final _search = TextEditingController();
  late var _files = [...widget.args.files];
  bool _preselected = false;
  bool _sending = false;
  bool _grading = false;
  double? _progress;
  String? _error;
  TeacherUploadResult? _result;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _preselect(List<Assignment> eligible) {
    if (_preselected) return;
    _preselected = true;
    final id = widget.args.assignmentId;
    final match = eligible.where((a) => a.id == id).firstOrNull;
    if (match != null) {
      _subject = subjectKeyOf(match);
      _assignment = match;
    } else {
      final subjects = {for (final a in eligible) subjectKeyOf(a)};
      if (subjects.length == 1) _subject = subjects.first;
    }
  }

  void _pickSubject(int key) => setState(() {
    if (_subject == key) return;
    _subject = key;
    _assignment = null;
    _student = null;
    _error = null;
  });

  void _pickAssignment(Assignment? a) => setState(() {
    _assignment = a;
    _student = null;
    _search.clear();
    _error = null;
  });

  Future<void> _send() async {
    final a = _assignment;
    final s = _student;
    if (a == null || s == null || _sending) return;
    if (handInFilesProblem(_files) != null) return;
    setState(() {
      _sending = true;
      _progress = 0;
      _error = null;
    });
    try {
      final result = await ref
          .read(handInRepositoryProvider)
          .uploadForStudent(
            a.id,
            s.studentId,
            _files,
            onProgress: (sent, total) {
              if (!mounted) return;
              setState(() => _progress = progressShare(sent, total));
            },
          );
      if (!mounted) return;
      ref
        ..invalidate(handedInProvider(a.id))
        ..invalidate(assignmentsProvider);
      setState(() => _result = result);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _gradeNow(TeacherUploadResult result) async {
    setState(() => _grading = true);
    try {
      await ref
          .read(reviewRepositoryProvider)
          .gradeSubmission(result.submissionId);
      if (!mounted) return;
      // The student's row is no longer "waiting for ตรวจ" (regrade_pending).
      if (_assignment case final a?) ref.invalidate(handedInProvider(a.id));
      ref
        ..invalidate(assignmentsProvider)
        ..invalidate(teacherAttentionProvider);
      setState(
        () => _result = TeacherUploadResult(
          submissionId: result.submissionId,
          studentId: result.studentId,
          pages: result.pages,
          grading: true,
        ),
      );
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _grading = false);
    }
  }

  void _next() => setState(() {
    _result = null;
    _student = null;
    _files = [];
    _search.clear();
  });

  @override
  Widget build(BuildContext context) {
    final assignments = ref.watch(assignmentsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('อัปโหลดรูปเพื่อตรวจ')),
      body: AsyncView(
        value: assignments,
        onRetry: () => ref.invalidate(assignmentsProvider),
        data: (all) {
          final eligible = all.where(canUploadFor).toList();
          _preselect(eligible);
          if (eligible.isEmpty) {
            return const EmptyView(
              icon: Icons.assignment_outlined,
              title: 'ยังไม่มีการบ้านที่รับงานได้',
              message:
                  'สร้างการบ้านและพิมพ์ใบงาน หรือสร้างงานแบบไม่ใช้ใบงานของแอป '
                  'แล้วกลับมาอัปโหลดงานของนักเรียนที่นี่',
            );
          }
          final result = _result;
          return FormColumn(
            maxWidth: 640,
            children: result != null
                ? [_resultCard(result)]
                : [
                    ..._subjectStep(eligible),
                    if (_subject != null) ..._assignmentStep(eligible),
                    if (_assignment case final a?) ..._studentStep(a),
                    if (_assignment != null && _student != null)
                      ..._filesStep(),
                  ],
          );
        },
      ),
    );
  }

  Widget _heading(String text) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 8),
    child: Text(text, style: Theme.of(context).textTheme.titleMedium),
  );

  List<Widget> _subjectStep(List<Assignment> eligible) {
    final names = <int, String>{};
    for (final a in eligible) {
      names[subjectKeyOf(a)] = a.subjectId == null
          ? 'ยังไม่ระบุวิชา'
          : a.subjectName ?? 'วิชา #${a.subjectId}';
    }
    final keys = names.keys.toList()
      ..sort((x, y) => names[x]!.compareTo(names[y]!));
    return [
      _heading('1. เลือกวิชา'),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final key in keys)
            ChoiceChip(
              key: ValueKey('upload_subject_$key'),
              label: Text(names[key]!),
              selected: _subject == key,
              onSelected: _sending ? null : (_) => _pickSubject(key),
            ),
        ],
      ),
    ];
  }

  List<Widget> _assignmentStep(List<Assignment> eligible) {
    final chosen = _assignment;
    final list = eligible.where((a) => subjectKeyOf(a) == _subject).toList();
    return [
      _heading('2. เลือกการบ้าน'),
      if (chosen != null)
        _AssignmentTile(
          assignment: chosen,
          selected: true,
          onTap: _sending ? null : () => _pickAssignment(null),
          trailing: TextButton(
            onPressed: _sending ? null : () => _pickAssignment(null),
            child: const Text('เปลี่ยน'),
          ),
        )
      else
        for (final a in list)
          _AssignmentTile(assignment: a, onTap: () => _pickAssignment(a)),
    ];
  }

  List<Widget> _studentStep(Assignment a) {
    final chosen = _student;
    final roster = ref.watch(rosterProvider(a.classroomId));
    // Supplementary: without it the list just has no "ส่งแล้ว" labels.
    final handedIn =
        ref.watch(handedInProvider(a.id)).value ?? const <int, HandedIn>{};
    return [
      _heading('3. เลือกนักเรียน'),
      if (chosen != null)
        _StudentTile(
          student: chosen,
          handedIn: handedIn[chosen.studentId],
          selected: true,
          onTap: _sending ? null : () => setState(() => _student = null),
          trailing: TextButton(
            onPressed: _sending ? null : () => setState(() => _student = null),
            child: const Text('เปลี่ยน'),
          ),
        )
      else
        AsyncView(
          value: roster,
          onRetry: () => ref.invalidate(rosterProvider(a.classroomId)),
          data: (students) {
            if (students.isEmpty) {
              return const Padding(
                padding: EdgeInsets.all(16),
                child: Text('ห้องนี้ยังไม่มีนักเรียน'),
              );
            }
            final shown = filterStudents(students, _search.text);
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  key: const ValueKey('upload_student_search'),
                  controller: _search,
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    labelText: 'ค้นหาด้วยชื่อหรือเลขที่',
                  ),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 8),
                Text(
                  'ส่งแล้ว ${handedIn.length} จาก ${students.length} คน',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 4),
                if (shown.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('ไม่พบนักเรียนที่ตรงกับคำค้น'),
                  ),
                for (final s in shown)
                  _StudentTile(
                    student: s,
                    handedIn: handedIn[s.studentId],
                    onTap: () => setState(() {
                      _student = s;
                      _error = null;
                    }),
                  ),
              ],
            );
          },
        ),
    ];
  }

  List<Widget> _filesStep() {
    final scheme = Theme.of(context).colorScheme;
    return [
      _heading('4. แนบรูปหรือ PDF ของงาน (1–$kMaxHandInFiles ไฟล์)'),
      HandInFilesPanel(
        files: _files,
        enabled: !_sending,
        allowCamera: false,
        pickerTitle: 'เลือกรูปหรือ PDF งานของนักเรียน',
        onChanged: (files) => setState(() {
          _files = files;
          _error = null;
        }),
      ),
      const SizedBox(height: 16),
      if (_sending) ...[
        UploadProgressBar(progress: _progress),
        const SizedBox(height: 12),
      ],
      if (_error case final error?) ...[
        Text(error, style: TextStyle(color: scheme.error)),
        const SizedBox(height: 12),
      ],
      FilledButton.icon(
        key: const ValueKey('upload_send'),
        onPressed: _sending || handInFilesProblem(_files) != null
            ? null
            : _send,
        icon: const Icon(Icons.send),
        label: const Text('ส่งตรวจ'),
      ),
      const SizedBox(height: 8),
      Text(
        'AI ตรวจจากรูปทั้งหน้า งานเป็นของนักเรียนที่เลือกไว้ ไม่อ่าน QR ในภาพ',
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ];
  }

  Widget _resultCard(TeacherUploadResult result) {
    final theme = Theme.of(context);
    final a = _assignment;
    final s = _student;
    final (title, message) = result.regradePending
        ? (
            'รับงานใหม่แล้ว รอครูกดตรวจ',
            'นักเรียนคนนี้มีงานที่ตรวจแล้ว กด "ตรวจงานใหม่" เพื่อตรวจงานนี้แทนงานเดิม',
          )
        : result.waitingKey
        ? ('เก็บงานไว้แล้ว', 'จะเริ่มตรวจเมื่ออนุมัติเฉลยของการบ้านนี้')
        : ('ส่งตรวจแล้ว', 'AI กำลังตรวจ ผลจะขึ้นในหน้าตรวจทานภายในไม่กี่นาที');
    return Card(
      key: const ValueKey('upload_result'),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Icon(Icons.task_alt, size: 56, color: theme.colorScheme.primary),
            const SizedBox(height: 12),
            Text(title, style: theme.textTheme.titleLarge),
            const SizedBox(height: 4),
            if (s != null && a != null)
              Text(
                'เลขที่ ${s.studentNumber} ${s.name} · ${a.title}'
                '${result.pages > 0 ? ' · ${result.pages} ไฟล์' : ''}',
                textAlign: TextAlign.center,
              ),
            const SizedBox(height: 8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                if (result.regradePending)
                  FilledButton.icon(
                    key: const ValueKey('upload_grade_now'),
                    onPressed: _grading ? null : () => _gradeNow(result),
                    icon: const Icon(Icons.play_arrow),
                    label: const Text('ตรวจงานใหม่'),
                  ),
                FilledButton.tonal(
                  key: const ValueKey('upload_next'),
                  onPressed: _next,
                  child: const Text('ส่งงานนักเรียนคนต่อไป'),
                ),
                if (a != null)
                  TextButton(
                    onPressed: () => context.push(AppRoutes.review(a.id)),
                    child: const Text('ไปหน้าตรวจทาน'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AssignmentTile extends StatelessWidget {
  const _AssignmentTile({
    required this.assignment,
    this.onTap,
    this.selected = false,
    this.trailing,
  });

  final Assignment assignment;
  final VoidCallback? onTap;
  final bool selected;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final a = assignment;
    final scheme = Theme.of(context).colorScheme;
    final count = a.submissionsCount;
    return Card(
      key: ValueKey('upload_assignment_${a.id}'),
      color: selected ? scheme.secondaryContainer : null,
      child: ListTile(
        leading: Icon(
          a.isFreeform ? Icons.photo_library_outlined : Icons.qr_code_2,
        ),
        title: Text(a.title),
        subtitle: Text(
          [
            a.classroomName ?? 'ห้อง #${a.classroomId}',
            count == null ? 'ส่งแล้ว - คน' : 'ส่งแล้ว $count คน',
            if (!a.keyApproved && a.isFreeform) 'รออนุมัติเฉลย',
            if (a.status == 'closed') 'ปิดรับแล้ว',
          ].join(' · '),
        ),
        trailing: trailing,
        onTap: onTap,
      ),
    );
  }
}

class _StudentTile extends StatelessWidget {
  const _StudentTile({
    required this.student,
    required this.handedIn,
    this.onTap,
    this.selected = false,
    this.trailing,
  });

  final RosterStudent student;
  final HandedIn? handedIn;
  final VoidCallback? onTap;
  final bool selected;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final h = handedIn;
    return Card(
      key: ValueKey('upload_student_${student.studentId}'),
      color: selected ? scheme.secondaryContainer : null,
      child: ListTile(
        leading: CircleAvatar(child: Text('${student.studentNumber}')),
        title: Text(student.name),
        subtitle: h == null
            ? null
            : Wrap(
                spacing: 6,
                runSpacing: 4,
                children: [
                  StatusChip(label: 'ส่งแล้ว', color: scheme.primary),
                  if (h.late) StatusChip(label: 'ส่งช้า', color: scheme.error),
                ],
              ),
        trailing: trailing,
        onTap: onTap,
      ),
    );
  }
}
