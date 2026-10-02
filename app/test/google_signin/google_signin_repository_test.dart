import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_signin/google_signin_errors.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'google_signin_fakes.dart';

Map<String, dynamic> _body(RequestOptions o) => switch (o.data) {
  null => const {},
  String s => jsonDecode(s) as Map<String, dynamic>,
  final Map<dynamic, dynamic> m => m.cast<String, dynamic>(),
  _ => throw StateError('unexpected body ${o.data}'),
};

void main() {
  late FakeHttpAdapter adapter;
  late ApiGoogleSignInRepository repo;

  void answer(int status, Object? body) {
    adapter = FakeHttpAdapter((_) async => jsonResponse(status, body));
    repo = ApiGoogleSignInRepository(fakeDio(adapter));
  }

  RequestOptions only() => adapter.requests.single;

  const tokenBody = {
    'token': '5|tok',
    'user': {'id': 21, 'name': 'มานี', 'role': 'student'},
  };

  test('config reads enabled, web_flow and the notice version', () async {
    answer(200, {
      'data': {'enabled': true, 'web_flow': false, 'notice_version': 'gsi-1'},
    });
    final config = await repo.config();
    expect(config.enabled, isTrue);
    expect(config.webFlow, isFalse);
    expect(config.noticeVersion, 'gsi-1');
    expect(only().path, '/auth/google/config');
    expect(only().method, 'GET');
  });

  test('sign-in posts the ID token and the tab, returns the token', () async {
    answer(200, tokenBody);
    expect(
      await repo.signIn(idToken: 'id.tok', intent: GoogleIntent.student),
      '5|tok',
    );
    expect(only().path, '/auth/google');
    expect(_body(only()), {'id_token': 'id.tok', 'intent': 'student'});
  });

  test('sign-in and web-url send the picked school (#71)', () async {
    answer(200, tokenBody);
    await repo.signIn(
      idToken: 'id.tok',
      intent: GoogleIntent.staff,
      schoolId: 7,
    );
    expect(_body(only()), {
      'id_token': 'id.tok',
      'intent': 'staff',
      'school_id': 7,
    });

    answer(200, {
      'data': {'url': 'https://accounts.google.com/o/oauth2/v2/auth?x=1'},
    });
    await repo.webUrl(link: false, intent: GoogleIntent.staff, schoolId: 7);
    expect(_body(only()), {
      'purpose': 'login',
      'intent': 'staff',
      'school_id': 7,
    });
  });

  test('a 404 with needs_school is read', () {
    final notLinked = GoogleNotLinked.of(staffNeedsSchool())!;
    expect(notLinked.needsSchool, isTrue);
    expect(notLinked.registration?.email, 'new@school.ac.th');
    expect(GoogleNotLinked.of(staffNotLinked())!.needsSchool, isFalse);
  });

  test('web-url for login sends the intent, for link the notice', () async {
    answer(200, {
      'data': {'url': 'https://accounts.google.com/o/oauth2/v2/auth?x=1'},
    });
    final url = await repo.webUrl(link: false, intent: GoogleIntent.staff);
    expect(url.host, 'accounts.google.com');
    expect(_body(only()), {'purpose': 'login', 'intent': 'staff'});

    adapter.requests.clear();
    await repo.webUrl(link: true, acceptNotice: true);
    expect(_body(only()), {'purpose': 'link', 'accept_notice': true});
    expect(only().path, '/auth/google/web-url');
  });

  test('the web ticket is redeemed for a token', () async {
    answer(200, tokenBody);
    expect(await repo.redeemTicket('c' * 48), '5|tok');
    expect(only().path, '/auth/google/ticket');
    expect(_body(only()), {'ticket': 'c' * 48});
  });

  test('link-with-pin and link-with-qr accept the notice', () async {
    answer(200, tokenBody);
    await repo.linkWithPin(
      linkTicket: 't1',
      classCode: 'K7Q3M2',
      studentNumber: 4,
      pin: '123456',
    );
    expect(only().path, '/auth/google/link-with-pin');
    expect(_body(only()), {
      'link_ticket': 't1',
      'class_code': 'K7Q3M2',
      'student_number': 4,
      'pin': '123456',
      'accept_notice': true,
    });

    adapter.requests.clear();
    await repo.linkWithQr(linkTicket: 't1', qrToken: 'card');
    expect(only().path, '/auth/google/link-with-qr');
    expect(_body(only()), {
      'link_ticket': 't1',
      'qr_token': 'card',
      'accept_notice': true,
    });
  });

  test('identity, link and unlink use /me/google-identity', () async {
    answer(200, {
      'data': {
        'linked': true,
        'email': 'kru@school.ac.th',
        'name': 'ครู',
        'picture_url': 'https://lh3.googleusercontent.com/a',
        'linked_via': 'teacher_email',
        'linked_at': '2026-10-01T03:00:00+00:00',
        'can_link': true,
        'notice_version': 'gsi-1',
      },
    });
    final id = await repo.identity();
    expect(id.linked, isTrue);
    expect(id.email, 'kru@school.ac.th');
    expect(id.linkedVia, 'teacher_email');
    expect(id.linkedAt, DateTime.utc(2026, 10, 1, 3));
    expect(id.pictureUrl, isNotNull);
    expect(only().path, '/me/google-identity');

    adapter.requests.clear();
    await repo.link(idToken: 'id.tok', acceptNotice: true);
    expect(only().method, 'POST');
    expect(_body(only()), {'id_token': 'id.tok', 'accept_notice': true});

    answer(204, null);
    await repo.unlink();
    expect(only().method, 'DELETE');
    expect(only().path, '/me/google-identity');

    adapter.requests.clear();
    await repo.unlinkStudent(502);
    expect(only().method, 'DELETE');
    expect(only().path, '/students/502/google-identity');
  });

  test('can_link false comes through', () {
    final id = GoogleIdentity.fromJson({'linked': false, 'can_link': false});
    expect(id.canLink, isFalse);
    expect(id.linkedAt, isNull);
  });

  test('register sends the link ticket only when there is one', () async {
    answer(201, {
      'user': {'id': 1},
    });
    final auth = ApiAuthRepository(fakeDio(adapter));
    await auth.register(
      schoolId: 3,
      name: 'ครู',
      email: 'k@s.th',
      password: 'secret-pass',
      googleLinkTicket: 'd' * 48,
    );
    expect(_body(only())['google_link_ticket'], 'd' * 48);
    expect(_body(only())['school_id'], 3);

    adapter.requests.clear();
    await auth.register(name: 'ครู', email: 'k@s.th', password: 'secret-pass');
    expect(
      _body(only()),
      {'name': 'ครู', 'email': 'k@s.th', 'password': 'secret-pass'},
      reason: 'no school_id (the server picks its only school), no code',
    );
  });

  test('the sign-up school list is read from GET /auth/schools', () async {
    answer(200, {
      'data': [
        {'id': 1, 'name': 'โรงเรียนหนึ่ง'},
        {'id': 2, 'name': 'โรงเรียนสอง'},
      ],
    });
    final schools = await ApiAuthRepository(fakeDio(adapter)).schools();
    expect(only().path, '/auth/schools');
    expect(only().method, 'GET');
    expect(
      [for (final s in schools) '${s.id}:${s.name}'],
      ['1:โรงเรียนหนึ่ง', '2:โรงเรียนสอง'],
    );
  });

  group('google_not_linked', () {
    test('the staff answer carries the registration prefill', () {
      final nl = GoogleNotLinked.of(staffNotLinked())!;
      expect(nl.registration?.email, 'new@school.ac.th');
      expect(nl.registration?.name, 'ครูใหม่ ใจดี');
      expect(nl.registration?.linkTicket, 'a' * 48);
      expect(nl.canConfirmAsStudent, isFalse);
    });

    test('the student answer leads to the first confirmation', () {
      final nl = GoogleNotLinked.of(studentNotLinked())!;
      expect(nl.registration, isNull);
      expect(nl.canConfirmAsStudent, isTrue);
    });

    test('an admin e-mail has only the message', () {
      final nl = GoogleNotLinked.of(
        apiError(
          404,
          'google_not_linked',
          message: 'เข้าสู่ระบบด้วยรหัสผ่านก่อน',
        ),
      )!;
      expect(nl.linkTicket, isNull);
      expect(nl.canConfirmAsStudent, isFalse);
      expect(nl.message, 'เข้าสู่ระบบด้วยรหัสผ่านก่อน');
    });

    test('other errors are not "not linked"', () {
      expect(
        GoogleNotLinked.of(apiError(403, 'google_domain_not_allowed')),
        isNull,
      );
      expect(GoogleNotLinked.of(StateError('x')), isNull);
    });
  });

  group('Thai messages', () {
    test('the codes the app shows', () {
      expect(
        googleSignInErrorMessage(apiError(403, 'google_domain_not_allowed')),
        contains('โดเมน'),
      );
      expect(
        googleSignInErrorMessage(apiError(403, 'student_google_disabled')),
        contains('ยังไม่เปิดให้นักเรียน'),
      );
      expect(
        googleSignInErrorMessage(apiError(409, 'google_already_linked')),
        contains('ผู้ใช้อื่น'),
      );
      expect(
        googleSignInErrorMessage(apiError(409, 'google_identity_exists')),
        contains('ยกเลิกการเชื่อมเดิมก่อน'),
      );
      expect(
        googleSignInErrorMessage(apiError(503, 'google_unavailable')),
        contains('ติดต่อ Google ไม่ได้'),
      );
    });

    test('the server explains not-linked and inactive accounts', () {
      expect(
        googleSignInErrorMessage(
          apiError(
            403,
            'account_not_active',
            message: 'บัญชีของคุณกำลังรอการอนุมัติ',
          ),
        ),
        'บัญชีของคุณกำลังรอการอนุมัติ',
      );
      expect(
        googleSignInErrorMessage(
          apiError(404, 'google_not_linked', message: 'ยังไม่เชื่อม'),
        ),
        'ยังไม่เชื่อม',
      );
    });

    test('rate limit, plugin errors and unknown codes', () {
      expect(
        googleSignInErrorMessage(apiError(429, 'too_many_requests')),
        contains('ถี่เกินไป'),
      );
      expect(
        googleSignInErrorMessage(const GoogleAuthFailed('ผิดพลาด')),
        'ผิดพลาด',
      );
      expect(
        googleSignInErrorMessage(
          apiError(422, 'something_else', message: 'ข้อความ'),
        ),
        'ข้อความ',
      );
      expect(googleReturnCodeMessage('cancelled'), contains('ยกเลิก'));
      expect(googleReturnCodeMessage('weird'), contains('weird'));
      expect(isGoogleSignInCode('link_ticket_invalid'), isTrue);
      expect(isGoogleSignInCode('invalid_credentials'), isFalse);
    });
  });
}
