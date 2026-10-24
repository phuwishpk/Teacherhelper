import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../google_classroom/google_auth.dart' show GoogleAuthCanceled;
import '../../core/widgets/small_spinner.dart';
import '../google_signin/google_signin_config.dart';
import '../google_signin/google_signin_errors.dart';
import '../google_signin/google_signin_gateway.dart';
import '../google_signin/google_signin_models.dart';
import '../google_signin/google_signin_providers.dart';
import '../google_signin/google_signin_repository.dart';
import 'join_repository.dart';
import 'student_overview.dart';

/// `/join/:code` (the page behind a class code or its QR, DESIGN §29.13.3)
/// and `/student/join` (typing the code). Signed out it names the classroom
/// and offers the student sign-in or a new account with Google; a signed-in
/// pupil picks their seat or joins under their own name; a teacher is told
/// the link is for pupils.
class JoinClassroomScreen extends ConsumerWidget {
  const JoinClassroomScreen({super.key, this.code});

  final String? code;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final code = this.code == null ? null : normalizeJoinCode(this.code!);
    final session = ref.watch(sessionProvider);
    final Widget body;
    if (code == null) {
      body = const _CodeEntry();
    } else if (session is SessionRestoring) {
      body = const Center(child: CircularProgressIndicator.adaptive());
    } else if (session is SignedIn && session.user.isStudent) {
      body = _StudentJoin(code: code, studentName: session.user.name);
    } else if (session is SignedIn) {
      body = EmptyView(
        key: const ValueKey('join_not_student'),
        icon: Icons.info_outline,
        title: 'ลิงก์นี้สำหรับนักเรียน',
        message:
            'นักเรียนเปิดลิงก์หรือสแกน QR นี้เพื่อเข้าห้อง $code '
            'ครูดูรหัสห้องและ QR ได้ในหน้าห้องเรียนของตัวเอง',
        action: FilledButton(
          onPressed: () => context.go(
            session.user.isAdmin ? AppRoutes.adminHome : AppRoutes.home,
          ),
          child: const Text('กลับหน้าหลัก'),
        ),
      );
    } else {
      body = _GuestJoin(code: code);
    }
    return Scaffold(
      appBar: AppBar(title: const Text('เข้าห้องเรียน')),
      body: body,
    );
  }
}

/// `/student/join`: the pupil types the 6-character code the teacher gave.
class _CodeEntry extends StatefulWidget {
  const _CodeEntry();

  @override
  State<_CodeEntry> createState() => _CodeEntryState();
}

class _CodeEntryState extends State<_CodeEntry> {
  final _code = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  void _next() {
    final code = normalizeJoinCode(_code.text);
    if (code.length != 6) {
      setState(() => _error = 'รหัสห้องมี 6 ตัวอักษร');
      return;
    }
    context.go(AppRoutes.join(code));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FormColumn(
      children: [
        Text('กรอกรหัสห้องที่ครูให้', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          'รหัสห้องมี 6 ตัวอักษร เช่น ABC234 หรือสแกน QR ของห้องด้วยกล้องมือถือ',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),
        TextField(
          key: const ValueKey('join_code_field'),
          controller: _code,
          autofocus: true,
          textCapitalization: TextCapitalization.characters,
          autocorrect: false,
          maxLength: 7,
          decoration: InputDecoration(
            labelText: 'รหัสห้อง',
            errorText: _error,
            counterText: '',
          ),
          style: theme.textTheme.headlineSmall?.copyWith(
            fontFamily: 'monospace',
            letterSpacing: 4,
          ),
          onSubmitted: (_) => _next(),
        ),
        const SizedBox(height: 24),
        FilledButton(
          key: const ValueKey('join_code_next'),
          onPressed: _next,
          child: const Text('ถัดไป'),
        ),
      ],
    );
  }
}

class _ClassroomCard extends StatelessWidget {
  const _ClassroomCard({required this.info});

  final JoinInfo info;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      key: const ValueKey('join_classroom_card'),
      child: ListTile(
        leading: const Icon(Icons.meeting_room_outlined),
        title: Text('ห้อง ${info.name}', style: theme.textTheme.titleMedium),
        subtitle: Text(
          [
            if (info.teacherName case final t? when t.isNotEmpty) 'ครู $t',
            if (info.schoolName case final s? when s.isNotEmpty) s,
            'ปีการศึกษา ${info.academicYear}',
          ].join(' · '),
        ),
      ),
    );
  }
}

/// Signed out: the classroom's name, the student sign-in, or a new account
/// with Google (the notice must be accepted first, DESIGN §24.14).
class _GuestJoin extends ConsumerStatefulWidget {
  const _GuestJoin({required this.code});

  final String code;

  @override
  ConsumerState<_GuestJoin> createState() => _GuestJoinState();
}

class _GuestJoinState extends ConsumerState<_GuestJoin> {
  bool _accepted = false;
  bool _busy = false;
  String? _error;

  Future<void> _google(GoogleSignInMode mode) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final storage = ref.read(tokenStorageProvider);
    final repo = ref.read(googleSignInRepositoryProvider);
    try {
      if (mode == GoogleSignInMode.web) {
        // The code waits in storage for the return page (GoogleLoginReturnScreen).
        await storage.writePendingJoinCode(widget.code);
        final url = await repo.webUrl(
          link: false,
          intent: GoogleIntent.student,
        );
        if (await ref.read(sameTabUrlOpenerProvider)(url)) return;
        await storage.writePendingJoinCode(null);
        setState(() => _error = 'เปิดหน้าเลือกบัญชี Google ไม่ได้ ลองอีกครั้ง');
        return;
      }
      final idToken = await ref.read(googleSignInGatewayProvider).idToken();
      final session = ref.read(sessionProvider.notifier);
      try {
        await session.signInWithToken(
          repo.signIn(idToken: idToken, intent: GoogleIntent.student),
        );
      } catch (e) {
        final ticket = GoogleNotLinked.of(e)?.linkTicket;
        if (ticket == null) rethrow;
        // Unknown to Krucheck: the code makes it a student account (§29.13.3).
        await session.signInWithToken(
          ref
              .read(joinRepositoryProvider)
              .googleJoin(linkTicket: ticket, code: widget.code),
        );
      }
      // Signed in: this same page now shows the seats.
    } on GoogleAuthCanceled {
      // Nothing to say.
    } catch (e) {
      if (mounted) setState(() => _error = googleSignInErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final info = ref.watch(joinPublicInfoProvider(widget.code));
    final mode =
        ref.watch(googleSignInAvailabilityProvider).value ??
        GoogleSignInMode.none;
    return AsyncView(
      value: info,
      onRetry: () => ref.invalidate(joinPublicInfoProvider(widget.code)),
      data: (info) => FormColumn(
        children: [
          _ClassroomCard(info: info),
          const SizedBox(height: 16),
          if (!info.open)
            Text(
              'ห้องนี้ปิดรับสมาชิกแล้ว ถ้ายังไม่มีชื่อในห้อง ติดต่อครูประจำชั้น',
              key: const ValueKey('join_closed'),
              style: TextStyle(color: theme.colorScheme.error),
            )
          else ...[
            Text('มีชื่อผู้ใช้จากครูแล้ว?', style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            FilledButton.icon(
              key: const ValueKey('join_login'),
              onPressed: _busy
                  ? null
                  : () => context.push(AppRoutes.loginStudent),
              icon: const Icon(Icons.login),
              label: const Text('เข้าสู่ระบบ แล้วเข้าห้อง'),
            ),
            if (mode != GoogleSignInMode.none) ...[
              const SizedBox(height: 24),
              Text(
                'ยังไม่มีบัญชี? สร้างบัญชีนักเรียนด้วย Google แล้วเข้าห้องนี้ได้เลย',
                style: theme.textTheme.titleSmall,
              ),
              CheckboxListTile.adaptive(
                key: const ValueKey('join_accept_notice'),
                value: _accepted,
                onChanged: _busy
                    ? null
                    : (v) => setState(() => _accepted = v ?? false),
                controlAffinity: ListTileControlAffinity.leading,
                contentPadding: EdgeInsets.zero,
                title: Text(
                  googleSignInNotice,
                  style: theme.textTheme.bodySmall,
                ),
              ),
              OutlinedButton.icon(
                key: const ValueKey('join_google'),
                onPressed: _busy || !_accepted ? null : () => _google(mode),
                icon: _busy
                    ? const SmallSpinner(size: 18)
                    : const Icon(Icons.account_circle_outlined),
                label: const Text('เข้าด้วย Google'),
              ),
            ],
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              key: const ValueKey('join_error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// A signed-in pupil: pick the seat the teacher made, or join under the
/// account's own name.
class _StudentJoin extends ConsumerStatefulWidget {
  const _StudentJoin({required this.code, required this.studentName});

  final String code;
  final String studentName;

  @override
  ConsumerState<_StudentJoin> createState() => _StudentJoinState();
}

class _StudentJoinState extends ConsumerState<_StudentJoin> {
  /// A seat's student id; -1 = the pupil's own name; null = nothing picked.
  int? _choice;
  final _studentCode = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _studentCode.dispose();
    super.dispose();
  }

  Future<void> _join(JoinInfo info) async {
    final choice = _choice;
    if (choice == null) {
      setState(() => _error = 'เลือกชื่อของคุณ หรือเลือก "เข้าด้วยชื่อตัวเอง"');
      return;
    }
    JoinSeat? seat;
    for (final s in info.seats) {
      if (s.studentId == choice) seat = s;
    }
    final code = _studentCode.text.trim();
    if (seat != null && seat.needsCode && code.isEmpty) {
      setState(() => _error = 'กรอกเลขประจำตัวนักเรียนตามที่ครูมี');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(joinRepositoryProvider)
          .join(
            widget.code,
            seatId: seat?.studentId,
            studentCode: seat != null && seat.needsCode ? code : null,
          );
      ref.invalidate(studentOverviewProvider);
      // A seat may have renamed the account (DESIGN §29.13.3).
      await ref.read(sessionProvider.notifier).reloadUser();
      if (!mounted) return;
      showMessage(
        context,
        result.joined
            ? 'เข้าห้อง ${info.name} แล้ว (เลขที่ ${result.studentNumber ?? '-'})'
            : 'คุณอยู่ในห้อง ${info.name} อยู่แล้ว',
      );
      context.go(AppRoutes.student);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = apiErrorMessage(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final info = ref.watch(joinPreviewProvider(widget.code));
    return AsyncView(
      value: info,
      onRetry: () => ref.invalidate(joinPreviewProvider(widget.code)),
      data: (info) {
        if (info.member) {
          return EmptyView(
            key: const ValueKey('join_member'),
            icon: Icons.check_circle_outline,
            title: 'คุณอยู่ในห้อง ${info.name} แล้ว',
            message: 'รายวิชาและงานของห้องอยู่ในหน้าวิชาของฉัน',
            action: FilledButton(
              onPressed: () => context.go(AppRoutes.student),
              child: const Text('ไปหน้าวิชาของฉัน'),
            ),
          );
        }
        if (!info.open) {
          return const EmptyView(
            key: ValueKey('join_closed'),
            icon: Icons.lock_outline,
            title: 'ห้องนี้ปิดรับสมาชิกแล้ว',
            message: 'ติดต่อครูประจำวิชาเพื่อให้เพิ่มชื่อของคุณ',
          );
        }
        JoinSeat? picked;
        for (final s in info.seats) {
          if (s.studentId == _choice) picked = s;
        }
        return FormColumn(
          children: [
            _ClassroomCard(info: info),
            const SizedBox(height: 16),
            Text(
              info.seats.isEmpty ? 'เข้าห้องด้วยชื่อตัวเอง' : 'เลือกชื่อของคุณ',
              style: theme.textTheme.titleMedium,
            ),
            if (info.seats.isNotEmpty)
              Text(
                'ครูเตรียมชื่อไว้แล้ว เลือกชื่อของคุณเพื่อรับเลขที่ ถ้าไม่มี เข้าด้วยชื่อตัวเอง',
                style: theme.textTheme.bodySmall,
              ),
            const SizedBox(height: 8),
            for (final seat in info.seats)
              ListTile(
                key: ValueKey('seat_${seat.studentId}'),
                leading: Icon(
                  _choice == seat.studentId
                      ? Icons.radio_button_checked
                      : Icons.radio_button_off,
                ),
                title: Text(seat.name),
                subtitle: Text(
                  'เลขที่ ${seat.studentNumber}'
                  '${seat.needsCode ? ' · ต้องกรอกเลขประจำตัว' : ''}',
                ),
                selected: _choice == seat.studentId,
                onTap: _busy
                    ? null
                    : () => setState(() => _choice = seat.studentId),
              ),
            ListTile(
              key: const ValueKey('join_own_name'),
              leading: Icon(
                _choice == -1
                    ? Icons.radio_button_checked
                    : Icons.radio_button_off,
              ),
              title: Text(
                info.seats.isEmpty
                    ? widget.studentName
                    : 'ไม่มีชื่อของฉัน เข้าด้วยชื่อตัวเอง',
              ),
              subtitle: info.seats.isEmpty
                  ? const Text('รับเลขที่ถัดไปของห้อง')
                  : Text(widget.studentName),
              selected: _choice == -1,
              onTap: _busy ? null : () => setState(() => _choice = -1),
            ),
            if (picked != null && picked.needsCode) ...[
              const SizedBox(height: 8),
              TextField(
                key: const ValueKey('join_student_code'),
                controller: _studentCode,
                decoration: const InputDecoration(
                  labelText: 'เลขประจำตัวนักเรียน',
                  helperText:
                      'ตามที่ครูกรอกไว้ เพื่อยืนยันว่าเป็นที่นั่งของคุณ',
                ),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                key: const ValueKey('join_error'),
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton.icon(
              key: const ValueKey('join_submit'),
              onPressed: _busy ? null : () => _join(info),
              icon: _busy
                  ? const SmallSpinner(size: 18)
                  : const Icon(Icons.group_add_outlined),
              label: const Text('เข้าห้อง'),
            ),
          ],
        );
      },
    );
  }
}
