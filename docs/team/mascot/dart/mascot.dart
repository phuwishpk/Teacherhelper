import 'package:flutter/material.dart';

import 'mascot_mood.dart';
import 'mascot_painter.dart';

/// น้องเช็ค, the assistant's character (DESIGN §43): an open book in a
/// graduation cap, drawn from the logo so it stays sharp at every size and
/// takes its colours from the theme.
///
/// It moves only while it thinks, and not at all when the device asks for
/// reduced motion. Every other mood stands still, so `pumpAndSettle` ends.
/// Decorative by default: pass [semanticLabel] where no text says the same.
class Mascot extends StatefulWidget {
  const Mascot({
    super.key,
    required this.mood,
    this.size = 40,
    this.semanticLabel,
  });

  static const name = 'น้องเช็ค';

  final MascotMood mood;
  final double size;
  final String? semanticLabel;

  @override
  State<Mascot> createState() => _MascotState();
}

class _MascotState extends State<Mascot> with SingleTickerProviderStateMixin {
  static const _popIn = Duration(milliseconds: 300);

  late final _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  );

  bool get _reducedMotion =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  bool get _pops =>
      widget.mood == MascotMood.happy || widget.mood == MascotMood.sorry;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncPulse();
  }

  @override
  void didUpdateWidget(Mascot oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.mood != widget.mood) _syncPulse();
  }

  void _syncPulse() {
    final thinks = widget.mood == MascotMood.thinking && !_reducedMotion;
    if (thinks && !_pulse.isAnimating) {
      _pulse.repeat();
    } else if (!thinks && _pulse.isAnimating) {
      _pulse.stop();
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final moving = _pulse.isAnimating;
    Widget picture = SizedBox.square(
      dimension: widget.size,
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, _) => CustomPaint(
          painter: MascotPainter(
            mood: widget.mood,
            colors: colors,
            pulse: moving ? _pulse.value : null,
          ),
        ),
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
