import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'class_post_models.dart';
import 'drive_picker_stub.dart'
    if (dart.library.js_interop) 'drive_picker_web.dart';

/// "เลือกจาก Google Drive" (DESIGN §30.15): on the web, with the picker
/// configured, a function that opens the Google Picker and returns the
/// chosen file as a link attachment (null when the teacher cancels).
/// Null where there is no picker: the button is not shown.
final drivePickerProvider = Provider<Future<PostAttachment?> Function()?>(
  (ref) => platformDrivePicker(),
);
