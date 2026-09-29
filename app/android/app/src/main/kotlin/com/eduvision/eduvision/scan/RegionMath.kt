package com.eduvision.eduvision.scan

import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.min
import kotlin.math.round

/** Half-open pixel rectangle: columns [left, right), rows [top, bottom). */
data class PixelRect(val left: Int, val top: Int, val right: Int, val bottom: Int) {
    val width: Int get() = right - left
    val height: Int get() = bottom - top
    val isEmpty: Boolean get() = width <= 0 || height <= 0
}

/** Frame-relative rectangle of the layout JSON (0..1, DESIGN §5.3). */
data class NormRect(val x: Double, val y: Double, val w: Double, val h: Double)

data class PixelCircle(val cx: Double, val cy: Double, val r: Double)

data class PixelSize(val width: Int, val height: Int)

/**
 * Geometry shared by detection and cropping. Pure Kotlin (no Android or
 * OpenCV types) so it runs in JVM unit tests. The Dart twin is
 * lib/features/scan/page_layout.dart; both must stay in step.
 */
object RegionMath {
    /** Resolution of the warped marker frame (DESIGN §6.2 step 3). */
    const val DPI = 200.0
    const val MM_PER_INCH = 25.4

    /** Crops grow by 2% of the frame on every side (DESIGN §5.3). */
    const val CROP_MARGIN = 0.02

    /** Long side of the uploaded page image (DESIGN §6.2 `warpedPagePath`). */
    const val WARPED_PAGE_LONG_SIDE = 1600

    /**
     * Long side of an uploaded crop (DESIGN §21.9). Only the bytes shrink:
     * Gemini bills an image by its media resolution level, not its size,
     * and `ink_ratio`, bubble fill and the CNN input are measured on the
     * full 200 DPI frame before the crop is scaled down.
     */
    const val CROP_UPLOAD_LONG_SIDE = 768

    /** Bubble fill is measured inside this share of the radius (§6.2 step 6). */
    const val INNER_BUBBLE = 0.7

    /** Printed borders are cut off before measuring ink (about 1.2 mm). */
    const val BORDER_INSET_MM = 1.2

    /** Never inset more than this share of a side. */
    const val MAX_INSET_SHARE = 0.15

    /**
     * Width of the printed line-number gutter on the left of a numbered
     * `lines` region (show_work; WorksheetPdfRenderer::drawLines starts the
     * rules 8 mm in). Ink is not measured there.
     */
    const val NUMBER_GUTTER_MM = 8.0

    /**
     * `ink_ratio` is the share of the answer area within this distance of a
     * handwritten stroke (DESIGN §9.4, §11.8), so a single digit in a 70 x
     * 16 mm box reads about 0.05 and a blank area 0.
     */
    const val INK_REACH_MM = 2.0

    /** Ink blobs smaller than this (dust, JPEG noise) are not handwriting. */
    const val MIN_SPECK_MM2 = 0.25

    /**
     * Printed horizontal rules are found as runs of at least a quarter of the
     * width, but never longer than this, so rules that curve with the paper
     * are still found in wide regions.
     */
    const val MAX_RULE_RUN_MM = 20.0

    /** Default frame of the A4 worksheet (WorksheetGeometry on the server). */
    const val DEFAULT_FRAME_W_MM = 178.0
    const val DEFAULT_FRAME_H_MM = 265.0

    /** CNN input canvas (ml/train/preprocess.py). */
    const val CNN_HEIGHT = 32
    const val CNN_WIDTH = 128

    /** Pixel size of a [frameWmm] x [frameHmm] frame at [dpi]. */
    fun warpedSize(frameWmm: Double, frameHmm: Double, dpi: Double = DPI): PixelSize {
        require(frameWmm > 0 && frameHmm > 0) { "frame must be positive" }
        return PixelSize(
            round(frameWmm / MM_PER_INCH * dpi).toInt(),
            round(frameHmm / MM_PER_INCH * dpi).toInt(),
        )
    }

    fun mmToPx(mm: Double, dpi: Double = DPI): Double = mm / MM_PER_INCH * dpi

    /**
     * Crop rectangle of [rect] on a warped frame of [width] x [height] px,
     * grown by [margin] (a share of the frame) and clamped to the frame.
     */
    fun cropRect(rect: NormRect, width: Int, height: Int, margin: Double = CROP_MARGIN): PixelRect {
        val left = floor((rect.x - margin) * width).toInt().coerceIn(0, width)
        val top = floor((rect.y - margin) * height).toInt().coerceIn(0, height)
        val right = ceil((rect.x + rect.w + margin) * width).toInt().coerceIn(0, width)
        val bottom = ceil((rect.y + rect.h + margin) * height).toInt().coerceIn(0, height)
        return PixelRect(left, top, max(left, right), max(top, bottom))
    }

    /** [rect] shrunk by [insetPx] on every side, at most [MAX_INSET_SHARE] of a side. */
    fun inset(rect: PixelRect, insetPx: Int): PixelRect {
        val dx = min(insetPx, (rect.width * MAX_INSET_SHARE).toInt())
        val dy = min(insetPx, (rect.height * MAX_INSET_SHARE).toInt())
        return PixelRect(rect.left + dx, rect.top + dy, rect.right - dx, rect.bottom - dy)
    }

    /**
     * Where ink is measured: [rect] without its printed border ([insetPx])
     * and, when [gutterPx] > 0, without that many pixels from its left edge
     * (the line-number gutter). Empty when nothing is left.
     */
    fun inkRect(rect: NormRect, width: Int, height: Int, insetPx: Int, gutterPx: Int = 0): PixelRect {
        val outer = cropRect(rect, width, height, 0.0)
        val inner = inset(outer, insetPx)
        if (gutterPx <= 0) return inner
        val left = min(inner.right, max(inner.left, outer.left + gutterPx))
        return inner.copy(left = left)
    }

    /** Minimum run length of a printed horizontal rule in a [width] px wide patch. */
    fun ruleRunLength(width: Int, maxRunPx: Int): Int = max(15, min(width / 4, maxRunPx))

    /** Area in px of [mm2] square millimetres at [dpi]. */
    fun mm2ToPx(mm2: Double, dpi: Double = DPI): Double {
        val side = mmToPx(1.0, dpi)
        return mm2 * side * side
    }

    /**
     * Bubble centre and radius in warped pixels. The radius is normalised by
     * the frame WIDTH on the server (WorksheetGeometry::nr).
     */
    fun bubbleCircle(cx: Double, cy: Double, r: Double, width: Int, height: Int): PixelCircle =
        PixelCircle(cx * width, cy * height, r * width)

    /** Size that keeps the aspect ratio with the long side at most [longSide]. */
    fun scaleToLongSide(width: Int, height: Int, longSide: Int): PixelSize {
        val long = max(width, height)
        if (long <= longSide) return PixelSize(width, height)
        val s = longSide.toDouble() / long
        return PixelSize(max(1, round(width * s).toInt()), max(1, round(height * s).toInt()))
    }

    /** Size of a [width] x [height] crop as it is written for upload. */
    fun uploadCropSize(width: Int, height: Int): PixelSize =
        scaleToLongSide(width, height, CROP_UPLOAD_LONG_SIDE)

    /**
     * Width of a [width] x [height] ink strip resized to [CNN_HEIGHT] rows,
     * squashed to [CNN_WIDTH] when wider (fit_to_canvas in preprocess.py).
     */
    fun cnnStripWidth(width: Int, height: Int): Int {
        if (width <= 0 || height <= 0) return 0
        val w = max(1, round(width.toDouble() * CNN_HEIGHT / height).toInt())
        return min(w, CNN_WIDTH)
    }

    /**
     * Ink bounding box plus a margin of [marginShare] x its height, clamped
     * to [width] x [height] (tight_crop in preprocess.py). Null when there is
     * no ink.
     */
    fun tightBox(
        inkLeft: Int, inkTop: Int, inkRight: Int, inkBottom: Int,
        width: Int, height: Int, marginShare: Double = 0.10,
    ): PixelRect? {
        if (inkRight < inkLeft || inkBottom < inkTop) return null
        val pad = round(marginShare * (inkBottom - inkTop + 1)).toInt()
        return PixelRect(
            max(0, inkLeft - pad),
            max(0, inkTop - pad),
            min(width, inkRight + 1 + pad),
            min(height, inkBottom + 1 + pad),
        )
    }

    /**
     * Otsu can split pure paper noise into "ink" and "paper". Only pixels at
     * least [minContrast] grey levels darker than the paper count as ink.
     */
    fun inkThreshold(otsu: Double, paperLevel: Double, minContrast: Double = 25.0): Double =
        min(otsu, paperLevel - minContrast)

    /** Value at [share] (0..1) of a 256-bin histogram. */
    fun percentile(histogram: IntArray, share: Double): Int {
        val total = histogram.sum()
        if (total == 0) return 0
        val target = share * total
        var seen = 0L
        for (level in histogram.indices) {
            seen += histogram[level]
            if (seen >= target) return level
        }
        return histogram.size - 1
    }

    /**
     * True when the marker centres TL, TR, BR, BL (x0, y0 .. x3, y3) form a
     * convex quadrilateral in that clockwise order (image y grows down).
     */
    fun isClockwiseConvex(points: DoubleArray): Boolean {
        require(points.size == 8)
        var sign = 0
        for (i in 0 until 4) {
            val ax = points[2 * i]; val ay = points[2 * i + 1]
            val bx = points[2 * ((i + 1) % 4)]; val by = points[2 * ((i + 1) % 4) + 1]
            val cx = points[2 * ((i + 2) % 4)]; val cy = points[2 * ((i + 2) % 4) + 1]
            val cross = (bx - ax) * (cy - by) - (by - ay) * (cx - bx)
            val s = if (cross > 0) 1 else if (cross < 0) -1 else 0
            if (s == 0) return false
            if (sign == 0) sign = s else if (s != sign) return false
        }
        return sign > 0
    }
}

/** The pure parts of the `ink_ratio` measurement (see ScanPipelineImpl.inkRatio). */
object InkCoverage {
    /**
     * Which connected components count as handwriting: [areas] are the pixel
     * areas by label (label 0 = background, never kept); components smaller
     * than [minArea] are specks.
     */
    fun keptLabels(areas: IntArray, minArea: Double): BooleanArray =
        BooleanArray(areas.size) { it > 0 && areas[it] >= minArea }

    /**
     * Input of the distance transform: 0 where [labels] (row-major, one per
     * pixel) belongs to a kept component, 255 elsewhere. Null when nothing
     * is kept.
     */
    fun distanceSource(labels: IntArray, kept: BooleanArray): ByteArray? {
        if (kept.none { it }) return null
        return ByteArray(labels.size) { if (kept[labels[it]]) 0 else PAPER_BYTE }
    }

    /** Share of [near] pixels in an area of [total]; 0 for an empty area. */
    fun ratio(near: Int, total: Int): Double = if (total <= 0) 0.0 else near.toDouble() / total

    private const val PAPER_BYTE: Byte = -1 // 255
}

/** Share of dark pixels inside a circle; pure so it can be unit tested. */
object CircleFill {
    /**
     * [pixels] is a row-major grayscale patch of [width] x [height] whose top
     * left corner sits at ([originX], [originY]) of the warped frame. Pixels
     * whose centre lies inside [circle] are counted; a pixel is dark when its
     * value is <= [threshold]. Returns 0 when no pixel centre is inside.
     */
    fun darkShare(
        pixels: ByteArray,
        width: Int,
        height: Int,
        originX: Int,
        originY: Int,
        circle: PixelCircle,
        threshold: Double,
    ): Double {
        require(pixels.size >= width * height)
        val r2 = circle.r * circle.r
        var inside = 0
        var dark = 0
        for (row in 0 until height) {
            val dy = originY + row + 0.5 - circle.cy
            for (col in 0 until width) {
                val dx = originX + col + 0.5 - circle.cx
                if (dx * dx + dy * dy > r2) continue
                inside++
                if ((pixels[row * width + col].toInt() and 0xFF) <= threshold) dark++
            }
        }
        return if (inside == 0) 0.0 else dark.toDouble() / inside
    }
}
