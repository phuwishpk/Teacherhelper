import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/assignment.dart';
import '../assignments/assignments_providers.dart';
import '../classrooms/classroom.dart';
import 'classroom_google_section.dart' show gradeReturnNote;
import 'google_browser_connect.dart' show externalUrlOpenerProvider;
import 'google_providers.dart';
import 'google_repository.dart';

/// Copies [link] and says so.
Future<void> copyLink(BuildContext context, String link) async {
  await Clipboard.setData(ClipboardData(text: link));
  if (context.mounted) {
    showMessage(context, 'คัดลอกลิงก์แล้ว เปิดในเบราว์เซอร์ได้');
  }
}

/// "เปิดใน Classroom": opens [link] (a Classroom https page) outside the
/// app, or copies it when nothing can open it.
Future<void> openInClassroom(
  BuildContext context,
  WidgetRef ref,
  String link,
) async {
  final url = Uri.tryParse(link);
  final opened =
      url != null &&
      url.scheme == 'https' &&
      await ref.read(externalUrlOpenerProvider)(url);
  if (!opened && context.mounted) await copyLink(context, link);
}

/// Shown on courseWork created on the Classroom website (DESIGN §19.3).
const webCourseWorkNote =
    'งานนี้สร้างในเว็บ Classroom แอปส่งคะแนนกลับให้ไม่ได้';

/// Google Docs/Sheets/Slides attached on the website cannot be read.
const googleDocsMaterialNote =
    'อ่านไฟล์ Google Docs ไม่ได้ บันทึกเป็น PDF แล้วแนบในแอป หรือพิมพ์เฉลยเอง';

/// "Google Classroom" card on the assignment detail (DESIGN §18.7): post the
/// assignment as courseWork, show its link, and fetch the submissions.
/// Hidden unless the server has Google Classroom set up
/// ([googleClassroomEnabledProvider]).
class AssignmentGoogleSection extends ConsumerStatefulWidget {
  const AssignmentGoogleSection({
    super.key,
    required this.assignment,
    this.classroom,
  });

  final Assignment assignment;
  final Classroom? classroom;

  @override
  ConsumerState<AssignmentGoogleSection> createState() =>
      _AssignmentGoogleSectionState();
}

class _AssignmentGoogleSectionState
    extends ConsumerState<AssignmentGoogleSection> {
  bool _busy = false;

  Future<void> _post() async {
    final a = widget.assignment;
    final choice = await showDialog<_PostChoice>(
      context: context,
      builder: (_) =>
          _PostDialog(courseName: widget.classroom?.googleLink?.courseName),
    );
    if (choice == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final due = a.dueAt;
      final link = await ref
          .read(googleClassroomRepositoryProvider)
          .post(
            a.id,
            attachBlankWorksheet: choice.attachBlankWorksheet,
            instructions: choice.instructions,
            dueAt: due != null && due.isAfter(DateTime.now()) ? due : null,
          );
      ref.read(assignmentDetailProvider(a.id).notifier).setGoogleLink(link);
      if (mounted) showMessage(context, 'โพสต์ลง Google Classroom แล้ว');
    } catch (e) {
      ref.read(googleStatusProvider.notifier).noteError(e);
      if (apiErrorCode(e) == 'already_posted') {
        ref.invalidate(assignmentDetailProvider(a.id));
      }
      if (mounted) showMessage(context, googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(googleClassroomEnabledProvider)) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    final a = widget.assignment;
    final link = a.googleLink;
    final course = widget.classroom?.googleLink;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    final List<Widget> content;
    if (link != null) {
      final web = link.fromClassroomWeb;
      final docs = link.materials.where((m) => !m.supported).toList();
      content = [
        if (web) ...[
          Text(
            webCourseWorkNote,
            key: const ValueKey('web_coursework_note'),
            style: TextStyle(color: theme.colorScheme.tertiary),
          ),
          const SizedBox(height: 4),
          Text(
            'ครูสร้างงานนี้ในเว็บ Classroom แอปนำเข้ามาให้ AI ตรวจงานที่ส่งได้ตามปกติ '
            'เมื่อเผยแพร่ผล นักเรียนได้ประกาศส่วนตัวที่มีคะแนนและคำอธิบายใน Classroom '
            'ส่วนคะแนนใน Classroom ใช้ "คัดลอกคะแนน" (หน้างานที่ส่ง หรือประกาศผลรายคน) แล้วกรอกเอง',
            style: muted,
          ),
          if (!a.keyApproved) ...[
            const SizedBox(height: 8),
            Text(
              'ระบบยังไม่ตรวจงานที่ส่งมาจนกว่าจะอนุมัติเฉลย '
              '(AI ร่างเฉลยจากชื่องาน คำอธิบาย และไฟล์ที่แนบในงานให้แล้ว)',
              style: TextStyle(color: theme.colorScheme.error),
            ),
            const SizedBox(height: 8),
            FilledButton.icon(
              key: const ValueKey('web_coursework_key'),
              onPressed: () => context.push(AppRoutes.answerKey(a.id)),
              icon: const Icon(Icons.fact_check_outlined),
              label: const Text('ตรวจและอนุมัติเฉลย'),
            ),
          ],
          if (link.materials.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              'ไฟล์ที่แนบในงาน: '
              '${link.materials.map((m) => m.supported ? m.title : '${m.title} (อ่านไม่ได้)').join(', ')}',
              style: muted,
            ),
          ],
          if (docs.any((m) => m.isGoogleDoc)) ...[
            const SizedBox(height: 4),
            Text(
              googleDocsMaterialNote,
              key: const ValueKey('google_docs_note'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ] else
          Text(
            [
              if (link.postedAt != null)
                'โพสต์แล้ว ${formatThaiDateTime(link.postedAt!)}'
              else
                'โพสต์แล้ว',
              if (link.hasBlankWorksheet) 'แนบใบงานสำรองแล้ว',
            ].join(' · '),
          ),
        if (link.alternateLink.isNotEmpty) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: SelectableText(
                  link.alternateLink,
                  key: const ValueKey('google_alternate_link'),
                  maxLines: 2,
                  style: theme.textTheme.bodySmall,
                ),
              ),
              IconButton(
                tooltip: 'คัดลอกลิงก์',
                icon: const Icon(Icons.copy),
                onPressed: () => copyLink(context, link.alternateLink),
              ),
            ],
          ),
        ],
        const SizedBox(height: 4),
        Text(
          link.lastSyncedAt == null
              ? 'ยังไม่ได้ซิงก์งานที่ส่ง ระบบซิงก์ให้เองทุก 5 นาที'
              : 'ซิงก์งานที่ส่งล่าสุด ${formatThaiDateTime(link.lastSyncedAt!)} '
                    '(ซิงก์เองทุก 5 นาที)',
          key: const ValueKey('assignment_last_synced'),
          style: muted,
        ),
        if (!web) ...[
          const SizedBox(height: 4),
          Text(
            'นักเรียนถ่ายรูปหรือแนบ PDF ส่งใน Classroom เซิร์ฟเวอร์ดาวน์โหลดไฟล์และให้ AI ตรวจจากรูปทั้งหน้าเอง '
            'กด "ดึงงานที่ส่ง" เพื่อดูสถานะ เมื่อเผยแพร่ผล ระบบส่งคะแนนกลับ Classroom '
            'และส่งประกาศส่วนตัวที่มีคำอธิบายรายข้อถึงนักเรียนให้เอง',
            style: muted,
          ),
        ],
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilledButton.tonalIcon(
              key: const ValueKey('google_fetch_submissions'),
              onPressed: () => context.push(AppRoutes.googleSubmissions(a.id)),
              icon: const Icon(Icons.cloud_download_outlined),
              label: const Text('ดึงงานที่ส่ง'),
            ),
            if (link.alternateLink.isNotEmpty)
              OutlinedButton.icon(
                key: const ValueKey('open_in_classroom'),
                onPressed: () =>
                    openInClassroom(context, ref, link.alternateLink),
                icon: const Icon(Icons.open_in_new),
                label: const Text('เปิดใน Classroom'),
              ),
            OutlinedButton.icon(
              key: const ValueKey('open_grade_conflicts'),
              onPressed: () => context.push(AppRoutes.gradeConflicts(a.id)),
              icon: const Icon(Icons.compare_arrows),
              label: const Text('คะแนนไม่ตรงกัน'),
            ),
            OutlinedButton.icon(
              key: const ValueKey('open_google_feedback'),
              onPressed: () => context.push(AppRoutes.googleFeedback(a.id)),
              icon: const Icon(Icons.campaign_outlined),
              label: const Text('ประกาศผลรายคน'),
            ),
          ],
        ),
      ];
    } else if (course == null) {
      content = [
        Text(
          'ห้อง ${widget.classroom?.name ?? a.classroomName ?? ''} ยังไม่ได้ผูกกับ Google Classroom '
          'ผูกที่หน้าห้องเรียนก่อน แล้วจึงโพสต์การบ้านนี้ได้',
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () => context.push(AppRoutes.classroom(a.classroomId)),
          icon: const Icon(Icons.meeting_room_outlined),
          label: const Text('ไปหน้าห้องเรียน'),
        ),
      ];
    } else {
      // §18.6: only a `ready` assignment (printable from its layout, or a
      // freeform one whose key is approved, §19.5).
      final ready = a.status == 'ready';
      content = [
        Text('โพสต์เป็นงานในคอร์ส ${course.courseName}'),
        const SizedBox(height: 4),
        Text(gradeReturnNote, style: muted),
        if (!ready) ...[
          const SizedBox(height: 8),
          Text(
            a.isFreeform
                ? 'โพสต์ได้เมื่อการบ้านอยู่ในสถานะ "พร้อมใช้" (อนุมัติเฉลยแล้ว)'
                : 'โพสต์ได้เมื่อการบ้านอยู่ในสถานะ "พร้อมใช้" '
                      '(อนุมัติ rubric ครบและสร้าง layout แล้ว)',
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ],
        const SizedBox(height: 12),
        FilledButton.icon(
          key: const ValueKey('google_post'),
          onPressed: _busy || !ready ? null : _post,
          icon: _busy
              ? const SizedBox.square(
                  dimension: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.send_outlined),
          label: const Text('โพสต์ลง Classroom'),
        ),
      ];
    }

    return Card(
      key: const ValueKey('assignment_google_section'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.school_outlined, color: theme.colorScheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Google Classroom',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (link != null && link.fromClassroomWeb)
                  StatusChip(
                    key: const ValueKey('web_coursework_chip'),
                    label: 'สร้างในเว็บ Classroom',
                    color: theme.colorScheme.tertiary,
                  )
                else if (link != null)
                  StatusChip(label: 'โพสต์แล้ว', color: Colors.green.shade700),
              ],
            ),
            const SizedBox(height: 8),
            ...content,
          ],
        ),
      ),
    );
  }
}

class _PostChoice {
  const _PostChoice({required this.attachBlankWorksheet, this.instructions});

  final bool attachBlankWorksheet;
  final String? instructions;
}

class _PostDialog extends StatefulWidget {
  const _PostDialog({this.courseName});

  final String? courseName;

  @override
  State<_PostDialog> createState() => _PostDialogState();
}

class _PostDialogState extends State<_PostDialog> {
  bool _attachBlank = true;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(
        widget.courseName == null
            ? 'โพสต์ลง Google Classroom'
            : 'โพสต์ลง ${widget.courseName}',
      ),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'นักเรียนจะเห็นงานพร้อมคำสั่ง "ทำบนใบงานที่ได้รับ ถ่ายรูปทุกหน้าให้เห็นมุมทั้ง 4 แล้วส่งที่นี่" '
              'คะแนนเต็มเท่ากับคะแนนของการบ้านนี้',
            ),
            const SizedBox(height: 8),
            CheckboxListTile(
              key: const ValueKey('google_attach_blank'),
              contentPadding: EdgeInsets.zero,
              value: _attachBlank,
              onChanged: (v) => setState(() => _attachBlank = v ?? false),
              title: const Text('แนบใบงานสำรอง'),
              subtitle: const Text(
                'ใบงานไม่ระบุชื่อ สำหรับนักเรียนที่ทำใบงานหาย พิมพ์หรือเปิดทำแล้วถ่ายรูปส่ง',
              ),
            ),
            TextField(
              controller: _note,
              maxLines: 3,
              maxLength: 500,
              decoration: const InputDecoration(
                labelText: 'ข้อความถึงนักเรียนเพิ่มเติม (ไม่บังคับ)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 4),
            Text(
              '$gradeReturnNote และโพสต์ซ้ำไม่ได้',
              style: Theme.of(context).textTheme.bodySmall,
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
          key: const ValueKey('google_post_confirm'),
          onPressed: () => Navigator.of(context).pop(
            _PostChoice(
              attachBlankWorksheet: _attachBlank,
              instructions: _note.text.trim().isEmpty
                  ? null
                  : _note.text.trim(),
            ),
          ),
          child: const Text('โพสต์'),
        ),
      ],
    );
  }
}
