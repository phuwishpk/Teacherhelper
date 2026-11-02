import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import 'mascot_mood.dart';

/// Draws น้องเช็ค on a 120 × 120 grid, the coordinates of the reference
/// files in the team guide (`mascot/nong-check-*.svg`), so a size is only a
/// scale. Colours come from [MascotColors].
class MascotPainter extends CustomPainter {
  const MascotPainter({
    required this.mood,
    required this.colors,
    this.pulse,
    this.wave,
  });

  /// The side of the drawing grid.
  static const grid = 120.0;

  /// Radians either way of the waving arm.
  static const _waveAngle = 0.4;

  final MascotMood mood;
  final ColorScheme colors;

  /// 0 to 1 through one cycle of the thinking dots; null keeps them still.
  final double? pulse;

  /// -1 to 1, how far the raised hand of [MascotMood.wave] is from its
  /// resting place; null keeps it there.
  final double? wave;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    canvas.scale(size.width / grid, size.height / grid);
    _shadowAndFeet(canvas);
    _arms(canvas);
    _book(canvas);
    _cap(canvas);
    _cheeks(canvas);
    _face(canvas);
    canvas.restore();
  }

  @override
  bool shouldRepaint(MascotPainter oldDelegate) =>
      oldDelegate.mood != mood ||
      oldDelegate.colors != colors ||
      oldDelegate.pulse != pulse ||
      oldDelegate.wave != wave;

  void _shadowAndFeet(Canvas canvas) {
    _oval(canvas, 60, 114, 30, 3.5, colors.mascotInk.withValues(alpha: 0.12));
    final feet = _fill(colors.mascotShade);
    for (final x in const [40.0, 66.0]) {
      canvas.drawRRect(_rounded(x, 102, 14, 10, 5), feet);
    }
  }

  void _arms(Canvas canvas) {
    final (left, right) = switch (mood) {
      MascotMood.idle => (
        const _Curve(13, 62, 3, 70, 7, 82),
        const _Curve(107, 62, 117, 68, 114, 74),
      ),
      MascotMood.wave => (
        const _Curve(13, 62, 3, 70, 7, 82),
        const _Curve(107, 60, 118, 52, 113, 38),
      ),
      MascotMood.thinking => (
        const _Curve(13, 62, 3, 70, 7, 82),
        const _Curve(107, 62, 117, 70, 113, 82),
      ),
      MascotMood.happy => (
        const _Curve(13, 60, 2, 52, 7, 38),
        const _Curve(107, 60, 118, 52, 113, 38),
      ),
      MascotMood.sorry => (
        const _Curve(13, 62, 3, 72, 8, 84),
        const _Curve(107, 62, 117, 72, 112, 84),
      ),
    };
    final arm = _line(colors.mascotCover, 6.5);
    left.draw(canvas, arm);
    canvas.save();
    if (mood == MascotMood.wave && wave != null) {
      // Turns about the shoulder, where the arm leaves the book.
      canvas.translate(107, 60);
      canvas.rotate(wave! * _waveAngle);
      canvas.translate(-107, -60);
    }
    right.draw(canvas, arm);
    if (mood == MascotMood.wave) {
      canvas.drawCircle(const Offset(113, 34), 5, _fill(colors.mascotCover));
    }
    canvas.restore();
  }

  void _book(Canvas canvas) {
    final cover = Path()
      ..moveTo(12, 42)
      ..quadraticBezierTo(12, 33, 21, 34)
      ..lineTo(52, 38)
      ..quadraticBezierTo(58, 39, 60, 43)
      ..quadraticBezierTo(62, 39, 68, 38)
      ..lineTo(99, 34)
      ..quadraticBezierTo(108, 33, 108, 42)
      ..lineTo(108, 90)
      ..quadraticBezierTo(108, 98, 100, 99)
      ..lineTo(68, 104)
      ..quadraticBezierTo(62, 105, 60, 108)
      ..quadraticBezierTo(58, 105, 52, 104)
      ..lineTo(20, 99)
      ..quadraticBezierTo(12, 98, 12, 90)
      ..close();
    canvas.drawPath(cover, _fill(colors.mascotCover));

    final leftPage = Path()
      ..moveTo(18, 43)
      ..quadraticBezierTo(18, 39, 23, 39.5)
      ..lineTo(52, 43.5)
      ..quadraticBezierTo(57, 44.5, 58, 48)
      ..lineTo(58, 101)
      ..quadraticBezierTo(56, 99, 51, 98.5)
      ..lineTo(22, 94)
      ..quadraticBezierTo(18, 93.5, 18, 89)
      ..close();
    final rightPage = Path()
      ..moveTo(102, 43)
      ..quadraticBezierTo(102, 39, 97, 39.5)
      ..lineTo(68, 43.5)
      ..quadraticBezierTo(63, 44.5, 62, 48)
      ..lineTo(62, 101)
      ..quadraticBezierTo(64, 99, 69, 98.5)
      ..lineTo(98, 94)
      ..quadraticBezierTo(102, 93.5, 102, 89)
      ..close();
    final paper = _fill(colors.mascotPaper);
    canvas.drawPath(leftPage, paper);
    canvas.drawPath(rightPage, paper);
  }

  void _cap(Canvas canvas) {
    canvas.save();
    if (mood == MascotMood.sorry) {
      // Slipped a little: 7 degrees around the button on top.
      canvas.translate(60, 23);
      canvas.rotate(-7 * 3.141592653589793 / 180);
      canvas.translate(-60, -23);
    }
    final shade = _fill(colors.mascotCapShade);
    final base = Path()
      ..moveTo(40, 27)
      ..lineTo(40, 36)
      ..quadraticBezierTo(60, 45, 80, 36)
      ..lineTo(80, 27)
      ..close();
    canvas.drawPath(base, shade);
    final board = Path()
      ..moveTo(60, 8)
      ..lineTo(98, 23)
      ..lineTo(60, 38)
      ..lineTo(22, 23)
      ..close();
    canvas.drawPath(board, _fill(colors.mascotCap));
    canvas.drawCircle(const Offset(60, 23), 2.5, shade);
    final tassel = Path()
      ..moveTo(60, 23)
      ..lineTo(90, 30)
      ..lineTo(90, 44);
    canvas.drawPath(tassel, _line(colors.mascotCapShade, 2.5));
    canvas.drawRRect(_rounded(87.5, 43, 5, 9, 2), shade);
    canvas.restore();
  }

  void _cheeks(Canvas canvas) {
    _oval(canvas, 29, 74, 5.5, 3.2, colors.mascotCheek);
    _oval(canvas, 91, 74, 5.5, 3.2, colors.mascotCheek);
  }

  void _face(Canvas canvas) {
    final ink = _line(colors.mascotInk, 3);
    final bold = _line(colors.mascotInk, 3.4);
    final thin = _line(colors.mascotInk, 2.8);
    switch (mood) {
      case MascotMood.idle:
        _eye(canvas, 40, 62);
        _eye(canvas, 80, 62);
        const _Curve(50, 76, 60, 86, 70, 76).draw(canvas, ink);
        _checkBadge(canvas, 13);
      case MascotMood.wave:
        const _Curve(33, 63, 40, 56, 47, 63).draw(canvas, bold);
        _eye(canvas, 80, 62);
        _openMouth(
          canvas,
          49,
          92,
          71,
          tongueY: 81.5,
          tongueX: 4.5,
          tongueH: 2.6,
        );
        _checkBadge(canvas, 13);
      case MascotMood.thinking:
        _eye(canvas, 42, 61);
        _eye(canvas, 82, 61);
        const _Curve(33, 51, 40, 46, 48, 50).draw(canvas, thin);
        const _Curve(73, 49, 81, 45, 88, 50).draw(canvas, thin);
        const _Curve(54, 79, 60, 76, 66, 79).draw(canvas, ink);
        _thoughtDots(canvas);
      case MascotMood.happy:
        const _Curve(33, 65, 40, 54, 47, 65).draw(canvas, bold);
        const _Curve(73, 65, 80, 54, 87, 65).draw(canvas, bold);
        _openMouth(canvas, 48, 95, 72, tongueY: 83, tongueX: 5, tongueH: 3);
        _checkBadge(canvas, 15);
        _sparkle(canvas, 10, 22, 6);
        _sparkle(canvas, 108, 14, 5);
      case MascotMood.sorry:
        _eye(canvas, 40, 65, radius: 4.6);
        _eye(canvas, 80, 65, radius: 4.6);
        const _Curve(32, 59, 38, 53, 47, 53).draw(canvas, thin);
        const _Curve(73, 53, 82, 53, 88, 59).draw(canvas, thin);
        const _Curve(51, 83, 60, 75, 69, 83).draw(canvas, ink);
        _sweat(canvas);
        _warningBadge(canvas);
    }
  }

  void _eye(Canvas canvas, double x, double y, {double radius = 5.5}) {
    canvas.drawCircle(Offset(x, y), radius, _fill(colors.mascotInk));
    canvas.drawCircle(Offset(x + 1.8, y - 2), 1.8, _fill(colors.mascotPaper));
  }

  void _openMouth(
    Canvas canvas,
    double left,
    double depth,
    double right, {
    required double tongueY,
    required double tongueX,
    required double tongueH,
  }) {
    final mouth = Path()
      ..moveTo(left, 74)
      ..quadraticBezierTo(60, depth, right, 74)
      ..close();
    canvas.drawPath(mouth, _fill(colors.mascotInk));
    _oval(canvas, 60, tongueY, tongueX, tongueH, colors.mascotCheek);
  }

  void _checkBadge(Canvas canvas, double radius) {
    canvas.drawCircle(const Offset(94, 88), radius, _fill(colors.mascotCheck));
    final mark = Path()
      ..moveTo(87.5, 88)
      ..lineTo(92, 92.5)
      ..lineTo(101, 82.5);
    canvas.drawPath(mark, _line(colors.mascotPaper, 3.6));
  }

  void _warningBadge(Canvas canvas) {
    canvas.drawCircle(const Offset(94, 88), 13, _fill(colors.mascotCap));
    final ink = _fill(colors.mascotInk);
    canvas.drawRRect(_rounded(92, 80, 4, 10, 2), ink);
    canvas.drawCircle(const Offset(94, 94.5), 2.2, ink);
  }

  void _thoughtDots(Canvas canvas) {
    const dots = [(17.0, 30.0, 2.6), (9.0, 21.0, 3.4), (17.0, 10.0, 4.3)];
    for (final (i, (x, y, radius)) in dots.indexed) {
      canvas.drawCircle(
        Offset(x, y),
        radius,
        _fill(colors.mascotCover.withValues(alpha: _dotOpacity(i))),
      );
    }
  }

  /// Each dot is brightest a third of a cycle after the one before.
  double _dotOpacity(int i) {
    final value = pulse;
    if (value == null) return 1;
    final phase = (value - i / 3) % 1;
    return 0.3 + 0.7 * (phase < 0.5 ? 1 - phase * 2 : 0);
  }

  void _sparkle(Canvas canvas, double x, double y, double s) {
    final bend = s * 0.2;
    final star = Path()
      ..moveTo(x, y - s)
      ..quadraticBezierTo(x + bend, y - bend, x + s, y)
      ..quadraticBezierTo(x + bend, y + bend, x, y + s)
      ..quadraticBezierTo(x - bend, y + bend, x - s, y)
      ..quadraticBezierTo(x - bend, y - bend, x, y - s)
      ..close();
    canvas.drawPath(star, _fill(colors.mascotCap));
  }

  void _sweat(Canvas canvas) {
    final drop = Path()
      ..moveTo(100, 46)
      ..quadraticBezierTo(107, 56, 100, 60)
      ..quadraticBezierTo(93, 56, 100, 46)
      ..close();
    canvas.drawPath(drop, _fill(colors.mascotSweat));
  }

  void _oval(
    Canvas canvas,
    double x,
    double y,
    double radiusX,
    double radiusY,
    Color color,
  ) => canvas.drawOval(
    Rect.fromCenter(
      center: Offset(x, y),
      width: radiusX * 2,
      height: radiusY * 2,
    ),
    _fill(color),
  );

  RRect _rounded(
    double x,
    double y,
    double width,
    double height,
    double radius,
  ) => RRect.fromRectAndRadius(
    Rect.fromLTWH(x, y, width, height),
    Radius.circular(radius),
  );

  Paint _fill(Color color) => Paint()
    ..color = color
    ..isAntiAlias = true;

  Paint _line(Color color, double width) => Paint()
    ..color = color
    ..style = PaintingStyle.stroke
    ..strokeWidth = width
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..isAntiAlias = true;
}

/// One quadratic stroke: an arm, a brow, a smile.
class _Curve {
  const _Curve(this.x0, this.y0, this.cx, this.cy, this.x1, this.y1);

  final double x0, y0, cx, cy, x1, y1;

  void draw(Canvas canvas, Paint paint) => canvas.drawPath(
    Path()
      ..moveTo(x0, y0)
      ..quadraticBezierTo(cx, cy, x1, y1),
    paint,
  );
}
