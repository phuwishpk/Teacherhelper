import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/my_indicators/my_indicators.dart';

/// "ตัวชี้วัดของฉัน" over a pool of known skills; records every save.
class FakeMyIndicators implements MyIndicatorsRepository {
  FakeMyIndicators(this.pool, {List<int> initial = const []})
    : ids = [...initial];

  final List<Skill> pool;
  List<int> ids;
  final saves = <List<int>>[];

  List<Skill> get _current => [
    for (final s in pool)
      if (ids.contains(s.id)) s,
  ];

  @override
  Future<List<Skill>> list() async => _current;

  @override
  Future<List<Skill>> save(List<int> skillIds) async {
    saves.add([...skillIds]..sort());
    ids = [...skillIds];
    return _current;
  }
}
