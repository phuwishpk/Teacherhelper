const _thaiMonths = [
  'ม.ค.',
  'ก.พ.',
  'มี.ค.',
  'เม.ย.',
  'พ.ค.',
  'มิ.ย.',
  'ก.ค.',
  'ส.ค.',
  'ก.ย.',
  'ต.ค.',
  'พ.ย.',
  'ธ.ค.',
];

/// Buddhist-era year for the current calendar year (e.g. 2569).
int currentThaiYear() => DateTime.now().year + 543;

/// "1 ต.ค. 2569" in local time (timestamps come from the API in UTC).
String formatThaiDate(DateTime dt) {
  final local = dt.toLocal();
  return '${local.day} ${_thaiMonths[local.month - 1]} ${local.year + 543}';
}

/// "1 ต.ค. 2569 09:15".
String formatThaiDateTime(DateTime dt) {
  final local = dt.toLocal();
  final h = local.hour.toString().padLeft(2, '0');
  final m = local.minute.toString().padLeft(2, '0');
  return '${formatThaiDate(dt)} $h:$m';
}

/// Grade level 1–12 -> "ป.1" … "ม.6" (DESIGN §8.1 classrooms.grade_level).
String gradeLevelLabel(int level) => level <= 6 ? 'ป.$level' : 'ม.${level - 6}';
