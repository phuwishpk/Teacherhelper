import 'package:dio/dio.dart';
import 'package:eduvision/core/auth/auth_repository.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_signin/google_signin_gateway.dart';
import 'package:eduvision/features/google_signin/google_signin_models.dart';
import 'package:eduvision/features/google_signin/google_signin_repository.dart';

/// A DioException as the API answers it: `{message, errors, code}` plus
/// any [extra] top-level field (DESIGN §9).
DioException apiError(
  int status,
  String code, {
  String message = 'ข้อความจากเซิร์ฟเวอร์',
  Map<String, dynamic> extra = const {},
}) {
  final options = RequestOptions(path: '/auth/google');
  return DioException(
    requestOptions: options,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: {
        'message': message,
        'errors': <String, dynamic>{},
        'code': code,
        ...extra,
      },
    ),
    type: DioExceptionType.badResponse,
  );
}

/// 404 google_not_linked of the staff tab with an unknown e-mail.
DioException staffNotLinked() => apiError(
  404,
  'google_not_linked',
  message: 'ยังไม่มีบัญชี EduVision ที่เชื่อมกับบัญชี Google นี้',
  extra: {
    'link_ticket': 'a' * 48,
    'registration': {'name': 'ครูใหม่ ใจดี', 'email': 'new@school.ac.th'},
  },
);

/// 404 google_not_linked of a new teacher when there are several schools
/// and none was picked yet (#71).
DioException staffNeedsSchool() => apiError(
  404,
  'google_not_linked',
  message: 'เลือกโรงเรียนของคุณ แล้วเข้าสู่ระบบด้วย Google อีกครั้ง',
  extra: {
    'link_ticket': 'c' * 48,
    'registration': {'name': 'ครูใหม่ ใจดี', 'email': 'new@school.ac.th'},
    'needs_school': true,
  },
);

/// 404 google_not_linked of the student tab.
DioException studentNotLinked() => apiError(
  404,
  'google_not_linked',
  message: 'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชีนักเรียน',
  extra: {'link_ticket': 'b' * 48},
);

/// Records every call; [error] (or a per-call error) makes calls fail.
class FakeGoogleSignInRepository implements GoogleSignInRepository {
  FakeGoogleSignInRepository({
    this.serverConfig = const GoogleSignInServerConfig(
      enabled: true,
      webFlow: true,
      noticeVersion: 'gsi-1',
    ),
    GoogleIdentity? identity,
    this.token = '5|google-token',
  }) : current = identity ?? const GoogleIdentity(linked: false);

  GoogleSignInServerConfig serverConfig;
  Object? configError;
  GoogleIdentity current;
  Object? identityError;
  final String token;

  Object? signInError;

  /// Errors of the next sign-ins, one per call (null = success), before
  /// [signInError] applies.
  final signInErrors = <Object?>[];
  Object? ticketError;
  Object? pinError;
  Object? qrError;
  Object? linkError;
  Object? unlinkError;

  final calls = <String>[];
  final signIns = <(String, GoogleIntent)>[];

  /// The `school_id` of each sign-in, in the order of [signIns].
  final signInSchools = <int?>[];
  final webUrls = <({bool link, GoogleIntent? intent, bool acceptNotice})>[];

  /// The `school_id` of each web-url, in the order of [webUrls].
  final webUrlSchools = <int?>[];
  final pinLinks = <Map<String, Object>>[];
  final qrLinks = <(String, String)>[];
  final links = <(String, bool)>[];
  final unlinkedStudents = <int>[];

  static final googleUrl = Uri.parse(
    'https://accounts.google.com/o/oauth2/v2/auth?state=s',
  );

  @override
  Future<GoogleSignInServerConfig> config() async {
    calls.add('config');
    if (configError != null) throw configError!;
    return serverConfig;
  }

  @override
  Future<String> signIn({
    required String idToken,
    required GoogleIntent intent,
    int? schoolId,
  }) async {
    signIns.add((idToken, intent));
    signInSchools.add(schoolId);
    if (signInErrors.isNotEmpty) {
      final error = signInErrors.removeAt(0);
      if (error != null) throw error;
      return token;
    }
    if (signInError != null) throw signInError!;
    return token;
  }

  @override
  Future<Uri> webUrl({
    required bool link,
    GoogleIntent? intent,
    bool acceptNotice = false,
    int? schoolId,
  }) async {
    webUrls.add((link: link, intent: intent, acceptNotice: acceptNotice));
    webUrlSchools.add(schoolId);
    return googleUrl;
  }

  @override
  Future<String> redeemTicket(String ticket) async {
    calls.add('ticket $ticket');
    if (ticketError != null) throw ticketError!;
    return token;
  }

  @override
  Future<String> linkWithPin({
    required String linkTicket,
    required String classCode,
    required int studentNumber,
    required String pin,
  }) async {
    pinLinks.add({
      'link_ticket': linkTicket,
      'class_code': classCode,
      'student_number': studentNumber,
      'pin': pin,
    });
    if (pinError != null) throw pinError!;
    return token;
  }

  @override
  Future<String> linkWithQr({
    required String linkTicket,
    required String qrToken,
  }) async {
    qrLinks.add((linkTicket, qrToken));
    if (qrError != null) throw qrError!;
    return token;
  }

  @override
  Future<GoogleIdentity> identity() async {
    calls.add('identity');
    if (identityError != null) throw identityError!;
    return current;
  }

  @override
  Future<GoogleIdentity> link({
    required String idToken,
    required bool acceptNotice,
  }) async {
    links.add((idToken, acceptNotice));
    if (linkError != null) throw linkError!;
    return current = const GoogleIdentity(
      linked: true,
      email: 'kru@school.ac.th',
      name: 'ครู Google',
      linkedVia: 'self',
    );
  }

  @override
  Future<void> unlink() async {
    calls.add('unlink');
    if (unlinkError != null) throw unlinkError!;
    current = const GoogleIdentity(linked: false);
  }

  @override
  Future<void> unlinkStudent(int studentId) async {
    unlinkedStudents.add(studentId);
    if (unlinkError != null) throw unlinkError!;
  }
}

/// The account picker without the plugin.
class FakeGoogleSignInGateway implements GoogleSignInGateway {
  FakeGoogleSignInGateway({this.error, this.token = 'google-id-token'});

  Object? error;
  final String token;
  int calls = 0;

  @override
  Future<String> idToken() async {
    calls++;
    if (error != null) throw error!;
    return calls == 1 ? token : '$token-$calls';
  }
}

/// `/me` after any sign-in; stores who signed in.
class FakeMeAuth implements AuthRepository {
  FakeMeAuth(this.user, {this.schoolList});

  User user;
  int logouts = 0;

  /// What `GET /auth/schools` answers; null = not expected in this test.
  List<SchoolOption>? schoolList;

  @override
  Future<User> me() async => user;

  @override
  Future<void> logout() async => logouts++;

  @override
  Future<String> login({required String email, required String password}) =>
      throw UnimplementedError();

  @override
  Future<String> loginStudentPin({
    required String classCode,
    required int studentNumber,
    required String pin,
  }) => throw UnimplementedError();

  @override
  Future<String> loginStudentQr(String qrToken) => throw UnimplementedError();

  @override
  Future<List<SchoolOption>> schools() async =>
      schoolList ?? (throw UnimplementedError());

  @override
  Future<void> register({
    int? schoolId,
    required String name,
    required String email,
    required String password,
    String? googleLinkTicket,
  }) => throw UnimplementedError();
}

const googleCanceled = GoogleAuthCanceled();

const teacherUser = User(
  id: 11,
  name: 'ครูสมศรี',
  role: 'teacher',
  status: 'active',
  email: 'somsri@school.ac.th',
);
const studentUser = User(id: 21, name: 'ด.ญ. มานี มีนา', role: 'student');
const adminUser = User(id: 2, name: 'ผู้ดูแลระบบ', role: 'admin');
