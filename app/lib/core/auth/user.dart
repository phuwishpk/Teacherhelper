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
