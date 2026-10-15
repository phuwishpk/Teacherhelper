import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Push notifications (DESIGN §9.9): the Firebase config is a private file
// (gitignored, never committed). Without it the plugin is skipped, Firebase
// is not initialised at runtime and the app simply runs without FCM.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

// Release signing: the project's own keystore, described by
// app/android/key.properties (storeFile, storePassword, keyAlias,
// keyPassword; gitignored like the keystore itself). Without that file the
// release build falls back to the debug key so `flutter run --release` and
// CI still work, but such an APK cannot be installed over one signed with
// the project key.
val releaseKeyProperties = Properties().apply {
    val f = rootProject.file("key.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
val hasReleaseKey = releaseKeyProperties.getProperty("storeFile") != null

android {
    namespace = "com.eduvision.eduvision"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.eduvision.app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = 26
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    packaging {
        jniLibs {
            // OpenCV ships 32-bit x86 libraries; Flutter builds no x86 engine.
            excludes += "lib/x86/**"
            // The digit reader runs on the CPU (a 1.3 MB CRNN); the LiteRT GPU
            // delegate that tflite_flutter pulls in is never loaded.
            excludes += "**/libtensorflowlite_gpu_jni.so"
        }
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }

    signingConfigs {
        if (hasReleaseKey) {
            create("release") {
                storeFile = rootProject.file(releaseKeyProperties.getProperty("storeFile"))
                storePassword = releaseKeyProperties.getProperty("storePassword")
                keyAlias = releaseKeyProperties.getProperty("keyAlias")
                keyPassword = releaseKeyProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName(if (hasReleaseKey) "release" else "debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    // Native scan pipeline (DESIGN §6.2). OpenCV 4.x from Maven Central
    // includes the objdetect ArUco detector.
    implementation("org.opencv:opencv:4.14.0")
    // Same bundled ML Kit barcode model mobile_scanner already pulls in.
    implementation("com.google.mlkit:barcode-scanning:17.3.0")
    // Pigeon generates suspend host API methods.
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.11.0")

    testImplementation("junit:junit:4.13.2")
}
