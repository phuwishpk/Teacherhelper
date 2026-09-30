import 'package:flutter/foundation.dart';

/// Web OAuth client id of the Google Cloud project (KICKOFF part 6, G4),
/// injected at build time:
///   flutter run --dart-define=GOOGLE_SERVER_CLIENT_ID=xxxx.apps.googleusercontent.com
///
/// It is not a secret (the client secret stays on the server). Only the
/// native Google Sign-In on Android needs it: the server auth code for
/// `POST /google/connect` and the Drive token that downloads the students'
/// pictures. Without it, and always on the web, the teacher connects
/// through the server's browser flow (`POST /google/oauth/url`). Whether
/// the Google Classroom UI shows at all is decided by the server
/// (`GET /google/status` -> `configured`, see googleClassroomEnabledProvider).
const String googleServerClientId = String.fromEnvironment(
  'GOOGLE_SERVER_CLIENT_ID',
);

/// This build can use Google Sign-In on the device (not the web, and a
/// client id was given).
const bool googleNativeSignInBuild = !kIsWeb && googleServerClientId.length > 0;

/// Scopes the teacher grants for the server (DESIGN §18.5). The server
/// checks it received all of them (`google_scope_missing` otherwise); the
/// list matches `GoogleScopes::REQUIRED` of the backend.
const googleServerScopes = [
  'https://www.googleapis.com/auth/classroom.courses.readonly',
  'https://www.googleapis.com/auth/classroom.rosters.readonly',
  'https://www.googleapis.com/auth/classroom.profile.emails',
  'https://www.googleapis.com/auth/classroom.coursework.students',
  'https://www.googleapis.com/auth/drive.file',
  driveReadonlyScope,
  announcementsScope,
];

/// The per-student private announcement of a published result (DESIGN
/// §19.7, Phase 8 build step 6). An account connected before it was added
/// lacks it and must connect again.
const announcementsScope =
    'https://www.googleapis.com/auth/classroom.announcements';

/// The one scope the app itself uses: downloading the pictures students
/// attached (§18.5). The access token stays in memory on the device.
const driveReadonlyScope = 'https://www.googleapis.com/auth/drive.readonly';
