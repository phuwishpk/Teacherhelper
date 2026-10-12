import 'package:flutter/material.dart';

/// A password [TextFormField] with an eye button that shows what was typed.
/// Behind the dots a browser autofill or a stray character is invisible,
/// which makes "รหัสผ่านไม่ถูกต้อง" look like a broken login; every password
/// field of the app uses this so the typed text can always be checked.
class PasswordField extends StatefulWidget {
  const PasswordField({
    super.key,
    required this.controller,
    required this.labelText,
    this.fieldKey,
    this.helperText,
    this.helperMaxLines,
    this.validator,
    this.onFieldSubmitted,
    this.textInputAction,
    this.focusNode,
    this.autofillHints,
  });

  /// Key of the inner [TextFormField] (what tests and tooling look up).
  final Key? fieldKey;

  final TextEditingController controller;
  final String labelText;
  final String? helperText;
  final int? helperMaxLines;
  final String? Function(String?)? validator;
  final ValueChanged<String>? onFieldSubmitted;
  final TextInputAction? textInputAction;
  final FocusNode? focusNode;
  final Iterable<String>? autofillHints;

  @override
  State<PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<PasswordField> {
  bool _show = false;

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      key: widget.fieldKey,
      controller: widget.controller,
      focusNode: widget.focusNode,
      obscureText: !_show,
      autocorrect: false,
      enableSuggestions: false,
      autofillHints: widget.autofillHints,
      decoration: InputDecoration(
        labelText: widget.labelText,
        helperText: widget.helperText,
        helperMaxLines: widget.helperMaxLines,
        suffixIcon: IconButton(
          tooltip: _show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน',
          icon: Icon(
            _show ? Icons.visibility_off_outlined : Icons.visibility_outlined,
          ),
          onPressed: () => setState(() => _show = !_show),
        ),
      ),
      validator: widget.validator,
      textInputAction: widget.textInputAction,
      onFieldSubmitted: widget.onFieldSubmitted,
    );
  }
}
