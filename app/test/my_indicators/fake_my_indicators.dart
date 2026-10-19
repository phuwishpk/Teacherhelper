import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/my_indicators/my_indicators.dart';
import 'package:eduvision/features/my_indicators/skill_trash.dart';

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

/// The teacher's own indicators and trash; records every call.
class FakeSkillTrash implements SkillTrashRepository {
  FakeSkillTrash({
    List<Skill> own = const [],
    List<TrashedSkill> trash = const [],
  }) : ownList = [...own],
       trashList = [...trash];

  List<Skill> ownList;
  List<TrashedSkill> trashList;
  final calls = <String>[];

  @override
  Future<List<Skill>> own() async => ownList;

  @override
  Future<List<TrashedSkill>> trash() async => trashList;

  @override
  Future<void> moveToTrash(int id) async {
    calls.add('trash $id');
    final s = ownList.firstWhere((s) => s.id == id);
    ownList = ownList.where((x) => x.id != id).toList();
    trashList = [TrashedSkill(s, const {}), ...trashList];
  }

  @override
  Future<void> restore(int id) async {
    calls.add('restore $id');
    final t = trashList.firstWhere((t) => t.skill.id == id);
    trashList = trashList.where((x) => x.skill.id != id).toList();
    ownList = [...ownList, t.skill];
  }

  @override
  Future<void> deleteForGood(int id) async {
    calls.add('purge $id');
    trashList = trashList.where((x) => x.skill.id != id).toList();
  }
}
