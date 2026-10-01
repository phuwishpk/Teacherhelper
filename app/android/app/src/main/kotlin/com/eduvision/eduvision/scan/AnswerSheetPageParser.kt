package com.eduvision.eduvision.scan

import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

/** Parses one page of an exam answer-sheet layout (DESIGN §22.8). */
object AnswerSheetPageParser {
    /** Throws [FlutterError] `layout_invalid` when the JSON is not an exam sheet page. */
    fun parse(json: String): AnswerSheetPage = try {
        val root = JSONObject(json)
        require(root.optString("sheet") == "exam") { "not an exam answer sheet layout" }
        val frame = root.getJSONObject("frame_mm")
        val w = frame.getDouble("w")
        val h = frame.getDouble("h")
        require(w > 0 && h > 0) { "frame_mm must be positive" }
        val area = root.getJSONObject("answer_area")

        var version = emptyList<SheetBubble>()
        val rows = ArrayList<OmrRow>()
        val blocks = ArrayList<DigitBlock>()
        val regions = root.getJSONArray("regions")
        for (i in 0 until regions.length()) {
            val r = regions.getJSONObject(i)
            when (val kind = r.getString("kind")) {
                "version_bubbles" -> version = bubbles(r.getJSONArray("bubbles"))
                "omr_row" -> rows += OmrRow(r.getInt("sheet_no"), bubbles(r.getJSONArray("bubbles")))
                "digit_block" -> {
                    val columns = r.getJSONArray("columns")
                    blocks += DigitBlock(
                        sheetNo = r.getInt("sheet_no"),
                        sign = r.optJSONObject("sign")?.let { bubble(it, "-") },
                        columns = (0 until columns.length()).map { bubbles(columns.getJSONObject(it).getJSONArray("bubbles")) },
                    )
                }
                else -> throw IllegalArgumentException("unknown region kind $kind")
            }
        }
        AnswerSheetPage(
            frameWmm = w,
            frameHmm = h,
            answerArea = NormRect(area.getDouble("x"), area.getDouble("y"), area.getDouble("w"), area.getDouble("h")),
            version = version,
            rows = rows,
            blocks = blocks,
        )
    } catch (e: JSONException) {
        throw FlutterError("layout_invalid", "Answer-sheet layout JSON is malformed: ${e.message}")
    } catch (e: IllegalArgumentException) {
        throw FlutterError("layout_invalid", "Answer-sheet layout JSON is malformed: ${e.message}")
    }

    private fun bubbles(arr: JSONArray): List<SheetBubble> =
        (0 until arr.length()).map { bubble(arr.getJSONObject(it), null) }

    private fun bubble(o: JSONObject, value: String?): SheetBubble = SheetBubble(
        value ?: o.get("value").toString(),
        o.getDouble("cx"),
        o.getDouble("cy"),
        o.getDouble("r"),
    )
}
