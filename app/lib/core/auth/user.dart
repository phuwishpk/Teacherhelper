/// Minimal user as returned by GET /api/v1/me (DESIGN §8.1 users table).
class User {
  const User({
    required this.id,
    required this.name,
    required this.role,
    this.email,
    this.schoolName,
  });

  final int id;
  final String name;
  final String role;
  final String? email;

  /// From the nested `school: {id, name}` object (DESIGN §9.1 GET /me).
  final String? schoolName;

  factory User.fromJson(Map<String, dynamic> json) => User(
    id: json['id'] as int,
    name: json['name'] as String,
    role: json['role'] as String,
    email: json['email'] as String?,
    schoolName: (json['school'] as Map<String, dynamic>?)?['name'] as String?,
  );
}
