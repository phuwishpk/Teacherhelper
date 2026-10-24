import 'dart:js_interop';

@JS('navigator.maxTouchPoints')
external int get _maxTouchPoints;

/// Whether the browser's device has a touch screen: a phone, a tablet (an
/// iPad in desktop mode too, which reports macOS) or a touch-screen laptop.
bool get hasTouchScreen => _maxTouchPoints > 0;
