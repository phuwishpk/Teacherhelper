# โค้ดตัวการ์ตูนน้องเช็คที่พร้อมวาง

ไฟล์ชุดนี้ผ่าน `flutter analyze` และ test แล้วใน package ทดลองที่ใช้ `app_theme.dart` กับกฎ lint ของแอป บน Flutter 3.44.5 แต่ยังไม่ได้วางในแอปจริง ให้ตรวจซ้ำตามหัวข้อท้ายไฟล์นี้

| ไฟล์ | วางที่ | ใช้ในข้อ |
|---|---|---|
| `app_theme_constants.txt` | ใน class `AppTheme` ของ `app/lib/core/theme/app_theme.dart` ต่อจากบรรทัด `_warningDark` | M1 |
| `app_theme_extension.txt` | ท้ายไฟล์ `app/lib/core/theme/app_theme.dart` | M1 |
| `mascot_mood.dart` | `app/lib/core/widgets/mascot_mood.dart` | M2 |
| `assistant_mood.dart` | `app/lib/features/assistant/assistant_mood.dart` | M2 |
| `assistant_mood_test.dart` | `app/test/assistant/assistant_mood_test.dart` | M2 |
| `mascot_painter.dart` | `app/lib/core/widgets/mascot_painter.dart` | M3 |
| `mascot.dart` | `app/lib/core/widgets/mascot.dart` | M4 |

## วิธีใช้ในหน้าจอ

```dart
import '../../core/widgets/mascot.dart';
import '../../core/widgets/mascot_mood.dart';

const Mascot(mood: MascotMood.idle, size: 28)      // ข้างคำตอบ
const Mascot(mood: MascotMood.wave, size: 96)      // แชทว่าง
const Mascot(mood: MascotMood.thinking, size: 28)  // รอคำตอบ จุดกะพริบเอง
```

- ปกติตัวการ์ตูนเป็นภาพประกอบ โปรแกรมอ่านหน้าจอจะข้ามไป
- ถ้าไม่มีข้อความกำกับ ให้ส่ง `semanticLabel: Mascot.name`
- ตัวการ์ตูนขยับเฉพาะ `thinking` ท่าอื่นอยู่นิ่ง และ `happy` กับ `sorry` ขยายเข้ามาครั้งเดียว
- ถ้าเครื่องตั้งลดภาพเคลื่อนไหว ตัวการ์ตูนจะไม่ขยับเลย

## ตรวจหลังวาง

```bash
cd app
flutter analyze lib/core
```

ถ้าเปลี่ยนชื่อตัวการ์ตูน ให้แก้ที่ `Mascot.name` จุดเดียว

ก่อน commit ต้องอ่านโค้ดให้เข้าใจ เพราะอาจารย์อาจถามตอนนำเสนอว่า `CustomPainter`, `shouldRepaint` และ `AnimationController` ทำงานอย่างไร
