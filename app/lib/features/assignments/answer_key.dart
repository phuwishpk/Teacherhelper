/// Builders for the `answer_key` JSON shapes of DESIGN §8.3.
abstract final class AnswerKey {
  static Map<String, dynamic> mcq(String correct) => {'correct': correct};

  static Map<String, dynamic> short({
    required List<String> accepted,
    double? numericValue,
    double absTol = 0,
  }) => {
    'accepted': accepted,
    if (numericValue != null)
      'numeric': {'value': numericValue, 'abs_tol': absTol},
  };

  static Map<String, dynamic> showWork({
    required List<String> finalAccepted,
    double? numericValue,
    double absTol = 0,
    List<String> referenceSteps = const [],
  }) => {
    'final': {
      'accepted': finalAccepted,
      if (numericValue != null)
        'numeric': {'value': numericValue, 'abs_tol': absTol},
    },
    'reference_steps': referenceSteps,
  };

  /// Splits a comma- or newline-separated list of accepted answers.
  static List<String> splitAccepted(String text) => text
      .split(RegExp(r'[\n,]'))
      .map((s) => s.trim())
      .where((s) => s.isNotEmpty)
      .toList();

  static List<String> splitLines(String text) =>
      text.split('\n').map((s) => s.trim()).where((s) => s.isNotEmpty).toList();
}
