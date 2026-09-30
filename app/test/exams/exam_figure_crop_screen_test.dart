import 'package:eduvision/features/exams/exam_figure_crop_screen.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';
import 'exam_import_fakes.dart';
import 'exam_test_helpers.dart';

/// "ลากกรอบใหม่" (DESIGN §22.4): draw, move or resize the figure's box on
/// the page image, then the server crops it again.
void main() {
  // The page is 1000 × 1400 px; on the 432-dp test view the canvas is
  // 400 × 560 dp (16 dp gutters), so 1 dp = 2.5 units across and down.
  const canvas = ValueKey('figure_crop_canvas');

  Future<FakeExamImportRepository> pump(
    WidgetTester tester, {
    Map<String, dynamic>? detail,
    FigureSource? source,
  }) async {
    final json = detail ?? importedExamJson();
    final exams = FakeExamsRepository(detail: json);
    final imports = FakeExamImportRepository(exams: exams);
    final q41 = ExamDetail.fromJson(json).question(41)!;
    await pumpExamScreen(
      tester,
      ExamFigureCropScreen(
        examId: 40,
        target: (option: false, id: 41),
        title: 'ภาพโจทย์ข้อ 5',
        source: source ?? q41.figureSource,
      ),
      repo: exams,
      overrides: [examImportRepositoryProvider.overrideWithValue(imports)],
    );
    return imports;
  }

  Offset at(WidgetTester tester, double x, double y) =>
      tester.getTopLeft(find.byKey(canvas)) + Offset(x, y);

  FilledButton save(WidgetTester tester) => tester.widget<FilledButton>(
    find.byKey(const ValueKey('figure_crop_save')),
  );

  testWidgets('starts from the read box and draws a new one', (tester) async {
    final imports = await pump(tester);
    expect(tester.getSize(find.byKey(canvas)), const Size(400, 560));
    expect(imports.args('pageImage'), [801]);
    expect(find.textContaining('กรอบ 100, 200, 400, 800'), findsOneWidget);
    expect(save(tester).onPressed, isNull, reason: 'nothing changed yet');

    // Outside the box: a new box from (20, 280) to (220, 420) dp.
    await tester.dragFrom(at(tester, 20, 280), const Offset(200, 140));
    await tester.pumpAndSettle();
    expect(find.textContaining('กรอบ 500, 50, 750, 550'), findsOneWidget);

    await tapVisible(tester, find.byKey(const ValueKey('figure_crop_save')));
    final (target, pageId, box) =
        imports.args('setFigure').single! as (ExamImageKey, int, List<int>);
    expect(target, (option: false, id: 41));
    expect(pageId, 801);
    expect(box, [500, 50, 750, 550]);
    expect(find.text('ตัดภาพประกอบใหม่แล้ว'), findsOneWidget);
    expect(find.byKey(canvas), findsNothing);
  });

  testWidgets('moves the box and resizes it from a corner', (tester) async {
    final imports = await pump(tester);

    // Inside the box (x 500, y 250 units): move by 40 × 56 dp.
    await tester.dragFrom(at(tester, 200, 140), const Offset(40, 56));
    await tester.pumpAndSettle();
    expect(find.textContaining('กรอบ 200, 300, 500, 900'), findsOneWidget);

    // The bottom-right corner (x 900, y 500 units = 360, 280 dp).
    await tester.dragFrom(at(tester, 360, 280), const Offset(-40, 56));
    await tester.pumpAndSettle();
    expect(find.textContaining('กรอบ 200, 300, 600, 800'), findsOneWidget);

    await tapVisible(tester, find.byKey(const ValueKey('figure_crop_save')));
    expect((imports.args('setFigure').single! as (Object, int, List<int>)).$3, [
      200,
      300,
      600,
      800,
    ]);
  });

  testWidgets('a box too small cannot be saved', (tester) async {
    await pump(tester);
    await tester.dragFrom(at(tester, 20, 500), const Offset(200, 1));
    await tester.pumpAndSettle();
    expect(find.text('กรอบเล็กเกินไป ลากให้ใหญ่ขึ้น'), findsOneWidget);
    expect(save(tester).onPressed, isNull);
  });

  testWidgets('another page starts without a box', (tester) async {
    final json = importedExamJson(
      pages: [
        for (final (id, doc, no) in [
          (801, 501, 1),
          (802, 501, 2),
          (803, 502, 1),
        ])
          {
            'id': id,
            'source_document_id': doc,
            'page_no': no,
            'width_px': 1000,
            'height_px': 1400,
            'available': true,
          },
      ],
    );
    final imports = await pump(tester, detail: json);

    await tester.tap(find.byKey(const ValueKey('figure_crop_page')));
    await tester.pumpAndSettle();
    expect(find.text('ไฟล์ 2 หน้า 1'), findsOneWidget);
    await tester.tap(find.text('ไฟล์ 1 หน้า 2').last);
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่มีกรอบ ลากบนภาพเพื่อวาด'), findsOneWidget);
    expect(imports.args('pageImage'), [801, 802]);

    await tester.dragFrom(at(tester, 40, 40), const Offset(100, 100));
    await tester.pumpAndSettle();
    await tapVisible(tester, find.byKey(const ValueKey('figure_crop_save')));
    final call = imports.args('setFigure').single! as (Object, int, List<int>);
    expect(call.$2, 802);
    expect(call.$3, [71, 100, 250, 350]);
  });

  testWidgets('a server error stays on the screen', (tester) async {
    final imports = await pump(tester);
    imports.failures['setFigure'] = Exception('boom');
    await tester.dragFrom(at(tester, 20, 280), const Offset(200, 140));
    await tester.pumpAndSettle();
    await tapVisible(tester, find.byKey(const ValueKey('figure_crop_save')));
    expect(find.byKey(canvas), findsOneWidget);
  });

  testWidgets('without page images the teacher attaches a picture', (
    tester,
  ) async {
    await pump(tester, detail: importedExamJson(pages: const []));
    expect(find.text('ยังไม่มีภาพหน้าเอกสาร'), findsOneWidget);
    expect(find.byKey(canvas), findsNothing);
  });
}
