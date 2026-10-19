import 'package:eduvision/features/gradebook/gradebook_dialogs.dart';
import 'package:eduvision/features/gradebook/gradebook_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The "การเข้าเรียน" item made from the check-ins (DESIGN §29.9) is not
/// typed in the gradebook: the server refuses any change to its cells
/// (422 score_from_attendance), so the dialog says where the score comes
/// from and offers nothing to save.
void main() {
  const fromCheckIns = GradebookColumn(
    key: 'i62',
    type: ColumnType.custom,
    id: 62,
    name: 'การเข้าเรียน',
    categoryId: 13,
    fullMarks: 10,
    isAttendance: true,
  );
  const typedItem = GradebookColumn(
    key: 'i60',
    type: ColumnType.custom,
    id: 60,
    name: 'การแต่งกาย',
    categoryId: 13,
    fullMarks: 10,
    editable: true,
  );
  const row = GradebookRow(
    studentId: 101,
    studentNumber: 1,
    name: 'เด็กชายเอ',
    cells: {
      'i62': GradebookCell(score: 8.5, state: CellState.scored),
      'i60': GradebookCell(state: CellState.scored, score: 9),
    },
  );

  Future<void> open(WidgetTester tester, GradebookColumn column) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () => showDialog<CellEditResult>(
              context: context,
              builder: (_) => CellEditDialog(row: row, column: column),
            ),
            child: const Text('open'),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
  }

  testWidgets('a check-in score points at the attendance page', (
    tester,
  ) async {
    await open(tester, fromCheckIns);

    expect(
      find.text('คะแนน 8.5/10 จากการเช็คชื่อ แก้ได้ที่หน้าเช็คชื่อของรายวิชา'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('cell_save')), findsNothing);
    expect(find.byKey(const ValueKey('cell_excused')), findsNothing);
    expect(find.text('คะแนนจากผลที่ประกาศแล้ว'), findsNothing);
    await tester.tap(find.text('ปิด'));
    await tester.pumpAndSettle();
    expect(find.byType(CellEditDialog), findsNothing);
  });

  testWidgets('a typed item still takes a score', (tester) async {
    await open(tester, typedItem);

    expect(find.byKey(const ValueKey('cell_score')), findsOneWidget);
    expect(find.byKey(const ValueKey('cell_save')), findsOneWidget);
    expect(find.byKey(const ValueKey('cell_from_attendance')), findsNothing);
  });
}
