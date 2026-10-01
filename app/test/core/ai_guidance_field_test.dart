import 'package:dio/dio.dart';
import 'package:eduvision/core/widgets/ai_guidance_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Future<TextEditingController> _pumpField(
  WidgetTester tester, {
  String text = '',
  String? errorText,
  ValueChanged<String>? onChanged,
}) async {
  final controller = TextEditingController(text: text);
  addTearDown(controller.dispose);
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: AiGuidanceField(
          controller: controller,
          hintText: kGuidanceHintKeyRead,
          errorText: errorText,
          onChanged: onChanged,
        ),
      ),
    ),
  );
  return controller;
}

void main() {
  group('normalizeGuidance', () {
    test('trims and treats blank as none', () {
      expect(normalizeGuidance(null), isNull);
      expect(normalizeGuidance('  \n\t '), isNull);
      expect(normalizeGuidance('  ข้อ 3 \n'), 'ข้อ 3');
    });

    test('counts code points like the server', () {
      // "ที่" is one grapheme but three code points (mb_strlen = 3).
      expect(guidanceLength('ที่'), 3);
      expect(guidanceLength('abc'), 3);
    });

    test('reads errors.guidance of a 422 validation_failed only', () {
      final options = RequestOptions(path: '/x');
      DioException error(String code, Object? errors) => DioException(
        requestOptions: options,
        response: Response(
          requestOptions: options,
          statusCode: 422,
          data: {'message': 'm', 'errors': errors, 'code': code},
        ),
      );
      expect(
        guidanceErrorOf(
          error('validation_failed', {
            'guidance': ['ยาวเกิน'],
          }),
        ),
        'ยาวเกิน',
      );
      expect(guidanceErrorOf(error('validation_failed', {'x': 'y'})), isNull);
      expect(
        guidanceErrorOf(
          error('ai_key_missing', {
            'guidance': ['x'],
          }),
        ),
        isNull,
      );
      expect(guidanceErrorOf(StateError('no')), isNull);
    });
  });

  group('AiGuidanceField', () {
    testWidgets('shows the label, examples, privacy line and counter', (
      tester,
    ) async {
      await _pumpField(tester);
      expect(find.text('คำแนะนำถึง AI (ไม่บังคับ)'), findsOneWidget);
      expect(find.text(kGuidanceHintKeyRead), findsOneWidget);
      expect(find.text('อย่าใส่ชื่อหรือข้อมูลของนักเรียน'), findsOneWidget);
      expect(find.text('0/500'), findsOneWidget);

      await tester.enterText(find.byType(TextField), 'ที่');
      await tester.pump();
      expect(find.text('3/500'), findsOneWidget);
    });

    testWidgets('stops at 500 code points (Thai marks count)', (tester) async {
      final changes = <String>[];
      final c = await _pumpField(tester, onChanged: changes.add);
      // 200 × "ที่" = 600 code points: cut to 500.
      await tester.enterText(find.byType(TextField), 'ที่' * 200);
      await tester.pump();
      expect(guidanceLength(c.text), kGuidanceMaxLength);
      expect(find.text('500/500'), findsOneWidget);

      // At the limit a keystroke is refused.
      final full = c.text;
      await tester.enterText(find.byType(TextField), '$fullก');
      await tester.pump();
      expect(c.text, full);
      expect(changes, isNotEmpty);
    });

    testWidgets('shows the server message', (tester) async {
      await _pumpField(tester, errorText: 'ยาวเกิน 500 ตัวอักษร');
      expect(find.text('ยาวเกิน 500 ตัวอักษร'), findsOneWidget);
      expect(find.text('อย่าใส่ชื่อหรือข้อมูลของนักเรียน'), findsNothing);
    });

    testWidgets('GuidanceUsedNote reads "คำแนะนำที่ใช้"', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(body: GuidanceUsedNote(guidance: 'เน้นเศษส่วน')),
        ),
      );
      expect(find.text('คำแนะนำที่ใช้: เน้นเศษส่วน'), findsOneWidget);
    });
  });

  group('showGuidanceDialog', () {
    Future<List<GuidanceChoice?>> open(
      WidgetTester tester, {
      String? initial,
    }) async {
      final results = <GuidanceChoice?>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () async => results.add(
                  await showGuidanceDialog(
                    context,
                    title: 'ให้ AI เขียนคำอธิบายใหม่',
                    message: 'อธิบาย',
                    hintText: kGuidanceHintExplanation,
                    initial: initial,
                  ),
                ),
                child: const Text('open'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      return results;
    }

    testWidgets('is prefilled and sends the trimmed text', (tester) async {
      final results = await open(tester, initial: 'นับทีละสิบ');
      expect(find.text('ให้ AI เขียนคำอธิบายใหม่'), findsOneWidget);
      expect(find.text('นับทีละสิบ'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('ai_guidance')),
        ' ใช้ภาษาง่าย ',
      );
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pumpAndSettle();
      expect(results.single?.guidance, 'ใช้ภาษาง่าย');
    });

    testWidgets('empty sends none; cancel resolves to null', (tester) async {
      final results = await open(tester);
      await tester.tap(find.byKey(const ValueKey('guidance_send')));
      await tester.pumpAndSettle();
      expect(results.single, isNotNull);
      expect(results.single!.guidance, isNull);

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(results, hasLength(2));
      expect(results.last, isNull);
    });
  });
}
