import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'mascot_mood.dart';
import 'mascot_painter.dart';

/// น้องเช็ค, the assistant's character (DESIGN §43): an open book in a
/// graduation cap, drawn from the logo so it stays sharp at every size and
/// takes its colours from the theme.
///
/// It sways for a few seconds, then rests, and sways again: a button on
/// every page must not keep the screen redrawing, and between sways
/// `pumpAndSettle` can end. The dots of [MascotMood.thinking] run until the
/// answer comes. Nothing moves when the device asks for reduced motion.
/// Decorative by default: pass [semanticLabel] where no text says the same.
class Mascot extends StatefulWidget {
  const Mascot({
    super.key,
    required this.mood,
    this.size = 40,
    this.semanticLabel,
    this.moves = true,
  });

  static const name = 'น้องเช็ค';

  final MascotMood mood;
  final double size;
  final String? semanticLabel;

  /// False stands still, for one of many on a screen. The thinking dots run
  /// either way: they say the AI is working.
  final bool moves;

  @override
  State<Mascot> createState() => _MascotState();
}

class _MascotState extends State<Mascot> with TickerProviderStateMixin {
  static const _popIn = Duration(milliseconds: 300);

  /// The first sway comes soon, so the teacher sees it is alive.
  static const _firstRest = Duration(milliseconds: 1200);
  static const _sway = Duration(milliseconds: 2800);
  static const _rest = Duration(seconds: 4);

  /// Each sway goes there and back this many times.
  static const _swings = 2;

  /// At full strength, in radians and as parts of [Mascot.size].
  static const _tilt = 0.14;
  static const _step = 0.05;
  static const _hop = 0.07;

  late final _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  );

  late final _swaying = AnimationController(vsync: this, duration: _sway)
    ..addStatusListener(_onSwayStatus);

  late final _motion = Listenable.merge([_pulse, _swaying]);

  Timer? _nextSway;

  bool get _reducedMotion =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  bool get _pops =>
      widget.mood == MascotMood.happy || widget.mood == MascotMood.sorry;

  /// How lively each mood is.
  double get _energy => switch (widget.mood) {
    MascotMood.happy => 1.2,
    MascotMood.wave => 1,
    MascotMood.idle => 0.8,
    MascotMood.thinking => 0.5,
    MascotMood.sorry => 0.4,
  };

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncPulse();
    _syncSway();
  }

  @override
  void didUpdateWidget(Mascot oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.mood != widget.mood) _syncPulse();
    if (oldWidget.moves != widget.moves) _syncSway();
  }

  void _syncPulse() {
    final thinks = widget.mood == MascotMood.thinking && !_reducedMotion;
    if (thinks && !_pulse.isAnimating) {
      _pulse.repeat();
    } else if (!thinks && _pulse.isAnimating) {
      _pulse.stop();
    }
  }

  void _syncSway() {
    if (widget.moves && !_reducedMotion) {
      if (_nextSway == null && !_swaying.isAnimating) _restFor(_firstRest);
      return;
    }
    _nextSway?.cancel();
    _nextSway = null;
    _swaying.value = 0;
  }

  void _restFor(Duration rest) {
    _nextSway = Timer(rest, () {
      _nextSway = null;
      if (mounted) _swaying.forward(from: 0);
    });
  }

  void _onSwayStatus(AnimationStatus status) {
    if (status == AnimationStatus.completed) _restFor(_rest);
  }

  @override
  void dispose() {
    _nextSway?.cancel();
    _swaying.dispose();
    _pulse.dispose();
    super.dispose();
  }

  /// [swing] is -1 to 1 and starts and ends a sway at 0, so it never jumps.
  Matrix4 _swayed(double swing) {
    final energy = _energy;
    final size = widget.size;
    return Matrix4.translationValues(
      size * _step * energy * swing,
      -size * _hop * energy * swing.abs(),
      0,
    )..rotateZ(_tilt * energy * swing);
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    Widget picture = SizedBox.square(
      dimension: widget.size,
      child: AnimatedBuilder(
        animation: _motion,
        builder: (context, _) {
          final swaying = _swaying.isAnimating;
          final at = _swaying.value;
          final swing =
              math.sin(at * math.pi) * math.sin(at * math.pi * 2 * _swings);
          return Transform(
            alignment: Alignment.bottomCenter,
            transform: swaying ? _swayed(swing) : Matrix4.identity(),
            child: CustomPaint(
              painter: MascotPainter(
                mood: widget.mood,
                colors: colors,
                pulse: _pulse.isAnimating ? _pulse.value : null,
                wave: swaying ? math.sin(at * math.pi * 8) : null,
              ),
            ),
          );
        },
      ),
    );
    if (_pops && !_reducedMotion) {
      picture = TweenAnimationBuilder<double>(
        key: ValueKey(widget.mood),
        tween: Tween(begin: 0.85, end: 1),
        duration: _popIn,
        curve: Curves.easeOutBack,
        builder: (context, scale, child) =>
            Transform.scale(scale: scale, child: child),
        child: picture,
      );
    }
    final label = widget.semanticLabel;
    return label == null
        ? ExcludeSemantics(child: picture)
        : Semantics(image: true, label: label, child: picture);
  }
}
