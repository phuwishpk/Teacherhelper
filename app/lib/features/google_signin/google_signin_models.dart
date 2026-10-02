import '../../core/api/api_client.dart';

/// Which login tab asked (`intent` of `POST /auth/google`, DESIGN §24.9.3).
/// It matters only while the Google account is not linked yet.
enum GoogleIntent {
  staff,
  student;

  String get apiValue => name;
}

/// How this app can get a Google account (DESIGN §24.9.4).
enum GoogleSignInMode {
  /// Neither: no Google button anywhere.
  none,

  /// Android with GOOGLE_SIGNIN_CLIENT_ID: `google_sign_in` ID token.
  native,

  /// The web preview: the server's redirect flow (`POST /auth/google/web-url`).
  web,
}

/// `GET /auth/google/config` (DESIGN §24.12 C).
class GoogleSignInServerConfig {
  const GoogleSignInServerConfig({
    required this.enabled,
    required this.webFlow,
    this.noticeVersion,
  });

  static const off = GoogleSignInServerConfig(enabled: false, webFlow: false);

  final bool enabled;
  final bool webFlow;
  final String? noticeVersion;

  factory GoogleSignInServerConfig.fromJson(Map<String, dynamic> json) =>
      GoogleSignInServerConfig(
        enabled: json['enabled'] == true,
        webFlow: json['web_flow'] == true,
        noticeVersion: json['notice_version'] as String?,
      );
}

/// `GET /me/google-identity` (DESIGN §24.12 C): the Google account the
/// signed-in user can sign in with.
class GoogleIdentity {
  const GoogleIdentity({
    required this.linked,
    this.email,
    this.name,
    this.pictureUrl,
    this.linkedVia,
    this.linkedAt,
    this.canLink = true,
  });

  final bool linked;
  final String? email;
  final String? name;
  final String? pictureUrl;

  /// `teacher_email`, `classroom_roster`, `pin_confirm`, `registration`,
  /// `self` or `google_signup` (DESIGN §24.3, #71).
  final String? linkedVia;
  final DateTime? linkedAt;

  /// False when the school keeps Google sign-in off for students.
  final bool canLink;

  factory GoogleIdentity.fromJson(Map<String, dynamic> json) => GoogleIdentity(
    linked: json['linked'] == true,
    email: json['email'] as String?,
    name: json['name'] as String?,
    pictureUrl: json['picture_url'] as String?,
    linkedVia: json['linked_via'] as String?,
    linkedAt: switch (json['linked_at']) {
      String s => DateTime.tryParse(s),
      _ => null,
    },
    canLink: json['can_link'] != false,
  );
}

/// The Google name and e-mail a new teacher's registration starts from
/// (404 `google_not_linked` of the staff tab, DESIGN §24.9.3 step 3). The
/// [linkTicket] links the account as it is created.
class GoogleRegistration {
  const GoogleRegistration({
    required this.linkTicket,
    required this.name,
    required this.email,
  });

  final String linkTicket;
  final String name;
  final String email;
}

/// 404 `google_not_linked`: the Google account belongs to nobody yet.
///
/// - [needsSchool] (staff tab, several schools, #71): pick the school and
///   sign in again with its id; the account is then created at once.
/// - [registration] set (staff tab, unknown e-mail, the school approves
///   teachers itself): offer the teacher registration or the password login.
/// - only [linkTicket] (student tab): the first confirmation with PIN or QR.
/// - neither (an admin's or an already linked teacher's e-mail): only the
///   server's [message].
class GoogleNotLinked {
  const GoogleNotLinked({
    required this.message,
    this.linkTicket,
    this.registration,
    this.needsSchool = false,
  });

  final String message;
  final String? linkTicket;
  final GoogleRegistration? registration;
  final bool needsSchool;

  bool get canConfirmAsStudent => linkTicket != null && registration == null;

  /// Null unless [error] is a 404 `google_not_linked`.
  static GoogleNotLinked? of(Object error) {
    if (apiErrorCode(error) != 'google_not_linked') return null;
    final body = apiErrorBody(error) ?? const {};
    final ticket = switch (body['link_ticket']) {
      String t when t.isNotEmpty => t,
      _ => null,
    };
    final reg = body['registration'];
    return GoogleNotLinked(
      message: switch (body['message']) {
        String m when m.isNotEmpty => m,
        _ => 'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชี EduVision',
      },
      linkTicket: ticket,
      registration: ticket != null && reg is Map
          ? GoogleRegistration(
              linkTicket: ticket,
              name: (reg['name'] as String?) ?? '',
              email: (reg['email'] as String?) ?? '',
            )
          : null,
      needsSchool: body['needs_school'] == true,
    );
  }
}
