import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/router/app_router.dart';
import '../../courses/course_import_screen.dart';
import '../../courses/course_models.dart';
import '../../courses/courses_repository.dart';
import '../assistant.dart';
import '../assistant_plan.dart';
import 'plan_card_common.dart';

/// What a file card does with the files, in the teacher's words.
String _fileActionLabel(String? action) => switch (action) {
  'answer_key' => 'อ่านเฉลยจากไฟล์',
  'exam' => 'อ่านข้อสอบจากไฟล์',
  'course' => 'อ่านคำอธิบายหรือโครงสร้างรายวิชาจากไฟล์',
  'lesson_plan' => 'อ่านแผนการสอนจากไฟล์',
  _ => 'อ่านไฟล์',
};

class FilePlanOpen extends StatelessWidget {
  const FilePlanOpen({super.key, required this.message});

  final AssistantMessage message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final plan = message.plan!;
    final target = plan.target;
    final where = switch (plan.fileAction) {
      'course' => 'รายวิชาใหม่ (ตรวจแล้วนำเข้าเอง)',
      _ when target.title != null =>
        '«${target.title}»${target.isNew ? ' (งานใหม่ เป็นร่าง)' : ''}',
      _ => null,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(Icons.find_in_page_outlined, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                _fileActionLabel(plan.fileAction),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        if (where != null) Text('ไปที่: $where', style: muted),
        if (target.error case final error?)
          Text(
            '$error พิมพ์บอกผู้ช่วยว่าจะใช้กับงานหรือรายวิชาไหน',
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
          ),
        const Divider(height: 16),
        for (final d in plan.documents)
          Row(
            children: [
              Icon(Icons.description_outlined, size: 18, color: scheme.outline),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '${d.name} (${d.pageCount} หน้า)',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
            ],
          ),
        const SizedBox(height: 6),
        Text(
          'ระบบอ่านไฟล์อยู่เบื้องหลัง แล้วครูตรวจผลในหน้าปกติก่อนใช้',
          style: muted,
        ),
        PlanCardFooter(
          message: message,
          label: 'ส่งไฟล์ไปอ่าน',
          icon: Icons.send_outlined,
          enabled: target.error == null,
        ),
      ],
    );
  }
}

class FilePlanApplied extends ConsumerWidget {
  const FilePlanApplied({
    super.key,
    required this.plan,
    required this.result,
    required this.onNavigate,
  });

  final AssistantPlan plan;
  final PlanResult result;
  final ValueChanged<String> onNavigate;

  /// A course read opens the import screen once it is done.
  Future<void> _openImport(BuildContext context, WidgetRef ref) async {
    final repo = ref.read(coursesRepositoryProvider);
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);
    try {
      final extraction = await repo.extraction(result.extractionId!);
      if (extraction.status != 'done') {
        messenger.showSnackBar(
          SnackBar(
            content: Text(
              extraction.status == 'failed'
                  ? (extraction.error ?? 'อ่านไฟล์ไม่สำเร็จ')
                  : 'ยังอ่านไฟล์ไม่เสร็จ ลองอีกครั้งในอีกสักครู่',
            ),
          ),
        );
        return;
      }
      final lessonPlan = plan.fileAction == 'lesson_plan';
      final course = lessonPlan && result.courseId != null
          ? await repo.get(result.courseId!)
          : null;
      await navigator.push(
        MaterialPageRoute<void>(
          builder: (_) => CourseImportScreen(
            extraction: extraction,
            purpose: lessonPlan
                ? CourseDocumentPurpose.lessonPlan
                : CourseDocumentPurpose.course,
            course: course,
          ),
        ),
      );
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final (button, open) = switch (plan.fileAction) {
      'answer_key' => (
        'เปิดหน้าเฉลย',
        () => onNavigate(AppRoutes.answerKey(result.assignmentId!)),
      ),
      'exam' => (
        'เปิดข้อสอบ',
        () => onNavigate(AppRoutes.exam(result.assignmentId!)),
      ),
      _ => ('ตรวจและนำเข้า', () => _openImport(context, ref)),
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.check_circle, color: theme.colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                result.cached
                    ? 'อ่านไฟล์นี้ไว้แล้ว ได้ผลทันที'
                    : 'ส่งไฟล์ไปอ่านแล้ว',
                key: const ValueKey('assistant_plan_done'),
                style: theme.textTheme.titleSmall,
              ),
            ),
          ],
        ),
        Text(
          '${_fileActionLabel(plan.fileAction)} ใช้เวลาไม่กี่นาที ผลเป็นร่างให้ครูตรวจก่อนใช้',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            key: const ValueKey('assistant_plan_open'),
            onPressed: open,
            icon: const Icon(Icons.open_in_new),
            label: Text(button),
          ),
        ),
      ],
    );
  }
}

