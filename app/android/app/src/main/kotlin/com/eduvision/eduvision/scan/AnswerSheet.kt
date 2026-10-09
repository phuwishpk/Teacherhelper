package com.eduvision.eduvision.scan

import java.util.Locale
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import kotlin.math.round

/** One bubble of an exam answer sheet (frame-relative, radius by frame width). */
data class SheetBubble(val value: String, val cx: Double, val cy: Double, val r: Double)

/** An `omr_row` region: the bubbles of one mcq / true-false question. */
data class OmrRow(val sheetNo: Int, val bubbles: List<SheetBubble>)

/** A `digit_block` region: the optional sign bubble and the digit columns. */
data class DigitBlock(val sheetNo: Int, val sign: SheetBubble?, val columns: List<List<SheetBubble>>)

/**
 * The part of one exam answer-sheet layout page (DESIGN §22.8) that
 * reading needs. Parsed from JSON by [AnswerSheetPageParser].
 */
data class AnswerSheetPage(
    val frameWmm: Double,
    val frameHmm: Double,
    val answerArea: NormRect,
    val version: List<SheetBubble>,
    val rows: List<OmrRow>,
    val blocks: List<DigitBlock>,
)

/** Fill of a digit block after the baseline: sign (null when not printed) and columns. */
data class DigitReading(val sign: Double?, val columns: List<Map<String, Double>>)

/** Every bubble of a page after the baseline was subtracted (DESIGN §22.9). */
data class SheetReading(
    val baseline: Double,
    val versionFill: Map<String, Double>?,
    val rows: Map<Int, Map<String, Double>>,
    val digits: Map<Int, DigitReading>,
)

/**
 * The page baseline of DESIGN §22.9: b = the median of the lowest fill of
 * every group (a row, a digit column with its ".", the sign bubble of a
 * block, the version bubbles). Each group is meant to hold at most one
 * mark, so its lowest bubble is nearly always an empty one and shows the
 * paper plus the grey printed label. The median of ALL bubbles is not
 * used: on a page of two-bubble rows that is fully answered half of the
 * bubbles are marked and that median would land on the marks.
 */
object AnswerSheetBaseline {
    /** Median of the group minima; 0 when there are no groups. */
    fun of(groups: List<List<Double>>): Double {
        val minima = groups.filter { it.isNotEmpty() }.map { it.min() }.sorted()
        if (minima.isEmpty()) return 0.0
        val mid = minima.size / 2
        return if (minima.size % 2 == 1) minima[mid] else (minima[mid - 1] + minima[mid]) / 2
    }

    /** fill' = clamp((fill - b) / (1 - b), 0, 1). */
    fun adjust(fill: Double, baseline: Double): Double {
        if (baseline >= 1.0) return 0.0
        return ((fill - baseline) / (1 - baseline)).coerceIn(0.0, 1.0)
    }
}

/** Pure parts of `readAnswerSheet` (ScanPipelineImpl), unit tested on the JVM. */
object AnswerSheetReader {
    /**
     * Upper bound of the ink threshold on answer sheets (DESIGN §22.9): the
     * bubble labels are printed 35% grey (about 166 of 255), so capping the
     * Otsu split of the answer area at 140 never counts a label as a mark,
     * even on a blank sheet where Otsu splits labels from paper. The
     * worksheet rule min(Otsu, paper - 25) would. Tune with real sheets
     * (DESIGN §16.2).
     */
    const val MAX_THRESHOLD = 140.0

    fun threshold(otsu: Double): Double = min(otsu, MAX_THRESHOLD)

    /**
     * Measures every bubble with [rawFill] (dark share inside 70% of the
     * radius, before the baseline), then subtracts the page baseline.
     */
    fun read(page: AnswerSheetPage, rawFill: (SheetBubble) -> Double): SheetReading {
        val groups = ArrayList<List<Double>>()

        val version = page.version.map { it.value to rawFill(it) }
        if (version.isNotEmpty()) groups += version.map { it.second }

        val rows = page.rows.map { row -> row.sheetNo to row.bubbles.map { it.value to rawFill(it) } }
        rows.forEach { (_, fills) -> groups += fills.map { it.second } }

        val blocks = page.blocks.map { block ->
            val sign = block.sign?.let { rawFill(it) }
            val columns = block.columns.map { column -> column.map { it.value to rawFill(it) } }
            if (sign != null) groups += listOf(sign)
            columns.forEach { column -> groups += column.map { it.second } }
            Triple(block.sheetNo, sign, columns)
        }

        val b = AnswerSheetBaseline.of(groups)
        fun adj(pairs: List<Pair<String, Double>>): Map<String, Double> =
            LinkedHashMap<String, Double>().apply { pairs.forEach { (k, v) -> put(k, AnswerSheetBaseline.adjust(v, b)) } }

        return SheetReading(
            baseline = b,
            versionFill = if (version.isEmpty()) null else adj(version),
            rows = LinkedHashMap<Int, Map<String, Double>>().apply { rows.forEach { (no, fills) -> put(no, adj(fills)) } },
            digits = LinkedHashMap<Int, DigitReading>().apply {
                blocks.forEach { (no, sign, columns) ->
                    put(no, DigitReading(sign?.let { AnswerSheetBaseline.adjust(it, b) }, columns.map { adj(it) }))
                }
            },
        )
    }

    /**
     * The JSON returned to Dart: {warped_page_path, blur_score, baseline,
     * version_fill, rows, digits}; fills rounded to 4 decimals.
     */
    fun toJson(reading: SheetReading, warpedPagePath: String, blurScore: Double): String {
        val sb = StringBuilder()
        sb.append('{')
        sb.append("\"warped_page_path\":").append(quote(warpedPagePath)).append(',')
        sb.append("\"blur_score\":").append(num(blurScore)).append(',')
        sb.append("\"baseline\":").append(num(reading.baseline)).append(',')
        sb.append("\"version_fill\":").append(reading.versionFill?.let { fillMap(it) } ?: "null").append(',')
        sb.append("\"rows\":{")
        reading.rows.entries.forEachIndexed { i, (no, fill) ->
            if (i > 0) sb.append(',')
            sb.append(quote(no.toString())).append(':').append(fillMap(fill))
        }
        sb.append("},\"digits\":{")
        reading.digits.entries.forEachIndexed { i, (no, block) ->
            if (i > 0) sb.append(',')
            sb.append(quote(no.toString())).append(":{\"sign\":")
            sb.append(block.sign?.let { num(it) } ?: "null")
            sb.append(",\"columns\":[")
            block.columns.forEachIndexed { j, column ->
                if (j > 0) sb.append(',')
                sb.append(fillMap(column))
            }
            sb.append("]}")
        }
        sb.append("}}")
        return sb.toString()
    }

    private fun fillMap(fill: Map<String, Double>): String =
        fill.entries.joinToString(",", "{", "}") { (k, v) -> quote(k) + ":" + num(v) }

    private fun num(v: Double): String =
        if (v.isNaN() || v.isInfinite()) "0" else String.format(Locale.US, "%.4f", round(v * 10000) / 10000)

    fun quote(s: String): String {
        val sb = StringBuilder("\"")
        for (c in s) {
            when {
                c == '"' -> sb.append("\\\"")
                c == '\\' -> sb.append("\\\\")
                c < ' ' -> sb.append(String.format(Locale.US, "\\u%04x", c.code))
                else -> sb.append(c)
            }
        }
        return sb.append('"').toString()
    }
}

/** Geometry of the continuous-scan frame check (DESIGN §22.10). */
object FrameMath {
    /**
     * Size to warp the marker frame of a camera frame to: the frame's own
     * resolution (mean length of the left and right edges between the
     * marker centres as the height), so a small preview frame is not
     * blown up (which would blur it and lower the Laplacian variance), and
     * never more than the 200 DPI of a full photo.
     */
    fun nativeWarpSize(points: DoubleArray, frameWmm: Double, frameHmm: Double): PixelSize {
        require(points.size == 8)
        val left = hypot(points[6] - points[0], points[7] - points[1])
        val right = hypot(points[4] - points[2], points[5] - points[3])
        val full = RegionMath.warpedSize(frameWmm, frameHmm)
        val h = min(full.height.toDouble(), (left + right) / 2)
        val w = h * frameWmm / frameHmm
        return PixelSize(max(1, round(w).toInt()), max(1, round(h).toInt()))
    }

    /** True when [payload] is an Krucheck worksheet or answer-sheet QR. */
    fun isKrucheckQr(payload: String): Boolean = payload.startsWith("EV1.") || payload.startsWith("EVX1.")
}
