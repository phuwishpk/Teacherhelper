import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/ai_guidance_field.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/answer_key_repository.dart';
import '../assignments/document_read_screen.dart';
import '../assignments/key_document_sources.dart';
import 'exam_import_models.dart';
import 'exam_import_repository.dart';
import 'exam_import_review_screen.dart';

/// Exam reads are cached for the whole school (DESIGN §22.17), and Gemini
/// must never see students' work.
const kExamReadPrivacyNote =
    'อย่าแนบไฟล์ที่มีชื่อหรือคำตอบของนักเรียน ผลการอ่านใช้ร่วมกันทั้งโรงเรียน';

/// "อ่านจากไฟล์ข้อสอบ" (DESIGN §22.4 item 2): pick or photograph the exam
/// file, upload it, show the cost with "คำแนะนำถึง AI", send the read once,
/// then open the review of the draft questions. Resolves when the teacher
/// leaves the review (or stopped before sending).
Future<void> runExamImport(
  BuildContext context,
  WidgetRef ref, {
  required int examId,
}) async {
  final source = await showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const ListTile(
            title: Text('อ่านข้อสอบจากไฟล์'),
            subtitle: Text(
              'AI อ่านครั้งเดียว สร้างตอนและข้อร่างพร้อมภาพประกอบ '
              'ครูตรวจและอนุมัติทุกข้อก่อนพิมพ์ '
              'ข้อเขียนตอบจะถูกข้าม ไฟล์ที่รับ: PDF, JPEG, PNG, HEIC, WebP',
            ),
          ),
          ListTile(
            leading: Icon(
              Icons.privacy_tip_outlined,
              color: Theme.of(context).colorScheme.error,
            ),
            title: const Text(kExamReadPrivacyNote),
          ),
          ListTile(
            key: const ValueKey('exam_read_file'),
            leading: const Icon(Icons.attach_file),
            title: const Text('แนบไฟล์ (PDF หรือรูป)'),
            onTap: () => Navigator.of(context).pop('file'),
          ),
          ListTile(
            key: const ValueKey('exam_read_photo'),
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('ถ่ายรูปข้อสอบ'),
            onTap: () => Navigator.of(context).pop('photo'),
          ),
        ],
      ),
    ),
  );
  if (source == null || !context.mounted) return;

  final List<PickedDocument> files;
  if (source == 'photo') {
    files =
        await Navigator.of(context).push<List<PickedDocument>>(
          MaterialPageRoute(
            builder: (_) => const KeyPhotoScreen(
              title: 'ถ่ายรูปข้อสอบ',
              hint: 'ถ่ายทีละหน้า ให้เห็นทั้งหน้า ภาพประกอบชัด',
              namePrefix: 'exam-page',
            ),
          ),
        ) ??
        const [];
  } else {
    files =
        (await ref
                .read(documentFilePickerProvider)
                .pick(dialogTitle: 'เลือกไฟล์ข้อสอบ'))
            .take(kMaxDocumentFiles)
            .toList();
  }
  if (files.isEmpty || !context.mounted) return;

  final List<SourceDocument> docs;
  try {
    showMessage(context, 'กำลังอัปโหลดไฟล์…');
    docs = await ref.read(answerKeyRepositoryProvider).upload(files);
  } catch (e) {
    if (context.mounted) showMessage(context, apiErrorMessage(e));
    return;
  }
  if (!context.mounted || docs.isEmpty) return;

  // The files picked now render their pages without a download; the
  // server answers the uploads in the order sent.
  final local = <int, PickedDocument>{
    if (docs.length == files.length)
      for (var i = 0; i < docs.length; i++) docs[i].id: files[i],
  };

  final repo = ref.read(examImportRepositoryProvider);
  final result = await Navigator.of(context).push<Object?>(
    MaterialPageRoute(
      builder: (_) => DocumentReadScreen.custom(
        title: 'ส่งให้ AI อ่านข้อสอบ',
        filesLabel: 'ไฟล์ข้อสอบ',
        rangeHint: 'เลือกช่วงหน้าที่มีข้อสอบ',
        note: kExamReadPrivacyNote,
        cachedMessage: 'โรงเรียนเคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย',
        readBefore: (d) => d.cachedPurposes.contains(kExamReadPurpose),
        documents: docs,
        guidanceHint: kGuidanceHintExamRead,
        estimator: (ref, ids, from, to, guidance) => repo.estimate(
          examId,
          documentIds: ids,
          pageFrom: from,
          pageTo: to,
          guidance: guidance,
        ),
        sender: (ref, ids, from, to, guidance) => repo.import(
          examId,
          documentIds: ids,
          pageFrom: from,
          pageTo: to,
          guidance: guidance,
        ),
      ),
    ),
  );
  if (result is! ExamImportResult || !context.mounted) return;

  await Navigator.of(context).push<void>(
    MaterialPageRoute(
      builder: (_) => ExamImportReviewScreen(
        examId: examId,
        result: result,
        localFiles: local,
      ),
    ),
  );
}
