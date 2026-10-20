import 'package:cloud_firestore/cloud_firestore.dart';

/// One document of users/{uid}/notices (DESIGN §38 step 2): the push the
/// server sent, kept so the app can list it and route on its `data`.
class Notice {
  const Notice({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
    required this.data,
    required this.read,
    required this.createdAt,
  });

  final String id;
  final String kind;
  final String title;
  final String body;

  /// The push data map (`type` and ids as strings), for `routeForPush`.
  final Map<String, String> data;
  final bool read;
  final DateTime createdAt;

  factory Notice.fromDoc(DocumentSnapshot<Map<String, dynamic>> doc) {
    final json = doc.data() ?? const {};
    final rawData = json['data'];
    return Notice(
      id: doc.id,
      kind: json['kind'] as String? ?? '',
      title: json['title'] as String? ?? 'Krucheck',
      body: json['body'] as String? ?? '',
      data: {
        if (rawData is Map)
          for (final MapEntry(:key, :value) in rawData.entries)
            key.toString(): value?.toString() ?? '',
      },
      read: json['read'] == true,
      createdAt: _date(json['created_at']),
    );
  }

  static DateTime _date(Object? value) => switch (value) {
    Timestamp t => t.toDate(),
    DateTime d => d,
    String s => DateTime.tryParse(s) ?? DateTime.now(),
    _ => DateTime.now(),
  };

  Notice copyWith({bool? read}) => Notice(
    id: id,
    kind: kind,
    title: title,
    body: body,
    data: data,
    read: read ?? this.read,
    createdAt: createdAt,
  );
}
