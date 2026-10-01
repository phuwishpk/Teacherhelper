import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../../core/widgets/content_column.dart';
import '../home/teacher_attention.dart';
import '../review/review_providers.dart';
import '../settings/ai_key_errors.dart';
import 'answer_key_models.dart';
import 'assignments_providers.dart';

int _int(Object? v) => (v as num?)?.toInt() ?? 0;

/// `POST /assignments/{id}/regrade/estimate` (DESIGN §21.13): what
/// "ตรวจใหม่ทั้งห้อง" would do with the current approved key, and an upper
/// bound of its cost. Free, changes nothing.
class RegradeEstimate {
  const RegradeEstimate({
    this.submissions = 0,
    this.queuedResponses = 0,
    this.mcqByCode = 0,
    this.wholePagePages = 0,
    this.skippedOverridden = 0,
    this.skippedInProgress = 0,
    this.skippedMissingImage = 0,
    this.publishedSubmissions = 0,
    this.inProgress = false,
    this.estimate = const CostEstimate(inputTokens: 0, outputTokens: 0),
  });

  /// Submissions with at least one answer to grade again.
  final int submissions;

  /// Answers Gemini reads again (crops and whole-page answers).
  final int queuedResponses;

  /// Multiple-choice answers scored again by code (free).
  final int mcqByCode;

  /// Whole-page photos or files read again.
  final int wholePagePages;

  /// Answers the teacher decided (kept unless `include_overridden`).
  final int skippedOverridden;
  final int skippedInProgress;
  final int skippedMissingImage;

  /// Published submissions that are reopened for review when they change.
  final int publishedSubmissions;

  /// A class regrade is still grading (a new one answers 409).
  final bool inProgress;
  final CostEstimate estimate;

  /// Something of this assignment was graded: the button is worth showing.
  bool get hasGraded =>
      submissions +
          skippedOverridden +
          skippedInProgress +
          skippedMissingImage >
      0;

  /// Nothing would change: confirming is pointless.
  bool get nothingToDo => submissions == 0;

  /// Gemini reads something (costs money).
  bool get usesAi => queuedResponses > 0 || wholePagePages > 0;

  factory RegradeEstimate.fromJson(Map<String, dynamic> json) =>
      RegradeEstimate(
        submissions: _int(json['submissions']),
        queuedResponses: _int(json['queued_responses']),
        mcqByCode: _int(json['mcq_by_code']),
        wholePagePages: _int(json['whole_page_pages']),
        skippedOverridden: _int(json['skipped_overridden']),
        skippedInProgress: _int(json['skipped_in_progress']),
        skippedMissingImage: _int(json['skipped_missing_image']),
        publishedSubmissions: _int(json['published_submissions']),
        inProgress: json['in_progress'] == true,
        estimate:
            CostEstimate.maybe(json['estimate']) ??
            const CostEstimate(inputTokens: 0, outputTokens: 0),
      );
}

/// The answer of `POST /assignments/{id}/regrade`.
class RegradeOutcome {
  const RegradeOutcome({
    this.queuedSubmissions = 0,
    this.skippedOverridden = 0,
    this.queuedResponses = 0,
    this.rescoredByCode = 0,
    this.skippedInProgress = 0,
    this.skippedMissingImage = 0,
    this.reopenedSubmissions = 0,
  });

  final int queuedSubmissions;
  final int skippedOverridden;
  final int queuedResponses;
  final int rescoredByCode;
  final int skippedInProgress;
  final int skippedMissingImage;
  final int reopenedSubmissions;

  factory RegradeOutcome.fromJson(Map<String, dynamic> json) => RegradeOutcome(
    queuedSubmissions: _int(json['queued_submissions']),
    skippedOverridden: _int(json['skipped_overridden']),
    queuedResponses: _int(json['queued_responses']),
    rescoredByCode: _int(json['rescored_by_code']),
    skippedInProgress: _int(json['skipped_in_progress']),
    skippedMissingImage: _int(json['skipped_missing_image']),
    reopenedSubmissions: _int(json['reopened_submissions']),
  );

  /// The snack bar after the run.
  String get summary {
    final parts = <String>[
      if (queuedResponses > 0) 'AI กำลังอ่านใหม่ $queuedResponses ข้อ',
      if (rescoredByCode > 0) 'คิดคะแนนปรนัยใหม่ $rescoredByCode ข้อ',
      if (reopenedSubmissions > 0)
        'เปิดงานที่เผยแพร่แล้วกลับมาตรวจทาน $reopenedSubmissions งาน',
      if (skippedOverridden > 0)
        'ข้ามข้อที่ครูแก้คะแนนเอง $skippedOverridden ข้อ',
    ];
    if (queuedSubmissions == 0) {
      return queuedResponses == 0 && rescoredByCode == 0 && parts.isEmpty
          ? 'ตรวจใหม่แล้ว ไม่มีคะแนนที่เปลี่ยน'
          : 'ตรวจใหม่แล้ว ${parts.join(' · ')}';
    }
    return 'เริ่มตรวจใหม่ $queuedSubmissions งาน'
        '${parts.isEmpty ? '' : ' · ${parts.join(' · ')}'}';
  }
}

/// "ตรวจใหม่ทั้งห้อง" after the answer key changed (DESIGN §21.13).
abstract class ClassRegradeRepository {
  /// `POST /assignments/{id}/regrade/estimate`; 409
  /// `answer_key_not_approved`.
  Future<RegradeEstimate> estimate(
    int assignmentId, {
    bool includeOverridden = false,
  });

  /// `POST /assignments/{id}/regrade`: 202 (200 when nothing changed); 409
  /// `answer_key_not_approved` / `regrade_in_progress`, 422
  /// `ai_key_missing`.
  Future<RegradeOutcome> regrade(
    int assignmentId, {
    bool includeOverridden = false,
  });
}

class ApiClassRegradeRepository implements ClassRegradeRepository {
  ApiClassRegradeRepository(this._dio);

  final Dio _dio;

  @override
  Future<RegradeEstimate> estimate(
    int assignmentId, {
    bool includeOverridden = false,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/regrade/estimate',
      data: {'include_overridden': includeOverridden},
    );
    return RegradeEstimate.fromJson(unwrapJson(res.data));
  }

  @override
  Future<RegradeOutcome> regrade(
    int assignmentId, {
    bool includeOverridden = false,
  }) async {
    final res = await _dio.post<Object?>(
      '/assignments/$assignmentId/regrade',
      data: {'include_overridden': includeOverridden},
    );
    return RegradeOutcome.fromJson(unwrapJson(res.data));
  }
}

final classRegradeRepositoryProvider = Provider<ClassRegradeRepository>(
  (ref) => ApiClassRegradeRepository(ref.watch(dioProvider)),
);

/// The default estimate (overridden answers skipped) of an assignment with
/// an approved key, or null when it cannot be asked (e.g. 409 while the key
/// is not approved): decides whether "ตรวจใหม่ทั้งห้อง" is shown.
final regradeEstimateProvider = FutureProvider.autoDispose
    .family<RegradeEstimate?, int>((ref, assignmentId) async {
      watchSignedInUser(ref, keepAlive: false);
      try {
        return await ref
            .watch(classRegradeRepositoryProvider)
            .estimate(assignmentId);
      } catch (_) {
        return null;
      }
    });

/// Thai text of the regrade errors (DESIGN §21.13).
const _regradeMessages = {
  'regrade_in_progress':
      'กำลังตรวจใหม่ทั้งห้องอยู่ รอให้ตรวจเสร็จก่อนแล้วลองอีกครั้ง',
  'answer_key_not_approved': 'อนุมัติเฉลยก่อน จึงตรวจใหม่ทั้งห้องได้',
  'ai_key_missing':
      'ยังไม่ได้ใส่ Gemini API key ต้องใช้ key เพื่อให้ AI อ่านคำตอบใหม่ '
      'ใส่ได้ที่หน้าตั้งค่า แล้วลองอีกครั้ง',
};

/// "ตรวจใหม่ทั้งห้อง": asks the estimate, shows [ClassRegradeDialog] (counts,
/// cost, "รวมข้อที่ครูแก้คะแนนเองด้วย"), runs the regrade on confirm and
/// refreshes the assignment and its review queue. Resolves to the outcome,
/// or null when the teacher stopped or it failed (the error is shown).
Future<RegradeOutcome?> runClassRegrade(
  BuildContext context,
  int assignmentId,
) async {
  // The container outlives the widget that started the run, so the
  // providers are refreshed even if the teacher left the screen meanwhile.
  final container = ProviderScope.containerOf(context, listen: false);
  final repo = container.read(classRegradeRepositoryProvider);
  final RegradeEstimate first;
  try {
    first = await repo.estimate(assignmentId);
  } catch (e) {
    if (context.mounted) showAiError(context, e, messages: _regradeMessages);
    return null;
  }
  if (!context.mounted) return null;
  final include = await showDialog<bool>(
    context: context,
    builder: (_) => ClassRegradeDialog(
      initial: first,
      estimator: (include) =>
          repo.estimate(assignmentId, includeOverridden: include),
    ),
  );
  if (include == null || !context.mounted) return null;
  try {
    final outcome = await repo.regrade(
      assignmentId,
      includeOverridden: include,
    );
    refreshAfterRegrade(container, assignmentId);
    if (context.mounted) showMessage(context, outcome.summary);
    return outcome;
  } catch (e) {
    if (apiErrorCode(e) == 'regrade_in_progress') {
      container.invalidate(regradeEstimateProvider(assignmentId));
    }
    if (context.mounted) showAiError(context, e, messages: _regradeMessages);
    return null;
  }
}

/// Scores and statuses of the assignment changed.
void refreshAfterRegrade(ProviderContainer container, int assignmentId) {
  container
    ..invalidate(regradeEstimateProvider(assignmentId))
    ..invalidate(assignmentDetailProvider(assignmentId))
    ..invalidate(assignmentsProvider)
    ..invalidate(reviewQueueProvider(assignmentId))
    ..invalidate(responseDetailProvider)
    ..invalidate(teacherAttentionProvider);
}

/// The confirmation of "ตรวจใหม่ทั้งห้อง": what is graded again, what is
/// skipped, which published work reopens and the cost. The checkbox
/// "รวมข้อที่ครูแก้คะแนนเองด้วย" asks the estimate again. Pops the
/// `include_overridden` value to send, or null when cancelled.
class ClassRegradeDialog extends StatefulWidget {
  const ClassRegradeDialog({
    super.key,
    required this.initial,
    required this.estimator,
  });

  final RegradeEstimate initial;
  final Future<RegradeEstimate> Function(bool includeOverridden) estimator;

  @override
  State<ClassRegradeDialog> createState() => _ClassRegradeDialogState();
}

class _ClassRegradeDialogState extends State<ClassRegradeDialog> {
  late RegradeEstimate _estimate = widget.initial;
  bool _include = false;
  bool _loading = false;
  String? _error;
  int _serial = 0;

  /// Overridden answers exist (skipped now, or included by the checkbox).
  late final bool _hasOverridden = widget.initial.skippedOverridden > 0;

  Future<void> _setInclude(bool value) async {
    final serial = ++_serial;
    setState(() {
      _include = value;
      _loading = true;
      _error = null;
    });
    try {
      final next = await widget.estimator(value);
      if (!mounted || serial != _serial) return;
      setState(() {
        _estimate = next;
        _loading = false;
      });
    } catch (e) {
      if (!mounted || serial != _serial) return;
      setState(() {
        _error = apiErrorMessage(e);
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final e = _estimate;
    final canConfirm =
        !_loading && _error == null && !e.inProgress && !e.nothingToDo;
    Widget line(String text, {Key? key, Color? color}) => Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Text(
        text,
        key: key,
        style: TextStyle(color: color),
      ),
    );
    return AlertDialog(
      title: const Text('ตรวจใหม่ทั้งห้อง?'),
      scrollable: true,
      content: SizedBox(
        width: 480,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'ตรวจงานที่ตรวจไปแล้วใหม่ด้วยเฉลยที่อนุมัติล่าสุด '
              'คำแนะนำถึง AI ไม่ถูกใช้ในการตรวจ',
            ),
            const SizedBox(height: 12),
            if (_loading) ...[
              const LinearProgressIndicator(),
              const SizedBox(height: 8),
            ],
            line(
              'งานที่จะตรวจใหม่: ${e.submissions} งาน',
              key: const ValueKey('regrade_submissions'),
            ),
            line(
              'ข้อที่ AI อ่านใหม่: ${e.queuedResponses} ข้อ',
              key: const ValueKey('regrade_queued'),
            ),
            line(
              'ปรนัยที่คิดคะแนนใหม่ด้วยโค้ด (ไม่เสียค่าใช้จ่าย): ${e.mcqByCode} ข้อ',
            ),
            if (e.wholePagePages > 0)
              line('หน้าที่ AI อ่านใหม่ทั้งหน้า: ${e.wholePagePages} หน้า'),
            if (e.skippedOverridden > 0)
              line(
                'ข้อที่ครูแก้คะแนนเองซึ่งจะข้าม: ${e.skippedOverridden} ข้อ',
                key: const ValueKey('regrade_skipped_overridden'),
              ),
            if (e.skippedInProgress > 0)
              line('ข้อที่กำลังตรวจอยู่ (ข้าม): ${e.skippedInProgress} ข้อ'),
            if (e.skippedMissingImage > 0)
              line(
                'ข้อที่รูปถูกลบแล้ว ตรวจใหม่ไม่ได้ (ข้าม): '
                '${e.skippedMissingImage} ข้อ',
              ),
            if (e.publishedSubmissions > 0)
              line(
                'งานที่เผยแพร่แล้ว ${e.publishedSubmissions} งาน จะถูกเปิดกลับมาให้ตรวจทาน '
                'ถ้าคะแนนเปลี่ยน นักเรียนจะเห็นคะแนนใหม่หลังเผยแพร่อีกครั้ง',
                key: const ValueKey('regrade_published'),
                color: theme.colorScheme.error,
              ),
            const SizedBox(height: 8),
            Text(
              'ค่าใช้จ่ายสูงสุดโดยประมาณ',
              style: theme.textTheme.labelLarge,
            ),
            Text(
              !e.usesAi
                  ? 'ไม่เสียค่าใช้จ่าย'
                  : e.estimate.thb == null
                  ? '${e.estimate.label} (ยังไม่ได้ตั้งราคาที่เครื่องแม่ข่าย)'
                  : e.estimate.label,
              key: const ValueKey('regrade_cost'),
            ),
            if (_hasOverridden)
              CheckboxListTile(
                key: const ValueKey('regrade_include_overridden'),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _include,
                onChanged: _loading ? null : (v) => _setInclude(v ?? false),
                title: const Text('รวมข้อที่ครูแก้คะแนนเองด้วย'),
                subtitle: const Text('คะแนนที่ครูแก้ไว้จะถูกแทนด้วยผลตรวจใหม่'),
              ),
            if (e.inProgress)
              line(
                'กำลังตรวจใหม่ทั้งห้องอยู่ รอให้ตรวจเสร็จก่อนแล้วลองอีกครั้ง',
                key: const ValueKey('regrade_in_progress'),
                color: theme.colorScheme.error,
              )
            else if (e.nothingToDo && !_loading)
              line('ไม่มีข้อที่ต้องตรวจใหม่'),
            if (_error case final error?)
              line(error, color: theme.colorScheme.error),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          key: const ValueKey('regrade_confirm'),
          onPressed: canConfirm
              ? () => Navigator.of(context).pop(_include)
              : null,
          child: const Text('ตรวจใหม่'),
        ),
      ],
    );
  }
}

/// On the answer-key screen once the key is approved and something was
/// graded (DESIGN §21.13): "ตรวจใหม่ทั้งห้อง" after the key was fixed.
class ClassRegradeCard extends ConsumerWidget {
  const ClassRegradeCard({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estimate = ref.watch(regradeEstimateProvider(assignmentId)).value;
    if (estimate == null || !estimate.hasGraded) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Card(
        key: const ValueKey('class_regrade_card'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('ตรวจใหม่ทั้งห้อง', style: theme.textTheme.titleMedium),
              const SizedBox(height: 4),
              Text(
                estimate.inProgress
                    ? 'กำลังตรวจใหม่ทั้งห้องอยู่ ดูผลได้ที่หน้าตรวจทาน'
                    : 'แก้เฉลยหลังตรวจไปแล้ว? ตรวจงานที่ตรวจแล้วทั้งห้องใหม่ด้วยเฉลยนี้ '
                          'แทนการแก้ทีละคน',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 8),
              FilledButton.tonalIcon(
                key: const ValueKey('class_regrade'),
                onPressed: estimate.inProgress
                    ? null
                    : () => runClassRegrade(context, assignmentId),
                icon: const Icon(Icons.restart_alt),
                label: const Text('ตรวจใหม่ทั้งห้อง'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
