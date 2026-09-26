import 'package:eduvision/main.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('a release APK built without API_BASE_URL explains itself', (
    tester,
  ) async {
    await tester.pumpWidget(const MisconfiguredApp());

    expect(find.textContaining('API_BASE_URL'), findsNWidgets(2));
    expect(find.textContaining('flutter build apk --release'), findsOneWidget);
  });
}
