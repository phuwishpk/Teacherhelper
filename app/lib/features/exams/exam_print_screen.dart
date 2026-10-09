import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import '../worksheets/pdf_files.dart';
import 'exam_models.dart';
import 'exam_print.dart';
import 'exam_providers.dart';

/// "พิมพ์ข้อสอบ" (DESIGN §22.6): a booklet per version with the copies to
/// print, the answer sheets of the class (or of chosen students), and the
/// teacher's key sheet. Each PDF renders on the server's `pdf` queue; the
/// row shows its progress, then open (Android), save/download and share.
class ExamPrintScreen extends ConsumerStatefulWidget {
  const ExamPrintScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamPrintScreen> createState() => _ExamPrintScreenState();
}

class _ExamPrintScreenState extends ConsumerState<ExamPrintScreen> {
  /// Copies the teacher set per version; the others follow the roster.
  final _copies = <int, int>{};

  /// Students chosen for the answer sheets; null = the whole class.
  Set<int>? _students;

  int get examId => widget.examId;

  ExamPrintsNotifier get _prints =>
      ref.read(examPrintsProvider(examId).notifier);

  /// The first print of any kind locks the structure (§22.6); ask once
  /// while the exam is still unlocked.
  Future<bool> _confirmLock(ExamDetail d) async {
    if (d.structureLocked) return true;
    return confirm(
      context,
      title: 'พิมพ์ครั้งแรกจะล็อกโครงสร้าง',
      message:
          'หลังพิมพ์ เพิ่ม ลบ หรือย้ายข้อและตอน เปลี่ยนจำนวนตัวเลือก '
          'จำนวนชุด และ "ห้ามสลับตัวเลือก" ไม่ได้ จนกว่าจะปลดล็อก '
          '(ต้องพิมพ์ใหม่ทั้งหมด) ข้อความ ภาพ คะแนน และเฉลยยังแก้ได้',
      confirmLabel: 'พิมพ์',
    );
  }

  Future<void> _print(ExamDetail d, List<ExamPrintRequest> requests) async {
    if (!await _confirmLock(d) || !mounted) return;
    await Future.wait(requests.map(_prints.run));
  }

  Future<void> _pickStudents(List<RosterStudent> roster) async {
    final picked = await showDialog<Set<int>>(
      context: context,
      builder: (_) => _StudentPicker(
        roster: roster,
        initial: _students ?? {for (final s in roster) s.studentId},
      ),
    );
    if (picked == null || !mounted) return;
    setState(() => _students = picked.length == roster.length ? null : picked);
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(examId));
    return Scaffold(
      appBar: AppBar(title: const Text('พิมพ์ข้อสอบ')),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(examDetailProvider(examId)),
        data: (d) => ContentColumn(
          padding: EdgeInsets.zero,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: _sections(d),
          ),
        ),
      ),
    );
  }

  List<Widget> _sections(ExamDetail d) {
    final theme = Theme.of(context);
    final roster = ref.watch(rosterProvider(d.exam.classroomId));
    final prints = ref.watch(examPrintsProvider(examId));
    final students = roster.value;
    final a = d.exam;
    return [
      Text(a.title, style: theme.textTheme.titleLarge),
      const SizedBox(height: 4),
      Text(
        [
          ?a.classroomName,
          switch (roster) {
            AsyncData(:final value) => 'นักเรียน ${value.length} คน',
            AsyncError() => 'โหลดรายชื่อนักเรียนไม่ได้',
            _ => 'กำลังโหลดรายชื่อ…',
          },
          '${d.questionCount} ข้อ',
        ].join(' · '),
      ),
      const SizedBox(height: 8),
      if (!d.structureLocked)
        const _Note(
          icon: Icons.lock_open_outlined,
          text:
              'การพิมพ์ครั้งแรก (เล่ม กระดาษคำตอบ หรือกระดาษเฉลย) '
              'ล็อกโครงสร้างข้อสอบ',
        )
      else
        const _Note(
          icon: Icons.lock_outline,
          text:
              'โครงสร้างถูกล็อกแล้ว ถ้าปลดล็อก ชุดข้อสอบจะถูกสุ่มใหม่ '
              'ต้องพิมพ์ทุกอย่างใหม่',
        ),
      if (d.isManual)
        const _Note(
          icon: Icons.edit_note,
          text:
              'ครูตรวจเอง: พิมพ์ได้เฉพาะเล่มข้อสอบ ไม่มีกระดาษคำตอบ '
              'กรอกคะแนนรวมในสมุดคะแนน',
        ),
      _bookletCard(d, prints, students?.length),
      if (!d.isManual) ...[
        _answerSheetCard(d, prints, roster),
        _keySheetCard(d, prints),
      ],
      const SizedBox(height: 8),
      Text(
        'PDF สร้างบนเซิร์ฟเวอร์ตามคิว (ทำงานทุก 1 นาที) อาจรอสักครู่ '
        'ออกจากหน้านี้ระหว่างรอได้ ไฟล์เก็บไว้ 30 วัน',
        style: theme.textTheme.bodySmall,
      ),
    ];
  }

  Widget _bookletCard(
    ExamDetail d,
    Map<ExamPrintTarget, ExamPrintState> prints,
    int? students,
  ) {
    final theme = Theme.of(context);
    final versions = d.exam.versionCount < 1 ? 1 : d.exam.versionCount;
    final blocker = ExamPrintReadiness.booklet(d);
    final defaults = examBookletCopies(students ?? 0, versions);
    int copiesOf(int v) => _copies[v] ?? defaults[v - 1];
    final total = [
      for (var v = 1; v <= versions; v++) copiesOf(v),
    ].fold(0, (a, b) => a + b);
    final anyBusy = [
      for (var v = 1; v <= versions; v++)
        prints[(kind: ExamPrintKind.booklet, versionNo: v)]?.busy ?? false,
    ].any((b) => b);
    return _KindCard(
      icon: Icons.menu_book_outlined,
      title: 'เล่มข้อสอบ',
      description: versions == 1
          ? 'หนึ่งไฟล์ ไม่มีชื่อนักเรียน สั่งพิมพ์ตามจำนวนสำเนา'
          : 'หนึ่งไฟล์ต่อชุด ไม่มีชื่อนักเรียน รหัสชุดอยู่ทุกหน้า '
                'แจกชุดสลับกันตามที่นั่ง นักเรียนฝนชุดในกระดาษคำตอบ',
      blocker: blocker,
      children: [
        for (var v = 1; v <= versions; v++)
          _PrintRow(
            id: 'booklet_$v',
            title: versions == 1 ? 'เล่มข้อสอบ' : 'ชุด ${examVersionLabel(v)}',
            extra: _CopiesStepper(
              id: '$v',
              copies: copiesOf(v),
              onChanged: (n) => setState(() => _copies[v] = n),
            ),
            state: prints[(kind: ExamPrintKind.booklet, versionNo: v)],
            enabled: blocker == null,
            shareSubject: [
              d.exam.title,
              if (versions > 1) 'ชุด ${examVersionLabel(v)}',
              'พิมพ์ ${copiesOf(v)} สำเนา',
            ].join(' · '),
            onPrint: () => _print(d, [ExamPrintRequest.booklet(v)]),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
          child: Text(
            key: const ValueKey('exam_copies_total'),
            [
              'รวม $total เล่ม',
              if (students != null) 'นักเรียน $students คน',
              if (students != null && total < students) 'ไม่พอทุกคน',
            ].join(' · '),
            style: students != null && total < students
                ? TextStyle(color: theme.colorScheme.error)
                : theme.textTheme.bodySmall,
          ),
        ),
        if (versions > 1)
          Align(
            alignment: Alignment.centerRight,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 4, 8, 0),
              child: TextButton.icon(
                key: const ValueKey('exam_print_all_booklets'),
                onPressed: blocker != null || anyBusy
                    ? null
                    : () => _print(d, [
                        for (var v = 1; v <= versions; v++)
                          ExamPrintRequest.booklet(v),
                      ]),
                icon: const Icon(Icons.library_books_outlined),
                label: Text('สร้าง PDF ทุกชุด ($versions ชุด)'),
              ),
            ),
          ),
      ],
    );
  }

  /// The shared answer sheet of an exam with the student-ID grid (DESIGN
  /// §22.19): one file for the class, whatever its size; no students to
  /// choose.
  Widget _sharedSheetCard(
    ExamDetail d,
    Map<ExamPrintTarget, ExamPrintState> prints,
  ) {
    final blocker = ExamPrintReadiness.answerSheets(d);
    final digits = d.exam.studentCodeDigits;
    return _KindCard(
      icon: Icons.assignment_outlined,
      title: 'กระดาษคำตอบ (ฝนเลขประจำตัว)',
      description:
          'ใบเดียวใช้ทั้งห้อง ถ่ายเอกสารแจกได้ ไม่มีชื่อและ QR รายคน '
          'นักเรียนเขียนชื่อและฝนเลขประจำตัว'
          '${digits == null ? '' : ' $digits หลัก'}'
          '${d.sheetPages > 0 ? ' · ${d.sheetPages} หน้า' : ''}',
      blocker: blocker,
      children: [
        _PrintRow(
          id: 'answer_sheet',
          title: 'กระดาษคำตอบใบกลาง',
          state: prints[(kind: ExamPrintKind.answerSheet, versionNo: null)],
          enabled: blocker == null,
          shareSubject: [d.exam.title, 'กระดาษคำตอบ'].join(' · '),
          onPrint: () => _print(d, [ExamPrintRequest.answerSheets()]),
        ),
      ],
    );
  }

  Widget _answerSheetCard(
    ExamDetail d,
    Map<ExamPrintTarget, ExamPrintState> prints,
    AsyncValue<List<RosterStudent>> roster,
  ) {
    if (d.exam.usesCodeSheets) return _sharedSheetCard(d, prints);
    final list = roster.value;
    final chosen = _students;
    final count = chosen?.length ?? list?.length;
    final blocker =
        ExamPrintReadiness.answerSheets(d, students: list?.length) ??
        (roster.hasError ? 'โหลดรายชื่อนักเรียนไม่ได้' : null);
    final numbers = chosen == null || list == null
        ? const <int>[]
        : [
            for (final s in list)
              if (chosen.contains(s.studentId)) s.studentNumber,
          ];
    return _KindCard(
      icon: Icons.assignment_outlined,
      title: 'กระดาษคำตอบ',
      description:
          'รายคน มีชื่อ เลขที่ ห้อง และ QR'
          '${d.sheetPages > 0 ? ' · คนละ ${d.sheetPages} หน้า' : ''} '
          'สร้างทีละ 20 คนแล้วรวมเป็นไฟล์เดียว',
      blocker: blocker,
      children: [
        ListTile(
          dense: true,
          leading: const Icon(Icons.groups_outlined),
          title: Text(
            key: const ValueKey('exam_sheet_students'),
            chosen == null
                ? 'ทั้งห้อง${list == null ? '' : ' (${list.length} คน)'}'
                : 'เลือก ${chosen.length} คน (เลขที่ ${numbers.join(', ')})',
          ),
          trailing: list == null || list.isEmpty
              ? null
              : chosen == null
              ? TextButton(
                  key: const ValueKey('exam_pick_students'),
                  onPressed: () => _pickStudents(list),
                  child: const Text('เลือกบางคน'),
                )
              : Wrap(
                  children: [
                    TextButton(
                      key: const ValueKey('exam_pick_students'),
                      onPressed: () => _pickStudents(list),
                      child: const Text('แก้'),
                    ),
                    TextButton(
                      key: const ValueKey('exam_whole_class'),
                      onPressed: () => setState(() => _students = null),
                      child: const Text('ทั้งห้อง'),
                    ),
                  ],
                ),
        ),
        _PrintRow(
          id: 'answer_sheet',
          title: 'กระดาษคำตอบ${count == null ? '' : ' $count คน'}',
          state: prints[(kind: ExamPrintKind.answerSheet, versionNo: null)],
          enabled: blocker == null && list != null,
          shareSubject: [
            d.exam.title,
            'กระดาษคำตอบ',
            if (count != null) '$count คน',
          ].join(' · '),
          onPrint: () => _print(d, [
            ExamPrintRequest.answerSheets(
              studentIds: chosen == null
                  ? null
                  : [
                      for (final s in list!)
                        if (chosen.contains(s.studentId)) s.studentId,
                    ],
            ),
          ]),
        ),
      ],
    );
  }

  Widget _keySheetCard(
    ExamDetail d,
    Map<ExamPrintTarget, ExamPrintState> prints,
  ) {
    final blocker = ExamPrintReadiness.keySheet(d);
    return _KindCard(
      icon: Icons.fact_check_outlined,
      title: 'กระดาษเฉลยของครู',
      description:
          'แบบเดียวกับกระดาษคำตอบ หัวกระดาษ "กระดาษเฉลย (สำหรับครู)" '
          'ไม่ต้องอนุมัติเฉลยก่อน ถ้าปลดล็อกโครงสร้างต้องพิมพ์ใหม่',
      blocker: blocker,
      children: [
        _PrintRow(
          id: 'key_sheet',
          title: 'กระดาษเฉลย',
          state: prints[(kind: ExamPrintKind.keySheet, versionNo: null)],
          enabled: blocker == null,
          shareSubject: '${d.exam.title} · กระดาษเฉลย',
          onPrint: () => _print(d, [const ExamPrintRequest.keySheet()]),
        ),
      ],
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final c = Theme.of(context).colorScheme.secondary;
    return Card(
      color: c.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: c),
            const SizedBox(width: 12),
            Expanded(child: Text(text)),
          ],
        ),
      ),
    );
  }
}

/// A card per kind: what it is, why it cannot print yet, and its rows.
class _KindCard extends StatelessWidget {
  const _KindCard({
    required this.icon,
    required this.title,
    required this.description,
    required this.children,
    this.blocker,
  });

  final IconData icon;
  final String title;
  final String description;
  final String? blocker;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final b = blocker;
    return Card(
      child: Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ListTile(
              leading: Icon(icon),
              title: Text(title, style: theme.textTheme.titleMedium),
              subtitle: Text(description),
            ),
            if (b != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      Icons.info_outline,
                      size: 18,
                      color: theme.colorScheme.error,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        b,
                        style: TextStyle(color: theme.colorScheme.error),
                      ),
                    ),
                  ],
                ),
              ),
            ...children,
          ],
        ),
      ),
    );
  }
}

class _CopiesStepper extends StatelessWidget {
  const _CopiesStepper({
    required this.id,
    required this.copies,
    required this.onChanged,
  });

  final String id;
  final int copies;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Text('สำเนา'),
        IconButton(
          key: ValueKey('exam_copies_minus_$id'),
          tooltip: 'ลดสำเนา',
          visualDensity: VisualDensity.compact,
          onPressed: copies > 0 ? () => onChanged(copies - 1) : null,
          icon: const Icon(Icons.remove_circle_outline),
        ),
        Text(
          '$copies',
          key: ValueKey('exam_copies_$id'),
          style: Theme.of(context).textTheme.titleMedium,
        ),
        IconButton(
          key: ValueKey('exam_copies_plus_$id'),
          tooltip: 'เพิ่มสำเนา',
          visualDensity: VisualDensity.compact,
          onPressed: copies < 999 ? () => onChanged(copies + 1) : null,
          icon: const Icon(Icons.add_circle_outline),
        ),
      ],
    );
  }
}

/// One PDF: the button, its progress, then the file actions.
class _PrintRow extends ConsumerWidget {
  const _PrintRow({
    required this.id,
    required this.title,
    required this.state,
    required this.enabled,
    required this.shareSubject,
    required this.onPrint,
    this.extra,
  });

  final String id;
  final String title;
  final Widget? extra;
  final ExamPrintState? state;
  final bool enabled;
  final String shareSubject;
  final VoidCallback onPrint;

  Future<void> _save(BuildContext context, WidgetRef ref, PdfFile f) async {
    final files = ref.read(pdfFilesProvider);
    try {
      final saved = await files.save(f);
      if (saved && context.mounted) {
        showMessage(context, 'บันทึก ${f.name} แล้ว');
      }
    } catch (e) {
      if (context.mounted) showMessage(context, 'บันทึกไฟล์ไม่ได้: $e');
    }
  }

  Future<void> _share(BuildContext context, WidgetRef ref, PdfFile f) async {
    try {
      await ref.read(pdfFilesProvider).share(f, subject: shareSubject);
    } catch (e) {
      if (context.mounted) showMessage(context, 'แชร์ไฟล์ไม่ได้: $e');
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final s = state;
    final file = s?.file;
    final files = ref.watch(pdfFilesProvider);
    final extra = this.extra;
    final Widget status;
    if (s == null) {
      status = Align(
        alignment: Alignment.centerRight,
        child: FilledButton.tonalIcon(
          key: ValueKey('exam_print_$id'),
          onPressed: enabled ? onPrint : null,
          icon: const Icon(Icons.picture_as_pdf_outlined),
          label: const Text('สร้าง PDF'),
        ),
      );
    } else if (s.busy) {
      status = Column(
        key: ValueKey('exam_print_progress_$id'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const LinearProgressIndicator(),
          const SizedBox(height: 4),
          Text(s.label, style: theme.textTheme.bodySmall),
        ],
      );
    } else if (s.phase == ExamPrintPhase.failed) {
      status = Row(
        children: [
          Expanded(
            child: Text(
              s.label,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
          TextButton(
            key: ValueKey('exam_print_retry_$id'),
            onPressed: enabled ? onPrint : null,
            child: const Text('ลองใหม่'),
          ),
        ],
      );
    } else {
      status = Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(file?.name ?? '', style: theme.textTheme.bodySmall),
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (files.canOpen)
                TextButton.icon(
                  key: ValueKey('exam_pdf_open_$id'),
                  onPressed: () => files.open(context, file!),
                  icon: const Icon(Icons.open_in_new),
                  label: const Text('เปิด'),
                ),
              TextButton.icon(
                key: ValueKey('exam_pdf_save_$id'),
                onPressed: () => _save(context, ref, file!),
                icon: const Icon(Icons.download_outlined),
                label: Text(files.saveLabel),
              ),
              TextButton.icon(
                key: ValueKey('exam_pdf_share_$id'),
                onPressed: () => _share(context, ref, file!),
                icon: const Icon(Icons.share_outlined),
                label: const Text('แชร์ / ส่งไปพิมพ์'),
              ),
              IconButton(
                key: ValueKey('exam_print_again_$id'),
                tooltip: 'สร้างใหม่',
                onPressed: enabled ? onPrint : null,
                icon: const Icon(Icons.refresh),
              ),
            ],
          ),
        ],
      );
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 8,
            children: [
              Text(title, style: theme.textTheme.titleSmall),
              ?extra,
            ],
          ),
          const SizedBox(height: 4),
          status,
          const Divider(height: 16),
        ],
      ),
    );
  }
}

/// Picks the students whose answer sheets to print (a lost or spoilt
/// sheet, a late enrolment).
class _StudentPicker extends StatefulWidget {
  const _StudentPicker({required this.roster, required this.initial});

  final List<RosterStudent> roster;
  final Set<int> initial;

  @override
  State<_StudentPicker> createState() => _StudentPickerState();
}

class _StudentPickerState extends State<_StudentPicker> {
  late final Set<int> _picked = {...widget.initial};

  @override
  Widget build(BuildContext context) {
    final all = _picked.length == widget.roster.length;
    return AlertDialog(
      title: const Text('เลือกนักเรียน'),
      content: SizedBox(
        width: 400,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CheckboxListTile(
              key: const ValueKey('exam_pick_all'),
              value: all,
              title: Text('ทั้งห้อง (${widget.roster.length} คน)'),
              onChanged: (v) => setState(() {
                _picked.clear();
                if (v == true) {
                  _picked.addAll(widget.roster.map((s) => s.studentId));
                }
              }),
            ),
            const Divider(height: 1),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final s in widget.roster)
                    CheckboxListTile(
                      key: ValueKey('exam_pick_student_${s.studentId}'),
                      dense: true,
                      value: _picked.contains(s.studentId),
                      title: Text('${s.studentNumber}. ${s.name}'),
                      onChanged: (v) => setState(
                        () => v == true
                            ? _picked.add(s.studentId)
                            : _picked.remove(s.studentId),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('exam_pick_done'),
          onPressed: _picked.isEmpty
              ? null
              : () => Navigator.of(context).pop(_picked),
          child: Text('ตกลง (${_picked.length} คน)'),
        ),
      ],
    );
  }
}
