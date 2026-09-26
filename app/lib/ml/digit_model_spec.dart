/// Thrown when a model's metrics.json asks for a decode this app does not
/// implement; the app then does not use that model at all.
class UnsupportedDigitModelException implements Exception {
  const UnsupportedDigitModelException(this.message);

  final String message;

  @override
  String toString() => 'UnsupportedDigitModelException: $message';
}

/// The input / output / decode contract of a digit model (DESIGN §12.2),
/// read from the `metrics.json` exported with it
/// (`ml/models/digit_crnn/<version>/metrics.json`, `model_versions.metrics`).
class DigitModelSpec {
  const DigitModelSpec({
    this.charset = defaultCharset,
    this.blankIndex = 13,
    this.numClasses = 14,
    this.timesteps = 32,
    this.inputHeight = 32,
    this.inputWidth = 128,
    this.abstainBelow = 0.8,
  });

  /// `0–9 . - /` in class order (class i = charset[i]).
  static const defaultCharset = '0123456789.-/';

  /// The only decode this app implements (mirror of
  /// ml/train/charset.py::ctc_greedy_decode).
  static const supportedMethod = 'ctc_greedy';
  static const supportedConfidence = 'emitting_mean_max_prob';

  final String charset;
  final int blankIndex;
  final int numClasses;
  final int timesteps;
  final int inputHeight;
  final int inputWidth;

  /// Below this confidence the reader abstains (`decode.abstain_below`).
  final double abstainBelow;

  int get inputLength => inputHeight * inputWidth;
  int get outputLength => timesteps * numClasses;

  /// Reads the contract from a metrics.json object. Missing keys keep the
  /// digit_crnn 0.1.0 defaults; a decode method or confidence definition
  /// other than the one implemented here is refused.
  factory DigitModelSpec.fromMetrics(Map<String, dynamic> metrics) {
    const d = DigitModelSpec();
    final decode = _map(metrics['decode']);
    final method = decode['method'];
    if (method != null && method != supportedMethod) {
      throw UnsupportedDigitModelException('decode.method = $method');
    }
    final confidence = decode['confidence'];
    if (confidence != null && confidence != supportedConfidence) {
      throw UnsupportedDigitModelException('decode.confidence = $confidence');
    }

    final input = _map(metrics['input']);
    final inputShape = _ints(input['shape']); // [1, H, W, 1]
    final outputShape = _ints(_map(metrics['output'])['shape']); // [1, T, C]
    final charset = metrics['charset'] is String
        ? metrics['charset'] as String
        : d.charset;
    final numClasses =
        _int(metrics['num_classes']) ??
        (outputShape.length == 3 ? outputShape[2] : null) ??
        charset.length + 1;
    final blank = _int(metrics['blank_index']) ?? numClasses - 1;
    final abstain = _double(decode['abstain_below']) ?? d.abstainBelow;

    final spec = DigitModelSpec(
      charset: charset,
      blankIndex: blank,
      numClasses: numClasses,
      timesteps:
          _int(metrics['timesteps']) ??
          (outputShape.length == 3 ? outputShape[1] : d.timesteps),
      inputHeight: inputShape.length == 4 ? inputShape[1] : d.inputHeight,
      inputWidth: inputShape.length == 4 ? inputShape[2] : d.inputWidth,
      abstainBelow: abstain,
    );
    if (spec.charset.isEmpty ||
        spec.numClasses != spec.charset.length + 1 ||
        spec.blankIndex < 0 ||
        spec.blankIndex >= spec.numClasses ||
        spec.abstainBelow < 0 ||
        spec.abstainBelow > 1) {
      throw UnsupportedDigitModelException(
        'charset ${spec.charset.length} chars, ${spec.numClasses} classes, '
        'blank ${spec.blankIndex}, abstain_below ${spec.abstainBelow}',
      );
    }
    return spec;
  }

  static Map<String, dynamic> _map(Object? v) =>
      v is Map ? v.cast<String, dynamic>() : const {};

  static List<int> _ints(Object? v) => [
    if (v is List)
      for (final e in v)
        if (e is num) e.toInt(),
  ];

  static int? _int(Object? v) => v is num ? v.toInt() : null;

  static double? _double(Object? v) => switch (v) {
    num n => n.toDouble(),
    String s => double.tryParse(s),
    _ => null,
  };
}
