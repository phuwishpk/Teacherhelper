package com.eduvision.eduvision.scan

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.roundToInt

/**
 * Mirrors test/scan/page_layout_test.dart: the Dart and Kotlin sides must
 * agree on every pixel rectangle.
 */
class RegionMathTest {
    @Test
    fun warpedSizeOfTheA4FrameAt200Dpi() {
        assertEquals(PixelSize(1402, 2087), RegionMath.warpedSize(178.0, 265.0))
    }

    @Test
    fun cropRectGrowsByTwoPercentOfTheFrame() {
        val r = RegionMath.cropRect(NormRect(0.55, 0.27, 0.30, 0.05), 1402, 2087)
        // x: (0.55 - 0.02) * 1402 = 743.06 -> 743, (0.85 + 0.02) * 1402 = 1219.74 -> 1220
        // y: (0.27 - 0.02) * 2087 = 521.75 -> 521, (0.32 + 0.02) * 2087 = 709.58 -> 710
        assertEquals(PixelRect(743, 521, 1220, 710), r)
    }

    @Test
    fun cropRectIsClampedToTheFrame() {
        val r = RegionMath.cropRect(NormRect(0.005, 0.9013, 0.99, 0.2), 1000, 2000)
        assertEquals(PixelRect(0, 1762, 1000, 2000), r)
        val outside = RegionMath.cropRect(NormRect(1.5, 0.1, 0.2, 0.1), 1000, 2000)
        assertTrue(outside.isEmpty)
    }

    @Test
    fun insetNeverTakesMoreThanFifteenPercent() {
        val r = RegionMath.inset(PixelRect(0, 0, 100, 40), 9)
        assertEquals(PixelRect(9, 6, 91, 34), r)
    }

    @Test
    fun bubbleRadiusIsScaledByTheFrameWidth() {
        val c = RegionMath.bubbleCircle(0.12, 0.205, 0.0157, 1402, 2087)
        assertEquals(168.24, c.cx, 1e-9)
        assertEquals(427.835, c.cy, 1e-9)
        assertEquals(22.0114, c.r, 1e-9)
    }

    @Test
    fun scaleToLongSideKeepsTheAspectRatio() {
        assertEquals(PixelSize(1075, 1600), RegionMath.scaleToLongSide(1402, 2087, 1600))
        assertEquals(PixelSize(800, 600), RegionMath.scaleToLongSide(800, 600, 1600))
    }

    @Test
    fun uploadCropsAreScaledToAtMost768Px() {
        // A full-width show_work region of the A4 frame (178 mm at 200 DPI
        // plus the 2% margins) is scaled down with its aspect ratio kept.
        assertEquals(PixelSize(768, 334), RegionMath.uploadCropSize(1402, 610))
        assertEquals(PixelSize(300, 768), RegionMath.uploadCropSize(600, 1536))
        // Short-answer boxes are already small: written as they are.
        assertEquals(PixelSize(620, 180), RegionMath.uploadCropSize(620, 180))
        assertEquals(PixelSize(768, 200), RegionMath.uploadCropSize(768, 200))
    }

    @Test
    fun cnnStripWidthFollowsPreprocess() {
        assertEquals(64, RegionMath.cnnStripWidth(100, 50))
        assertEquals(128, RegionMath.cnnStripWidth(1000, 50))
        assertEquals(1, RegionMath.cnnStripWidth(1, 100))
        assertEquals(0, RegionMath.cnnStripWidth(0, 10))
    }

    @Test
    fun tightBoxPadsByTenPercentOfTheInkHeight() {
        assertEquals(PixelRect(8, 18, 43, 43), RegionMath.tightBox(10, 20, 40, 40, 100, 50))
        assertEquals(PixelRect(0, 0, 100, 50), RegionMath.tightBox(0, 0, 99, 49, 100, 50))
        assertNull(RegionMath.tightBox(5, 5, 4, 4, 100, 50))
    }

    @Test
    fun inkThresholdStaysAwayFromThePaper() {
        assertEquals(120.0, RegionMath.inkThreshold(120.0, 230.0), 0.0)
        assertEquals(205.0, RegionMath.inkThreshold(228.0, 230.0), 0.0)
    }

    @Test
    fun percentileOfAHistogram() {
        val h = IntArray(256)
        h[10] = 10
        h[200] = 90
        assertEquals(200, RegionMath.percentile(h, 0.9))
        assertEquals(10, RegionMath.percentile(h, 0.05))
        assertEquals(0, RegionMath.percentile(IntArray(256), 0.5))
    }

    @Test
    fun markerQuadOrder() {
        assertTrue(RegionMath.isClockwiseConvex(doubleArrayOf(0.0, 0.0, 10.0, 0.0, 10.0, 14.0, 0.0, 14.0)))
        // Counter-clockwise (mirrored) and self-intersecting orders are rejected.
        assertFalse(RegionMath.isClockwiseConvex(doubleArrayOf(0.0, 0.0, 0.0, 14.0, 10.0, 14.0, 10.0, 0.0)))
        assertFalse(RegionMath.isClockwiseConvex(doubleArrayOf(0.0, 0.0, 10.0, 14.0, 10.0, 0.0, 0.0, 14.0)))
    }

    @Test
    fun circleFillCountsDarkPixelsInsideTheCircle() {
        // 10 x 10 patch at (100, 200); left half dark.
        val w = 10
        val px = ByteArray(w * w) { i -> if (i % w < 5) 20.toByte() else 250.toByte() }
        val circle = PixelCircle(105.0, 205.0, 4.0)
        val share = CircleFill.darkShare(px, w, w, 100, 200, circle, 128.0)
        assertEquals(0.5, share, 0.05)
        val none = CircleFill.darkShare(px, w, w, 100, 200, PixelCircle(0.0, 0.0, 1.0), 128.0)
        assertEquals(0.0, none, 0.0)
    }

    @Test
    fun inkRectDropsTheBorderAndTheNumberGutter() {
        // 165 x 30 mm show_work area of the A4 frame at 200 DPI (1402 x 2087).
        val rect = NormRect(11.0 / 178, 84.0 / 265, 165.0 / 178, 30.0 / 265)
        val outer = RegionMath.cropRect(rect, 1402, 2087, 0.0)
        val inset = RegionMath.mmToPx(RegionMath.BORDER_INSET_MM).roundToInt()
        val gutter = RegionMath.mmToPx(RegionMath.NUMBER_GUTTER_MM).roundToInt()
        assertEquals(9, inset)
        assertEquals(63, gutter)

        val plain = RegionMath.inkRect(rect, 1402, 2087, inset)
        assertEquals(RegionMath.inset(outer, inset), plain)

        val numbered = RegionMath.inkRect(rect, 1402, 2087, inset, gutter)
        assertEquals(outer.left + gutter, numbered.left)
        assertEquals(plain.top, numbered.top)
        assertEquals(plain.right, numbered.right)
        assertEquals(plain.bottom, numbered.bottom)

        // A gutter wider than the area leaves nothing, never a negative width.
        val tiny = RegionMath.inkRect(NormRect(0.1, 0.1, 0.01, 0.1), 1402, 2087, inset, gutter)
        assertTrue(tiny.isEmpty)
        assertEquals(0, tiny.width)
    }

    @Test
    fun ruleRunLengthIsAQuarterOfTheWidthWithinLimits() {
        val cap = RegionMath.mmToPx(RegionMath.MAX_RULE_RUN_MM).roundToInt()
        assertEquals(157, cap)
        assertEquals(15, RegionMath.ruleRunLength(40, cap))
        assertEquals(134, RegionMath.ruleRunLength(536, cap)) // 70 mm box
        assertEquals(157, RegionMath.ruleRunLength(1281, cap)) // 165 mm lines
    }

    @Test
    fun squareMillimetresToPixels() {
        assertEquals(15.5, RegionMath.mm2ToPx(RegionMath.MIN_SPECK_MM2), 0.01)
        assertEquals(RegionMath.mmToPx(1.0) * RegionMath.mmToPx(1.0), RegionMath.mm2ToPx(1.0), 1e-9)
    }

    @Test
    fun specksAreNotHandwriting() {
        val kept = InkCoverage.keptLabels(intArrayOf(5000, 12, 16, 900), 15.5)
        assertArrayEquals(booleanArrayOf(false, false, true, true), kept)
    }

    @Test
    fun distanceSourceMarksKeptInkAsZero() {
        val labels = intArrayOf(0, 1, 1, 2, 0, 3)
        val kept = booleanArrayOf(false, true, false, true)
        val source = InkCoverage.distanceSource(labels, kept)!!
        assertEquals(listOf(255, 0, 0, 255, 255, 0), source.map { it.toInt() and 0xFF })
        assertNull(InkCoverage.distanceSource(labels, booleanArrayOf(false, false, false, false)))
    }

    @Test
    fun coverageRatio() {
        assertEquals(0.25, InkCoverage.ratio(25, 100), 0.0)
        assertEquals(0.0, InkCoverage.ratio(0, 0), 0.0)
    }
}
