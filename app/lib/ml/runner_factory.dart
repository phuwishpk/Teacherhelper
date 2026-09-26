// The TFLite runner needs dart:ffi, which the web preview build does not
// have (`flutter run -d chrome`, CLAUDE.md); there the digit reader is off.
export 'runner_factory_stub.dart'
    if (dart.library.ffi) 'runner_factory_native.dart';
