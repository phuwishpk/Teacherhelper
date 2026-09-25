package com.eduvision.eduvision.scan

import android.content.Context
import android.graphics.Bitmap
import android.os.Build
import android.util.Log
import com.google.android.gms.tasks.Tasks
import com.google.mlkit.vision.barcode.BarcodeScanner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import kotlinx.coroutines.ExecutorCoroutineDispatcher
import kotlinx.coroutines.asCoroutineDispatcher
import kotlinx.coroutines.withContext
import org.opencv.android.OpenCVLoader
import org.opencv.android.Utils
import org.opencv.core.Core
import org.opencv.core.CvException
import org.opencv.core.CvType
import org.opencv.core.Mat
import org.opencv.core.MatOfDouble
import org.opencv.core.MatOfFloat
import org.opencv.core.MatOfInt
import org.opencv.core.MatOfPoint2f
import org.opencv.core.Point
import org.opencv.core.Rect
import org.opencv.core.Scalar
import org.opencv.core.Size
import org.opencv.imgcodecs.Imgcodecs
import org.opencv.imgproc.Imgproc
import org.opencv.objdetect.ArucoDetector
import org.opencv.objdetect.DetectorParameters
import org.opencv.objdetect.Objdetect
import java.io.File
import java.io.FileOutputStream
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Native scan pipeline (DESIGN §6.2): OpenCV ArUco + perspective warp +
 * crops, ML Kit for the QR. All work runs on one background thread so two
 * pages never hold full-resolution buffers at the same time.
 */
class ScanPipelineImpl(context: Context) : ScanPipelineApi, AutoCloseable {
    private val cacheRoot = File(context.cacheDir, OUTPUT_DIR)
    private val executor = Executors.newSingleThreadExecutor { r -> Thread(r, "eduvision-scan") }
    private val dispatcher: ExecutorCoroutineDispatcher = executor.asCoroutineDispatcher()

    // Only touched on the pipeline thread.
    private var openCvReady = false
    private var detector: ArucoDetector? = null
    private var scanner: BarcodeScanner? = null

    init {
        // Crops the Dart side never adopted (app killed between capture and
        // confirm) would otherwise pile up in the cache.
        executor.execute { deleteStaleOutputs() }
    }

    override suspend fun detectPage(imagePath: String): PageDetection = withContext(dispatcher) {
        guarded { MatScope().use { s -> detect(s, imagePath) } }
    }

    override suspend fun cropPage(
        imagePath: String,
        detection: PageDetection,
        layoutJson: String,
    ): PageCrops = withContext(dispatcher) {
        guarded {
            val layout = LayoutPage.parse(layoutJson)
            MatScope().use { s -> crop(s, imagePath, detection, layout) }
        }
    }

    override fun close() {
        executor.execute {
            scanner?.close()
            scanner = null
        }
        // Lets queued work finish, then stops the thread.
        dispatcher.close()
    }

    // ---------------------------------------------------------------- detect

    private fun detect(s: MatScope, imagePath: String): PageDetection {
        val color = readImage(s, imagePath)
        val gray = s.track(Mat())
        Imgproc.cvtColor(color, gray, Imgproc.COLOR_BGR2GRAY)

        // Markers are 12 mm wide, so a downscaled copy is enough and much
        // faster; fall back to full resolution when the page is small in
        // the photo (DESIGN §6.2 step 1).
        val scale = min(1.0, DETECT_LONG_SIDE.toDouble() / max(gray.cols(), gray.rows()))
        val small = if (scale < 1.0) resize(s, gray, scale) else gray
        var found = findMarkers(s, small, scale)
        if (found.size < 4 && scale < 1.0) {
            val full = findMarkers(s, gray, 1.0)
            if (full.size > found.size) found = full
        }

        val corners = MutableList<Double?>(8) { null }
        for ((id, p) in found) {
            corners[2 * id] = p.x
            corners[2 * id + 1] = p.y
        }
        var missing = (0..3).filter { it !in found }

        var warpedGray: Mat? = null
        if (missing.isEmpty()) {
            val pts = DoubleArray(8) { corners[it]!! }
            if (RegionMath.isClockwiseConvex(pts)) {
                val size = RegionMath.warpedSize(RegionMath.DEFAULT_FRAME_W_MM, RegionMath.DEFAULT_FRAME_H_MM)
                warpedGray = warp(s, gray, pts, size)
            } else {
                // Four ids but not in page order (two sheets in the photo, or
                // a false detection): nothing trustworthy to warp with.
                Log.w(TAG, "marker centres are not a clockwise convex quad")
                missing = listOf(0, 1, 2, 3)
                for (i in corners.indices) corners[i] = null
            }
        }

        // Blur on the warped frame keeps the score independent of how far
        // away the phone was (DESIGN §6.2 step 4).
        val blur = laplacianVariance(s, warpedGray ?: small)

        var qr = readQr(s, color, QR_LONG_SIDE)
        if (qr == null && warpedGray != null) qr = readQr(s, warpedGray, Int.MAX_VALUE)
        if (qr == null && warpedGray == null && scale < 1.0) qr = readQr(s, gray, Int.MAX_VALUE)

        return PageDetection(
            qrPayload = qr,
            markerCorners = corners,
            missingMarkerIds = missing.map { it.toLong() },
            blurScore = blur,
        )
    }

    /** Marker id (0..3) -> centre in full-resolution pixels. */
    private fun findMarkers(s: MatScope, image: Mat, scale: Double): Map<Int, Point> {
        val corners = ArrayList<Mat>()
        val ids = s.track(Mat())
        arucoDetector().detectMarkers(image, corners, ids)
        corners.forEach { s.track(it) }

        val best = HashMap<Int, Pair<Double, Point>>()
        val c = FloatArray(8)
        for (i in 0 until ids.rows()) {
            val id = ids.get(i, 0)[0].toInt()
            if (id !in 0..3) continue
            corners[i].get(0, 0, c)
            var cx = 0.0
            var cy = 0.0
            var area2 = 0.0
            for (k in 0 until 4) {
                val x = c[2 * k].toDouble()
                val y = c[2 * k + 1].toDouble()
                val nx = c[2 * ((k + 1) % 4)].toDouble()
                val ny = c[2 * ((k + 1) % 4) + 1].toDouble()
                cx += x
                cy += y
                area2 += x * ny - nx * y
            }
            val area = abs(area2) / 2
            // The same id twice (two sheets in view): keep the larger one.
            if (area > (best[id]?.first ?: -1.0)) {
                best[id] = area to Point(cx / 4 / scale, cy / 4 / scale)
            }
        }
        return best.mapValues { it.value.second }
    }

    private fun arucoDetector(): ArucoDetector = detector ?: ArucoDetector(
        Objdetect.getPredefinedDictionary(Objdetect.DICT_4X4_50),
        DetectorParameters().apply { set_cornerRefinementMethod(Objdetect.CORNER_REFINE_SUBPIX) },
    ).also { detector = it }

    private fun laplacianVariance(s: MatScope, gray: Mat): Double {
        val lap = s.track(Mat())
        Imgproc.Laplacian(gray, lap, CvType.CV_64F)
        val mean = s.track(MatOfDouble())
        val std = s.track(MatOfDouble())
        Core.meanStdDev(lap, mean, std)
        val sd = std.get(0, 0)[0]
        return sd * sd
    }

    /** ML Kit QR reader; prefers a worksheet payload when several QRs are visible. */
    private fun readQr(s: MatScope, image: Mat, longSide: Int): String? {
        val size = RegionMath.scaleToLongSide(image.cols(), image.rows(), longSide)
        val src = if (size.width != image.cols()) {
            val m = s.track(Mat())
            Imgproc.resize(image, m, Size(size.width.toDouble(), size.height.toDouble()), 0.0, 0.0, Imgproc.INTER_AREA)
            m
        } else {
            image
        }
        val bitmap = toBitmap(s, src)
        return try {
            val codes = Tasks.await(
                barcodeScanner().process(InputImage.fromBitmap(bitmap, 0)),
                QR_TIMEOUT_SECONDS,
                TimeUnit.SECONDS,
            )
            val values = codes.mapNotNull { it.rawValue }
            values.firstOrNull { it.startsWith("EV1.") } ?: values.firstOrNull()
        } catch (e: Exception) {
            Log.w(TAG, "QR reading failed", e)
            null
        } finally {
            bitmap.recycle()
        }
    }

    // ------------------------------------------------------------------ crop

    private fun crop(s: MatScope, imagePath: String, detection: PageDetection, layout: LayoutPage): PageCrops {
        val corners = detection.markerCorners
        if (corners.size != 8 || corners.any { it == null }) {
            throw FlutterError("markers_missing", "All four corner markers are needed to crop the page")
        }
        val pts = DoubleArray(8) { corners[it]!! }
        val color = readImage(s, imagePath)
        val size = RegionMath.warpedSize(layout.frameWmm, layout.frameHmm)
        val warped = warp(s, color, pts, size)
        val gray = s.track(Mat())
        Imgproc.cvtColor(warped, gray, Imgproc.COLOR_BGR2GRAY)
        val w = size.width
        val h = size.height

        val outDir = File(cacheRoot, UUID.randomUUID().toString())
        if (!outDir.mkdirs()) throw FlutterError("storage_failed", "Cannot create ${outDir.path}")

        val pageSize = RegionMath.scaleToLongSide(w, h, RegionMath.WARPED_PAGE_LONG_SIDE)
        val page = s.track(Mat())
        Imgproc.resize(warped, page, Size(pageSize.width.toDouble(), pageSize.height.toDouble()), 0.0, 0.0, Imgproc.INTER_AREA)
        val pageFile = File(outDir, "page.webp")
        writeWebp(s, page, pageFile)

        val crops = ArrayList<RegionCrop>()
        for (region in layout.regions) {
            val rect = RegionMath.cropRect(region.rect, w, h)
            if (rect.isEmpty) {
                throw FlutterError("layout_invalid", "Region ${region.regionId} lies outside the marker frame")
            }
            val file = File(outDir, "${safeName(region.regionId)}.webp")
            writeWebp(s, sub(s, warped, rect), file)
            when (region.kind) {
                LayoutPage.KIND_MCQ -> crops += RegionCrop(
                    regionId = region.regionId,
                    imagePath = file.path,
                    inkRatio = 0.0,
                    bubbleFill = bubbleFill(s, gray, region, rect),
                )
                LayoutPage.KIND_BOX -> crops += RegionCrop(
                    regionId = region.regionId,
                    imagePath = file.path,
                    inkRatio = inkRatio(s, gray, region.rect, rect),
                    cnnInput = if (region.numeric) cnnInput(s, gray, region.rect) else null,
                )
                else -> {
                    crops += RegionCrop(
                        regionId = region.regionId,
                        imagePath = file.path,
                        inkRatio = inkRatio(s, gray, region.rect, rect),
                    )
                    region.finalAnswer?.let { fa ->
                        val finalRect = RegionMath.cropRect(fa.rect, w, h)
                        if (finalRect.isEmpty) {
                            throw FlutterError("layout_invalid", "Final answer of ${region.regionId} lies outside the marker frame")
                        }
                        val id = region.regionId + LayoutPage.FINAL_SUFFIX
                        val finalFile = File(outDir, "${safeName(id)}.webp")
                        writeWebp(s, sub(s, warped, finalRect), finalFile)
                        crops += RegionCrop(
                            regionId = id,
                            imagePath = finalFile.path,
                            inkRatio = inkRatio(s, gray, fa.rect, finalRect),
                            cnnInput = if (fa.numeric) cnnInput(s, gray, fa.rect) else null,
                        )
                    }
                }
            }
        }
        return PageCrops(warpedPagePath = pageFile.path, regions = crops)
    }

    /** Otsu + dark ratio inside 70% of each bubble's radius (DESIGN §6.2 step 6). */
    private fun bubbleFill(s: MatScope, gray: Mat, region: LayoutRegion, rect: PixelRect): Map<String, Double> {
        // The printed circles and letters make the region bimodal, so Otsu
        // over the whole row finds the ink/paper split.
        val threshold = inkThreshold(s, sub(s, gray, rect))
        val fill = LinkedHashMap<String, Double>()
        for (b in region.bubbles) {
            val circle = RegionMath.bubbleCircle(b.cx, b.cy, b.r, gray.cols(), gray.rows())
            val inner = PixelCircle(circle.cx, circle.cy, circle.r * RegionMath.INNER_BUBBLE)
            val x0 = floor(inner.cx - inner.r).toInt().coerceIn(0, gray.cols())
            val x1 = ceil(inner.cx + inner.r).toInt().coerceIn(0, gray.cols())
            val y0 = floor(inner.cy - inner.r).toInt().coerceIn(0, gray.rows())
            val y1 = ceil(inner.cy + inner.r).toInt().coerceIn(0, gray.rows())
            val box = PixelRect(x0, y0, max(x0, x1), max(y0, y1))
            fill[b.option] = if (box.isEmpty) {
                0.0
            } else {
                val bytes = bytesOf(s, sub(s, gray, box))
                CircleFill.darkShare(bytes, box.width, box.height, x0, y0, inner, threshold)
            }
        }
        return fill
    }

    /**
     * Handwriting share inside [norm]: the rect without its printed border,
     * thresholded with the split found on the wider crop [marginRect], and
     * with long straight printed lines (ruling, box edges) removed.
     */
    private fun inkRatio(s: MatScope, gray: Mat, norm: NormRect, marginRect: PixelRect): Double {
        val threshold = inkThreshold(s, sub(s, gray, marginRect))
        val inner = RegionMath.inset(RegionMath.cropRect(norm, gray.cols(), gray.rows(), 0.0), borderInsetPx)
        if (inner.isEmpty) return 0.0
        val bin = binarize(s, sub(s, gray, inner), threshold)
        val ink = withoutRuledLines(s, bin)
        return Core.countNonZero(ink).toDouble() / (inner.width.toDouble() * inner.height)
    }

    /**
     * 32 x 128 grayscale bytes for the digit reader, following
     * ml/train/preprocess.py: printed lines painted as paper, tight crop to
     * the ink (+10% of its height), resize to 32 rows keeping the aspect
     * ratio (squash if wider than 128), pad right with paper.
     */
    private fun cnnInput(s: MatScope, gray: Mat, norm: NormRect): ByteArray? {
        val box = RegionMath.inset(RegionMath.cropRect(norm, gray.cols(), gray.rows(), 0.0), borderInsetPx)
        if (box.isEmpty) return null
        val patch = s.track(sub(s, gray, box).clone())

        val lines = ruledLines(s, binarize(s, patch, inkThreshold(s, patch)))
        patch.setTo(Scalar(PAPER), lines)

        val ink = binarize(s, patch, inkThreshold(s, patch))
        val inkPixels = Core.countNonZero(ink)
        var strip = patch
        if (inkPixels > 0 && inkPixels * 2 <= patch.rows() * patch.cols()) {
            val points = s.track(Mat())
            Core.findNonZero(ink, points)
            val r = Imgproc.boundingRect(points)
            val tight = RegionMath.tightBox(
                r.x, r.y, r.x + r.width - 1, r.y + r.height - 1,
                patch.cols(), patch.rows(),
            )
            if (tight != null && !tight.isEmpty) strip = sub(s, patch, tight)
        }

        val stripW = RegionMath.cnnStripWidth(strip.cols(), strip.rows())
        val canvas = s.track(Mat(RegionMath.CNN_HEIGHT, RegionMath.CNN_WIDTH, CvType.CV_8UC1, Scalar(PAPER)))
        if (stripW > 0) {
            val resized = s.track(Mat())
            val interpolation = if (strip.rows() > RegionMath.CNN_HEIGHT) Imgproc.INTER_AREA else Imgproc.INTER_LINEAR
            Imgproc.resize(strip, resized, Size(stripW.toDouble(), RegionMath.CNN_HEIGHT.toDouble()), 0.0, 0.0, interpolation)
            resized.copyTo(s.track(canvas.submat(0, RegionMath.CNN_HEIGHT, 0, stripW)))
        }
        val out = ByteArray(RegionMath.CNN_HEIGHT * RegionMath.CNN_WIDTH)
        canvas.get(0, 0, out)
        return out
    }

    // --------------------------------------------------------------- helpers

    private val borderInsetPx = RegionMath.mmToPx(RegionMath.BORDER_INSET_MM).roundToInt()

    /** Otsu split of [gray], never closer than 25 levels to the paper. */
    private fun inkThreshold(s: MatScope, gray: Mat): Double {
        val tmp = s.track(Mat())
        val otsu = Imgproc.threshold(gray, tmp, 0.0, 255.0, Imgproc.THRESH_BINARY_INV or Imgproc.THRESH_OTSU)
        val hist = s.track(Mat())
        Imgproc.calcHist(
            listOf(gray),
            s.track(MatOfInt(0)),
            s.track(Mat()),
            hist,
            s.track(MatOfInt(256)),
            s.track(MatOfFloat(0f, 256f)),
        )
        val counts = FloatArray(256)
        hist.get(0, 0, counts)
        val paper = RegionMath.percentile(IntArray(256) { counts[it].toInt() }, PAPER_PERCENTILE)
        return RegionMath.inkThreshold(otsu, paper.toDouble())
    }

    /** 255 where [gray] <= [threshold] (ink), 0 elsewhere. */
    private fun binarize(s: MatScope, gray: Mat, threshold: Double): Mat {
        val bin = s.track(Mat())
        Imgproc.threshold(gray, bin, threshold, 255.0, Imgproc.THRESH_BINARY_INV)
        return bin
    }

    /**
     * Mask of long horizontal (>= 1/4 of the width) and near full-height
     * vertical printed lines, slightly dilated to swallow anti-aliasing.
     */
    private fun ruledLines(s: MatScope, bin: Mat): Mat {
        val hLen = max(15, bin.cols() / 4).toDouble()
        val vLen = max(15, (bin.rows() * 0.9).toInt()).toDouble()
        val horizontal = s.track(Mat())
        val vertical = s.track(Mat())
        Imgproc.morphologyEx(bin, horizontal, Imgproc.MORPH_OPEN, s.track(Imgproc.getStructuringElement(Imgproc.MORPH_RECT, Size(hLen, 1.0))))
        Imgproc.morphologyEx(bin, vertical, Imgproc.MORPH_OPEN, s.track(Imgproc.getStructuringElement(Imgproc.MORPH_RECT, Size(1.0, vLen))))
        val lines = s.track(Mat())
        Core.bitwise_or(horizontal, vertical, lines)
        Imgproc.dilate(lines, lines, s.track(Imgproc.getStructuringElement(Imgproc.MORPH_RECT, Size(3.0, 3.0))))
        return lines
    }

    private fun withoutRuledLines(s: MatScope, bin: Mat): Mat {
        val out = s.track(Mat())
        Core.subtract(bin, ruledLines(s, bin), out)
        return out
    }

    private fun warp(s: MatScope, src: Mat, pts: DoubleArray, size: PixelSize): Mat {
        val from = s.track(MatOfPoint2f(
            Point(pts[0], pts[1]), Point(pts[2], pts[3]), Point(pts[4], pts[5]), Point(pts[6], pts[7]),
        ))
        val w = size.width.toDouble()
        val h = size.height.toDouble()
        val to = s.track(MatOfPoint2f(Point(0.0, 0.0), Point(w, 0.0), Point(w, h), Point(0.0, h)))
        val m = s.track(Imgproc.getPerspectiveTransform(from, to))
        val out = s.track(Mat())
        Imgproc.warpPerspective(src, out, m, Size(w, h), Imgproc.INTER_LINEAR, Core.BORDER_REPLICATE)
        return out
    }

    private fun resize(s: MatScope, src: Mat, scale: Double): Mat {
        val out = s.track(Mat())
        Imgproc.resize(src, out, Size(), scale, scale, Imgproc.INTER_AREA)
        return out
    }

    private fun readImage(s: MatScope, path: String): Mat {
        // IMREAD_COLOR applies the EXIF orientation of camera JPEGs.
        val m = s.track(Imgcodecs.imread(path, Imgcodecs.IMREAD_COLOR))
        if (m.empty()) throw FlutterError("image_unreadable", "Cannot decode image $path")
        return m
    }

    private fun sub(s: MatScope, m: Mat, r: PixelRect): Mat =
        s.track(m.submat(Rect(r.left, r.top, r.width, r.height)))

    private fun bytesOf(s: MatScope, m: Mat): ByteArray {
        val c = if (m.isContinuous) m else s.track(m.clone())
        val out = ByteArray((c.total() * c.channels()).toInt())
        c.get(0, 0, out)
        return out
    }

    private fun toBitmap(s: MatScope, m: Mat): Bitmap {
        val rgba = s.track(Mat())
        val code = if (m.channels() == 1) Imgproc.COLOR_GRAY2RGBA else Imgproc.COLOR_BGR2RGBA
        Imgproc.cvtColor(m, rgba, code)
        val bitmap = Bitmap.createBitmap(rgba.cols(), rgba.rows(), Bitmap.Config.ARGB_8888)
        Utils.matToBitmap(rgba, bitmap)
        return bitmap
    }

    private fun writeWebp(s: MatScope, m: Mat, file: File) {
        val bitmap = toBitmap(s, m)
        try {
            val format = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                Bitmap.CompressFormat.WEBP_LOSSY
            } else {
                @Suppress("DEPRECATION")
                Bitmap.CompressFormat.WEBP
            }
            FileOutputStream(file).use { out ->
                if (!bitmap.compress(format, WEBP_QUALITY, out)) {
                    throw FlutterError("storage_failed", "Cannot encode ${file.name}")
                }
            }
        } finally {
            bitmap.recycle()
        }
    }

    private fun ensureOpenCv() {
        if (openCvReady) return
        if (!OpenCVLoader.initLocal()) {
            throw FlutterError("opencv_unavailable", "OpenCV native library failed to load")
        }
        openCvReady = true
    }

    /** Runs [block] with OpenCV loaded and maps native failures to FlutterError. */
    private inline fun <T> guarded(block: () -> T): T {
        ensureOpenCv()
        return try {
            block()
        } catch (e: FlutterError) {
            throw e
        } catch (e: CvException) {
            Log.e(TAG, "OpenCV failure", e)
            throw FlutterError("pipeline_failed", e.message ?: "OpenCV error")
        } catch (e: OutOfMemoryError) {
            throw FlutterError("pipeline_failed", "Not enough memory for this photo")
        }
    }

    private fun barcodeScanner(): BarcodeScanner = scanner ?: BarcodeScanning.getClient(
        BarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_QR_CODE).build(),
    ).also { scanner = it }

    private fun deleteStaleOutputs() {
        val cutoff = System.currentTimeMillis() - STALE_OUTPUT_MS
        cacheRoot.listFiles()?.forEach { dir ->
            if (dir.lastModified() < cutoff) dir.deleteRecursively()
        }
    }

    private fun safeName(id: String): String = id.replace(Regex("[^A-Za-z0-9_-]"), "_")

    companion object {
        private const val TAG = "ScanPipeline"
        private const val OUTPUT_DIR = "scan_pipeline"
        private const val DETECT_LONG_SIDE = 1600
        private const val QR_LONG_SIDE = 2000
        private const val QR_TIMEOUT_SECONDS = 15L
        private const val WEBP_QUALITY = 80
        private const val PAPER = 255.0
        private const val PAPER_PERCENTILE = 0.9
        private const val STALE_OUTPUT_MS = 24L * 60 * 60 * 1000
    }
}

/** Releases every Mat created while handling one call. */
class MatScope : AutoCloseable {
    private val mats = ArrayList<Mat>()

    fun <T : Mat> track(m: T): T {
        mats += m
        return m
    }

    override fun close() {
        mats.forEach { it.release() }
        mats.clear()
    }
}
