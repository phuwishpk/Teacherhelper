# Picked up by the Flutter Gradle plugin for release builds (R8 is on).
# OpenCV's JNI code looks up Java classes, fields (Mat.nativeObj) and
# constructors by name; the AAR ships no consumer rules, so keep it whole.
-keep class org.opencv.** { *; }
-dontwarn org.opencv.**
