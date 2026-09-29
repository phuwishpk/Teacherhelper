import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';

/// `GET /teacher/attention` (DESIGN §19.3, §19.9): what waits for the
/// teacher across their assignments that are not closed.
class TeacherAttention {
  const TeacherAttention({
    this.keysPending = 0,
    this.gradeConflicts = 0,
    this.gradeFailed = 0,
    this.feedbackFailed = 0,
    this.regradePending = 0,
    this.needsReconnect = false,
  });

  /// Freeform assignments whose key is not approved yet, mirrors of work
  /// created on the Classroom website included.
  final int keysPending;

  /// Open "คะแนนไม่ตรงกัน" rows.
  final int gradeConflicts;

  /// Classroom hand-ins whose grade could not be sent back.
  final int gradeFailed;

  /// Private result announcements that failed (Phase 8 build step 6).
  final int feedbackFailed;

  /// New hand-ins waiting for the teacher's "ตรวจ".
  final int regradePending;

  /// The teacher's Google account must be connected again.
  final bool needsReconnect;

  bool get isEmpty =>
      keysPending == 0 &&
      gradeConflicts == 0 &&
      gradeFailed == 0 &&
      feedbackFailed == 0 &&
      regradePending == 0 &&
      !needsReconnect;

  factory TeacherAttention.fromJson(Map<String, dynamic> json) {
    int count(String key) => (json[key] as num?)?.toInt() ?? 0;
    return TeacherAttention(
      keysPending: count('keys_pending'),
      gradeConflicts: count('grade_conflicts'),
      gradeFailed: count('grade_failed'),
      feedbackFailed: count('feedback_failed'),
      regradePending: count('regrade_pending'),
      needsReconnect: json['needs_reconnect'] == true,
    );
  }
}

abstract class TeacherAttentionRepository {
  Future<TeacherAttention> attention();
}

class ApiTeacherAttentionRepository implements TeacherAttentionRepository {
  ApiTeacherAttentionRepository(this._dio);

  final Dio _dio;

  @override
  Future<TeacherAttention> attention() async {
    final res = await _dio.get<Object?>('/teacher/attention');
    return TeacherAttention.fromJson(unwrapJson(res.data));
  }
}

final teacherAttentionRepositoryProvider = Provider<TeacherAttentionRepository>(
  (ref) => ApiTeacherAttentionRepository(ref.watch(dioProvider)),
);

/// The home "รอดำเนินการ" card. Screens that settle one of the counted
/// things (approving a key, a grade conflict, a late hand-in) invalidate
/// it. A server without the endpoint (404) counts as nothing waiting.
final teacherAttentionProvider = FutureProvider.autoDispose<TeacherAttention>((
  ref,
) async {
  watchSignedInUser(ref, keepAlive: false);
  try {
    return await ref.watch(teacherAttentionRepositoryProvider).attention();
  } on DioException catch (e) {
    if (e.response?.statusCode == 404) return const TeacherAttention();
    rethrow;
  }
});

/// Where a line of the card leads.
enum AttentionTarget { assignments, review }

/// "รอดำเนินการ" on the teacher home (DESIGN §19.11): keys to approve,
/// grades that differ from Classroom, grades or announcements that failed,
/// and new hand-ins waiting for "ตรวจ". Each line opens the tab that holds
/// it ([onOpen]).
class TeacherAttentionCard extends ConsumerWidget {
  const TeacherAttentionCard({super.key, required this.onOpen});

  final ValueChanged<AttentionTarget> onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(teacherAttentionProvider);
    final theme = Theme.of(context);
    final a = value.value;
    final lines = a == null
        ? const <_Line>[]
        : [
            if (a.keysPending > 0)
              _Line(
                key: 'attention_keys',
                icon: Icons.fact_check_outlined,
                text: 'เฉลยรออนุมัติ ${a.keysPending} งาน',
                detail:
                    'รวมงานใหม่จาก Classroom ระบบยังไม่ตรวจจนกว่าจะอนุมัติเฉลย',
                target: AttentionTarget.assignments,
              ),
            if (a.gradeConflicts > 0)
              _Line(
                key: 'attention_conflicts',
                icon: Icons.compare_arrows,
                text: 'คะแนนไม่ตรงกับ Classroom ${a.gradeConflicts} รายการ',
                detail:
                    'ครูแก้คะแนนในเว็บ Classroom เปิดการบ้านแล้วเลือกว่าจะใช้คะแนนฝั่งไหน',
                target: AttentionTarget.assignments,
              ),
            if (a.gradeFailed > 0)
              _Line(
                key: 'attention_grade_failed',
                icon: Icons.sync_problem_outlined,
                text: 'ส่งคะแนนกลับ Classroom ไม่สำเร็จ ${a.gradeFailed} คน',
                detail:
                    'เปิดงานที่ส่งใน Classroom แล้วกด "ส่งคะแนนกลับอีกครั้ง"',
                target: AttentionTarget.assignments,
              ),
            if (a.feedbackFailed > 0)
              _Line(
                key: 'attention_feedback_failed',
                icon: Icons.campaign_outlined,
                text:
                    'ส่งประกาศผลใน Classroom ไม่สำเร็จ ${a.feedbackFailed} คน',
                target: AttentionTarget.assignments,
              ),
            if (a.regradePending > 0)
              _Line(
                key: 'attention_regrade',
                icon: Icons.upload_file_outlined,
                text: 'งานส่งใหม่รอกดตรวจ ${a.regradePending} งาน',
                target: AttentionTarget.review,
              ),
          ];

    return Card(
      key: const ValueKey('teacher_attention_card'),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 4, 0),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    'รอดำเนินการ',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                IconButton(
                  tooltip: 'โหลดใหม่',
                  onPressed: value.isLoading
                      ? null
                      : () => ref.invalidate(teacherAttentionProvider),
                  icon: const Icon(Icons.refresh),
                ),
              ],
            ),
          ),
          if (value.isLoading && a == null)
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: LinearProgressIndicator(),
            )
          else if (a == null)
            ListTile(
              title: const Text('โหลดรายการที่รอดำเนินการไม่ได้'),
              trailing: TextButton(
                onPressed: () => ref.invalidate(teacherAttentionProvider),
                child: const Text('ลองใหม่'),
              ),
            )
          else if (lines.isEmpty)
            const ListTile(
              key: ValueKey('attention_empty'),
              leading: Icon(Icons.check_circle_outline),
              title: Text('ไม่มีงานค้าง'),
            )
          else
            for (final l in lines)
              ListTile(
                key: ValueKey(l.key),
                leading: Icon(l.icon, color: theme.colorScheme.primary),
                title: Text(l.text),
                subtitle: l.detail == null ? null : Text(l.detail!),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => onOpen(l.target),
              ),
        ],
      ),
    );
  }
}

class _Line {
  const _Line({
    required this.key,
    required this.icon,
    required this.text,
    required this.target,
    this.detail,
  });

  final String key;
  final IconData icon;
  final String text;
  final String? detail;
  final AttentionTarget target;
}
