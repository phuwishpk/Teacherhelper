import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/ai_guidance_field.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/answer_key_repository.dart';
import '../assignments/document_read_screen.dart';
import '../assignments/key_document_sources.dart';
import 'course_import_screen.dart';
import 'course_models.dart';
import 'courses_providers.dart';
import 'courses_repository.dart';

/// Course documents are cached for the whole school (DESIGN §20.9), so they
/// must not carry anything about students.
const kCourseDocumentPrivacyNote =
    'ผลการอ่านเอกสารนี้ใช้ร่วมกันทั้งโรงเรียน ห้ามมีชื่อ เลขที่ '
    'หรือข้อมูลอื่นของนักเรียนในไฟล์';

/// "สร้างจากเอกสาร" / "นำเข้าแผนจากเอกสาร" (DESIGN §20.1): pick or photograph
/// the files, upload them, show the cost and send the read, then let the
/// teacher confirm what was read. Resolves to the saved course, or null
/// when the teacher stopped somewhere.
///
/// [course] is the course lesson plans are added to ([purpose] lessonPlan);
/// without it a new course is created.
Future<Course?> runCourseDocumentImport(
  BuildContext context,
  WidgetRef ref, {
  required CourseDocumentPurpose purpose,
  Course? course,
}) async {
  final source = await showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(
            title: Text('อ่าน${purpose.label}จากไฟล์'),
            subtitle: const Text(
              'AI อ่านครั้งเดียวแล้วเติมฟอร์มให้ ตรวจและแก้ก่อนบันทึก '
              'ไฟล์ที่รับ: PDF, JPEG, PNG, HEIC, WebP',
            ),
          ),
          ListTile(
            leading: Icon(
              Icons.privacy_tip_outlined,
              color: Theme.of(context).colorScheme.error,
            ),
            title: const Text(kCourseDocumentPrivacyNote),
          ),
          ListTile(
            key: const ValueKey('course_doc_file'),
            leading: const Icon(Icons.attach_file),
            title: const Text('แนบไฟล์ (PDF หรือรูป)'),
            onTap: () => Navigator.of(context).pop('file'),
          ),
          ListTile(
            key: const ValueKey('course_doc_photo'),
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('ถ่ายรูปเอกสาร'),
            onTap: () => Navigator.of(context).pop('photo'),
          ),
        ],
      ),
    ),
  );
  if (source == null || !context.mounted) return null;

  final List<PickedDocument> files;
  if (source == 'photo') {
    files =
        await Navigator.of(context).push<List<PickedDocument>>(
          MaterialPageRoute(
            builder: (_) => const KeyPhotoScreen(
              title: 'ถ่ายรูปเอกสาร',
              hint: 'ถ่ายทีละหน้า ให้เห็นทั้งหน้าและอ่านชัด',
              namePrefix: 'course-doc',
            ),
          ),
        ) ??
        const [];
  } else {
    files =
        (await ref
                .read(documentFilePickerProvider)
                .pick(dialogTitle: 'เลือกไฟล์${purpose.label}'))
            .take(kMaxDocumentFiles)
            .toList();
  }
  if (files.isEmpty || !context.mounted) return null;

  final List<SourceDocument> docs;
  try {
    showMessage(context, 'กำลังอัปโหลดไฟล์…');
    docs = await ref.read(answerKeyRepositoryProvider).upload(files);
  } catch (e) {
    if (context.mounted) showMessage(context, apiErrorMessage(e));
    return null;
  }
  if (!context.mounted) return null;

  final repo = ref.read(coursesRepositoryProvider);
  final memory = ref.read(courseGuidanceMemoryProvider.notifier);
  final extraction = await Navigator.of(context).push<Object?>(
    MaterialPageRoute(
      builder: (_) => DocumentReadScreen.custom(
        title: 'ส่งให้ AI อ่าน${purpose.label}',
        filesLabel: 'ไฟล์${purpose.label}',
        rangeHint: 'เลือกช่วงหน้าที่ต้องการให้อ่าน',
        note: kCourseDocumentPrivacyNote,
        cachedMessage: 'โรงเรียนเคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย',
        readBefore: (d) => d.cachedPurposes.contains(purpose.apiValue),
        documents: docs,
        guidanceHint: kGuidanceHintCourse,
        initialGuidance: ref.read(courseGuidanceMemoryProvider)[purpose],
        estimator: (ref, ids, from, to, guidance) => repo.estimate(
          purpose: purpose,
          documentIds: ids,
          pageFrom: from,
          pageTo: to,
          guidance: guidance,
        ),
        sender: (ref, ids, from, to, guidance) => repo.extract(
          purpose: purpose,
          documentIds: ids,
          pageFrom: from,
          pageTo: to,
          guidance: guidance,
        ),
      ),
    ),
  );
  if (extraction is! CourseExtraction) return null;
  memory.remember(purpose, extraction.guidance);
  if (!context.mounted) return null;

  return Navigator.of(context).push<Course>(
    MaterialPageRoute(
      builder: (_) => CourseImportScreen(
        extraction: extraction,
        purpose: purpose,
        course: course,
      ),
    ),
  );
}
