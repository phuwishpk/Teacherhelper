import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'exam_fakes.dart';

/// Stubs of the exam routes a screen may push to.
final examStubRoutes = [
  stubRoute('/exams/:id', 'exam'),
  stubRoute('/exams/:id/edit', 'exam-edit'),
  stubRoute('/exams/:id/answer-key', 'answer-key'),
  stubRoute('/exams/:id/versions', 'versions'),
  stubRoute('/exams/:id/print', 'print'),
  stubRoute('/exams/:id/questions/:qid', 'question'),
  stubRoute('/exams/:id/sections/:sid/questions/new', 'question-new'),
];

/// Pumps [screen] with [repo] behind `examsRepositoryProvider` on a tall
/// phone-width view.
Future<FakeExamsRepository> pumpExamScreen(
  WidgetTester tester,
  Widget screen, {
  FakeExamsRepository? repo,
  FakeDocumentPicker? picker,
  List<Override> overrides = const [],
  bool stubs = true,
}) async {
  tester.view.physicalSize = const Size(1080, 3000);
  tester.view.devicePixelRatio = 2.5;
  addTearDown(tester.view.reset);
  final r = repo ?? FakeExamsRepository();
  await pumpScreen(
    tester,
    screen,
    overrides: [
      examsRepositoryProvider.overrideWithValue(r),
      documentFilePickerProvider.overrideWithValue(
        picker ?? FakeDocumentPicker(),
      ),
      ...overrides,
    ],
    extraRoutes: stubs ? examStubRoutes : const [],
  );
  return r;
}

/// Taps [finder] after scrolling it into view.
Future<void> tapVisible(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}
