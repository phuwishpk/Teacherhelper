import 'package:flutter/cupertino.dart'
    show CupertinoAlertDialog, CupertinoDialogAction;
import 'package:flutter/material.dart';

/// One button of [showMessageDialog]; tapping it closes the dialog with
/// [value].
class DialogAction<T> {
  const DialogAction(
    this.label, {
    this.value,
    this.key,
    this.primary = false,
    this.destructive = false,
  });

  final String label;
  final T? value;
  final Key? key;

  /// The action the dialog asks for: a filled button, or the bold button
  /// of an iOS alert.
  final bool primary;

  /// Deletes or discards something: red on every platform.
  final bool destructive;
}

/// A title, a short message and buttons (DESIGN §27.7): a Material
/// [AlertDialog] on Android and the web, an iOS alert on iOS. Dialogs with
/// fields, lists or pictures stay Material dialogs everywhere.
Future<T?> showMessageDialog<T>(
  BuildContext context, {
  Key? key,
  required String title,
  required String message,
  required List<DialogAction<T>> actions,
}) {
  return showAdaptiveDialog<T>(
    context: context,
    builder: (context) {
      void close(DialogAction<T> a) => Navigator.of(context).pop(a.value);
      if (Theme.of(context).platform == TargetPlatform.iOS) {
        return CupertinoAlertDialog(
          key: key,
          title: Text(title),
          content: Text(message),
          actions: [
            for (final a in actions)
              CupertinoDialogAction(
                key: a.key,
                isDefaultAction: a.primary && !a.destructive,
                isDestructiveAction: a.destructive,
                onPressed: () => close(a),
                child: Text(a.label),
              ),
          ],
        );
      }
      final scheme = Theme.of(context).colorScheme;
      return AlertDialog(
        key: key,
        title: Text(title),
        content: Text(message),
        actions: [
          for (final a in actions)
            if (a.primary)
              FilledButton(
                key: a.key,
                style: a.destructive
                    ? FilledButton.styleFrom(
                        backgroundColor: scheme.error,
                        foregroundColor: scheme.onError,
                      )
                    : null,
                onPressed: () => close(a),
                child: Text(a.label),
              )
            else
              TextButton(
                key: a.key,
                style: a.destructive
                    ? TextButton.styleFrom(foregroundColor: scheme.error)
                    : null,
                onPressed: () => close(a),
                child: Text(a.label),
              ),
        ],
      );
    },
  );
}
