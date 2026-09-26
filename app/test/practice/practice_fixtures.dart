import 'package:eduvision/features/practice/practice_models.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:flutter_test/flutter_test.dart';

/// `GET /student/practice` in the grouped shape.
List<Map<String, dynamic>> recommendationsJson() => [
  {
    'skill': {'id': 7, 'code': 'ค 1.1 ป.4/2', 'name': 'บวกลบเศษส่วน'},
    'mastery': {'value': 0.32, 'n_obs': 3},
    'items': [
      {
        'id': 31,
        'answer_type': 'numeric',
        'prompt_text': '3/4 + 1/4 = ?',
        'options': null,
      },
      {
        'id': 32,
        'answer_type': 'mcq',
        'prompt_text': 'ข้อใดเท่ากับ 1/2',
        'options': [
          {'key': 'A', 'text': '2/4'},
          {'key': 'B', 'text': '1/3'},
          {'key': 'C', 'text': '3/4'},
        ],
      },
    ],
    'resources': [
      {'id': 2, 'title': 'คลิปเศษส่วน', 'url': 'https://example.org/frac'},
    ],
  },
  {
    'skill': {'id': 8, 'code': 'ท 1.1', 'name': 'สะกดคำ'},
    'mastery': {'value': 0.6, 'n_obs': 1},
    'items': [
      {'id': 41, 'answer_type': 'short', 'prompt_text': 'สะกดคำว่า "กะ-เพรา"'},
    ],
  },
];

class FakeStudentPractice implements StudentPracticeRepository {
  FakeStudentPractice({this.results = const {}});

  /// item id -> answer of the attempt.
  final Map<int, Map<String, dynamic>> results;
  final attempts = <(int, String)>[];
  int loads = 0;

  @override
  Future<List<PracticeRecommendation>> recommendations() async {
    loads++;
    return PracticeRecommendation.listFromJson(recommendationsJson());
  }

  @override
  Future<PracticeAttemptResult> attempt(int itemId, String answer) async {
    attempts.add((itemId, answer));
    return PracticeAttemptResult.fromJson(
      results[itemId] ?? {'score_ratio': 0, 'explanation': 'ลองอีกครั้ง'},
    );
  }
}

/// Teacher bank rows (`GET /practice-items`).
Map<String, dynamic> bankItemJson({
  int id = 31,
  String status = 'draft',
  String type = 'numeric',
}) => {
  'id': id,
  'skill_id': 7,
  'skill': {'id': 7, 'code': 'ค 1.1 ป.4/2', 'name': 'บวกลบเศษส่วน'},
  'answer_type': type,
  'prompt_text': type == 'mcq' ? 'ข้อใดเท่ากับ 1/2' : '3/4 + 1/4 = ?',
  'options': type == 'mcq'
      ? [
          {'key': 'A', 'text': '2/4'},
          {'key': 'B', 'text': '1/3'},
        ]
      : null,
  'answer_key': type == 'mcq'
      ? {'correct': 'A'}
      : {
          'accepted': ['1', '4/4'],
          'numeric': {'value': 1, 'abs_tol': 0},
        },
  'explanation': 'ตัวส่วนเท่ากัน บวกตัวเศษ 3 + 1 = 4 ได้ 4/4 = 1',
  'status': status,
  'source': 'ai',
};

class FakePracticeBank extends Fake implements PracticeBankRepository {
  FakePracticeBank(this.items);

  final List<Map<String, dynamic>> items;
  final patches = <(int, Map<String, dynamic>)>[];
  final generated = <(int, int)>[];
  final listCalls = <(int?, PracticeItemStatus?)>[];

  @override
  Future<List<PracticeItem>> list({
    int? skillId,
    PracticeItemStatus? status,
  }) async {
    listCalls.add((skillId, status));
    return [
      for (final i in items)
        if (status == null || i['status'] == status.apiValue)
          PracticeItem.fromJson(i),
    ];
  }

  @override
  Future<void> generate(int skillId, {int count = 5}) async {
    generated.add((skillId, count));
  }

  @override
  Future<PracticeItem> update(int itemId, PracticeItemPatch patch) async {
    final body = patch.toJson();
    patches.add((itemId, body));
    final row = items.firstWhere((i) => i['id'] == itemId);
    row.addAll(body);
    return PracticeItem.fromJson(row);
  }
}
