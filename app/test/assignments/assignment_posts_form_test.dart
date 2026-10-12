import 'dart:typed_data';

import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignment_form_screen.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/class_posts/class_post_models.dart';
import 'package:eduvision/features/class_posts/class_posts_repository.dart';
import 'package:eduvision/features/class_posts/post_files.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/gradebook/gradebook_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../class_posts/class_post_fakes.dart';
import '../classrooms/classroom_fakes.dart';
import '../courses/course_fakes.dart';
import '../gradebook/gradebook_fakes.dart';
import '../helpers/pump_screen.dart';

/// The post-like parts of the assignment form (DESIGN §30.12): the text,
/// the topic, the files and links, and "ส่งไป Google Classroom ด้วย".
class _FakeAssignments extends Fake implements AssignmentsRepository {
  final created = <Map<String, Object?>>[];
  final updated = <Map<String, Object?>>[];

  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];

  @override
  Future<Assignment> create({
    required int classroomId,
    required int courseId,
    int? lessonPlanId,
    required String title,
    Strictness strictness = Strictness.normal,
    DateTime? dueAt,
    AssignmentMode mode = AssignmentMode.worksheet,
    bool acceptLate = true,
    bool scoreOnly = false,
    int? gradebookCategoryId,
    bool excludedFromGrade = false,
    int? unitId,
    String? topic,
    String? instructions,
    bool sendToGoogle = true,
    List<PostAttachment> attachments = const [],
  }) async {
    created.add({
      'title': title,
      'unit_id': unitId,
      'topic': topic,
      'instructions': instructions,
      'send_to_google': sendToGoogle,
      'attachments': [for (final a in attachments) a.toJson()],
    });
    return Assignment(
      id: 55,
      classroomId: classroomId,
      subjectId: 1,
      courseId: courseId,
      title: title,
    );
  }

  @override
  Future<Assignment> update(
    int id, {
    String? title,
    Strictness? strictness,
    DateTime? dueAt,
    bool clearDueAt = false,
    String? status,
    AssignmentMode? mode,
    bool? acceptLate,
    bool? scoreOnly,
    int? courseId,
    int? lessonPlanId,
    bool clearLessonPlan = false,
    int? gradebookCategoryId,
    bool clearGradebookCategory = false,
    bool? excludedFromGrade,
    int? unitId,
    String? topic,
    bool clearTopic = false,
    String? instructions,
    bool? sendToGoogle,
    List<PostAttachment>? attachments,
  }) async {
    updated.add({
      'unit_id': unitId,
      'topic': topic,
      'clear_topic': clearTopic,
      'instructions': instructions,
      'send_to_google': sendToGoogle,
      'attachments': attachments == null
          ? null
          : [for (final a in attachments) a.toJson()],
    });
    return Assignment(id: id, classroomId: 7, subjectId: 1, title: 'x');
  }
}

final _linkedRoom = Classroom(
  id: 7,
  name: 'ป.5/2',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'K7Q3M2',
  studentCount: 2,
  googleLink: const ClassroomGoogleLink(
    courseId: '612',
    courseName: 'คณิต ป.5',
  ),
);

List<Override> _overrides(
  _FakeAssignments assignments, {
  bool linked = true,
  List<PickedPostFile> picked = const [],
}) => [
  classroomsRepositoryProvider.overrideWithValue(
    FakeSchoolClassrooms(open: [linked ? _linkedRoom : room()]),
  ),
  assignmentsRepositoryProvider.overrideWithValue(assignments),
  coursesRepositoryProvider.overrideWithValue(
    FakeCoursesRepository([
      course(
        units: const [
          {'id': 31, 'position': 1, 'title': 'เศษส่วน'},
        ],
      ),
    ]),
  ),
  gradebookRepositoryProvider.overrideWithValue(FakeGradebookRepository()),
  classPostsRepositoryProvider.overrideWithValue(FakeClassPosts()),
  postFilePickerProvider.overrideWithValue(() async => picked),
];

Future<void> _pickRoomAndCourse(WidgetTester tester) async {
  await tester.enterText(
    find.widgetWithText(TextFormField, 'ชื่อการบ้าน'),
    'แบบฝึกหัดเศษส่วน',
  );
  await tester.tap(
    find.widgetWithText(DropdownButtonFormField<int>, 'ห้องเรียน'),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('ป.5/2 (ป.5)').last);
  await tester.pumpAndSettle();
  await tester.tap(
    find.widgetWithText(DropdownButtonFormField<int>, 'รายวิชา'),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('ค15101 คณิตศาสตร์ 5').last);
  await tester.pumpAndSettle();
}

Future<void> _submit(WidgetTester tester) async {
  final button = find.widgetWithText(FilledButton, 'สร้างการบ้าน');
  await tester.ensureVisible(button);
  await tester.tap(button);
  await tester.pumpAndSettle();
}

void main() {
  Future<_FakeAssignments> pump(
    WidgetTester tester, {
    Assignment? existing,
    bool linked = true,
  }) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      AssignmentFormScreen(existing: existing),
      overrides: _overrides(assignments, linked: linked),
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id',
          builder: (_, _) => const Text('detail'),
        ),
      ],
    );
    return assignments;
  }

  testWidgets('text, a unit, a link and the Classroom switch are sent', (
    tester,
  ) async {
    final assignments = await pump(tester);
    await _pickRoomAndCourse(tester);
    await tester.enterText(
      find.byKey(const ValueKey('assignment_instructions')),
      ' ทำข้อ 1-5 ',
    );
    await tester.tap(find.byKey(const ValueKey('assignment_topic_4')).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('เศษส่วน').last);
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const ValueKey('post_attach_link')));
    await tester.tap(find.byKey(const ValueKey('post_attach_link')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('link_url')),
      'https://youtu.be/abc',
    );
    await tester.tap(find.byKey(const ValueKey('link_add')));
    await tester.pumpAndSettle();
    expect(find.text('https://youtu.be/abc'), findsWidgets);

    final checkbox = tester.widget<CheckboxListTile>(
      find.byKey(const ValueKey('assignment_send_google')),
    );
    expect(checkbox.value, isTrue);
    expect(find.textContaining('คณิต ป.5'), findsOneWidget);
    await tester.ensureVisible(
      find.byKey(const ValueKey('assignment_send_google')),
    );
    await tester.tap(find.byKey(const ValueKey('assignment_send_google')));
    await tester.pumpAndSettle();

    await _submit(tester);
    expect(assignments.created.single, {
      'title': 'แบบฝึกหัดเศษส่วน',
      'unit_id': 31,
      'topic': null,
      'instructions': 'ทำข้อ 1-5',
      'send_to_google': false,
      'attachments': [
        {'url': 'https://youtu.be/abc', 'title': 'https://youtu.be/abc'},
      ],
    });
  });

  testWidgets(
    'a free topic replaces the unit; an unlinked room has no switch',
    (tester) async {
      final assignments = await pump(tester, linked: false);
      await _pickRoomAndCourse(tester);
      expect(
        find.byKey(const ValueKey('assignment_send_google')),
        findsNothing,
      );
      await tester.tap(find.byKey(const ValueKey('assignment_topic_4')).first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('พิมพ์หัวข้อเอง…').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('topic_text')),
        'ทบทวนก่อนสอบ',
      );
      await _submit(tester);
      expect(assignments.created.single['unit_id'], isNull);
      expect(assignments.created.single['topic'], 'ทบทวนก่อนสอบ');
      expect(assignments.created.single['send_to_google'], isTrue);
    },
  );

  testWidgets('files picked are uploaded and attached', (tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final assignments = _FakeAssignments();
    await pumpScreen(
      tester,
      const AssignmentFormScreen(),
      overrides: _overrides(
        assignments,
        picked: [
          (name: 'ใบงาน.pdf', bytes: Uint8List.fromList([1, 2, 3])),
        ],
      ),
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id',
          builder: (_, _) => const Text('detail'),
        ),
      ],
    );
    await _pickRoomAndCourse(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('post_attach_file')));
    await tester.tap(find.byKey(const ValueKey('post_attach_file')));
    await tester.pumpAndSettle();
    expect(find.text('ใบงาน.pdf'), findsOneWidget);
    await _submit(tester);
    expect(assignments.created.single['attachments'], [
      {'file_id': 900, 'title': 'ใบงาน.pdf'},
    ]);
  });

  testWidgets('a posted assignment keeps its files and its switch', (
    tester,
  ) async {
    final posted = Assignment(
      id: 12,
      classroomId: 7,
      subjectId: 1,
      courseId: 4,
      title: 'เศษส่วน',
      status: 'ready',
      instructions: 'เดิม',
      attachments: const [
        PostAttachment(title: 'เว็บ', url: 'https://example.com'),
      ],
      googleLink: const AssignmentGoogleLink(
        courseWorkId: 'cw-1',
        alternateLink: 'https://classroom.google.com/x',
      ),
    );
    final assignments = await pump(tester, existing: posted);
    expect(find.byKey(const ValueKey('post_files_locked')), findsOneWidget);
    expect(find.byKey(const ValueKey('post_attach_file')), findsNothing);
    final checkbox = tester.widget<CheckboxListTile>(
      find.byKey(const ValueKey('assignment_send_google')),
    );
    expect(checkbox.onChanged, isNull);

    await tester.enterText(
      find.byKey(const ValueKey('assignment_instructions')),
      'ใหม่',
    );
    final save = find.widgetWithText(FilledButton, 'บันทึก');
    await tester.ensureVisible(save);
    await tester.tap(save);
    await tester.pumpAndSettle();
    expect(assignments.updated.single['instructions'], 'ใหม่');
    expect(
      assignments.updated.single['attachments'],
      isNull,
      reason: 'unchanged files are not sent',
    );
  });
}
