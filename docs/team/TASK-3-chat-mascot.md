# งานที่ 3: แยกโค้ดแชท AI + ใส่ตัวการ์ตูน "น้องเช็ค" + แก้แชทบอท

สำหรับ korn-daengthong (เครื่อง RAM 4 GB) และ agent ที่ช่วยเขียนโค้ด หน้าติดตามงานคือ #247

| ส่วน | branch | commit | ทำอะไร |
|---|---|---|---|
| A | `refactor/app-assistant-split` | 6 | แยกไฟล์แชทที่ใหญ่เกินไป พฤติกรรมไม่เปลี่ยน |
| B | `feat/app-assistant-mascot` | 16 | ใส่ตัวการ์ตูนในแชท, เปิดปิดได้และจำผ่าน Firestore, test ครบ 4 ประเภท |
| C | `fix/app-assistant-chat` | 15 | แก้บั๊กแชทบอท 12 จุด, แก้ความรู้ที่ตอบผิด, ให้ผู้ช่วยรู้จักชื่อน้องเช็ค |
| D (ถ้ามีเวลา) | `feat/app-onboarding-about` | 15 | ทำตาม `TASK-1-onboarding-about.md` โดยแก้ 4 จุดท้ายไฟล์นี้ |

**branch:** ทุกส่วนแตกจาก `main` ล่าสุด และเปิด PR เข้า `main` รายละเอียดอยู่ใน `00-START-HERE.md`

**ลำดับ:** ทำ A → B → C และให้แต่ละส่วนเป็น PR ของตัวเอง งานที่ต้องจบคือ A, B และ C (37 commit) ส่วน D ทำต่อเมื่อยังมีเวลา ถ้าไม่ทันก็ข้ามได้

**แบบตัวการ์ตูน:** `mascot/nong-check-idle.svg`, `-wave`, `-thinking`, `-happy`, `-sorry` ในโฟลเดอร์คู่มือนี้

**โค้ดตัวการ์ตูนเตรียมไว้แล้ว:** `mascot/dart/` มีไฟล์พร้อมวางสำหรับ M1–M4 ดูวิธีวางใน `mascot/dart/README.md`

**โค้ดแก้แชทเตรียมไว้แล้ว:** `chat-fixes/` มี test พร้อมวางและ diff สำหรับส่วน C

phuwishpk อนุมัติให้ใส่น้องเช็คแล้วเมื่อ 1 พ.ย. 2569 เริ่มส่วน B ได้เลยหลังจบส่วน A

## ตารางความคืบหน้า (ติ๊ก [x] เมื่อ commit แล้ว)

**ส่วน A: refactor**
- [x] R1 ย้ายฟองข้อความ (#249)
- [x] R2 ย้ายรายการบทสนทนา หน้าไม่มี key และไฟล์แนบ (#250)
- [x] R3 ย้ายหน้าแชท (#251)
- [x] R4 แยกส่วนกลางของการ์ด (#252)
- [x] R5 แยกการ์ดโจทย์ (#253)
- [x] R6 แยกการ์ด rubric ตัวชี้วัด สมุดคะแนน แบบฝึก และไฟล์ (#254)

**ส่วน B: ตัวการ์ตูน**
- [x] M1 สีของตัวการ์ตูนใน AppTheme (#255)
- [x] M2 เลือกอารมณ์จากสถานะแชท + unit test (#256)
- [x] M3 วางโค้ดวาดตัวการ์ตูน (#257)
- [x] M4 วาง widget ตัวการ์ตูนและภาพเคลื่อนไหว (#258)
- [x] M5 widget test ของตัวการ์ตูน (#259)
- [x] M6 ตัวการ์ตูนข้างคำตอบ (#260)
- [x] M7 ตัวการ์ตูนตอนกำลังคิด (#261)
- [x] M8 หน้าแชทว่าง + แก้ข้อความที่ล้าสมัย (#262)
- [x] M9 หัวแผงและหน้าที่ใช้แชทไม่ได้ (#263)
- [x] M10 ที่เก็บการตั้งค่าในเครื่องและ Firestore (#264)
- [x] M11 test ของที่เก็บการตั้งค่า (#265)
- [x] M12 Firestore rules (#266)
- [ ] M13 สวิตช์เปิดปิดในหน้าตั้งค่า (#267)
- [ ] M14 integration test (#268)
- [ ] M15 security test (#269)
- [ ] M16 เอกสาร DESIGN (#270)

**ส่วน C: แก้แชทบอท**
- [ ] F1 ลองใหม่หลังเปิดบทสนทนาไม่สำเร็จ (#271)
- [ ] F2 ไม่ส่งคำถามตอนไฟล์ยังอัปโหลด (#272)
- [ ] F3 เอาไฟล์ออกแล้วการอัปโหลดอื่นยังทำงาน (#273)
- [ ] F4 บอกเหตุผลเมื่อแนบเกิน 5 ไฟล์ (#274)
- [ ] F5 ช่องพิมพ์บอกว่าต้องทำอะไรกับไฟล์ (#275)
- [ ] F6 พิมพ์ต่อได้ทันทีหลังได้คำตอบบนคอม (#276)
- [ ] F7 ใส่ key แล้วกลับมาถามได้เลยบนมือถือ (#277)
- [ ] F8 ปุ่มในฟอง error ตรงกับสาเหตุ (#278)
- [ ] F9 ลองใหม่แล้วเน็ตหลุดต้องไม่ส่งคำถามซ้ำ (#279)
- [ ] F10 ชิปตัวอย่างไม่ลบข้อความที่พิมพ์ค้าง (#280)
- [ ] F11 จุดกะพริบหยุดเมื่อเครื่องขอลดภาพเคลื่อนไหว (#281)
- [ ] F12 แชทพอดีกับจอเตี้ย (#282)
- [ ] F13 แก้ความรู้เรื่องสแกนบนเว็บ (#283)
- [ ] F14 prompt v7 ให้รู้จักชื่อน้องเช็ค (#284)
- [ ] F15 เอกสาร DESIGN (#285)

**ส่วน D (ถ้ามีเวลา):** ติ๊กในตารางของ `TASK-1-onboarding-about.md`

---

## ความเกี่ยวข้องกับเกณฑ์ประเมิน 20 คะแนน

คะแนนเป็นของทั้งกลุ่ม งานนี้จึงไม่ต้องสร้างทุกอย่างใหม่ แต่ต้องไม่ทำให้ข้อใดเสียและเพิ่มหลักฐานได้ตามตารางนี้

| เกณฑ์ | คะแนน | งานนี้ช่วยอย่างไร | หลักฐานในวันนำเสนอ |
|---|---|---|---|
| 1. UI Material 3 + Usability | 3 | ใช้สี ตัวอักษร และ component ของ Material 3 จาก `AppTheme` รองรับ 3 ขนาดจอ | เปิดแชทให้ดู และเพื่อนช่วยคุมการทดสอบผู้ใช้ 5 คน |
| 2. REST / Firebase ≥ 2 บริการ | 3 | ใช้ REST `/assistant/*` และ `/health` คู่กับ Firestore `settings/app` | ถามแชท แล้วเปิด Firebase console ให้เห็นเอกสาร |
| 3. AI Feature | 3 | ตัวการ์ตูนตอบสนองทุกขั้นของ flow: ถาม คิด ตอบ สร้างงาน และล้มเหลว และแชทตอบเรื่องสแกนบนเว็บถูกต้อง | ถามจริงด้วย Gemini key ของครู แล้วสร้างการบ้านจากแชท |
| 4. Local DB + Firestore | 3 | ค่า "เปิดตัวการ์ตูน" เก็บในเครื่องและ Firestore; ฐานข้อมูลในเครื่อง (drift) ยังเป็นของบันทึกครูและคิวสแกน | ปิดสวิตช์บนเครื่องหนึ่ง แล้วเปิดอีกเครื่องต้องปิดตาม |
| 5. Test 4 ประเภท + Coverage ≥ 70% | 5 | Unit (M2, M11, F13), Widget (M5, F1–F12), Integration (M14), Security (M15) ทุกไฟล์ใหม่มี test | เปิดผล coverage ใน GitHub Actions |
| 6. GitHub Actions | 3 | ทุก PR ต้องเขียว | เปิดแท็บ Checks ของ PR |

ถ้ามีในโค้ดแต่สาธิตไม่ได้ เกณฑ์จะให้แค่ระดับ "บางส่วน" จึงต้องซ้อมเดโมบนเครื่องที่จะใช้จริง

**เครื่อง 4 GB ไม่ควรใช้เดโม** ให้เดโมบนเครื่องที่แรงกว่า และต้องทำสไลด์ของส่วนนี้ด้วย

---

## ข้อตกลงก่อนเริ่ม

**1. ใช้ RAM ให้น้อยที่สุด**
- ตรวจเฉพาะส่วนที่แก้: `flutter analyze lib/features/assistant lib/core/widgets`
- รัน test เฉพาะโฟลเดอร์: `flutter test test/assistant/`
- ให้ CI ใน PR รัน test ทั้งชุด ไม่ต้องรันเองบนเครื่อง 4 GB
- ถ้าจะลองปรับหน้าตาตัวการ์ตูน ให้ลองใน https://dartpad.dev ก่อน DartPad คอมไพล์บน cloud จึงไม่กิน RAM ของเครื่องเรา
- ถ้า VS Code หน่วง ให้ลองตั้ง `"dart.onlyAnalyzeProjectsWithOpenFiles": true` และ `"dart.analysisExcludedFolders": ["app/build"]`
- เปิด Chrome ดูหน้าตาจริงเมื่อจบแต่ละกลุ่มงาน ไม่ต้องเปิดทุก commit
- ถ้าเครื่องค้าง ให้ย้ายไปทำใน GitHub Codespaces

**2. ทดสอบแชทจริง**
- ชี้แอปไปที่ server ทดสอบ แล้วขอบัญชีครูจาก phuwishpk
- ใส่ Gemini key ของตัวเองในหน้าตั้งค่าของแอป สร้างฟรีได้ที่ Google AI Studio
- ห้ามเอา key ใส่ในโค้ดหรือไฟล์ที่จะ commit
- ห้ามพิมพ์ข้อมูลนักเรียนจริง

**3. ข้อห้ามของงานนี้**
- **ห้ามส่งข้อความแชทขึ้น Firestore** แชทอาจมีข้อมูลนักเรียนตาม DESIGN §37.8 และ §38.1 ห้ามไว้
- ห้ามใช้ตัวการ์ตูนของคนอื่น repo นี้เป็น public
- ห้ามเพิ่ม package ใหม่
- ห้ามกำหนดสี ฟอนต์ หรือมุมโค้งในหน้าจอ สีใหม่ให้เพิ่มใน `AppTheme`
- ห้ามเปลี่ยนปุ่มเปิดแชท (ไอคอน `Icons.smart_toy_outlined`) เพราะคู่มือทุกฉบับเรียกว่า "ปุ่มรูปหุ่นยนต์"
- ห้ามเปลี่ยน key ของ widget ที่มีอยู่ เช่น `assistant_thinking`, `assistant_error`, `assistant_input` เพราะ test เดิมใช้
- ห้ามเขียน Firestore จากแชทนอกเหนือจากเอกสาร `settings/app`

**4. ข้อความ commit**
ทุก commit ขึ้นต้นตามรูปแบบในแต่ละข้อ จบด้วยบรรทัด `Closes #เลข` ของ commit นั้น (เลขอยู่ในตารางความคืบหน้า) และไม่มีบรรทัด `Co-Authored-By`

---

# ส่วน A: refactor แชท (ไม่เปลี่ยนพฤติกรรม)

**ทำไม:** `assistant_plan_card.dart` ยาว 1,531 บรรทัด เป็นไฟล์ใหญ่ที่สุดของแอป และ `assistant_panel.dart` ยาว 771 บรรทัด ถ้าใส่ตัวการ์ตูนลงไปตรง ๆ จะยิ่งแก้ยาก

**กฎของส่วนนี้:**
- ย้ายโค้ดเท่านั้น ห้ามแก้ข้อความ สี ระยะ หรือ logic
- ไม่ใช้ `part` เพราะในโปรเจกต์นี้ใช้เฉพาะไฟล์ generate
- class ที่ถูกย้ายข้ามไฟล์ต้องเปลี่ยนจาก `_ชื่อ` เป็นชื่อ public เช่น `_Bubble` → `AssistantBubble`
- ทุก commit ต้องผ่านคำสั่งตรวจนี้โดยไม่แก้ไฟล์ test:

```bash
flutter analyze lib/features/assistant
flutter test test/assistant/
```

ถ้า test เดิมพังหลังย้ายโค้ด แปลว่าย้ายผิด ให้แก้โค้ด ไม่ใช่แก้ test

## R1: ย้ายฟองข้อความ 🤖

**ไฟล์ใหม่:** `app/lib/features/assistant/assistant_bubble.dart`

**ทำ:** ย้าย `_Bubble` เป็น `AssistantBubble` และ `_ThinkingBubble` เป็น `AssistantThinkingBubble` จาก `assistant_panel.dart`

**commit:** `refactor(app): move assistant bubbles to their own file`

## R2: ย้ายรายการบทสนทนา หน้าไม่มี key และไฟล์แนบ 🤖

**ไฟล์ใหม่:**
- `assistant_conversation_list.dart`: `_ConversationList` → `AssistantConversationList`
- `assistant_notices.dart`: `_NoKey` → `AssistantNoKey`, `_Centered` → `AssistantCentered`
- `assistant_attached_files.dart`: `_AttachedFiles` → `AssistantAttachedFiles`

**commit:** `refactor(app): move the conversation list, notices and attachments out of the panel`

## R3: ย้ายหน้าแชท 🤖

**ไฟล์ใหม่:** `assistant_chat_view.dart`: `_Chat` → `AssistantChatView` พร้อม `_thousands`

**ผลที่ต้องได้:** `assistant_panel.dart` เหลือแค่ `AssistantPanel` และ `AssistantScreen` (ราว 200 บรรทัด)

**commit:** `refactor(app): move the chat view out of the panel`

## R4: แยกส่วนกลางของการ์ด 🤖

**ไฟล์ใหม่:** `app/lib/features/assistant/plan_cards/plan_card_common.dart`

**ทำ:** ย้าย `_Replaced`, `_Footer`, `_SimpleApplied` และ `_points` ซึ่งการ์ดหลายชนิดใช้ร่วมกัน เปลี่ยนเป็นชื่อ public

**คงไว้:** `AssistantPlanCard` อยู่ใน `assistant_plan_card.dart` ตามเดิม เพื่อให้ไฟล์อื่นและ test ไม่ต้องเปลี่ยน import

**commit:** `refactor(app): extract the shared parts of the plan cards`

## R5: แยกการ์ดโจทย์ 🤖

**ไฟล์ใหม่:** `plan_cards/question_plan_card.dart`

**ทำ:** ย้าย `_Open`, `_QuestionTile`, `_Option`, `_TargetDialog`, `_Applied`

**commit:** `refactor(app): move the question plan card to its own file`

## R6: แยกการ์ดที่เหลือ 🤖

**ไฟล์ใหม่ใน `plan_cards/`:**
- `rubric_plan_card.dart`: `_OpenRubric`, `_RubricApplied`
- `indicator_plan_card.dart`: `_OpenIndicators`, `_IndicatorsApplied`
- `gradebook_plan_card.dart`: `_OpenGradebook`
- `practice_plan_card.dart`: `_OpenPractice`
- `file_plan_card.dart`: `_OpenFile`, `_FileApplied`

**ผลที่ต้องได้:** `assistant_plan_card.dart` เหลือแค่ตัวเลือกว่าจะแสดงการ์ดใด (ราว 100 บรรทัด)

**commit:** `refactor(app): split the remaining plan cards by kind`

**จบส่วน A:** push แล้วเปิด PR เข้า `main` รอ CI เขียว และรอ merge ก่อนเริ่มส่วน B

---

# ส่วน B: ตัวการ์ตูน "น้องเช็ค"

**อารมณ์ทั้งห้า**

| อารมณ์ | enum | เมื่อไหร่ |
|---|---|---|
| ปกติ | `MascotMood.idle` | คำตอบทั่วไป |
| ทักทาย | `MascotMood.wave` | แชทว่าง และหน้าแนะนำ |
| กำลังคิด | `MascotMood.thinking` | รอคำตอบจาก AI |
| ดีใจ | `MascotMood.happy` | ข้อความล่าสุดเป็นการ์ดที่สร้างงานสำเร็จ |
| ขอโทษ | `MascotMood.sorry` | ข้อความล่าสุดเป็น error |

## M1: สีของตัวการ์ตูนใน AppTheme 🧑

**ไฟล์:** `app/lib/core/theme/app_theme.dart`

**ทำ:** โค้ดเตรียมไว้แล้วใน `mascot/dart/`
1. วาง `app_theme_constants.txt` ใน class `AppTheme` ต่อจากบรรทัด `_warningDark`
2. วาง `app_theme_extension.txt` ท้ายไฟล์
3. อ่านให้เข้าใจว่าทำไมหน้าปกตามธีม แต่กระดาษกับหมึกใช้สีเดียวกันทั้งสองธีม

**ตรวจ:** `flutter analyze lib/core/theme`

**commit:** `feat(app): add mascot colours to AppTheme`
## M2: เลือกอารมณ์จากสถานะแชท 🤖

**ไฟล์:** คัดลอกจาก `mascot/dart/` แล้วแก้ `assistant.dart`
- `app/lib/core/widgets/mascot_mood.dart`
- `app/lib/features/assistant/assistant_mood.dart`: ฟังก์ชัน `MascotMood moodForChat(AssistantChatState chat)`
- `app/test/assistant/assistant_mood_test.dart`
- `app/lib/features/assistant/assistant.dart`: เพิ่ม `justApplied` ใน `AssistantChatState`

**ลำดับการตัดสินใน `moodForChat`:**
1. `chat.sending` → `thinking`
2. `chat.messages` ว่าง → `wave`
3. `messages.last.isError` → `sorry`
4. `chat.justApplied` หรือ `messages.last.planStatus == AssistantMessage.planApplied` → `happy`
5. นอกนั้น → `idle`

**ทำไมต้องมี `justApplied`:** ครูพิมพ์ "โอเค สร้างเลย" แทนการกดปุ่มได้ ข้อความล่าสุดจึงเป็น "กำลังสร้างข้อที่ติ๊กไว้…" ไม่ใช่การ์ด ถ้าดูแค่ `messages.last` น้องเช็คจะไม่ดีใจ

```dart
// AssistantChatState: ใน constructor เพิ่ม this.justApplied = false,
/// A card was created just now, by its button or a typed confirmation.
final bool justApplied;

// AssistantChatController.replaceMessage: เพิ่มใน state ใหม่
justApplied: message.planStatus == AssistantMessage.planApplied,
```

ทุกที่อื่นสร้าง state ใหม่โดยไม่ส่งค่านี้ ค่าจึงกลับเป็น `false` เองเมื่อครูถามข้อถัดไป

**ตรวจ:** `flutter test test/assistant/assistant_mood_test.dart`

**commit:** `feat(app): choose the mascot's mood from the chat state`

## M3: วางโค้ดวาดตัวการ์ตูน 🧑

**ไฟล์:** คัดลอก `mascot/dart/mascot_painter.dart` ไปที่ `app/lib/core/widgets/mascot_painter.dart`

**ทำ:**
1. อ่าน `paint()` แล้วเทียบกับ `mascot/nong-check-idle.svg` ว่าแต่ละส่วนคือส่วนไหนของตัวการ์ตูน
2. 🧑 ถ้าอยากฝึก ลองเปลี่ยนรอยยิ้มหรือสีแก้มในโค้ด แล้วดูผลใน DartPad

ขั้นนี้ยังไม่แสดงบนหน้าจอ เพราะ widget `Mascot` มาใน M4

**ตรวจ:** `flutter analyze lib/core/widgets`

**commit:** `feat(app): draw the mascot with CustomPainter`
## M4: วาง widget ตัวการ์ตูนและภาพเคลื่อนไหว 🧑

**ไฟล์:** คัดลอก `mascot/dart/mascot.dart` ไปที่ `app/lib/core/widgets/mascot.dart`

**สิ่งที่โค้ดนี้ทำ (อ่านให้เข้าใจ):**
- `AnimationController` วนเฉพาะ `MascotMood.thinking`
- หยุดเมื่อเปลี่ยนอารมณ์ หรือเมื่อเครื่องตั้งลดภาพเคลื่อนไหว
- `happy` และ `sorry` ขยายเข้ามาครั้งเดียว 300 ms
- ปกติซ่อนจากโปรแกรมอ่านหน้าจอ ถ้าต้องการให้อ่านออกเสียงให้ส่ง `semanticLabel`

**ข้อควรระวัง:** ห้ามแก้ให้ท่า `idle` หรือ `wave` วนไม่จบ เพราะ `pumpAndSettle` ใน test เดิมจะค้างจน timeout

**ตรวจ:** `flutter analyze lib/core/widgets` และเปิดดูใน Chrome ทั้งสองธีม

**commit:** `feat(app): add the mascot widget, animated only while it thinks`
## M5: widget test ของตัวการ์ตูน 🤖

**ไฟล์ใหม่:** `app/test/core/mascot_test.dart`

**กรณีที่ต้องมี:**
1. วาดได้ครบทุกอารมณ์ ทั้งธีมสว่างและมืด ที่ขนาด 28, 40, 96 และ 160
2. `idle`, `wave`, `happy` และ `sorry` จบแอนิเมชัน (`pumpAndSettle` ผ่าน)
3. `thinking` มีแอนิเมชัน และหยุดเมื่อตั้ง `MediaQueryData(disableAnimations: true)`
4. `shouldRepaint` คืน `true` เมื่ออารมณ์เปลี่ยน

**ตรวจ:** `flutter test test/core/mascot_test.dart`

**commit:** `test(app): mascot paints every mood and respects reduced motion`

## M6: ตัวการ์ตูนข้างคำตอบ 🧑🤖

**ไฟล์:** `assistant_bubble.dart`, `assistant_chat_view.dart`

**ทำ:**
- คำตอบของผู้ช่วยและข้อความ error มี `Mascot(size: 28)` อยู่ซ้ายของฟอง จัดชิดล่าง
- เฉพาะข้อความล่าสุดใช้ `moodForChat(chat)` ข้อความเก่าใช้ `idle` (error เก่าใช้ `sorry`)
- ข้อความของครูไม่มีตัวการ์ตูน
- `maxWidth` ของฟองต้องหักความกว้างตัวการ์ตูนกับระยะห่าง (36 px) เพื่อไม่ให้ล้นแผง 420 px

**ตรวจ:** `flutter test test/assistant/` และดูใน Chrome ที่ 390 px กับ 1440 px

**commit:** `feat(app): show the mascot beside the assistant's answers`

## M7: ตัวการ์ตูนตอนกำลังคิด 🤖

**ไฟล์:** `assistant_bubble.dart`

**ทำ:** ใน `AssistantThinkingBubble` แสดง `Mascot(mood: MascotMood.thinking, size: 28)` ซ้ายของฟอง ฟองยังมีจุดกะพริบและข้อความ "ผู้ช่วยกำลังคิด…" เหมือนเดิม คง key `assistant_thinking` ไว้

**commit:** `feat(app): the mascot thinks beside the waiting bubble`

## M8: หน้าแชทว่างและแก้ข้อความที่ล้าสมัย 🧑🤖

**ไฟล์:** `assistant_chat_view.dart`

**ทำ:**
- ด้านบนของหน้าแชทว่างแสดง `Mascot(mood: MascotMood.wave, size: 96)` และข้อความ "สวัสดี น้องเช็คเอง"
- **แก้บั๊กข้อความ:** ตอนนี้เขียนว่า "ตอนนี้ยังเปิดดูหรือแก้ข้อมูลในบัญชีให้ไม่ได้" ซึ่งไม่จริงแล้ว เพราะผู้ช่วยสร้างงานและดูข้อมูลได้ตาม DESIGN §37.6–§37.10 ให้เปลี่ยนเป็น "ผู้ช่วยตอบจากคู่มือของระบบ และสร้างงานให้ได้เมื่อครูกดยืนยันในการ์ด"
- 🧑 คิดคำทักทายเองได้ แต่ต้องสั้นและบอกว่าถามอะไรได้
- ค้น `grep -rn "ยังเปิดดูหรือแก้ข้อมูล" lib test` ถ้า test เดิมตรวจข้อความนี้ ให้แก้ test ใน commit เดียวกัน

**commit:** `feat(app): greet with the mascot and correct what the empty chat promises`

## M9: หัวแผงและหน้าที่ใช้แชทไม่ได้ 🤖

**ไฟล์:** `assistant_panel.dart`, `assistant_notices.dart`

**ทำ:**
- หัวแผง: แทน `Icon(Icons.smart_toy_outlined)` ด้วย `Mascot(mood: moodForChat(chat), size: 40)`
- `AssistantCentered`: รับ widget แทน `IconData` ได้
  - หน้ายังไม่มี key ใช้ `idle` ขนาด 96
  - โหลดสถานะไม่สำเร็จใช้ `sorry` ขนาด 96

**commit:** `feat(app): mascot in the panel header and the unavailable states`

## M10: ที่เก็บการตั้งค่าในเครื่องและ Firestore 🤖

**ไฟล์:** `app/lib/core/auth/token_storage.dart` และไฟล์ใหม่ `app/lib/core/live/app_settings.dart`

**ทำ:**
1. `TokenStorage` เพิ่ม `readMascotEnabled(int userId)` คืน `bool?` และ `writeMascotEnabled(int userId, bool on)` ทำให้ครบใน `SecureTokenStorage` และ `InMemoryTokenStorage` ดูแบบ `readMyIndicatorsPromptClosed`
2. `AppSettingsStore` อ่านเขียนเอกสาร Firestore `users/{uid}/settings/app` รับ `FirebaseFirestore? db` กับ `String? uid` ถ้าเป็น `null` ให้ทำงานในเครื่องอย่างเดียว
3. `mascotEnabledProvider` (Notifier ของ `bool`, ค่าเริ่มต้น `true`)
   - ตอนเริ่ม: อ่านในเครื่องก่อน แล้วอ่าน Firestore ถ้ามีค่าให้ใช้ค่านั้นและเขียนกลับลงเครื่อง
   - ตอนเปลี่ยน: เขียนในเครื่องทันที แล้วเขียน Firestore ด้วย `SetOptions(merge: true)`
   - ครอบทุกการอ่านเขียนด้วย `try/catch` ถ้าพลาดให้ `debugPrint` แล้วใช้ค่าเดิม ห้ามทำให้แชทพัง
4. ใน `Mascot`-related widget ที่เปิดอยู่ในแชท ถ้า `mascotEnabledProvider` เป็น `false` ให้กลับไปใช้ `Icons.smart_toy_outlined` ตามเดิม และซ่อนตัวการ์ตูนข้างฟอง

**ข้อควรระวัง:** test เดิมหลายไฟล์ไม่ได้ override `tokenStorageProvider` การเรียก `SecureTokenStorage` ใน test จะโยน exception จึงต้องมี `try/catch` และคืนค่าเริ่มต้น `true`

**ตรวจ:** `flutter test test/assistant/`

**commit:** `feat(app): keep the mascot setting on the device and in Firestore`

## M11: test ของที่เก็บการตั้งค่า 🤖

**ไฟล์ใหม่:** `app/test/core/app_settings_test.dart`

**ใช้:** `FakeFirebaseFirestore` และ `InMemoryTokenStorage`

**กรณีที่ต้องมี:**
1. ไม่มีค่าที่ไหนเลย → `true`
2. ปิดแล้วเขียนทั้งสองที่
3. เครื่องใหม่ที่ไม่มีค่าในเครื่อง อ่านจาก Firestore แล้วเขียนลงเครื่อง
4. `db` เป็น `null` ใช้งานได้
5. Firestore โยน exception → ค่าเดิมยังอยู่ และไม่โยนต่อ
6. ค่าของผู้ใช้คนหนึ่งไม่กระทบอีกคน

**commit:** `test(app): mascot setting on the device and in Firestore`

## M12: Firestore rules 🧑🤖

**ไฟล์:** `firebase/firestore.rules`

**ทำ:** เพิ่มฟังก์ชันระดับ `service` และ `match` ก่อนบรรทัด `match /{document=**}` อันสุดท้าย

```
function validAppSettings() {
  let d = request.resource.data;
  return d.keys().hasOnly(['mascot_enabled', 'onboarding_done_at', 'device'])
    && (!('mascot_enabled' in d) || d.mascot_enabled is bool)
    && (!('onboarding_done_at' in d) || d.onboarding_done_at is timestamp)
    && (!('device' in d) || d.device in ['android', 'ios', 'web']);
}

match /users/{uid}/settings/app {
  allow create, update: if request.auth != null
    && request.auth.uid == uid && validAppSettings();
}
```

🧑 อ่านแล้วอธิบายให้ได้ว่าแต่ละบรรทัดกันอะไร อาจารย์อาจถามตอนนำเสนอ

**หลัง merge:** แจ้ง phuwishpk ให้วาง rules ใน Firebase console แล้วกด Publish

**commit:** `feat(repo): let users write only their own app settings in Firestore`

## M13: สวิตช์เปิดปิดในหน้าตั้งค่า 🧑

**ไฟล์:** `app/lib/features/settings/settings_screen.dart`

**ทำ:** เพิ่ม `SwitchListTile` ชื่อ "แสดงน้องเช็คในแชท" key `settings_mascot` และคำอธิบาย "ปิดแล้วแชทจะใช้ไอคอนธรรมดา" ดูรูปแบบจาก `theme_mode_section.dart`

**ตรวจ:** ปิดสวิตช์แล้วกลับไปดูแชท และ `flutter test test/settings/`

**commit:** `feat(app): turn the mascot on or off in settings`

## M14: integration test 🤖

**ไฟล์ใหม่:** `app/test/flows/assistant_mascot_flow_test.dart`

**ทำ:** ดูแบบ `app/test/flows/teacher_flow.dart` และ `_FakeAssistant` ใน `app/test/assistant/assistant_test.dart` แล้วทดสอบ flow เดียวต่อเนื่อง:

1. เปิดแชทว่าง → อารมณ์ `wave`
2. พิมพ์คำถามแล้วส่ง โดยให้ repository ปลอมค้างไว้ → อารมณ์ `thinking`
3. ได้คำตอบ → `idle`
4. คำถามถัดไปล้มเหลว → `sorry` และเห็น `assistant_retry`
5. กดลองใหม่แล้วสำเร็จ → กลับเป็น `idle`
6. ไปปิดสวิตช์ → ตัวการ์ตูนหายจากแชท

ตรวจอารมณ์ด้วย `tester.widget<Mascot>(...)` ไม่ต้องเทียบรูป

**commit:** `test(app): assistant flow from greeting to retry with the mascot`

## M15: security test 🤖

**ไฟล์ใหม่:** `app/test/security/assistant_firestore_privacy_test.dart`

**กรณีที่ต้องมี:**
1. ส่งข้อความแชทที่มีคำเฉพาะ เช่น `ลับเฉพาะ123` โดยใช้ `FakeFirebaseFirestore` แล้วค้นทุกเอกสารใน `users/` ต้องไม่พบข้อความนี้
2. เอกสาร `settings/app` มี key อยู่ใน 3 ชื่อที่อนุญาตเท่านั้น
3. อ่านไฟล์ `../firebase/firestore.rules` แบบเดียวกับ `android_config_test.dart` แล้วยืนยันว่ามี `hasOnly` ของ 3 field และ `request.auth.uid == uid`

CI ไม่ได้รัน Firebase emulator จึงตรวจกฎจริงไม่ได้ ข้อ 3 ตรวจเฉพาะว่าข้อความในไฟล์ไม่หายไป ให้บอกข้อจำกัดนี้ในสไลด์ตามจริง

**ตรวจ:** `flutter test test/security/assistant_firestore_privacy_test.dart`

**commit:** `test(app): chat text never reaches Firestore`

## M16: เอกสาร 🧑

**ไฟล์:** `docs/DESIGN.md` เขียนภาษาไทย

**ทำ:**
- แก้ §37.5 ให้บอกตัวการ์ตูนในแชท โดยปุ่มเปิดแชทยังเป็นรูปหุ่นยนต์เหมือนเดิม
- เพิ่มข้อยกเว้นใน §38.1 และ §38.2: แอปเขียนเอกสาร `settings/app` เองได้ ไม่มี `expires_at` เพราะเป็นค่าตั้งของผู้ใช้ ไม่มีข้อมูลนักเรียน
- เพิ่มหัวข้อท้ายไฟล์ `## 43. น้องเช็ค: ตัวการ์ตูนของผู้ช่วย` อธิบายอารมณ์ เงื่อนไขของแต่ละอารมณ์ การเก็บค่า rules การแยกไฟล์ และ test ทั้ง 4 ประเภท
- ถ้าเลข 43 ถูกใช้แล้ว ให้ใช้เลขถัดไป และแก้เลขในคอมเมนต์โค้ด

**commit:** `docs: describe the mascot and the split assistant files`

**จบส่วน B:** push แล้วเปิด PR เข้า `main` รอ CI เขียว ลองบน Chrome ที่ 390, 820, 1440 px และแนบรูปหน้าจอใน PR

---

# ส่วน C: แก้แชทบอท

**ทำไม:** ลองแชทผ่าน test แล้วพบบั๊ก 12 จุด และผู้ช่วยยังตอบผิดว่าสแกนบนเว็บไม่ได้ ทั้งที่เว็บสแกนใบงานได้ตั้งแต่ 25 ต.ค. 2569 (DESIGN §25.6)

| ไฟล์ใน `chat-fixes/` | ใช้ทำอะไร |
|---|---|
| `assistant_chat_fixes_test.dart` | test ทุกข้อ แบ่งด้วยคอมเมนต์ `// F1` ถึง `// F13` วางที่ `app/test/assistant/` |
| `app-fixes.diff` | การแก้ F1–F12 ทั้งหมด เป็นแบบให้อ่าน |
| `knowledge-fixes.diff` | การแก้ `help_content.dart` และ `04-troubleshooting.md` ของ F13 |
| `prompt-v7.diff` | การแก้ prompt ของ F14 |

**ตรวจมาแล้วแค่ไหน:** ลองแก้ครบบนโค้ดก่อนแยกไฟล์แล้ว test ใหม่ 19 ข้อล้ม 17 ข้อก่อนแก้ และผ่านทั้งหมดหลังแก้ `flutter test test/assistant test/help` ผ่าน 55 ข้อ และ test ฝั่ง backend ของผู้ช่วยผ่าน 60 ข้อ แต่ยังไม่ได้ลองบนโค้ดหลังแยกไฟล์ และยังไม่ได้ลองกับ Gemini จริง

**`git apply` จะไม่ผ่าน** เพราะ diff ทำบนไฟล์ก่อนแยก ให้อ่านแล้วแก้ในไฟล์ใหม่ตามหัวข้อ "ไฟล์" ของแต่ละข้อ (`_Chat` คือ `AssistantChatView`, `_Bubble` คือ `AssistantBubble`, `_ThinkingBubble` คือ `AssistantThinkingBubble`) ส่วน hunk ของ `justApplied` ใน diff ทำไปแล้วใน M2

**ทำทุกข้อ F1–F12 ตามลำดับนี้:**
1. คัดลอก test ของข้อนั้นจาก `chat-fixes/assistant_chat_fixes_test.dart` ลง `app/test/assistant/assistant_chat_fixes_test.dart` (F1 คัดลอกส่วนหัวไฟล์, fake และ `pumpPanel` ด้วย)
2. รัน `flutter test test/assistant/assistant_chat_fixes_test.dart` ต้อง**แดง**ก่อน
3. แก้โค้ด แล้วรันใหม่จน**เขียว**
4. รัน `flutter analyze lib/features/assistant test/assistant` และ `flutter test test/assistant/`
5. commit

helper ที่ยังไม่มีคนเรียกจะโดน `flutter analyze` เตือน จึงคัดลอกมาตอนข้อที่ใช้: `focused` ที่ F6, `_apiError` กับ `ask` ที่ F8

ห้ามแก้ test เดิมเพื่อให้ผ่าน test เดิมผ่านครบหลังแก้ทุกข้ออยู่แล้ว

## F1: ลองใหม่หลังเปิดบทสนทนาไม่สำเร็จ 🤖

**อาการ:** เปิดบทสนทนาเก่าตอนเน็ตหลุด แล้วกด "ลองใหม่" แชทกลายเป็นหน้าว่าง และไม่เปิดบทสนทนาเดิมอีก

**สาเหตุ:** `retry()` คิดว่าเป็นคำถามที่ส่งไม่ถึง พอไม่มีคำถามก่อนหน้าจึงลบฟอง error แล้วจบ

**ไฟล์:** `assistant.dart`

**ทำ:** เพิ่ม `failedOpenId` ใน `AssistantChatState` ตั้งค่าใน `catch` ของ `open()` แล้วเพิ่มในต้น `retry()`:
```dart
if (state.failedOpenId case final id?) return open(id);
```

**commit:** `fix(app): retry opens a conversation that failed to open`

## F2: ไม่ส่งคำถามตอนไฟล์ยังอัปโหลด 🤖

**อาการ:** แนบไฟล์แล้วกดส่งระหว่างขึ้น "กำลังอัปโหลด…" คำถามไปโดยไม่มีไฟล์ และไฟล์หายเองเมื่อได้คำตอบ เพราะ `send()` ล้างรายการไฟล์หลังถาม

**ไฟล์:** `assistant_panel.dart`, `assistant_chat_view.dart`

**ทำ:**
- `_send`: ถ้า `assistantAttachmentsProvider.uploading` เป็น `true` ให้ `return` ก่อน `_input.clear()` ข้อความจะได้ไม่หาย
- ปุ่มส่ง: `onPressed: busy || attachments.uploading ? null : onSend`
- `hintText` ตอนอัปโหลด: "รออัปโหลดไฟล์ให้เสร็จก่อน"

**commit:** `fix(app): wait for a file that is still uploading before sending`

## F3: เอาไฟล์ออกแล้วการอัปโหลดอื่นยังทำงาน 🧑🤖

**อาการ:** กดเอาไฟล์ออกระหว่างอัปโหลดอีกไฟล์ ป้าย "กำลังอัปโหลด…" หาย และปุ่มแนบกดได้ทั้งที่ยังอัปโหลดอยู่

**ไฟล์:** `assistant.dart`

**ทำ:** `AssistantAttachmentsController.remove` ส่ง `uploading: state.uploading` ไปด้วย

**commit:** `fix(app): removing a file leaves the running upload alone`

## F4: บอกเหตุผลเมื่อแนบเกิน 5 ไฟล์ 🤖

**อาการ:** มี 5 ไฟล์แล้วกดแนบ ไม่มีอะไรเกิดขึ้น และเลือก 7 ไฟล์ก็อัปโหลดแค่ 5 ไฟล์โดยไม่บอก

**ไฟล์:** `assistant.dart`

**ทำ:** ใน `pick()` ตั้ง `error` เป็น "แนบได้ไม่เกิน 5 ไฟล์ต่อคำถาม" ทั้งตอนเต็มแล้วและตอนเลือกเกิน คำนวณ `room` ก่อนตั้ง `uploading: true`

**commit:** `fix(app): tell the teacher when a file is over the limit`

## F5: ช่องพิมพ์บอกว่าต้องทำอะไรกับไฟล์ 🧑

**อาการ:** แนบไฟล์แล้วกดส่งโดยไม่พิมพ์ ไม่มีอะไรเกิดขึ้นและไม่มีคำอธิบาย

**ไฟล์:** `assistant_chat_view.dart`

**ทำ:** เมื่อมีไฟล์ `hintText` เป็น "บอกผู้ช่วยว่าจะให้ทำอะไรกับไฟล์นี้"

**commit:** `fix(app): the hint says what to do with an attached file`

## F6: พิมพ์ต่อได้ทันทีหลังได้คำตอบบนคอม 🤖

**อาการ:** ช่องพิมพ์ถูกล็อกระหว่างรอคำตอบจึงเสียโฟกัส ครูบนคอมต้องคลิกช่องพิมพ์ใหม่ทุกครั้ง

**ไฟล์:** `assistant_panel.dart`, `assistant_chat_view.dart`

**ทำ:** สร้าง `FocusNode` ใน `_AssistantPanelState` ส่งให้ `TextField` แล้วฟังตอนรอคำตอบจบ:
```dart
ref.listen(assistantChatProvider.select((c) => c.sending), (was, sending) {
  if (was != true || sending) return;
  if (context.windowSize != WindowSize.expanded) return;
  WidgetsBinding.instance.addPostFrameCallback((_) {
    if (mounted) _inputFocus.requestFocus();
  });
});
```

**ข้อควรระวัง:**
- ต้องใช้ `addPostFrameCallback` เพราะตอนที่ฟังก์ชันนี้ทำงาน `TextField` ยังถูกล็อกอยู่
- ทำเฉพาะจอ `expanded` เพราะบนมือถือคีย์บอร์ดจะเด้งขึ้นมาบังคำตอบ
- ต้อง `dispose()` ตัว `FocusNode`

**commit:** `fix(app): keep typing after an answer on a desktop`

## F7: ใส่ key แล้วกลับมาถามได้เลยบนมือถือ 🤖

**อาการ:** ข้อความบอกว่า "ใส่ที่หน้าตั้งค่าก่อน แล้วกลับมาถามได้เลย" แต่พอใส่ key แล้วกดกลับ แชทยังขึ้นว่าไม่มี key จนกว่าจะออกแล้วเปิดใหม่

**สาเหตุ:** บนมือถือหน้าแชทยังเปิดค้างใต้หน้าตั้งค่า `assistantStatusProvider` จึงไม่โหลดใหม่ บนคอมไม่เกิดเพราะแผงปิดก่อนไปหน้าอื่น

**ไฟล์:** `assistant_panel.dart`

**ทำ:** เปลี่ยน `AssistantScreen` เป็น `ConsumerWidget`:
```dart
onNavigate: (route) async {
  await context.push(route);
  // A key added in settings counts as soon as the teacher is back.
  if (context.mounted) ref.invalidate(assistantStatusProvider);
},
```

**commit:** `fix(app): a key added in settings counts on the way back`

## F8: ปุ่มในฟอง error ตรงกับสาเหตุ 🧑🤖

**อาการ:** ถามครบ 100 ข้อความแล้วยังมีปุ่ม "ลองใหม่" ซึ่งกดกี่ครั้งก็ไม่ผ่าน และ key ถูกปฏิเสธแล้วไม่มีทางไปหน้าตั้งค่า

**ไฟล์:** `app/lib/core/api/error_codes.dart`, `assistant.dart`, `assistant_bubble.dart`

**ทำ:**
- เพิ่ม `ErrorCode.chatLimit = 'CHAT-LIMIT'` และ `ErrorCode.keyInvalid = 'AI-KEY-INVALID'`
- `canRetry` ต้องเป็น `false` เมื่อ `messages.last.errorCode == ErrorCode.chatLimit`
- `AssistantBubble`: เมื่อ `errorCode == ErrorCode.keyInvalid` แสดงปุ่ม "ไปที่ตั้งค่า" key `assistant_error_settings` คงปุ่ม "ลองใหม่" ไว้ด้วย เพราะแก้ key แล้วถามซ้ำได้

**commit:** `fix(app): the error bubble offers what can help`

## F9: ลองใหม่แล้วเน็ตหลุดต้องไม่ส่งคำถามซ้ำ 🤖

**อาการ:** AI ล้มแล้วกด "ลองใหม่" แต่เน็ตหลุด พอกด "ลองใหม่" อีกครั้ง แอปส่งคำถามเป็นข้อความใหม่ คำถามจึงซ้ำในบทสนทนาและนับโควตาเพิ่ม

**ไฟล์:** `assistant.dart`

**ทำ:**
- `_ask` รับ `storedFailureId` และส่ง `_localFailure(e, id: storedFailureId)` ฟอง error จะยังมี id ทำให้ `retry()` เรียก API retry เหมือนเดิม
- ถ้า `apiErrorBody(e)?['code'] == 'nothing_to_retry'` แปลว่า server ตอบไปแล้วแต่คำตอบมาไม่ถึง ให้ล้างสถานะแล้ว `open()` บทสนทนาใหม่

ดูโค้ดเต็มใน `app-fixes.diff` เพราะข้อนี้ลำดับใน `catch` สำคัญ

**commit:** `fix(app): a retry that loses the connection never sends the question twice`

## F10: ชิปตัวอย่างไม่ลบข้อความที่พิมพ์ค้าง 🧑

**อาการ:** พิมพ์ค้างไว้แล้วกดชิปตัวอย่าง ข้อความที่พิมพ์หาย

**ไฟล์:** `assistant_panel.dart`

**ทำ:** ใน `_send` เปลี่ยนเป็น `if (text == null) _input.clear();`

**commit:** `fix(app): a suggestion keeps what the teacher typed`

## F11: จุดกะพริบหยุดเมื่อเครื่องขอลดภาพเคลื่อนไหว 🤖

**อาการ:** น้องเช็คหยุดขยับเมื่อเครื่องตั้งลดภาพเคลื่อนไหว แต่จุดสามจุดในฟองยังกะพริบ

**ไฟล์:** `assistant_bubble.dart`

**ทำ:** เอา `..repeat()` ออกจาก `_pulse` แล้วเพิ่ม:
```dart
@override
void didChangeDependencies() {
  super.didChangeDependencies();
  // The dots rest when the device asks for less motion.
  if (MediaQuery.disableAnimationsOf(context)) {
    _pulse.stop();
  } else if (!_pulse.isAnimating) {
    _pulse.repeat();
  }
}
```

**commit:** `fix(app): thinking dots rest when the device asks for less motion`

## F12: แชทพอดีกับจอเตี้ย 🤖

**อาการ:** จอเตี้ยกว่าราว 340 px (มือถือแนวนอนตอนเปิดคีย์บอร์ด) ส่วนล่างของแชทล้นจอ 72 px ขึ้นไป และช่องพิมพ์อาจถูกตัด

**ไฟล์:** `assistant_chat_view.dart`

**ทำ:** แยกทุกอย่างใต้รายการข้อความเป็น `footer` แล้วครอบ:
```dart
LayoutBuilder(
  builder: (context, constraints) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Expanded(child: list),
      ConstrainedBox(
        constraints: BoxConstraints(maxHeight: constraints.maxHeight * 0.75),
        child: SingleChildScrollView(reverse: true, child: footer),
      ),
    ],
  ),
);
```

`reverse: true` ทำให้เห็นช่องพิมพ์ก่อนเสมอ และ `footer` ต้องตั้ง `mainAxisSize: MainAxisSize.min`

**ตรวจ:** test `fits …` ทั้ง 4 ขนาด และเปิด Chrome ที่ 390, 820, 1440 px

**commit:** `fix(app): the chat fits a short window`

## F13: แก้ความรู้เรื่องสแกนบนเว็บ 🧑🤖

**อาการ:** ถามว่า "สแกนบนเว็บได้ไหม" ผู้ช่วยตอบว่าไม่ได้ และคู่มือในแอปก็เขียนแบบเดียวกัน ทั้งที่เว็บบนมือถือและแท็บเล็ตสแกนใบงานได้แล้ว ส่วนกระดาษคำตอบข้อสอบยังต้องใช้แอป Android

**ไฟล์:**
- `app/lib/features/help/help_content.dart` และ `backend/resources/assistant/core/04-troubleshooting.md` แก้ตาม `knowledge-fixes.diff`
- `backend/resources/assistant/core/01-guide.md` ห้ามแก้เอง ให้สร้างใหม่ด้วยคำสั่งด้านล่าง
- `backend/resources/assistant/manual/*.md` แก้ตามตารางนี้

| ไฟล์ใน `manual/` | แก้เป็น |
|---|---|
| `usecase-00-intro.md` บรรทัด 31 | เปลี่ยน "การสแกนใบงานและกระดาษคำตอบ" เป็น "การสแกนกระดาษคำตอบข้อสอบ" และเติมประโยค "สแกนใบงานบนเว็บใช้กล้องของมือถือหรือแท็บเล็ตได้ แต่ส่งรูปทั้งหน้าและต้องต่ออินเทอร์เน็ต" |
| `usecase-04-teacher-account.md` บรรทัด 204 | เปลี่ยน "บนเว็บหน้านั้นแจ้ง **สแกนใบงานได้เฉพาะในแอป Android**" เป็น "บนเว็บหน้านั้นใช้กล้องของเครื่อง" |
| `usecase-07-homework-scan.md` บรรทัด 4 | เปลี่ยน "ครูสแกนด้วยแอป Android" เป็น "ครูสแกนด้วยแอป Android หรือเว็บบนมือถือ" และเปลี่ยนท้ายย่อหน้าเป็น "คิวอัปโหลดและการเตรียมสแกนออฟไลน์มีเฉพาะในแอป Android" |
| `usecase-07-homework-scan.md` บรรทัด 213 | "**บนเว็บ หน้า **สแกนใบงานด้วยกล้อง** ใช้กล้องของเครื่อง กด **ถ่าย** ทีละหน้าแล้วกด **ส่งตรวจ** ต้องต่ออินเทอร์เน็ต ถ้าเปิดกล้องไม่ได้กด **เลือกรูปจากเครื่องแทน** หรือใช้ **อัปโหลดรูปเพื่อตรวจ** (UC-T39)**" |
| `user-06-scan.md` บรรทัด 4 | "**บทนี้เขียนตามแอป Android เว็บบนมือถือหรือแท็บเล็ตก็สแกนใบงานได้ที่หน้า **สแกนใบงานด้วยกล้อง** แต่ส่งรูปทั้งหน้าและไม่มีคิวออฟไลน์ ถ้าไม่มีกล้องใช้ **อัปโหลดรูปเพื่อตรวจ** (บทที่ 7)**" |
| `user-13-teacher-faq.md` บรรทัด 62 | "\| สแกนบนเว็บไม่ได้ \| เปิดเว็บบนมือถือหรือแท็บเล็ตแล้วกดอนุญาตกล้อง ถ้าไม่มีกล้องใช้ **อัปโหลดรูปเพื่อตรวจ** (หัวข้อ 7.3) กระดาษคำตอบข้อสอบต้องสแกนในแอป Android \|" |

ไฟล์ใน `manual/` สร้างจากต้นฉบับนอก repo ให้บอกในคำอธิบาย PR ว่าแก้ 6 จุดนี้ เพื่อให้ phuwishpk แก้ต้นฉบับตาม

**ห้ามแก้** `usecase-19-admin-accounts.md` และไฟล์ที่พูดเรื่องกระดาษคำตอบข้อสอบ เช่น `user-09-exams.md` และ `usecase-10-exams.md` เพราะข้อความนั้นยังถูกต้อง

**ตรวจ:**
```bash
UPDATE_ASSISTANT_KNOWLEDGE=1 flutter test test/help/assistant_knowledge_test.dart
flutter test test/help/ test/assistant/assistant_chat_fixes_test.dart
```

**commit:** `fix(backend): the assistant knows the web can scan worksheets`

## F14: prompt v7 ให้รู้จักชื่อน้องเช็ค 🧑

**อาการ:** หน้าจอทักว่า "สวัสดี น้องเช็คเอง" แต่ผู้ช่วยไม่รู้ว่าตัวเองชื่อน้องเช็ค และ prompt มีประโยค "You cannot see files" ซึ่งขัดกับส่วนอ่านไฟล์แนบ

**ไฟล์ใหม่:** `backend/resources/prompts/assistant_chat.general.v7.md`

**ทำ:** คัดลอกจาก `v6` แล้วแก้ 3 จุดตาม `prompt-v7.diff` ห้ามแก้ไฟล์ `v6` เพราะระบบใช้เลขสูงสุดเองและเก็บเลขรุ่นไว้เทียบใน `ai_calls`

**ตรวจ:** ให้ CI รัน backend และลองถาม "คุณชื่ออะไร" บน server ทดสอบหลัง deploy

**commit:** `feat(backend): prompt v7 gives the assistant the mascot's name`

## F15: เอกสาร 🧑

**ไฟล์:** `docs/DESIGN.md` เขียนภาษาไทย

**ทำ:**
- §37.5: เพิ่มว่าส่งไม่ได้ระหว่างอัปโหลดไฟล์, `CHAT-LIMIT` ไม่มี "ลองใหม่", `AI-KEY-INVALID` มี "ไปที่ตั้งค่า", บนคอมโฟกัสกลับช่องพิมพ์หลังได้คำตอบ, บนจอเตี้ยส่วนล่างของแชทเลื่อนได้
- §37.10: เพิ่ม prompt `assistant_chat.general.v7` (ให้รู้จักชื่อน้องเช็ค และแก้ประโยคเรื่องไฟล์)

**commit:** `docs: record the chat fixes and prompt v7`

**จบส่วน C:** push แล้วเปิด PR เข้า `main` รอ CI เขียว ลองบน Chrome ที่ 390, 820, 1440 px และแนบรูปหน้าจอใน PR

---

# ส่วน D: ทำตาม TASK-1 โดยแก้ 4 จุด (ถ้ามีเวลา)

เปิด `TASK-1-onboarding-about.md` แล้วทำตามลำดับ ยกเว้นจุดต่อไปนี้

| ข้อใน TASK-1 | เปลี่ยนเป็น |
|---|---|
| C2 | `OnboardingPage` เก็บ `MascotMood mood` แทน `IconData icon` สไลด์ 1–3 ใช้ `wave` หรือ `idle` และสไลด์สุดท้ายใช้ `happy` |
| C3 | ใช้ `AppSettingsStore` จาก M10 อ่านเขียน `onboarding_done_at` และ `device` ไม่ต้องสร้างโค้ด Firestore ซ้ำ |
| C5 | **ข้าม** เพราะ rule ของ M12 ครอบ field เหล่านี้แล้ว |
| C6 | แสดง `Mascot(mood: page.mood, size: 160)` แทนไอคอนขนาด 96 |

ส่วน D จึงเหลือ 15 commit และเลขหัวข้อ DESIGN ให้ใช้เลขถัดจากที่มีอยู่บน main

---

## เสร็จเมื่อ

- [ ] แชททำงานเหมือนเดิมทุกอย่าง และไม่มีไฟล์ที่แยกใหม่ยาวเกิน 700 บรรทัด (`assistant.dart` กับ `assistant_plan.dart` ไม่ได้แตะ)
- [ ] ตัวการ์ตูนแสดงครบ 5 อารมณ์ตามสถานะแชท ทั้งสองธีม และที่ 390, 820, 1440 px
- [ ] ปิดตัวการ์ตูนได้ และค่าตามไปเครื่องอื่นเมื่อ Firestore เปิด
- [ ] ออฟไลน์หรือไม่มี Firebase แล้วแชทยังใช้งานได้ และไม่มีข้อความแชทใน Firestore
- [ ] ผู้ใช้ที่ปิดภาพเคลื่อนไหว ตัวการ์ตูนและจุดกะพริบไม่ขยับ
- [ ] test ใน `assistant_chat_fixes_test.dart` ผ่านทุกข้อ และ F1–F12 ลองกดเองบน Chrome แล้วอย่างน้อยหนึ่งรอบ
- [ ] ถาม "สแกนบนเว็บได้ไหม" และ "คุณชื่ออะไร" กับ Gemini จริงแล้วตอบถูก
- [ ] มี test ครบ Unit, Widget, Integration และ Security และ CI ใน PR เขียวทั้งหมด (coverage ≥ 70%)
- [ ] commit ทั้งหมดมาจากบัญชี GitHub ของตัวเอง และ merge แบบไม่ squash
- [ ] มีสไลด์ส่วนนี้ และซ้อมเดโมบนเครื่องที่จะใช้จริงแล้ว
