import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  // The native scan pipeline and document pages (DESIGN §6.2, §22.4, §33).
  private let scanPipeline = ScanPipeline()
  private let documentPages = DocumentPageRenderer()

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "EduvisionScan") {
      ScanPipelineApiSetup.setUp(binaryMessenger: registrar.messenger(), api: scanPipeline)
      DocumentPageApiSetup.setUp(binaryMessenger: registrar.messenger(), api: documentPages)
    }
  }
}
