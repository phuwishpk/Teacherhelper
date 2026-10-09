package com.eduvision.eduvision.scan

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * DESIGN §22.9 / §22.18 `AnswerSheetBaselineTest`: raw fills (before the
 * baseline) of whole pages, the threshold cap for the grey labels, and the
 * JSON handed to Dart.
 */
class AnswerSheetTest {
    private val filled = 0.45

    private fun bubbles(n: Int) = (1..n).map { SheetBubble(it.toString(), 0.1 * it, 0.5, 0.012) }

    private fun rowsPage(rows: Int, options: Int, version: Int = 0) = AnswerSheetPage(
        frameWmm = 178.0,
        frameHmm = 265.0,
        answerArea = NormRect(0.01, 0.12, 0.98, 0.84),
        version = bubbles(version),
        rows = (1..rows).map { OmrRow(it, bubbles(options)) },
        blocks = emptyList(),
    )

    /** Reads [page]; [raw] is called once per bubble in page order (version, rows, blocks). */
    private fun read(page: AnswerSheetPage, raw: (SheetBubble) -> Double) = AnswerSheetReader.read(page, raw)

    @Test
    fun aFullyAnsweredTrueFalsePageWithLightMarksStaysMarked() {
        // 100 two-bubble rows, every row answered with a light mark (0.55)
        // on paper that reads 0.03: half of all bubbles are marks.
        val page = rowsPage(100, 2)
        var n = 0
        val reading = read(page) { b ->
            n++
            val row = (n - 1) / 2
            if (b.value == (if (row % 2 == 0) "1" else "2")) 0.55 else 0.03
        }
        assertEquals(0.03, reading.baseline, 1e-9)
        reading.rows.forEach { (no, fill) ->
            val marked = fill.filterValues { it >= filled }.keys
            assertEquals("row $no", 1, marked.size)
        }
    }

    @Test
    fun aFullyAnsweredTwoOptionMcqPageKeepsTheBaselineOnThePaper() {
        val page = rowsPage(100, 2)
        val reading = read(page) { b -> if (b.value == "2") 0.9 else 0.05 }
        assertEquals(0.05, reading.baseline, 1e-9)
        assertTrue(reading.rows.values.all { it.getValue("2") > 0.85 && it.getValue("1") == 0.0 })
    }

    @Test
    fun aBlankPageReadsNoMarkEvenWithGreyishPaper() {
        val page = rowsPage(40, 4, version = 4)
        val reading = read(page) { 0.12 }
        assertEquals(0.12, reading.baseline, 1e-9)
        assertTrue(reading.rows.values.all { row -> row.values.all { it == 0.0 } })
        assertTrue(reading.versionFill!!.values.all { it == 0.0 })
    }

    @Test
    fun rowsWithTwoMarksDoNotShiftTheBaseline() {
        // 10 rows answered with "1"; rows 1-3 also marked "2": their minimum
        // is a mark, but the median of the group minima stays on the paper.
        val page = rowsPage(10, 4)
        var i = 0
        val reading = read(page) { b ->
            val row = i++ / 4 + 1
            when {
                b.value == "1" -> 0.8
                row <= 3 && b.value == "2" -> 0.9
                else -> 0.04
            }
        }
        assertEquals(0.04, reading.baseline, 1e-9)
        assertEquals(2, reading.rows.getValue(1).filterValues { it >= filled }.size)
        assertEquals(1, reading.rows.getValue(10).filterValues { it >= filled }.size)
    }

    @Test
    fun aMixedPageGroupsDigitColumnsAndTheSignSeparately() {
        val digits = listOf(".", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9")
        val column = digits.map { SheetBubble(it, 0.5, 0.7, 0.011) }
        val page = AnswerSheetPage(
            frameWmm = 178.0,
            frameHmm = 265.0,
            answerArea = NormRect(0.01, 0.12, 0.98, 0.84),
            version = bubbles(2),
            rows = (1..3).map { OmrRow(it, bubbles(4)) },
            blocks = listOf(DigitBlock(101, SheetBubble("-", 0.4, 0.7, 0.011), listOf(column, column, column))),
        )
        // Version ข, rows answered, block "-1.5" with column 2 = "." (all raw on 0.06 paper).
        var col = 0
        var seen = 0
        val reading = read(page) { b ->
            when {
                b.value == "-" -> 0.9
                b.r == 0.011 -> {
                    col = seen++ / 11
                    if ((col == 0 && b.value == "1") || (col == 1 && b.value == ".") || (col == 2 && b.value == "5")) 0.8 else 0.06
                }
                b.cy == 0.5 && b.value == "2" -> 0.85
                else -> 0.06
            }
        }
        assertEquals(0.06, reading.baseline, 1e-9)
        assertEquals(setOf("2"), reading.versionFill!!.filterValues { it >= filled }.keys)
        val block = reading.digits.getValue(101)
        assertTrue(block.sign!! > 0.85)
        assertEquals(listOf("1", ".", "5"), block.columns.map { c -> c.filterValues { it >= filled }.keys.single() })
    }

    @Test
    fun theBaselineIsTheMedianOfGroupMinima() {
        assertEquals(0.0, AnswerSheetBaseline.of(emptyList()), 0.0)
        assertEquals(0.2, AnswerSheetBaseline.of(listOf(listOf(0.5, 0.2), listOf(0.1), listOf(0.9, 0.3))), 1e-9)
        assertEquals(0.15, AnswerSheetBaseline.of(listOf(listOf(0.1), listOf(0.2))), 1e-9)
        assertEquals(0.5, AnswerSheetBaseline.adjust(0.55, 0.1), 1e-9)
        assertEquals(0.0, AnswerSheetBaseline.adjust(0.05, 0.1), 0.0)
        assertEquals(1.0, AnswerSheetBaseline.adjust(1.2, 0.1), 0.0)
        assertEquals(0.0, AnswerSheetBaseline.adjust(1.0, 1.0), 0.0)
    }

    @Test
    fun theOtsuSplitIsCappedBelowTheGreyLabels() {
        assertEquals(140.0, AnswerSheetReader.threshold(171.0), 0.0)
        assertEquals(118.0, AnswerSheetReader.threshold(118.0), 0.0)

        // A 20 x 20 patch: a bubble of radius 8 at the centre whose printed
        // label is 35% grey (166) on white, and the same bubble pencilled (60).
        val circle = PixelCircle(10.0, 10.0, 8.0)
        val label = ByteArray(400) { i -> if (i % 20 in 6..13 && i / 20 in 6..13) 166.toByte() else 255.toByte() }
        val pencil = ByteArray(400) { 60 }
        val cap = AnswerSheetReader.threshold(200.0)
        assertEquals(0.0, CircleFill.darkShare(label, 20, 20, 0, 0, circle, cap), 0.0)
        assertEquals(1.0, CircleFill.darkShare(pencil, 20, 20, 0, 0, circle, cap), 0.0)
        // The worksheet rule (Otsu 200 against paper 255 - 25 = 230) would count the label.
        assertTrue(CircleFill.darkShare(label, 20, 20, 0, 0, circle, RegionMath.inkThreshold(200.0, 255.0)) > 0.2)
    }

    @Test
    fun readingsAreHandedToDartAsJson() {
        val reading = SheetReading(
            baseline = 0.031,
            versionFill = linkedMapOf("1" to 0.0, "2" to 0.91234),
            rows = linkedMapOf(12 to linkedMapOf("1" to 0.0, "2" to 0.88)),
            digits = linkedMapOf(101 to DigitReading(null, listOf(linkedMapOf("." to 0.0, "5" to 0.9)))),
        )
        assertEquals(
            "{\"warped_page_path\":\"/c/a \\\"b\\\".webp\",\"blur_score\":150.5000,\"baseline\":0.0310," +
                "\"version_fill\":{\"1\":0.0000,\"2\":0.9123},\"rows\":{\"12\":{\"1\":0.0000,\"2\":0.8800}}," +
                "\"digits\":{\"101\":{\"sign\":null,\"columns\":[{\".\":0.0000,\"5\":0.9000}]}}}",
            AnswerSheetReader.toJson(reading, "/c/a \"b\".webp", 150.5),
        )
        val noVersion = reading.copy(versionFill = null, rows = emptyMap(), digits = emptyMap())
        assertTrue(AnswerSheetReader.toJson(noVersion, "p", 0.0).contains("\"version_fill\":null,\"rows\":{},\"digits\":{}"))
        assertEquals("\"a\\u000ab\"", AnswerSheetReader.quote("a\nb"))
    }

    @Test
    fun framesAreWarpedAtTheirOwnResolution() {
        // Marker centres of a page 530 px tall in a 720 p preview frame.
        val pts = doubleArrayOf(100.0, 100.0, 456.0, 100.0, 456.0, 630.0, 100.0, 630.0)
        val size = FrameMath.nativeWarpSize(pts, 178.0, 265.0)
        assertEquals(530, size.height)
        assertEquals(356, size.width)
        // Never above the 200 DPI of a full photo.
        val huge = doubleArrayOf(0.0, 0.0, 3000.0, 0.0, 3000.0, 4000.0, 0.0, 4000.0)
        assertEquals(RegionMath.warpedSize(178.0, 265.0).height, FrameMath.nativeWarpSize(huge, 178.0, 265.0).height)
    }

    @Test
    fun onlyKrucheckQrsArePreferred() {
        assertTrue(FrameMath.isKrucheckQr("EV1.1.2.1.1.ABCDEFGH"))
        assertTrue(FrameMath.isKrucheckQr("EVX1.301.4567.1.1.Q2M7K3PA"))
        assertFalse(FrameMath.isKrucheckQr("https://example.com"))
    }
}
