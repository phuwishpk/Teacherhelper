import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Web OAuth client id of the Google Cloud project (KICKOFF part 6, G4),
/// injected at build time:
///   flutter run --dart-define=GOOGLE_SERVER_CLIENT_ID=xxxx.apps.googleusercontent.com
///
/// It is not a secret (the client secret stays on the server). Without it
/// the app hides everything about Google Classroom (DESIGN §18.7).
const String googleServerClientId = String.fromEnvironment(
  'GOOGLE_SERVER_CLIENT_ID',
);

/// Whether this build shows the Google Classroom features. A provider so
/// widget tests can switch it on without a real client id.
final googleClassroomEnabledProvider = Provider<bool>(
  (ref) => googleServerClientId.isNotEmpty,
);

/// Scopes the teacher grants for the server (DESIGN §18.5). The server
/// checks it received all of them (`google_scope_missing` otherwise).
const googleServerScopes = [
  'https://www.googleapis.com/auth/classroom.courses.readonly',
  'https://www.googleapis.com/auth/classroom.rosters.readonly',
  'https://www.googleapis.com/auth/classroom.profile.emails',
  'https://www.googleapis.com/auth/classroom.coursework.students',
  'https://www.googleapis.com/auth/drive.file',
  driveReadonlyScope,
];

/// The one scope the app itself uses: downloading the pictures students
/// attached (§18.5). The access token stays in memory on the device.
const driveReadonlyScope = 'https://www.googleapis.com/auth/drive.readonly';
