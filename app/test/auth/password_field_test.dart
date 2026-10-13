import 'package:eduvision/core/widgets/password_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('the eye shows and hides what was typed', (tester) async {
    final controller = TextEditingController();
    addTearDown(controller.dispose);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Form(
            child: PasswordField(
              fieldKey: const ValueKey('pw'),
              controller: controller,
              labelText: 'รหัสผ่าน',
            ),
          ),
        ),
      ),
    );

    await tester.enterText(find.byKey(const ValueKey('pw')), '028744 ');
    await tester.pump();
    EditableText editable() => tester.widget(find.byType(EditableText));
    expect(editable().obscureText, isTrue);

    await tester.tap(find.byTooltip('แสดงรหัสผ่าน'));
    await tester.pump();
    expect(editable().obscureText, isFalse);
    // The stray trailing space is now the teacher's to see and delete.
    expect(controller.text, '028744 ');

    await tester.tap(find.byTooltip('ซ่อนรหัสผ่าน'));
    await tester.pump();
    expect(editable().obscureText, isTrue);
  });
}
