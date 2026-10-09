import 'dart:convert';

import 'package:dio/dio.dart';

import 'fake_http_adapter.dart';

/// One request the fake server answered, for assertions.
class RecordedRequest {
  RecordedRequest(this.options, this.status);

  final RequestOptions options;

  /// Status the fake answered with.
  final int status;

  String get method => options.method.toUpperCase();

  /// Path without the `/api/v1` prefix, e.g. `/classrooms/101`.
  String get path => FakeApiServer.apiPath(options.uri);

  String? get authorization => options.headers['Authorization'] as String?;

  /// The JSON body the app sent (empty for a GET or no body).
  Map<String, dynamic> get jsonBody => FakeApiServer.bodyOf(options);
}

/// In-process stand-in for the Krucheck API (DESIGN §9), reached through
/// [adapter] by a Dio built with `createDio`, so the whole app runs without
/// opening a socket. It keeps the state one teacher's session produces
/// (classrooms, assignments, questions, layouts), rejects every protected
/// route without the teacher's bearer token the way Sanctum does, answers
/// errors in the `{message, errors, code}` envelope and records each request
/// so a test can assert on exactly what the app sent.
class FakeApiServer {
  FakeApiServer({
    this.teacherEmail = 'somsri@school.test',
    this.teacherPassword = 'secret-pass-1234',
    this.teacherToken = '7|sanctum-plain-text-token-for-tests',
    this.adminEmail = 'admin@school.test',
    this.adminPassword = 'admin-pass-1234',
    this.adminToken = '9|sanctum-admin-token-for-tests',
  });

  /// Origin the app is pointed at; nothing ever connects to it.
  static const origin = 'http://eduvision.test';

  static const _prefix = '/api/v1';

  final String teacherEmail;
  final String teacherPassword;
  final String teacherToken;

  /// An admin signs in on the same form (DESIGN §7.4); their token opens
  /// only `/me`, logout and `POST /auth/admin-handoff` (403 elsewhere).
  final String adminEmail;
  final String adminPassword;
  final String adminToken;

  /// `POST /auth/admin-handoff` calls answered.
  int handoffs = 0;

  static const handoffUrl =
      '$origin/admin/handoff/0123456789abcdef0123456789abcdef0123456789abcdef';

  final requests = <RecordedRequest>[];

  /// `METHOD /path` of requests that matched no route: a contract gap
  /// between the app and this fake (or the real API).
  final unrouted = <String>[];

  final classrooms = <Map<String, dynamic>>[];
  final assignments = <Map<String, dynamic>>[];

  /// Courses (DESIGN §20.1) in the detail form of `GET /courses/{id}`.
  final courses = <Map<String, dynamic>>[];

  /// Questions by assignment id.
  final questions = <int, List<Map<String, dynamic>>>{};

  /// Rows of `GET /assignments/{id}/review-queue` by assignment id. The
  /// fake grades nothing, so a test seeds them.
  final reviewRows = <int, List<Map<String, dynamic>>>{};

  /// Full `GET /responses/{id}` payloads by response id (optional).
  final responses = <int, Map<String, dynamic>>{};

  final subjects = <Map<String, dynamic>>[
    {'id': 1, 'code': 'MATH', 'name': 'คณิตศาสตร์'},
    {'id': 2, 'code': 'THAI', 'name': 'ภาษาไทย'},
  ];

  int logouts = 0;
  int _nextId = 100;

  /// Google sign-in on (DESIGN §24.9): `GET /auth/google/config` says so
  /// and `POST /auth/google` with the staff intent signs up a new teacher
  /// at once (#71), answering the teacher's token.
  bool googleSignIn = false;

  late final FakeHttpAdapter adapter = FakeHttpAdapter(handle);

  Map<String, dynamic> get teacherJson => {
    'id': 1,
    'name': 'ครูสมศรี',
    'role': 'teacher',
    'email': teacherEmail,
    'status': 'active',
    'school': {'id': 3, 'name': 'โรงเรียนทดสอบ'},
  };

  Map<String, dynamic> get adminJson => {
    'id': 2,
    'name': 'ผู้ดูแลระบบ',
    'role': 'admin',
    'email': adminEmail,
    'status': 'active',
    'school': null,
  };

  static String apiPath(Uri uri) => uri.path.startsWith(_prefix)
      ? uri.path.substring(_prefix.length)
      : uri.path;

  /// Dio hands the adapter the original `data`; a JSON string is accepted
  /// too in case a transformer encoded it first.
  static Map<String, dynamic> bodyOf(RequestOptions options) =>
      switch (options.data) {
        final Map<String, dynamic> m => m,
        final Map m => m.cast<String, dynamic>(),
        final String s when s.isNotEmpty =>
          (jsonDecode(s) as Map).cast<String, dynamic>(),
        _ => const {},
      };

  Future<ResponseBody> handle(RequestOptions options) async {
    final (status, body) = _route(options);
    requests.add(RecordedRequest(options, status));
    if (status == 204) return ResponseBody.fromString('', 204);
    return jsonResponse(status, body);
  }

  (int, Object?) _adminRoute(String method, String path) {
    switch ((method, path)) {
      case ('GET', '/me'):
        return (200, {'data': adminJson});
      case ('POST', '/auth/logout'):
        logouts++;
        return (204, null);
      case ('POST', '/auth/admin-handoff'):
        handoffs++;
        return (
          200,
          {
            'data': {'url': handoffUrl, 'expires_at': '2026-10-01T03:01:00Z'},
          },
        );
    }
    return (403, _error('คุณไม่มีสิทธิ์ทำรายการนี้', code: 'forbidden'));
  }

  (int, Object?) _route(RequestOptions o) {
    final method = o.method.toUpperCase();
    final path = apiPath(o.uri);
    final body = bodyOf(o);

    if (method == 'POST' && path == '/auth/teacher/login') {
      if (body['email'] == teacherEmail &&
          body['password'] == teacherPassword) {
        return (200, {'token': teacherToken, 'user': teacherJson});
      }
      if (body['email'] == adminEmail && body['password'] == adminPassword) {
        return (200, {'token': adminToken, 'user': adminJson});
      }
      return (
        422,
        _error(
          'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
          code: 'invalid_credentials',
          errors: {
            'email': ['อีเมลหรือรหัสผ่านไม่ถูกต้อง'],
          },
        ),
      );
    }
    if (method == 'GET' && path == '/auth/google/config') {
      return (
        200,
        {
          'data': {
            'enabled': googleSignIn,
            'web_flow': false,
            'notice_version': 'gsi-1',
          },
        },
      );
    }
    if (method == 'POST' && path == '/auth/google' && googleSignIn) {
      if (body['intent'] == 'staff') {
        return (200, {'token': teacherToken, 'user': teacherJson});
      }
      return (
        404,
        _error(
          'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชีนักเรียน',
          code: 'google_not_linked',
        ),
      );
    }
    if (o.headers['Authorization'] == 'Bearer $adminToken') {
      return _adminRoute(method, path);
    }
    if (o.headers['Authorization'] != 'Bearer $teacherToken') {
      return (401, _error('Unauthenticated.', code: 'unauthenticated'));
    }

    final segments = path.split('/').where((s) => s.isNotEmpty).toList();
    switch ((method, segments)) {
      case ('GET', ['me']):
        return (200, {'data': teacherJson});
      case ('POST', ['auth', 'logout']):
        logouts++;
        return (204, null);
      case ('GET', ['me', 'ai-key']):
        // AiKeyController: {data: {provider, configured, key_last4,
        // last_verified_at, server_key_available}}, never the key.
        return (
          200,
          {
            'data': {
              'provider': 'gemini',
              'configured': true,
              'key_last4': 'ABCD',
              'last_verified_at': '2026-09-01T00:00:00+00:00',
              'server_key_available': false,
            },
          },
        );
      case ('GET', ['subjects']):
        return (200, {'data': subjects});
      case ('GET', ['classrooms']):
        return (200, _page(classrooms));
      case ('POST', ['classrooms']):
        return _createClassroom(body);
      case ('GET', ['classrooms', final id]):
        final row = _find(classrooms, id);
        return row == null ? _notFound() : (200, {'data': row});
      case ('GET', ['classrooms', final id, 'roster']):
        return _find(classrooms, id) == null
            ? _notFound()
            : (200, {'data': <Object>[]});
      case ('GET', ['courses']):
        final classroomId = int.tryParse(
          o.uri.queryParameters['classroom_id'] ?? '',
        );
        return (
          200,
          {
            'data': [
              for (final c in courses)
                if (classroomId == null ||
                    (c['classroom_ids'] as List).contains(classroomId))
                  c,
            ],
          },
        );
      case ('POST', ['courses']):
        return _createCourse(body);
      case ('GET', ['courses', final id]):
        final row = _find(courses, id);
        return row == null ? _notFound() : (200, {'data': row});
      // "ตัดเกรด" (DESIGN §23.11): no course of this fake has categories.
      case ('GET', ['gradebook', 'overview']):
        return (
          200,
          {
            'data': {
              'courses': [
                for (final c in courses)
                  {
                    'id': c['id'],
                    'code': c['code'],
                    'name': c['name'],
                    'grade_level': c['grade_level'],
                    'semester': c['semester'],
                    'academic_year': c['academic_year'],
                    'configured': false,
                    'category_count': 0,
                    'classrooms': [
                      for (final r in c['classrooms'] as List)
                        {
                          'id': r['id'],
                          'name': r['name'],
                          'student_count':
                              _find(
                                classrooms,
                                '${r['id']}',
                              )?['students_count'] ??
                              0,
                          'status': 'not_configured',
                          'empty_categories': <String>[],
                          'published_at': null,
                          'stale': false,
                          'at_risk_ms_count': 0,
                          'special_counts': {'ร': 0, 'มส': 0},
                        },
                    ],
                  },
              ],
            },
          },
        );
      // A course whose gradebook is not set up yet (DESIGN §23.11): the
      // assignment and exam forms show "ยังไม่ระบุหมวด".
      case ('GET', ['courses', final id, 'gradebook', 'settings']):
        return _find(courses, id) == null
            ? _notFound()
            : (
                200,
                {
                  'data': {
                    'configured': false,
                    'template': null,
                    'categories': <Object>[],
                    'cutoffs': [80, 75, 70, 65, 60, 55, 50],
                    'default_cutoffs': [80, 75, 70, 65, 60, 55, 50],
                    'uncategorised_count': 0,
                  },
                },
              );
      case ('GET', ['assignments']):
        return (
          200,
          _page([for (final a in assignments) _assignmentSummary(a)]),
        );
      case ('POST', ['assignments']):
        return _createAssignment(body);
      case ('GET', ['assignments', final id]):
        final row = _find(assignments, id);
        return row == null ? _notFound() : (200, {'data': _detail(row)});
      case ('POST', ['assignments', final id, 'questions']):
        return _addQuestion(id, body);
      case ('POST', ['assignments', final id, 'layout']):
        return _createLayout(id);
      case ('GET', ['assignments', final id, 'layouts']):
        final row = _find(assignments, id);
        if (row == null) return _notFound();
        return (
          200,
          {
            'data': row['current_layout_version'] == null
                ? <Object>[]
                : [_layoutJson(row)],
          },
        );
      case ('GET', ['assignments', final id, 'review-queue']):
        final row = _find(assignments, id);
        if (row == null) return _notFound();
        return (
          200,
          {
            'data': reviewRows[row['id']] ?? const <Object>[],
            'meta': {'next_cursor': null, 'missing_ai_key_count': 0},
          },
        );
      case ('GET', ['responses', final id]):
        final r = responses[int.tryParse(id)];
        return r == null ? _notFound() : (200, {'data': r});
      case ('GET', ['appeals']):
        return (200, _page(const <Object>[]));
      case ('GET', ['practice-items']):
        return (200, _page(const <Object>[]));
      case ('GET', ['teacher', 'attention']):
        return (
          200,
          {
            'data': {
              'keys_pending': 0,
              'grade_conflicts': 0,
              'grade_failed': 0,
              'feedback_failed': 0,
              'regrade_pending': 0,
              'needs_reconnect': false,
            },
          },
        );
      case ('GET', ['ml', 'models', 'active']):
        return (404, _error('ยังไม่มีโมเดลที่เปิดใช้', code: 'not_found'));
      // A server without Google Classroom (no OAuth client): the app hides
      // every Google section (DESIGN §18.6, `configured`).
      case ('GET', ['google', 'status']):
        return (
          200,
          {
            'data': {
              'connected': false,
              'email': null,
              'scopes': <Object>[],
              'needs_reconnect': false,
              'last_error': null,
              'connected_at': null,
              'configured': false,
              'server_configured': false,
            },
          },
        );
      default:
        unrouted.add('$method $path');
        return _notFound();
    }
  }

  (int, Object?) _createClassroom(Map<String, dynamic> body) {
    final errors = <String, List<String>>{};
    final name = body['name'];
    final grade = body['grade_level'];
    final year = body['academic_year'];
    if (name is! String || name.trim().isEmpty) {
      errors['name'] = ['กรอกชื่อห้อง'];
    }
    if (grade is! int || grade < 1 || grade > 12) {
      errors['grade_level'] = ['ระดับชั้นต้องอยู่ระหว่าง 1 ถึง 12'];
    }
    if (year is! int) errors['academic_year'] = ['กรอกปีการศึกษา'];
    if (errors.isNotEmpty) return _invalid(errors);
    final id = _nextId++;
    final row = <String, dynamic>{
      'id': id,
      'name': name,
      'grade_level': grade,
      'academic_year': year,
      'class_code': 'CLS$id',
      'students_count': 0,
      'google_link': null,
    };
    classrooms.add(row);
    return (201, {'data': row});
  }

  /// `POST /courses` like CourseController: the classrooms must be the
  /// teacher's; the answer is the course detail.
  (int, Object?) _createCourse(Map<String, dynamic> body) {
    final errors = <String, List<String>>{};
    final subject = subjects
        .where((s) => s['id'] == body['subject_id'])
        .firstOrNull;
    for (final field in ['code', 'name']) {
      final v = body[field];
      if (v is! String || v.trim().isEmpty) errors[field] = ['กรอก $field'];
    }
    if (subject == null) errors['subject_id'] = ['กรุณาเลือกกลุ่มสาระ'];
    if (body['grade_level'] is! int) errors['grade_level'] = ['กรุณาเลือกชั้น'];
    if (body['academic_year'] is! int) {
      errors['academic_year'] = ['กรุณากรอกปีการศึกษา (พ.ศ.)'];
    }
    final ids = [
      for (final id in (body['classroom_ids'] as List?) ?? const []) id as int,
    ];
    final rooms = [for (final id in ids) ?_find(classrooms, '$id')];
    if (rooms.length != ids.length) {
      errors['classroom_ids.0'] = ['ไม่พบห้องเรียนนี้ในห้องที่คุณสอน'];
    }
    if (errors.isNotEmpty) return _invalid(errors);
    final id = _nextId++;
    final row = <String, dynamic>{
      'id': id,
      'code': body['code'],
      'name': body['name'],
      'subject_id': subject!['id'],
      'subject': subject,
      'grade_level': body['grade_level'],
      'semester': body['semester'] ?? 0,
      'academic_year': body['academic_year'],
      'hours': body['hours'],
      'description': body['description'],
      'classroom_ids': ids,
      'classrooms': [
        for (final r in rooms) {'id': r['id'], 'name': r['name']},
      ],
      'indicator_count': 0,
      'unit_count': 0,
      'lesson_plan_count': 0,
      'assignment_count': 0,
      'indicators': <Object>[],
      'units': <Object>[],
      'lesson_plans': <Object>[],
    };
    courses.add(row);
    return (201, {'data': row});
  }

  /// `POST /assignments`: the course must be bound to the classroom and
  /// gives the subject (DESIGN §20.1).
  (int, Object?) _createAssignment(Map<String, dynamic> body) {
    final errors = <String, List<String>>{};
    final classroom = _find(classrooms, '${body['classroom_id']}');
    final course = _find(courses, '${body['course_id']}');
    final title = body['title'];
    if (classroom == null) errors['classroom_id'] = ['ไม่พบห้องเรียน'];
    if (course == null ||
        classroom == null ||
        !(course['classroom_ids'] as List).contains(classroom['id'])) {
      errors['course_id'] = ['รายวิชานี้ไม่ได้ผูกกับห้องเรียนของการบ้าน'];
    }
    if (title is! String || title.trim().isEmpty) {
      errors['title'] = ['กรอกชื่อการบ้าน'];
    }
    if (errors.isNotEmpty) return _invalid(errors);
    final subject = course!['subject'] as Map<String, dynamic>;
    course['assignment_count'] = (course['assignment_count'] as int) + 1;
    final id = _nextId++;
    final row = <String, dynamic>{
      'id': id,
      'classroom_id': classroom!['id'],
      'subject_id': subject['id'],
      'course_id': course['id'],
      'lesson_plan_id': body['lesson_plan_id'],
      'title': title,
      'strictness': body['strictness'] ?? 'normal',
      'status': 'draft',
      'current_layout_version': null,
      'due_at': body['due_at'],
      'classroom': {'id': classroom['id'], 'name': classroom['name']},
      'subject': {'id': subject['id'], 'name': subject['name']},
      'course': {
        'id': course['id'],
        'code': course['code'],
        'name': course['name'],
      },
      'lesson_plan': null,
      'google_link': null,
    };
    assignments.add(row);
    questions[id] = [];
    return (201, {'data': _detail(row)});
  }

  (int, Object?) _addQuestion(String id, Map<String, dynamic> body) {
    final row = _find(assignments, id);
    if (row == null) return _notFound();
    final type = body['type'];
    if (type is! String ||
        !const {'mcq', 'short', 'show_work', 'open'}.contains(type)) {
      return _invalid({
        'type': ['ประเภทคำถามไม่ถูกต้อง'],
      });
    }
    final list = questions[row['id'] as int]!;
    final q = <String, dynamic>{
      'id': _nextId++,
      'position': list.length + 1,
      'type': type,
      'prompt_text': body['prompt_text'] ?? '',
      'max_points': body['max_points'] ?? 1,
      'answer_lines': body['answer_lines'],
      'is_numeric': body['is_numeric'] == true,
      'match_mode': body['match_mode'] ?? 'flexible',
      'answer_key': body['answer_key'],
      'rubric_status': type == 'show_work' || type == 'open'
          ? 'draft'
          : 'not_needed',
      'skills': <Object>[],
      'rubric_criteria': <Object>[],
    };
    list.add(q);
    return (201, {'data': q});
  }

  /// `POST /assignments/{id}/layout`: like LayoutController, the assignment
  /// becomes `ready` once it has a layout.
  (int, Object?) _createLayout(String id) {
    final row = _find(assignments, id);
    if (row == null) return _notFound();
    if ((questions[row['id']] ?? const []).isEmpty) {
      return (422, _error('เพิ่มคำถามก่อนสร้าง layout', code: 'no_questions'));
    }
    row['current_layout_version'] =
        ((row['current_layout_version'] as int?) ?? 0) + 1;
    row['status'] = 'ready';
    return (201, {'data': _layoutJson(row)});
  }

  Map<String, dynamic> _layoutJson(Map<String, dynamic> a) => {
    'version': a['current_layout_version'],
    'pages': [
      {'page_no': 1, 'regions': <Object>[]},
    ],
  };

  Map<String, dynamic> _assignmentSummary(Map<String, dynamic> a) => {
    ...a,
    'needs_review_count': (reviewRows[a['id']] ?? const [])
        .where((r) => r['reviewed_at'] == null)
        .length,
  };

  Map<String, dynamic> _detail(Map<String, dynamic> a) => {
    ..._assignmentSummary(a),
    'questions': questions[a['id']] ?? const <Object>[],
  };

  static Map<String, dynamic>? _find(
    List<Map<String, dynamic>> rows,
    String id,
  ) {
    final n = int.tryParse(id);
    return rows.where((r) => r['id'] == n).firstOrNull;
  }

  /// One page of a cursor-paginated list the way Laravel's resource
  /// collections and AppealController answer it: `{data, meta: {next_cursor,
  /// prev_cursor, per_page}}` (PER_PAGE = 50 on every list controller).
  static Map<String, dynamic> _page(List<Object?> rows) => {
    'data': rows,
    'meta': {'next_cursor': null, 'prev_cursor': null, 'per_page': 50},
  };

  static (int, Object?) _notFound() =>
      (404, _error('ไม่พบข้อมูล', code: 'not_found'));

  static (int, Object?) _invalid(Map<String, List<String>> errors) => (
    422,
    _error('ข้อมูลไม่ถูกต้อง', code: 'validation_failed', errors: errors),
  );

  static Map<String, dynamic> _error(
    String message, {
    required String code,
    Map<String, List<String>> errors = const {},
  }) => {'message': message, 'errors': errors, 'code': code};
}
