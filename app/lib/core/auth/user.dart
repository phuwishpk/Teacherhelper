/// Minimal user as returned by GET /api/v1/me (DESIGN §8.1 users table).
class User {
  const User({
    required this.id,
    required this.name,
    required this.role,
    this.email,
    this.status,
    this.schoolId,
    this.schoolName,
  });

  final int id;
  final String name;

  /// `admin`, `teacher` or `student` (DESIGN §8.1).
  final String role;
  final String? email;
  final String? status;

  /// From the nested `school: {id, name}` object (DESIGN §9.1 GET /me).
  final int? schoolId;
  final String? schoolName;

  bool get isTeacher => role == 'teacher';
  bool get isStudent => role == 'student';

  /// An admin signs in on the same login page; their token opens only
  /// `/me`, logout and the handoff to the web panel (DESIGN §7.4).
  bool get isAdmin => role == 'admin';

  /// Round-trips through [fromJson]; used to cache the last `/me` payload so
  /// the app can start offline (DESIGN §6.3 offline scanning).
  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'role': role,
    'email': email,
    'status': status,
    'school': schoolId == null && schoolName == null
        ? null
        : {'id': schoolId, 'name': schoolName},
  };

  factory User.fromJson(Map<String, dynamic> json) {
    final school = json['school'] as Map<String, dynamic>?;
    return User(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String,
      role: json['role'] as String,
      email: json['email'] as String?,
      status: json['status'] as String?,
      schoolId:
          (school?['id'] as num?)?.toInt() ??
          (json['school_id'] as num?)?.toInt(),
      schoolName: school?['name'] as String?,
    );
  }
}
