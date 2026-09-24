// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'app_database.dart';

// ignore_for_file: type=lint
class $CachedLayoutsTable extends CachedLayouts
    with TableInfo<$CachedLayoutsTable, CachedLayout> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $CachedLayoutsTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _assignmentIdMeta = const VerificationMeta(
    'assignmentId',
  );
  @override
  late final GeneratedColumn<int> assignmentId = GeneratedColumn<int>(
    'assignment_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _versionMeta = const VerificationMeta(
    'version',
  );
  @override
  late final GeneratedColumn<int> version = GeneratedColumn<int>(
    'version',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _pageMeta = const VerificationMeta('page');
  @override
  late final GeneratedColumn<int> page = GeneratedColumn<int>(
    'page',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _jsonMeta = const VerificationMeta('json');
  @override
  late final GeneratedColumn<String> json = GeneratedColumn<String>(
    'json',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _cachedAtMeta = const VerificationMeta(
    'cachedAt',
  );
  @override
  late final GeneratedColumn<DateTime> cachedAt = GeneratedColumn<DateTime>(
    'cached_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    assignmentId,
    version,
    page,
    json,
    cachedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'cached_layouts';
  @override
  VerificationContext validateIntegrity(
    Insertable<CachedLayout> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('assignment_id')) {
      context.handle(
        _assignmentIdMeta,
        assignmentId.isAcceptableOrUnknown(
          data['assignment_id']!,
          _assignmentIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_assignmentIdMeta);
    }
    if (data.containsKey('version')) {
      context.handle(
        _versionMeta,
        version.isAcceptableOrUnknown(data['version']!, _versionMeta),
      );
    } else if (isInserting) {
      context.missing(_versionMeta);
    }
    if (data.containsKey('page')) {
      context.handle(
        _pageMeta,
        page.isAcceptableOrUnknown(data['page']!, _pageMeta),
      );
    } else if (isInserting) {
      context.missing(_pageMeta);
    }
    if (data.containsKey('json')) {
      context.handle(
        _jsonMeta,
        json.isAcceptableOrUnknown(data['json']!, _jsonMeta),
      );
    } else if (isInserting) {
      context.missing(_jsonMeta);
    }
    if (data.containsKey('cached_at')) {
      context.handle(
        _cachedAtMeta,
        cachedAt.isAcceptableOrUnknown(data['cached_at']!, _cachedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_cachedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {assignmentId, version, page};
  @override
  CachedLayout map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return CachedLayout(
      assignmentId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}assignment_id'],
      )!,
      version: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}version'],
      )!,
      page: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}page'],
      )!,
      json: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}json'],
      )!,
      cachedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}cached_at'],
      )!,
    );
  }

  @override
  $CachedLayoutsTable createAlias(String alias) {
    return $CachedLayoutsTable(attachedDatabase, alias);
  }
}

class CachedLayout extends DataClass implements Insertable<CachedLayout> {
  final int assignmentId;
  final int version;
  final int page;
  final String json;
  final DateTime cachedAt;
  const CachedLayout({
    required this.assignmentId,
    required this.version,
    required this.page,
    required this.json,
    required this.cachedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['assignment_id'] = Variable<int>(assignmentId);
    map['version'] = Variable<int>(version);
    map['page'] = Variable<int>(page);
    map['json'] = Variable<String>(json);
    map['cached_at'] = Variable<DateTime>(cachedAt);
    return map;
  }

  CachedLayoutsCompanion toCompanion(bool nullToAbsent) {
    return CachedLayoutsCompanion(
      assignmentId: Value(assignmentId),
      version: Value(version),
      page: Value(page),
      json: Value(json),
      cachedAt: Value(cachedAt),
    );
  }

  factory CachedLayout.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return CachedLayout(
      assignmentId: serializer.fromJson<int>(json['assignmentId']),
      version: serializer.fromJson<int>(json['version']),
      page: serializer.fromJson<int>(json['page']),
      json: serializer.fromJson<String>(json['json']),
      cachedAt: serializer.fromJson<DateTime>(json['cachedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'assignmentId': serializer.toJson<int>(assignmentId),
      'version': serializer.toJson<int>(version),
      'page': serializer.toJson<int>(page),
      'json': serializer.toJson<String>(json),
      'cachedAt': serializer.toJson<DateTime>(cachedAt),
    };
  }

  CachedLayout copyWith({
    int? assignmentId,
    int? version,
    int? page,
    String? json,
    DateTime? cachedAt,
  }) => CachedLayout(
    assignmentId: assignmentId ?? this.assignmentId,
    version: version ?? this.version,
    page: page ?? this.page,
    json: json ?? this.json,
    cachedAt: cachedAt ?? this.cachedAt,
  );
  CachedLayout copyWithCompanion(CachedLayoutsCompanion data) {
    return CachedLayout(
      assignmentId: data.assignmentId.present
          ? data.assignmentId.value
          : this.assignmentId,
      version: data.version.present ? data.version.value : this.version,
      page: data.page.present ? data.page.value : this.page,
      json: data.json.present ? data.json.value : this.json,
      cachedAt: data.cachedAt.present ? data.cachedAt.value : this.cachedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('CachedLayout(')
          ..write('assignmentId: $assignmentId, ')
          ..write('version: $version, ')
          ..write('page: $page, ')
          ..write('json: $json, ')
          ..write('cachedAt: $cachedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(assignmentId, version, page, json, cachedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is CachedLayout &&
          other.assignmentId == this.assignmentId &&
          other.version == this.version &&
          other.page == this.page &&
          other.json == this.json &&
          other.cachedAt == this.cachedAt);
}

class CachedLayoutsCompanion extends UpdateCompanion<CachedLayout> {
  final Value<int> assignmentId;
  final Value<int> version;
  final Value<int> page;
  final Value<String> json;
  final Value<DateTime> cachedAt;
  final Value<int> rowid;
  const CachedLayoutsCompanion({
    this.assignmentId = const Value.absent(),
    this.version = const Value.absent(),
    this.page = const Value.absent(),
    this.json = const Value.absent(),
    this.cachedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  CachedLayoutsCompanion.insert({
    required int assignmentId,
    required int version,
    required int page,
    required String json,
    required DateTime cachedAt,
    this.rowid = const Value.absent(),
  }) : assignmentId = Value(assignmentId),
       version = Value(version),
       page = Value(page),
       json = Value(json),
       cachedAt = Value(cachedAt);
  static Insertable<CachedLayout> custom({
    Expression<int>? assignmentId,
    Expression<int>? version,
    Expression<int>? page,
    Expression<String>? json,
    Expression<DateTime>? cachedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (assignmentId != null) 'assignment_id': assignmentId,
      if (version != null) 'version': version,
      if (page != null) 'page': page,
      if (json != null) 'json': json,
      if (cachedAt != null) 'cached_at': cachedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  CachedLayoutsCompanion copyWith({
    Value<int>? assignmentId,
    Value<int>? version,
    Value<int>? page,
    Value<String>? json,
    Value<DateTime>? cachedAt,
    Value<int>? rowid,
  }) {
    return CachedLayoutsCompanion(
      assignmentId: assignmentId ?? this.assignmentId,
      version: version ?? this.version,
      page: page ?? this.page,
      json: json ?? this.json,
      cachedAt: cachedAt ?? this.cachedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (assignmentId.present) {
      map['assignment_id'] = Variable<int>(assignmentId.value);
    }
    if (version.present) {
      map['version'] = Variable<int>(version.value);
    }
    if (page.present) {
      map['page'] = Variable<int>(page.value);
    }
    if (json.present) {
      map['json'] = Variable<String>(json.value);
    }
    if (cachedAt.present) {
      map['cached_at'] = Variable<DateTime>(cachedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('CachedLayoutsCompanion(')
          ..write('assignmentId: $assignmentId, ')
          ..write('version: $version, ')
          ..write('page: $page, ')
          ..write('json: $json, ')
          ..write('cachedAt: $cachedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $CachedRostersTable extends CachedRosters
    with TableInfo<$CachedRostersTable, CachedRoster> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $CachedRostersTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _classroomIdMeta = const VerificationMeta(
    'classroomId',
  );
  @override
  late final GeneratedColumn<int> classroomId = GeneratedColumn<int>(
    'classroom_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _studentIdMeta = const VerificationMeta(
    'studentId',
  );
  @override
  late final GeneratedColumn<int> studentId = GeneratedColumn<int>(
    'student_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _studentNumberMeta = const VerificationMeta(
    'studentNumber',
  );
  @override
  late final GeneratedColumn<int> studentNumber = GeneratedColumn<int>(
    'student_number',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    classroomId,
    studentId,
    studentNumber,
    name,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'cached_rosters';
  @override
  VerificationContext validateIntegrity(
    Insertable<CachedRoster> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('classroom_id')) {
      context.handle(
        _classroomIdMeta,
        classroomId.isAcceptableOrUnknown(
          data['classroom_id']!,
          _classroomIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_classroomIdMeta);
    }
    if (data.containsKey('student_id')) {
      context.handle(
        _studentIdMeta,
        studentId.isAcceptableOrUnknown(data['student_id']!, _studentIdMeta),
      );
    } else if (isInserting) {
      context.missing(_studentIdMeta);
    }
    if (data.containsKey('student_number')) {
      context.handle(
        _studentNumberMeta,
        studentNumber.isAcceptableOrUnknown(
          data['student_number']!,
          _studentNumberMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_studentNumberMeta);
    }
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {classroomId, studentId};
  @override
  CachedRoster map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return CachedRoster(
      classroomId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}classroom_id'],
      )!,
      studentId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}student_id'],
      )!,
      studentNumber: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}student_number'],
      )!,
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
    );
  }

  @override
  $CachedRostersTable createAlias(String alias) {
    return $CachedRostersTable(attachedDatabase, alias);
  }
}

class CachedRoster extends DataClass implements Insertable<CachedRoster> {
  final int classroomId;
  final int studentId;
  final int studentNumber;
  final String name;
  const CachedRoster({
    required this.classroomId,
    required this.studentId,
    required this.studentNumber,
    required this.name,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['classroom_id'] = Variable<int>(classroomId);
    map['student_id'] = Variable<int>(studentId);
    map['student_number'] = Variable<int>(studentNumber);
    map['name'] = Variable<String>(name);
    return map;
  }

  CachedRostersCompanion toCompanion(bool nullToAbsent) {
    return CachedRostersCompanion(
      classroomId: Value(classroomId),
      studentId: Value(studentId),
      studentNumber: Value(studentNumber),
      name: Value(name),
    );
  }

  factory CachedRoster.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return CachedRoster(
      classroomId: serializer.fromJson<int>(json['classroomId']),
      studentId: serializer.fromJson<int>(json['studentId']),
      studentNumber: serializer.fromJson<int>(json['studentNumber']),
      name: serializer.fromJson<String>(json['name']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'classroomId': serializer.toJson<int>(classroomId),
      'studentId': serializer.toJson<int>(studentId),
      'studentNumber': serializer.toJson<int>(studentNumber),
      'name': serializer.toJson<String>(name),
    };
  }

  CachedRoster copyWith({
    int? classroomId,
    int? studentId,
    int? studentNumber,
    String? name,
  }) => CachedRoster(
    classroomId: classroomId ?? this.classroomId,
    studentId: studentId ?? this.studentId,
    studentNumber: studentNumber ?? this.studentNumber,
    name: name ?? this.name,
  );
  CachedRoster copyWithCompanion(CachedRostersCompanion data) {
    return CachedRoster(
      classroomId: data.classroomId.present
          ? data.classroomId.value
          : this.classroomId,
      studentId: data.studentId.present ? data.studentId.value : this.studentId,
      studentNumber: data.studentNumber.present
          ? data.studentNumber.value
          : this.studentNumber,
      name: data.name.present ? data.name.value : this.name,
    );
  }

  @override
  String toString() {
    return (StringBuffer('CachedRoster(')
          ..write('classroomId: $classroomId, ')
          ..write('studentId: $studentId, ')
          ..write('studentNumber: $studentNumber, ')
          ..write('name: $name')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(classroomId, studentId, studentNumber, name);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is CachedRoster &&
          other.classroomId == this.classroomId &&
          other.studentId == this.studentId &&
          other.studentNumber == this.studentNumber &&
          other.name == this.name);
}

class CachedRostersCompanion extends UpdateCompanion<CachedRoster> {
  final Value<int> classroomId;
  final Value<int> studentId;
  final Value<int> studentNumber;
  final Value<String> name;
  final Value<int> rowid;
  const CachedRostersCompanion({
    this.classroomId = const Value.absent(),
    this.studentId = const Value.absent(),
    this.studentNumber = const Value.absent(),
    this.name = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  CachedRostersCompanion.insert({
    required int classroomId,
    required int studentId,
    required int studentNumber,
    required String name,
    this.rowid = const Value.absent(),
  }) : classroomId = Value(classroomId),
       studentId = Value(studentId),
       studentNumber = Value(studentNumber),
       name = Value(name);
  static Insertable<CachedRoster> custom({
    Expression<int>? classroomId,
    Expression<int>? studentId,
    Expression<int>? studentNumber,
    Expression<String>? name,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (classroomId != null) 'classroom_id': classroomId,
      if (studentId != null) 'student_id': studentId,
      if (studentNumber != null) 'student_number': studentNumber,
      if (name != null) 'name': name,
      if (rowid != null) 'rowid': rowid,
    });
  }

  CachedRostersCompanion copyWith({
    Value<int>? classroomId,
    Value<int>? studentId,
    Value<int>? studentNumber,
    Value<String>? name,
    Value<int>? rowid,
  }) {
    return CachedRostersCompanion(
      classroomId: classroomId ?? this.classroomId,
      studentId: studentId ?? this.studentId,
      studentNumber: studentNumber ?? this.studentNumber,
      name: name ?? this.name,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (classroomId.present) {
      map['classroom_id'] = Variable<int>(classroomId.value);
    }
    if (studentId.present) {
      map['student_id'] = Variable<int>(studentId.value);
    }
    if (studentNumber.present) {
      map['student_number'] = Variable<int>(studentNumber.value);
    }
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('CachedRostersCompanion(')
          ..write('classroomId: $classroomId, ')
          ..write('studentId: $studentId, ')
          ..write('studentNumber: $studentNumber, ')
          ..write('name: $name, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $ScanQueueTable extends ScanQueue
    with TableInfo<$ScanQueueTable, ScanQueueData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ScanQueueTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _clientScanIdMeta = const VerificationMeta(
    'clientScanId',
  );
  @override
  late final GeneratedColumn<String> clientScanId = GeneratedColumn<String>(
    'client_scan_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  @override
  late final GeneratedColumnWithTypeConverter<ScanState, String> state =
      GeneratedColumn<String>(
        'state',
        aliasedName,
        false,
        type: DriftSqlType.string,
        requiredDuringInsert: true,
      ).withConverter<ScanState>($ScanQueueTable.$converterstate);
  static const VerificationMeta _metaJsonMeta = const VerificationMeta(
    'metaJson',
  );
  @override
  late final GeneratedColumn<String> metaJson = GeneratedColumn<String>(
    'meta_json',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _filesJsonMeta = const VerificationMeta(
    'filesJson',
  );
  @override
  late final GeneratedColumn<String> filesJson = GeneratedColumn<String>(
    'files_json',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attemptsMeta = const VerificationMeta(
    'attempts',
  );
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
    'attempts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _lastErrorMeta = const VerificationMeta(
    'lastError',
  );
  @override
  late final GeneratedColumn<String> lastError = GeneratedColumn<String>(
    'last_error',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _serverScanIdMeta = const VerificationMeta(
    'serverScanId',
  );
  @override
  late final GeneratedColumn<int> serverScanId = GeneratedColumn<int>(
    'server_scan_id',
    aliasedName,
    true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _nextAttemptAtMeta = const VerificationMeta(
    'nextAttemptAt',
  );
  @override
  late final GeneratedColumn<DateTime> nextAttemptAt =
      GeneratedColumn<DateTime>(
        'next_attempt_at',
        aliasedName,
        true,
        type: DriftSqlType.dateTime,
        requiredDuringInsert: false,
      );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<DateTime> createdAt = GeneratedColumn<DateTime>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _updatedAtMeta = const VerificationMeta(
    'updatedAt',
  );
  @override
  late final GeneratedColumn<DateTime> updatedAt = GeneratedColumn<DateTime>(
    'updated_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    clientScanId,
    state,
    metaJson,
    filesJson,
    attempts,
    lastError,
    serverScanId,
    nextAttemptAt,
    createdAt,
    updatedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'scan_queue';
  @override
  VerificationContext validateIntegrity(
    Insertable<ScanQueueData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('client_scan_id')) {
      context.handle(
        _clientScanIdMeta,
        clientScanId.isAcceptableOrUnknown(
          data['client_scan_id']!,
          _clientScanIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_clientScanIdMeta);
    }
    if (data.containsKey('meta_json')) {
      context.handle(
        _metaJsonMeta,
        metaJson.isAcceptableOrUnknown(data['meta_json']!, _metaJsonMeta),
      );
    } else if (isInserting) {
      context.missing(_metaJsonMeta);
    }
    if (data.containsKey('files_json')) {
      context.handle(
        _filesJsonMeta,
        filesJson.isAcceptableOrUnknown(data['files_json']!, _filesJsonMeta),
      );
    } else if (isInserting) {
      context.missing(_filesJsonMeta);
    }
    if (data.containsKey('attempts')) {
      context.handle(
        _attemptsMeta,
        attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta),
      );
    }
    if (data.containsKey('last_error')) {
      context.handle(
        _lastErrorMeta,
        lastError.isAcceptableOrUnknown(data['last_error']!, _lastErrorMeta),
      );
    }
    if (data.containsKey('server_scan_id')) {
      context.handle(
        _serverScanIdMeta,
        serverScanId.isAcceptableOrUnknown(
          data['server_scan_id']!,
          _serverScanIdMeta,
        ),
      );
    }
    if (data.containsKey('next_attempt_at')) {
      context.handle(
        _nextAttemptAtMeta,
        nextAttemptAt.isAcceptableOrUnknown(
          data['next_attempt_at']!,
          _nextAttemptAtMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    if (data.containsKey('updated_at')) {
      context.handle(
        _updatedAtMeta,
        updatedAt.isAcceptableOrUnknown(data['updated_at']!, _updatedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_updatedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clientScanId};
  @override
  ScanQueueData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ScanQueueData(
      clientScanId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}client_scan_id'],
      )!,
      state: $ScanQueueTable.$converterstate.fromSql(
        attachedDatabase.typeMapping.read(
          DriftSqlType.string,
          data['${effectivePrefix}state'],
        )!,
      ),
      metaJson: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}meta_json'],
      )!,
      filesJson: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}files_json'],
      )!,
      attempts: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempts'],
      )!,
      lastError: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}last_error'],
      ),
      serverScanId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}server_scan_id'],
      ),
      nextAttemptAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}next_attempt_at'],
      ),
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}created_at'],
      )!,
      updatedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}updated_at'],
      )!,
    );
  }

  @override
  $ScanQueueTable createAlias(String alias) {
    return $ScanQueueTable(attachedDatabase, alias);
  }

  static TypeConverter<ScanState, String> $converterstate =
      const ScanStateConverter();
}

class ScanQueueData extends DataClass implements Insertable<ScanQueueData> {
  /// UUID generated on the phone; the server de-duplicates on it.
  final String clientScanId;
  final ScanState state;

  /// The `meta` JSON object exactly as it will be sent (§9.4).
  final String metaJson;

  /// JSON object mapping multipart field name -> local file path,
  /// e.g. {"page": ".../page.webp", "crop_q501": ".../q501.webp"}.
  final String filesJson;
  final int attempts;
  final String? lastError;

  /// `scan_id` returned by the server; needed for confirm-replace.
  final int? serverScanId;

  /// Earliest time of the next upload attempt (exponential backoff).
  final DateTime? nextAttemptAt;
  final DateTime createdAt;
  final DateTime updatedAt;
  const ScanQueueData({
    required this.clientScanId,
    required this.state,
    required this.metaJson,
    required this.filesJson,
    required this.attempts,
    this.lastError,
    this.serverScanId,
    this.nextAttemptAt,
    required this.createdAt,
    required this.updatedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['client_scan_id'] = Variable<String>(clientScanId);
    {
      map['state'] = Variable<String>(
        $ScanQueueTable.$converterstate.toSql(state),
      );
    }
    map['meta_json'] = Variable<String>(metaJson);
    map['files_json'] = Variable<String>(filesJson);
    map['attempts'] = Variable<int>(attempts);
    if (!nullToAbsent || lastError != null) {
      map['last_error'] = Variable<String>(lastError);
    }
    if (!nullToAbsent || serverScanId != null) {
      map['server_scan_id'] = Variable<int>(serverScanId);
    }
    if (!nullToAbsent || nextAttemptAt != null) {
      map['next_attempt_at'] = Variable<DateTime>(nextAttemptAt);
    }
    map['created_at'] = Variable<DateTime>(createdAt);
    map['updated_at'] = Variable<DateTime>(updatedAt);
    return map;
  }

  ScanQueueCompanion toCompanion(bool nullToAbsent) {
    return ScanQueueCompanion(
      clientScanId: Value(clientScanId),
      state: Value(state),
      metaJson: Value(metaJson),
      filesJson: Value(filesJson),
      attempts: Value(attempts),
      lastError: lastError == null && nullToAbsent
          ? const Value.absent()
          : Value(lastError),
      serverScanId: serverScanId == null && nullToAbsent
          ? const Value.absent()
          : Value(serverScanId),
      nextAttemptAt: nextAttemptAt == null && nullToAbsent
          ? const Value.absent()
          : Value(nextAttemptAt),
      createdAt: Value(createdAt),
      updatedAt: Value(updatedAt),
    );
  }

  factory ScanQueueData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ScanQueueData(
      clientScanId: serializer.fromJson<String>(json['clientScanId']),
      state: serializer.fromJson<ScanState>(json['state']),
      metaJson: serializer.fromJson<String>(json['metaJson']),
      filesJson: serializer.fromJson<String>(json['filesJson']),
      attempts: serializer.fromJson<int>(json['attempts']),
      lastError: serializer.fromJson<String?>(json['lastError']),
      serverScanId: serializer.fromJson<int?>(json['serverScanId']),
      nextAttemptAt: serializer.fromJson<DateTime?>(json['nextAttemptAt']),
      createdAt: serializer.fromJson<DateTime>(json['createdAt']),
      updatedAt: serializer.fromJson<DateTime>(json['updatedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clientScanId': serializer.toJson<String>(clientScanId),
      'state': serializer.toJson<ScanState>(state),
      'metaJson': serializer.toJson<String>(metaJson),
      'filesJson': serializer.toJson<String>(filesJson),
      'attempts': serializer.toJson<int>(attempts),
      'lastError': serializer.toJson<String?>(lastError),
      'serverScanId': serializer.toJson<int?>(serverScanId),
      'nextAttemptAt': serializer.toJson<DateTime?>(nextAttemptAt),
      'createdAt': serializer.toJson<DateTime>(createdAt),
      'updatedAt': serializer.toJson<DateTime>(updatedAt),
    };
  }

  ScanQueueData copyWith({
    String? clientScanId,
    ScanState? state,
    String? metaJson,
    String? filesJson,
    int? attempts,
    Value<String?> lastError = const Value.absent(),
    Value<int?> serverScanId = const Value.absent(),
    Value<DateTime?> nextAttemptAt = const Value.absent(),
    DateTime? createdAt,
    DateTime? updatedAt,
  }) => ScanQueueData(
    clientScanId: clientScanId ?? this.clientScanId,
    state: state ?? this.state,
    metaJson: metaJson ?? this.metaJson,
    filesJson: filesJson ?? this.filesJson,
    attempts: attempts ?? this.attempts,
    lastError: lastError.present ? lastError.value : this.lastError,
    serverScanId: serverScanId.present ? serverScanId.value : this.serverScanId,
    nextAttemptAt: nextAttemptAt.present
        ? nextAttemptAt.value
        : this.nextAttemptAt,
    createdAt: createdAt ?? this.createdAt,
    updatedAt: updatedAt ?? this.updatedAt,
  );
  ScanQueueData copyWithCompanion(ScanQueueCompanion data) {
    return ScanQueueData(
      clientScanId: data.clientScanId.present
          ? data.clientScanId.value
          : this.clientScanId,
      state: data.state.present ? data.state.value : this.state,
      metaJson: data.metaJson.present ? data.metaJson.value : this.metaJson,
      filesJson: data.filesJson.present ? data.filesJson.value : this.filesJson,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      lastError: data.lastError.present ? data.lastError.value : this.lastError,
      serverScanId: data.serverScanId.present
          ? data.serverScanId.value
          : this.serverScanId,
      nextAttemptAt: data.nextAttemptAt.present
          ? data.nextAttemptAt.value
          : this.nextAttemptAt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
      updatedAt: data.updatedAt.present ? data.updatedAt.value : this.updatedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ScanQueueData(')
          ..write('clientScanId: $clientScanId, ')
          ..write('state: $state, ')
          ..write('metaJson: $metaJson, ')
          ..write('filesJson: $filesJson, ')
          ..write('attempts: $attempts, ')
          ..write('lastError: $lastError, ')
          ..write('serverScanId: $serverScanId, ')
          ..write('nextAttemptAt: $nextAttemptAt, ')
          ..write('createdAt: $createdAt, ')
          ..write('updatedAt: $updatedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    clientScanId,
    state,
    metaJson,
    filesJson,
    attempts,
    lastError,
    serverScanId,
    nextAttemptAt,
    createdAt,
    updatedAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ScanQueueData &&
          other.clientScanId == this.clientScanId &&
          other.state == this.state &&
          other.metaJson == this.metaJson &&
          other.filesJson == this.filesJson &&
          other.attempts == this.attempts &&
          other.lastError == this.lastError &&
          other.serverScanId == this.serverScanId &&
          other.nextAttemptAt == this.nextAttemptAt &&
          other.createdAt == this.createdAt &&
          other.updatedAt == this.updatedAt);
}

class ScanQueueCompanion extends UpdateCompanion<ScanQueueData> {
  final Value<String> clientScanId;
  final Value<ScanState> state;
  final Value<String> metaJson;
  final Value<String> filesJson;
  final Value<int> attempts;
  final Value<String?> lastError;
  final Value<int?> serverScanId;
  final Value<DateTime?> nextAttemptAt;
  final Value<DateTime> createdAt;
  final Value<DateTime> updatedAt;
  final Value<int> rowid;
  const ScanQueueCompanion({
    this.clientScanId = const Value.absent(),
    this.state = const Value.absent(),
    this.metaJson = const Value.absent(),
    this.filesJson = const Value.absent(),
    this.attempts = const Value.absent(),
    this.lastError = const Value.absent(),
    this.serverScanId = const Value.absent(),
    this.nextAttemptAt = const Value.absent(),
    this.createdAt = const Value.absent(),
    this.updatedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ScanQueueCompanion.insert({
    required String clientScanId,
    required ScanState state,
    required String metaJson,
    required String filesJson,
    this.attempts = const Value.absent(),
    this.lastError = const Value.absent(),
    this.serverScanId = const Value.absent(),
    this.nextAttemptAt = const Value.absent(),
    required DateTime createdAt,
    required DateTime updatedAt,
    this.rowid = const Value.absent(),
  }) : clientScanId = Value(clientScanId),
       state = Value(state),
       metaJson = Value(metaJson),
       filesJson = Value(filesJson),
       createdAt = Value(createdAt),
       updatedAt = Value(updatedAt);
  static Insertable<ScanQueueData> custom({
    Expression<String>? clientScanId,
    Expression<String>? state,
    Expression<String>? metaJson,
    Expression<String>? filesJson,
    Expression<int>? attempts,
    Expression<String>? lastError,
    Expression<int>? serverScanId,
    Expression<DateTime>? nextAttemptAt,
    Expression<DateTime>? createdAt,
    Expression<DateTime>? updatedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clientScanId != null) 'client_scan_id': clientScanId,
      if (state != null) 'state': state,
      if (metaJson != null) 'meta_json': metaJson,
      if (filesJson != null) 'files_json': filesJson,
      if (attempts != null) 'attempts': attempts,
      if (lastError != null) 'last_error': lastError,
      if (serverScanId != null) 'server_scan_id': serverScanId,
      if (nextAttemptAt != null) 'next_attempt_at': nextAttemptAt,
      if (createdAt != null) 'created_at': createdAt,
      if (updatedAt != null) 'updated_at': updatedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  ScanQueueCompanion copyWith({
    Value<String>? clientScanId,
    Value<ScanState>? state,
    Value<String>? metaJson,
    Value<String>? filesJson,
    Value<int>? attempts,
    Value<String?>? lastError,
    Value<int?>? serverScanId,
    Value<DateTime?>? nextAttemptAt,
    Value<DateTime>? createdAt,
    Value<DateTime>? updatedAt,
    Value<int>? rowid,
  }) {
    return ScanQueueCompanion(
      clientScanId: clientScanId ?? this.clientScanId,
      state: state ?? this.state,
      metaJson: metaJson ?? this.metaJson,
      filesJson: filesJson ?? this.filesJson,
      attempts: attempts ?? this.attempts,
      lastError: lastError ?? this.lastError,
      serverScanId: serverScanId ?? this.serverScanId,
      nextAttemptAt: nextAttemptAt ?? this.nextAttemptAt,
      createdAt: createdAt ?? this.createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clientScanId.present) {
      map['client_scan_id'] = Variable<String>(clientScanId.value);
    }
    if (state.present) {
      map['state'] = Variable<String>(
        $ScanQueueTable.$converterstate.toSql(state.value),
      );
    }
    if (metaJson.present) {
      map['meta_json'] = Variable<String>(metaJson.value);
    }
    if (filesJson.present) {
      map['files_json'] = Variable<String>(filesJson.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (lastError.present) {
      map['last_error'] = Variable<String>(lastError.value);
    }
    if (serverScanId.present) {
      map['server_scan_id'] = Variable<int>(serverScanId.value);
    }
    if (nextAttemptAt.present) {
      map['next_attempt_at'] = Variable<DateTime>(nextAttemptAt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<DateTime>(createdAt.value);
    }
    if (updatedAt.present) {
      map['updated_at'] = Variable<DateTime>(updatedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ScanQueueCompanion(')
          ..write('clientScanId: $clientScanId, ')
          ..write('state: $state, ')
          ..write('metaJson: $metaJson, ')
          ..write('filesJson: $filesJson, ')
          ..write('attempts: $attempts, ')
          ..write('lastError: $lastError, ')
          ..write('serverScanId: $serverScanId, ')
          ..write('nextAttemptAt: $nextAttemptAt, ')
          ..write('createdAt: $createdAt, ')
          ..write('updatedAt: $updatedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $ModelCacheTable extends ModelCache
    with TableInfo<$ModelCacheTable, ModelCacheData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $ModelCacheTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _nameMeta = const VerificationMeta('name');
  @override
  late final GeneratedColumn<String> name = GeneratedColumn<String>(
    'name',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _versionMeta = const VerificationMeta(
    'version',
  );
  @override
  late final GeneratedColumn<int> version = GeneratedColumn<int>(
    'version',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _sha256Meta = const VerificationMeta('sha256');
  @override
  late final GeneratedColumn<String> sha256 = GeneratedColumn<String>(
    'sha256',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _pathMeta = const VerificationMeta('path');
  @override
  late final GeneratedColumn<String> path = GeneratedColumn<String>(
    'path',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _downloadedAtMeta = const VerificationMeta(
    'downloadedAt',
  );
  @override
  late final GeneratedColumn<DateTime> downloadedAt = GeneratedColumn<DateTime>(
    'downloaded_at',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    name,
    version,
    sha256,
    path,
    downloadedAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'model_cache';
  @override
  VerificationContext validateIntegrity(
    Insertable<ModelCacheData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('name')) {
      context.handle(
        _nameMeta,
        name.isAcceptableOrUnknown(data['name']!, _nameMeta),
      );
    } else if (isInserting) {
      context.missing(_nameMeta);
    }
    if (data.containsKey('version')) {
      context.handle(
        _versionMeta,
        version.isAcceptableOrUnknown(data['version']!, _versionMeta),
      );
    } else if (isInserting) {
      context.missing(_versionMeta);
    }
    if (data.containsKey('sha256')) {
      context.handle(
        _sha256Meta,
        sha256.isAcceptableOrUnknown(data['sha256']!, _sha256Meta),
      );
    } else if (isInserting) {
      context.missing(_sha256Meta);
    }
    if (data.containsKey('path')) {
      context.handle(
        _pathMeta,
        path.isAcceptableOrUnknown(data['path']!, _pathMeta),
      );
    } else if (isInserting) {
      context.missing(_pathMeta);
    }
    if (data.containsKey('downloaded_at')) {
      context.handle(
        _downloadedAtMeta,
        downloadedAt.isAcceptableOrUnknown(
          data['downloaded_at']!,
          _downloadedAtMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_downloadedAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {name};
  @override
  ModelCacheData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return ModelCacheData(
      name: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}name'],
      )!,
      version: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}version'],
      )!,
      sha256: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}sha256'],
      )!,
      path: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}path'],
      )!,
      downloadedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}downloaded_at'],
      )!,
    );
  }

  @override
  $ModelCacheTable createAlias(String alias) {
    return $ModelCacheTable(attachedDatabase, alias);
  }
}

class ModelCacheData extends DataClass implements Insertable<ModelCacheData> {
  final String name;
  final int version;
  final String sha256;
  final String path;
  final DateTime downloadedAt;
  const ModelCacheData({
    required this.name,
    required this.version,
    required this.sha256,
    required this.path,
    required this.downloadedAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['name'] = Variable<String>(name);
    map['version'] = Variable<int>(version);
    map['sha256'] = Variable<String>(sha256);
    map['path'] = Variable<String>(path);
    map['downloaded_at'] = Variable<DateTime>(downloadedAt);
    return map;
  }

  ModelCacheCompanion toCompanion(bool nullToAbsent) {
    return ModelCacheCompanion(
      name: Value(name),
      version: Value(version),
      sha256: Value(sha256),
      path: Value(path),
      downloadedAt: Value(downloadedAt),
    );
  }

  factory ModelCacheData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return ModelCacheData(
      name: serializer.fromJson<String>(json['name']),
      version: serializer.fromJson<int>(json['version']),
      sha256: serializer.fromJson<String>(json['sha256']),
      path: serializer.fromJson<String>(json['path']),
      downloadedAt: serializer.fromJson<DateTime>(json['downloadedAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'name': serializer.toJson<String>(name),
      'version': serializer.toJson<int>(version),
      'sha256': serializer.toJson<String>(sha256),
      'path': serializer.toJson<String>(path),
      'downloadedAt': serializer.toJson<DateTime>(downloadedAt),
    };
  }

  ModelCacheData copyWith({
    String? name,
    int? version,
    String? sha256,
    String? path,
    DateTime? downloadedAt,
  }) => ModelCacheData(
    name: name ?? this.name,
    version: version ?? this.version,
    sha256: sha256 ?? this.sha256,
    path: path ?? this.path,
    downloadedAt: downloadedAt ?? this.downloadedAt,
  );
  ModelCacheData copyWithCompanion(ModelCacheCompanion data) {
    return ModelCacheData(
      name: data.name.present ? data.name.value : this.name,
      version: data.version.present ? data.version.value : this.version,
      sha256: data.sha256.present ? data.sha256.value : this.sha256,
      path: data.path.present ? data.path.value : this.path,
      downloadedAt: data.downloadedAt.present
          ? data.downloadedAt.value
          : this.downloadedAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('ModelCacheData(')
          ..write('name: $name, ')
          ..write('version: $version, ')
          ..write('sha256: $sha256, ')
          ..write('path: $path, ')
          ..write('downloadedAt: $downloadedAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(name, version, sha256, path, downloadedAt);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is ModelCacheData &&
          other.name == this.name &&
          other.version == this.version &&
          other.sha256 == this.sha256 &&
          other.path == this.path &&
          other.downloadedAt == this.downloadedAt);
}

class ModelCacheCompanion extends UpdateCompanion<ModelCacheData> {
  final Value<String> name;
  final Value<int> version;
  final Value<String> sha256;
  final Value<String> path;
  final Value<DateTime> downloadedAt;
  final Value<int> rowid;
  const ModelCacheCompanion({
    this.name = const Value.absent(),
    this.version = const Value.absent(),
    this.sha256 = const Value.absent(),
    this.path = const Value.absent(),
    this.downloadedAt = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  ModelCacheCompanion.insert({
    required String name,
    required int version,
    required String sha256,
    required String path,
    required DateTime downloadedAt,
    this.rowid = const Value.absent(),
  }) : name = Value(name),
       version = Value(version),
       sha256 = Value(sha256),
       path = Value(path),
       downloadedAt = Value(downloadedAt);
  static Insertable<ModelCacheData> custom({
    Expression<String>? name,
    Expression<int>? version,
    Expression<String>? sha256,
    Expression<String>? path,
    Expression<DateTime>? downloadedAt,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (name != null) 'name': name,
      if (version != null) 'version': version,
      if (sha256 != null) 'sha256': sha256,
      if (path != null) 'path': path,
      if (downloadedAt != null) 'downloaded_at': downloadedAt,
      if (rowid != null) 'rowid': rowid,
    });
  }

  ModelCacheCompanion copyWith({
    Value<String>? name,
    Value<int>? version,
    Value<String>? sha256,
    Value<String>? path,
    Value<DateTime>? downloadedAt,
    Value<int>? rowid,
  }) {
    return ModelCacheCompanion(
      name: name ?? this.name,
      version: version ?? this.version,
      sha256: sha256 ?? this.sha256,
      path: path ?? this.path,
      downloadedAt: downloadedAt ?? this.downloadedAt,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (name.present) {
      map['name'] = Variable<String>(name.value);
    }
    if (version.present) {
      map['version'] = Variable<int>(version.value);
    }
    if (sha256.present) {
      map['sha256'] = Variable<String>(sha256.value);
    }
    if (path.present) {
      map['path'] = Variable<String>(path.value);
    }
    if (downloadedAt.present) {
      map['downloaded_at'] = Variable<DateTime>(downloadedAt.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('ModelCacheCompanion(')
          ..write('name: $name, ')
          ..write('version: $version, ')
          ..write('sha256: $sha256, ')
          ..write('path: $path, ')
          ..write('downloadedAt: $downloadedAt, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$AppDatabase extends GeneratedDatabase {
  _$AppDatabase(QueryExecutor e) : super(e);
  $AppDatabaseManager get managers => $AppDatabaseManager(this);
  late final $CachedLayoutsTable cachedLayouts = $CachedLayoutsTable(this);
  late final $CachedRostersTable cachedRosters = $CachedRostersTable(this);
  late final $ScanQueueTable scanQueue = $ScanQueueTable(this);
  late final $ModelCacheTable modelCache = $ModelCacheTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    cachedLayouts,
    cachedRosters,
    scanQueue,
    modelCache,
  ];
}

typedef $$CachedLayoutsTableCreateCompanionBuilder =
    CachedLayoutsCompanion Function({
      required int assignmentId,
      required int version,
      required int page,
      required String json,
      required DateTime cachedAt,
      Value<int> rowid,
    });
typedef $$CachedLayoutsTableUpdateCompanionBuilder =
    CachedLayoutsCompanion Function({
      Value<int> assignmentId,
      Value<int> version,
      Value<int> page,
      Value<String> json,
      Value<DateTime> cachedAt,
      Value<int> rowid,
    });

class $$CachedLayoutsTableFilterComposer
    extends Composer<_$AppDatabase, $CachedLayoutsTable> {
  $$CachedLayoutsTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get assignmentId => $composableBuilder(
    column: $table.assignmentId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get version => $composableBuilder(
    column: $table.version,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get page => $composableBuilder(
    column: $table.page,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get json => $composableBuilder(
    column: $table.json,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get cachedAt => $composableBuilder(
    column: $table.cachedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$CachedLayoutsTableOrderingComposer
    extends Composer<_$AppDatabase, $CachedLayoutsTable> {
  $$CachedLayoutsTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get assignmentId => $composableBuilder(
    column: $table.assignmentId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get version => $composableBuilder(
    column: $table.version,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get page => $composableBuilder(
    column: $table.page,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get json => $composableBuilder(
    column: $table.json,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get cachedAt => $composableBuilder(
    column: $table.cachedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$CachedLayoutsTableAnnotationComposer
    extends Composer<_$AppDatabase, $CachedLayoutsTable> {
  $$CachedLayoutsTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get assignmentId => $composableBuilder(
    column: $table.assignmentId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get version =>
      $composableBuilder(column: $table.version, builder: (column) => column);

  GeneratedColumn<int> get page =>
      $composableBuilder(column: $table.page, builder: (column) => column);

  GeneratedColumn<String> get json =>
      $composableBuilder(column: $table.json, builder: (column) => column);

  GeneratedColumn<DateTime> get cachedAt =>
      $composableBuilder(column: $table.cachedAt, builder: (column) => column);
}

class $$CachedLayoutsTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $CachedLayoutsTable,
          CachedLayout,
          $$CachedLayoutsTableFilterComposer,
          $$CachedLayoutsTableOrderingComposer,
          $$CachedLayoutsTableAnnotationComposer,
          $$CachedLayoutsTableCreateCompanionBuilder,
          $$CachedLayoutsTableUpdateCompanionBuilder,
          (
            CachedLayout,
            BaseReferences<_$AppDatabase, $CachedLayoutsTable, CachedLayout>,
          ),
          CachedLayout,
          PrefetchHooks Function()
        > {
  $$CachedLayoutsTableTableManager(_$AppDatabase db, $CachedLayoutsTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$CachedLayoutsTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$CachedLayoutsTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$CachedLayoutsTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> assignmentId = const Value.absent(),
                Value<int> version = const Value.absent(),
                Value<int> page = const Value.absent(),
                Value<String> json = const Value.absent(),
                Value<DateTime> cachedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CachedLayoutsCompanion(
                assignmentId: assignmentId,
                version: version,
                page: page,
                json: json,
                cachedAt: cachedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required int assignmentId,
                required int version,
                required int page,
                required String json,
                required DateTime cachedAt,
                Value<int> rowid = const Value.absent(),
              }) => CachedLayoutsCompanion.insert(
                assignmentId: assignmentId,
                version: version,
                page: page,
                json: json,
                cachedAt: cachedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$CachedLayoutsTable, CachedLayout>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $CachedLayoutsTable,
                    CachedLayout
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$CachedLayoutsTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $CachedLayoutsTable,
      CachedLayout,
      $$CachedLayoutsTableFilterComposer,
      $$CachedLayoutsTableOrderingComposer,
      $$CachedLayoutsTableAnnotationComposer,
      $$CachedLayoutsTableCreateCompanionBuilder,
      $$CachedLayoutsTableUpdateCompanionBuilder,
      (
        CachedLayout,
        BaseReferences<_$AppDatabase, $CachedLayoutsTable, CachedLayout>,
      ),
      CachedLayout,
      PrefetchHooks Function()
    >;
typedef $$CachedRostersTableCreateCompanionBuilder =
    CachedRostersCompanion Function({
      required int classroomId,
      required int studentId,
      required int studentNumber,
      required String name,
      Value<int> rowid,
    });
typedef $$CachedRostersTableUpdateCompanionBuilder =
    CachedRostersCompanion Function({
      Value<int> classroomId,
      Value<int> studentId,
      Value<int> studentNumber,
      Value<String> name,
      Value<int> rowid,
    });

class $$CachedRostersTableFilterComposer
    extends Composer<_$AppDatabase, $CachedRostersTable> {
  $$CachedRostersTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get classroomId => $composableBuilder(
    column: $table.classroomId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get studentId => $composableBuilder(
    column: $table.studentId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get studentNumber => $composableBuilder(
    column: $table.studentNumber,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );
}

class $$CachedRostersTableOrderingComposer
    extends Composer<_$AppDatabase, $CachedRostersTable> {
  $$CachedRostersTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get classroomId => $composableBuilder(
    column: $table.classroomId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get studentId => $composableBuilder(
    column: $table.studentId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get studentNumber => $composableBuilder(
    column: $table.studentNumber,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$CachedRostersTableAnnotationComposer
    extends Composer<_$AppDatabase, $CachedRostersTable> {
  $$CachedRostersTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get classroomId => $composableBuilder(
    column: $table.classroomId,
    builder: (column) => column,
  );

  GeneratedColumn<int> get studentId =>
      $composableBuilder(column: $table.studentId, builder: (column) => column);

  GeneratedColumn<int> get studentNumber => $composableBuilder(
    column: $table.studentNumber,
    builder: (column) => column,
  );

  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);
}

class $$CachedRostersTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $CachedRostersTable,
          CachedRoster,
          $$CachedRostersTableFilterComposer,
          $$CachedRostersTableOrderingComposer,
          $$CachedRostersTableAnnotationComposer,
          $$CachedRostersTableCreateCompanionBuilder,
          $$CachedRostersTableUpdateCompanionBuilder,
          (
            CachedRoster,
            BaseReferences<_$AppDatabase, $CachedRostersTable, CachedRoster>,
          ),
          CachedRoster,
          PrefetchHooks Function()
        > {
  $$CachedRostersTableTableManager(_$AppDatabase db, $CachedRostersTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$CachedRostersTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$CachedRostersTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$CachedRostersTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> classroomId = const Value.absent(),
                Value<int> studentId = const Value.absent(),
                Value<int> studentNumber = const Value.absent(),
                Value<String> name = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => CachedRostersCompanion(
                classroomId: classroomId,
                studentId: studentId,
                studentNumber: studentNumber,
                name: name,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required int classroomId,
                required int studentId,
                required int studentNumber,
                required String name,
                Value<int> rowid = const Value.absent(),
              }) => CachedRostersCompanion.insert(
                classroomId: classroomId,
                studentId: studentId,
                studentNumber: studentNumber,
                name: name,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$CachedRostersTable, CachedRoster>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $CachedRostersTable,
                    CachedRoster
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$CachedRostersTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $CachedRostersTable,
      CachedRoster,
      $$CachedRostersTableFilterComposer,
      $$CachedRostersTableOrderingComposer,
      $$CachedRostersTableAnnotationComposer,
      $$CachedRostersTableCreateCompanionBuilder,
      $$CachedRostersTableUpdateCompanionBuilder,
      (
        CachedRoster,
        BaseReferences<_$AppDatabase, $CachedRostersTable, CachedRoster>,
      ),
      CachedRoster,
      PrefetchHooks Function()
    >;
typedef $$ScanQueueTableCreateCompanionBuilder =
    ScanQueueCompanion Function({
      required String clientScanId,
      required ScanState state,
      required String metaJson,
      required String filesJson,
      Value<int> attempts,
      Value<String?> lastError,
      Value<int?> serverScanId,
      Value<DateTime?> nextAttemptAt,
      required DateTime createdAt,
      required DateTime updatedAt,
      Value<int> rowid,
    });
typedef $$ScanQueueTableUpdateCompanionBuilder =
    ScanQueueCompanion Function({
      Value<String> clientScanId,
      Value<ScanState> state,
      Value<String> metaJson,
      Value<String> filesJson,
      Value<int> attempts,
      Value<String?> lastError,
      Value<int?> serverScanId,
      Value<DateTime?> nextAttemptAt,
      Value<DateTime> createdAt,
      Value<DateTime> updatedAt,
      Value<int> rowid,
    });

class $$ScanQueueTableFilterComposer
    extends Composer<_$AppDatabase, $ScanQueueTable> {
  $$ScanQueueTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clientScanId => $composableBuilder(
    column: $table.clientScanId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnWithTypeConverterFilters<ScanState, ScanState, String> get state =>
      $composableBuilder(
        column: $table.state,
        builder: (column) => ColumnWithTypeConverterFilters(column),
      );

  ColumnFilters<String> get metaJson => $composableBuilder(
    column: $table.metaJson,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get filesJson => $composableBuilder(
    column: $table.filesJson,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get lastError => $composableBuilder(
    column: $table.lastError,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get serverScanId => $composableBuilder(
    column: $table.serverScanId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$ScanQueueTableOrderingComposer
    extends Composer<_$AppDatabase, $ScanQueueTable> {
  $$ScanQueueTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clientScanId => $composableBuilder(
    column: $table.clientScanId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get state => $composableBuilder(
    column: $table.state,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get metaJson => $composableBuilder(
    column: $table.metaJson,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get filesJson => $composableBuilder(
    column: $table.filesJson,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get lastError => $composableBuilder(
    column: $table.lastError,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get serverScanId => $composableBuilder(
    column: $table.serverScanId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get updatedAt => $composableBuilder(
    column: $table.updatedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ScanQueueTableAnnotationComposer
    extends Composer<_$AppDatabase, $ScanQueueTable> {
  $$ScanQueueTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clientScanId => $composableBuilder(
    column: $table.clientScanId,
    builder: (column) => column,
  );

  GeneratedColumnWithTypeConverter<ScanState, String> get state =>
      $composableBuilder(column: $table.state, builder: (column) => column);

  GeneratedColumn<String> get metaJson =>
      $composableBuilder(column: $table.metaJson, builder: (column) => column);

  GeneratedColumn<String> get filesJson =>
      $composableBuilder(column: $table.filesJson, builder: (column) => column);

  GeneratedColumn<int> get attempts =>
      $composableBuilder(column: $table.attempts, builder: (column) => column);

  GeneratedColumn<String> get lastError =>
      $composableBuilder(column: $table.lastError, builder: (column) => column);

  GeneratedColumn<int> get serverScanId => $composableBuilder(
    column: $table.serverScanId,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get nextAttemptAt => $composableBuilder(
    column: $table.nextAttemptAt,
    builder: (column) => column,
  );

  GeneratedColumn<DateTime> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);

  GeneratedColumn<DateTime> get updatedAt =>
      $composableBuilder(column: $table.updatedAt, builder: (column) => column);
}

class $$ScanQueueTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ScanQueueTable,
          ScanQueueData,
          $$ScanQueueTableFilterComposer,
          $$ScanQueueTableOrderingComposer,
          $$ScanQueueTableAnnotationComposer,
          $$ScanQueueTableCreateCompanionBuilder,
          $$ScanQueueTableUpdateCompanionBuilder,
          (
            ScanQueueData,
            BaseReferences<_$AppDatabase, $ScanQueueTable, ScanQueueData>,
          ),
          ScanQueueData,
          PrefetchHooks Function()
        > {
  $$ScanQueueTableTableManager(_$AppDatabase db, $ScanQueueTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ScanQueueTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ScanQueueTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ScanQueueTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clientScanId = const Value.absent(),
                Value<ScanState> state = const Value.absent(),
                Value<String> metaJson = const Value.absent(),
                Value<String> filesJson = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<String?> lastError = const Value.absent(),
                Value<int?> serverScanId = const Value.absent(),
                Value<DateTime?> nextAttemptAt = const Value.absent(),
                Value<DateTime> createdAt = const Value.absent(),
                Value<DateTime> updatedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ScanQueueCompanion(
                clientScanId: clientScanId,
                state: state,
                metaJson: metaJson,
                filesJson: filesJson,
                attempts: attempts,
                lastError: lastError,
                serverScanId: serverScanId,
                nextAttemptAt: nextAttemptAt,
                createdAt: createdAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clientScanId,
                required ScanState state,
                required String metaJson,
                required String filesJson,
                Value<int> attempts = const Value.absent(),
                Value<String?> lastError = const Value.absent(),
                Value<int?> serverScanId = const Value.absent(),
                Value<DateTime?> nextAttemptAt = const Value.absent(),
                required DateTime createdAt,
                required DateTime updatedAt,
                Value<int> rowid = const Value.absent(),
              }) => ScanQueueCompanion.insert(
                clientScanId: clientScanId,
                state: state,
                metaJson: metaJson,
                filesJson: filesJson,
                attempts: attempts,
                lastError: lastError,
                serverScanId: serverScanId,
                nextAttemptAt: nextAttemptAt,
                createdAt: createdAt,
                updatedAt: updatedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$ScanQueueTable, ScanQueueData>(table),
                  BaseReferences<_$AppDatabase, $ScanQueueTable, ScanQueueData>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ScanQueueTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ScanQueueTable,
      ScanQueueData,
      $$ScanQueueTableFilterComposer,
      $$ScanQueueTableOrderingComposer,
      $$ScanQueueTableAnnotationComposer,
      $$ScanQueueTableCreateCompanionBuilder,
      $$ScanQueueTableUpdateCompanionBuilder,
      (
        ScanQueueData,
        BaseReferences<_$AppDatabase, $ScanQueueTable, ScanQueueData>,
      ),
      ScanQueueData,
      PrefetchHooks Function()
    >;
typedef $$ModelCacheTableCreateCompanionBuilder =
    ModelCacheCompanion Function({
      required String name,
      required int version,
      required String sha256,
      required String path,
      required DateTime downloadedAt,
      Value<int> rowid,
    });
typedef $$ModelCacheTableUpdateCompanionBuilder =
    ModelCacheCompanion Function({
      Value<String> name,
      Value<int> version,
      Value<String> sha256,
      Value<String> path,
      Value<DateTime> downloadedAt,
      Value<int> rowid,
    });

class $$ModelCacheTableFilterComposer
    extends Composer<_$AppDatabase, $ModelCacheTable> {
  $$ModelCacheTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get version => $composableBuilder(
    column: $table.version,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get sha256 => $composableBuilder(
    column: $table.sha256,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get path => $composableBuilder(
    column: $table.path,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get downloadedAt => $composableBuilder(
    column: $table.downloadedAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$ModelCacheTableOrderingComposer
    extends Composer<_$AppDatabase, $ModelCacheTable> {
  $$ModelCacheTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get name => $composableBuilder(
    column: $table.name,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get version => $composableBuilder(
    column: $table.version,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get sha256 => $composableBuilder(
    column: $table.sha256,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get path => $composableBuilder(
    column: $table.path,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get downloadedAt => $composableBuilder(
    column: $table.downloadedAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$ModelCacheTableAnnotationComposer
    extends Composer<_$AppDatabase, $ModelCacheTable> {
  $$ModelCacheTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get name =>
      $composableBuilder(column: $table.name, builder: (column) => column);

  GeneratedColumn<int> get version =>
      $composableBuilder(column: $table.version, builder: (column) => column);

  GeneratedColumn<String> get sha256 =>
      $composableBuilder(column: $table.sha256, builder: (column) => column);

  GeneratedColumn<String> get path =>
      $composableBuilder(column: $table.path, builder: (column) => column);

  GeneratedColumn<DateTime> get downloadedAt => $composableBuilder(
    column: $table.downloadedAt,
    builder: (column) => column,
  );
}

class $$ModelCacheTableTableManager
    extends
        RootTableManager<
          _$AppDatabase,
          $ModelCacheTable,
          ModelCacheData,
          $$ModelCacheTableFilterComposer,
          $$ModelCacheTableOrderingComposer,
          $$ModelCacheTableAnnotationComposer,
          $$ModelCacheTableCreateCompanionBuilder,
          $$ModelCacheTableUpdateCompanionBuilder,
          (
            ModelCacheData,
            BaseReferences<_$AppDatabase, $ModelCacheTable, ModelCacheData>,
          ),
          ModelCacheData,
          PrefetchHooks Function()
        > {
  $$ModelCacheTableTableManager(_$AppDatabase db, $ModelCacheTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$ModelCacheTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$ModelCacheTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$ModelCacheTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> name = const Value.absent(),
                Value<int> version = const Value.absent(),
                Value<String> sha256 = const Value.absent(),
                Value<String> path = const Value.absent(),
                Value<DateTime> downloadedAt = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => ModelCacheCompanion(
                name: name,
                version: version,
                sha256: sha256,
                path: path,
                downloadedAt: downloadedAt,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String name,
                required int version,
                required String sha256,
                required String path,
                required DateTime downloadedAt,
                Value<int> rowid = const Value.absent(),
              }) => ModelCacheCompanion.insert(
                name: name,
                version: version,
                sha256: sha256,
                path: path,
                downloadedAt: downloadedAt,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$ModelCacheTable, ModelCacheData>(table),
                  BaseReferences<
                    _$AppDatabase,
                    $ModelCacheTable,
                    ModelCacheData
                  >(db, table, e),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$ModelCacheTableProcessedTableManager =
    ProcessedTableManager<
      _$AppDatabase,
      $ModelCacheTable,
      ModelCacheData,
      $$ModelCacheTableFilterComposer,
      $$ModelCacheTableOrderingComposer,
      $$ModelCacheTableAnnotationComposer,
      $$ModelCacheTableCreateCompanionBuilder,
      $$ModelCacheTableUpdateCompanionBuilder,
      (
        ModelCacheData,
        BaseReferences<_$AppDatabase, $ModelCacheTable, ModelCacheData>,
      ),
      ModelCacheData,
      PrefetchHooks Function()
    >;

class $AppDatabaseManager {
  final _$AppDatabase _db;
  $AppDatabaseManager(this._db);
  $$CachedLayoutsTableTableManager get cachedLayouts =>
      $$CachedLayoutsTableTableManager(_db, _db.cachedLayouts);
  $$CachedRostersTableTableManager get cachedRosters =>
      $$CachedRostersTableTableManager(_db, _db.cachedRosters);
  $$ScanQueueTableTableManager get scanQueue =>
      $$ScanQueueTableTableManager(_db, _db.scanQueue);
  $$ModelCacheTableTableManager get modelCache =>
      $$ModelCacheTableTableManager(_db, _db.modelCache);
}
