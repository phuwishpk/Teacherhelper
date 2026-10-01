import 'package:dio/dio.dart';

import 'api_client.dart';

/// Server limit of "คำแนะนำถึง AI" (DESIGN §21.12, `TeacherGuidance::MAX`),
/// counted in Unicode code points like PHP's `mb_strlen`: a Thai vowel or
/// tone mark is a character of its own, so Flutter's grapheme-based
/// `maxLength` would let a Thai text through that the server refuses.
const kGuidanceMaxLength = 500;

/// The text to send as `guidance`: trimmed, null when empty (the server
/// treats null and whitespace alike, so nothing is sent then).
String? normalizeGuidance(String? text) {
  final t = text?.trim();
  return t == null || t.isEmpty ? null : t;
}

/// Characters as the server counts them (code points).
int guidanceLength(String text) => text.runes.length;

/// `errors.guidance[0]` of a 422 `validation_failed`, if any.
String? guidanceErrorOf(Object error) {
  if (error is! DioException || apiErrorCode(error) != 'validation_failed') {
    return null;
  }
  final data = error.response?.data;
  if (data is Map && data['errors'] is Map) {
    final list = (data['errors'] as Map)['guidance'];
    if (list is List && list.isNotEmpty) return list.first.toString();
  }
  return null;
}
