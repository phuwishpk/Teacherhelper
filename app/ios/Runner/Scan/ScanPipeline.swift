import Flutter
import Foundation

/// The Pigeon ScanPipelineApi on iOS (DESIGN §6.2, §33). The work is done by
/// EVScanCore (ScanCore.mm, the port of the Android ScanPipelineImpl) on one
/// serial queue, so two pages never hold full-resolution buffers at the
/// same time.
final class ScanPipeline: ScanPipelineApi {
  private let core: EVScanCore
  private let queue = DispatchQueue(label: "eduvision-scan", qos: .userInitiated)

  init() {
    let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
    core = EVScanCore(cacheDirectory: caches.appendingPathComponent("scan_pipeline").path)
    // Crops the Dart side never adopted (app killed between capture and
    // confirm) would otherwise pile up in the cache.
    queue.async { [core] in core.deleteStaleOutputs() }
  }

  func detectPage(imagePath: String) async throws -> PageDetection {
    try await run { core in Self.detection(try core.detectPage(imagePath)) }
  }

  func cropPage(imagePath: String, detection: PageDetection, layoutJson: String) async throws -> PageCrops {
    let corners = Self.corners(detection)
    return try await run { core in
      let crops = try core.cropPage(imagePath, corners: corners, layoutJson: layoutJson)
      return PageCrops(
        warpedPagePath: crops.warpedPagePath,
        regions: crops.regions.map { r in
          RegionCrop(
            regionId: r.regionId,
            imagePath: r.imagePath,
            inkRatio: r.inkRatio,
            bubbleFill: r.bubbleFill?.mapValues { $0.doubleValue },
            cnnInput: r.cnnInput.map { FlutterStandardTypedData(bytes: $0) }
          )
        }
      )
    }
  }

  func readAnswerSheet(imagePath: String, detection: PageDetection, layoutJson: String) async throws -> String {
    let corners = Self.corners(detection)
    return try await run { core in try core.readAnswerSheet(imagePath, corners: corners, layoutJson: layoutJson) }
  }

  func detectFrame(
    yPlane: FlutterStandardTypedData, width: Int64, height: Int64, bytesPerRow: Int64, rotation: Int64
  ) async throws -> FrameDetection {
    let bytes = yPlane.data
    return try await run { core in
      let frame = try core.detectFrame(
        bytes, width: Int(width), height: Int(height), bytesPerRow: Int(bytesPerRow))
      return FrameDetection(
        markersFound: Int64(frame.markersFound), qrPayload: frame.qrPayload, blurScore: frame.blurScore)
    }
  }

  /// Runs [work] on the pipeline queue; core errors keep their Pigeon code.
  private func run<T>(_ work: @escaping (EVScanCore) throws -> T) async throws -> T {
    try await withCheckedThrowingContinuation { continuation in
      queue.async { [core] in
        do {
          continuation.resume(returning: try work(core))
        } catch let error as NSError where error.domain == EVScanErrorDomain {
          continuation.resume(
            throwing: PigeonError(
              code: error.userInfo[EVScanErrorCodeKey] as? String ?? "pipeline_failed",
              message: error.localizedDescription, details: nil))
        } catch {
          continuation.resume(
            throwing: PigeonError(code: "pipeline_failed", message: "\(error)", details: nil))
        }
      }
    }
  }

  private static func detection(_ d: EVPageDetection) -> PageDetection {
    PageDetection(
      qrPayload: d.qrPayload,
      markerCorners: d.markerCorners.map { ($0 as? NSNumber)?.doubleValue },
      missingMarkerIds: d.missingMarkerIds.map { $0.int64Value },
      blurScore: d.blurScore
    )
  }

  private static func corners(_ detection: PageDetection) -> [Any] {
    detection.markerCorners.map { value -> Any in value.map { NSNumber(value: $0) } ?? NSNull() }
  }
}
