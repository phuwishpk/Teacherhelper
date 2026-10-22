import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/live/live_scope.dart';

/// Ticks when one of the student's hand-ins changes status in Firestore
/// (users/{uid}/submissions, DESIGN §38.9). StudentShell refetches its
/// lists from the API on each tick, so "ส่งงาน" and "ผลการบ้าน" move on
/// their own while the teacher grades and publishes.
final liveSubmissionTicksProvider = StreamProvider.autoDispose<int>(
  (ref) => liveChangeTicks(ref, 'submissions'),
);

/// The teacher's counterpart: an assignment's grading counts changed.
final liveAssignmentTicksProvider = StreamProvider.autoDispose<int>(
  (ref) => liveChangeTicks(ref, 'assignments'),
);
