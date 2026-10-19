import Flutter
import Foundation
import ImageIO
import PDFKit
import UIKit

/// DocumentPageApi on iOS (DESIGN §22.4, §33), the twin of the Android
/// DocumentPageRenderer.kt: a PDF page through PDFKit or a photo (HEIC and
/// the rest) through ImageIO with its EXIF orientation, drawn on white and
/// written as a JPEG (quality 90) in the cache. The server crops the
/// figures from the uploaded page.
final class DocumentPageRenderer: DocumentPageApi {
  private static let outputDir = "document_pages"
  private static let pdf = "application/pdf"
  private static let jpegQuality: CGFloat = 0.9
  private static let minLongSide = 200
  private static let maxLongSide = 4000
  private static let staleSeconds: TimeInterval = 24 * 60 * 60

  func renderDocumentPage(path: String, mimeType: String, pageNo: Int64, maxLongSide: Int64) async throws
    -> String
  {
    try await Task.detached(priority: .userInitiated) {
      try Self.render(path: path, mimeType: mimeType, pageNo: Int(pageNo), maxLongSide: Int(maxLongSide))
    }.value
  }

  private static func render(path: String, mimeType: String, pageNo: Int, maxLongSide: Int) throws -> String {
    let longSide = min(max(maxLongSide, minLongSide), Self.maxLongSide)
    var isDirectory: ObjCBool = false
    guard FileManager.default.fileExists(atPath: path, isDirectory: &isDirectory), !isDirectory.boolValue else {
      throw failure("document_unreadable", "No file at \(path)")
    }
    let url = URL(fileURLWithPath: path)
    let image: UIImage
    if mimeType == pdf {
      image = try renderPdf(url: url, pageNo: pageNo, longSide: longSide)
    } else {
      if pageNo != 1 { throw failure("page_out_of_range", "A photo has one page") }
      image = try decodePhoto(url: url, longSide: longSide)
    }
    return try write(image)
  }

  private static func renderPdf(url: URL, pageNo: Int, longSide: Int) throws -> UIImage {
    guard let document = PDFDocument(url: url) else {
      throw failure("document_unreadable", "Cannot open the file")
    }
    if document.isLocked && !document.unlock(withPassword: "") {
      throw failure("document_unreadable", "The file is password protected")
    }
    guard pageNo >= 1, pageNo <= document.pageCount, let page = document.page(at: pageNo - 1) else {
      throw failure("page_out_of_range", "The file has \(document.pageCount) pages")
    }
    // Page size is in points (1/72 inch); scale the long side.
    var bounds = page.bounds(for: .mediaBox).size
    if page.rotation % 180 != 0 { bounds = CGSize(width: bounds.height, height: bounds.width) }
    let scale = CGFloat(longSide) / max(bounds.width, bounds.height)
    let size = CGSize(
      width: max(1, (bounds.width * scale).rounded()), height: max(1, (bounds.height * scale).rounded()))
    let thumbnail = page.thumbnail(of: size, for: .mediaBox)
    return onWhite(thumbnail, size: size)
  }

  private static func decodePhoto(url: URL, longSide: Int) throws -> UIImage {
    guard let source = CGImageSourceCreateWithURL(url as CFURL, nil) else {
      throw failure("document_unreadable", "Cannot decode the photo")
    }
    let options: [CFString: Any] = [
      kCGImageSourceCreateThumbnailFromImageAlways: true,
      // Applies the EXIF orientation, like ImageDecoder on Android.
      kCGImageSourceCreateThumbnailWithTransform: true,
      kCGImageSourceThumbnailMaxPixelSize: longSide,
    ]
    guard let cgImage = CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary) else {
      throw failure("document_unreadable", "Cannot decode the photo")
    }
    let image = UIImage(cgImage: cgImage)
    return onWhite(image, size: CGSize(width: cgImage.width, height: cgImage.height))
  }

  /// Transparent pixels (PNG, WebP) would turn black in a JPEG.
  private static func onWhite(_ image: UIImage, size: CGSize) -> UIImage {
    let format = UIGraphicsImageRendererFormat()
    format.scale = 1
    format.opaque = true
    return UIGraphicsImageRenderer(size: size, format: format).image { context in
      UIColor.white.setFill()
      context.fill(CGRect(origin: .zero, size: size))
      image.draw(in: CGRect(origin: .zero, size: size))
    }
  }

  private static func write(_ image: UIImage) throws -> String {
    let fm = FileManager.default
    let root = fm.urls(for: .cachesDirectory, in: .userDomainMask)[0].appendingPathComponent(outputDir)
    try? fm.createDirectory(at: root, withIntermediateDirectories: true)
    let cutoff = Date().addingTimeInterval(-staleSeconds)
    for file in (try? fm.contentsOfDirectory(at: root, includingPropertiesForKeys: [.contentModificationDateKey])) ?? [] {
      let modified = try? file.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate
      if let modified, modified < cutoff { try? fm.removeItem(at: file) }
    }
    let file = root.appendingPathComponent("page-\(UUID().uuidString).jpg")
    guard let data = image.jpegData(compressionQuality: jpegQuality) else {
      throw failure("storage_failed", "Cannot encode \(file.lastPathComponent)")
    }
    do {
      try data.write(to: file)
    } catch {
      throw failure("storage_failed", "Cannot write \(file.lastPathComponent)")
    }
    return file.path
  }

  private static func failure(_ code: String, _ message: String) -> PigeonError {
    PigeonError(code: code, message: message, details: nil)
  }
}
