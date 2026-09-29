import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:flutter_riverpod/misc.dart';

import '../google_classroom/google_fakes.dart';

/// `GET /teacher/attention` without a server.
class FakeTeacherAttentionRepository implements TeacherAttentionRepository {
  FakeTeacherAttentionRepository([this.value = const TeacherAttention()]);

  TeacherAttention value;
  Object? error;
  int calls = 0;

  @override
  Future<TeacherAttention> attention() async {
    calls++;
    if (error case final e?) throw e;
    return value;
  }
}

/// What the teacher home needs besides its own counts: nothing waiting and
/// a server without Google Classroom, unless a test says otherwise.
List<Override> homeOverrides({
  TeacherAttention attention = const TeacherAttention(),
  GoogleStatus google = const GoogleStatus(connected: false, configured: false),
}) => [
  teacherAttentionRepositoryProvider.overrideWithValue(
    FakeTeacherAttentionRepository(attention),
  ),
  googleClassroomRepositoryProvider.overrideWithValue(
    FakeGoogleRepository(statusValue: google),
  ),
];
