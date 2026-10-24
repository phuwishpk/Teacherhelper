import 'package:flutter/cupertino.dart' show CupertinoActivityIndicator;
import 'package:flutter/material.dart';

/// The spinner that stands in for a button's icon or a row's leading icon
/// while it works (DESIGN §27.7): a thin Material ring, or on iOS the
/// activity indicator sized to the same box and drawn in the colour of the
/// icon it replaces (Flutter's adaptive one is always 20 px and grey).
class SmallSpinner extends StatelessWidget {
  const SmallSpinner({super.key, this.size = 18});

  final double size;

  @override
  Widget build(BuildContext context) => SizedBox.square(
    dimension: size,
    child: Theme.of(context).platform == TargetPlatform.iOS
        ? CupertinoActivityIndicator(
            radius: size / 2,
            color: IconTheme.of(context).color,
          )
        : const CircularProgressIndicator(strokeWidth: 2),
  );
}
