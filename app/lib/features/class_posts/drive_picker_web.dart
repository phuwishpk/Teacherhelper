import 'dart:js_interop';

import '../google_classroom/google_config.dart';
import 'class_post_models.dart';

@JS('kruPickDrive')
external JSPromise<JSAny?> _kruPickDrive(
  JSString clientId,
  JSString apiKey,
  JSString appId,
);

/// The Google Picker in the browser (DESIGN §30.15): `kruPickDrive` of
/// web/index.html asks the teacher for a token and opens the picker; the
/// chosen file comes back as a link attachment. Null until the picker is
/// configured (the three --dart-define values).
Future<PostAttachment?> Function()? platformDrivePicker() {
  if (!googlePickerConfigured) return null;
  return () async {
    final result = await _kruPickDrive(
      googlePickerClientId.toJS,
      googlePickerApiKey.toJS,
      googlePickerAppId.toJS,
    ).toDart;
    if (result == null) return null;
    final map = (result as JSObject).dartify() as Map<Object?, Object?>;
    final url = map['url'] as String? ?? '';
    final name = map['name'] as String? ?? url;
    if (url.isEmpty) return null;
    return PostAttachment(title: name, url: url);
  };
}
