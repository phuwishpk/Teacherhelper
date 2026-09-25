package com.eduvision.eduvision.scan

import org.json.JSONException
import org.json.JSONObject

/** One bubble of an `mcq` region (frame-relative, radius by frame width). */
data class LayoutBubble(val option: String, val cx: Double, val cy: Double, val r: Double)

data class LayoutFinalAnswer(val rect: NormRect, val numeric: Boolean)

/** One region of a layout page (DESIGN §5.3). */
data class LayoutRegion(
    val regionId: String,
    val kind: String,
    val rect: NormRect,
    val numeric: Boolean,
    val bubbles: List<LayoutBubble>,
    val finalAnswer: LayoutFinalAnswer?,
)

/** The part of one layout JSON page that the crop step needs. */
data class LayoutPage(
    val frameWmm: Double,
    val frameHmm: Double,
    val regions: List<LayoutRegion>,
) {
    companion object {
        const val KIND_MCQ = "mcq"
        const val KIND_BOX = "box"
        const val KIND_LINES = "lines"

        /** Suffix of the crop id of a `lines` region's final-answer box. */
        const val FINAL_SUFFIX = "_final"

        /** Parses one page; throws [FlutterError] `layout_invalid` when malformed. */
        fun parse(json: String): LayoutPage = try {
            val root = JSONObject(json)
            val frame = root.getJSONObject("frame_mm")
            val regionsJson = root.getJSONArray("regions")
            val regions = (0 until regionsJson.length()).map { i ->
                val r = regionsJson.getJSONObject(i)
                val kind = r.getString("kind")
                require(kind == KIND_MCQ || kind == KIND_BOX || kind == KIND_LINES) {
                    "unknown region kind $kind"
                }
                val bubbles = r.optJSONArray("bubbles")?.let { arr ->
                    (0 until arr.length()).map { j ->
                        val b = arr.getJSONObject(j)
                        LayoutBubble(
                            b.getString("option"),
                            b.getDouble("cx"),
                            b.getDouble("cy"),
                            b.getDouble("r"),
                        )
                    }
                } ?: emptyList()
                val final = r.optJSONObject("final_answer")?.let {
                    LayoutFinalAnswer(rect(it.getJSONObject("rect")), it.optBoolean("numeric", false))
                }
                LayoutRegion(
                    regionId = r.getString("region_id"),
                    kind = kind,
                    rect = rect(r.getJSONObject("rect")),
                    numeric = r.optBoolean("numeric", false),
                    bubbles = bubbles,
                    finalAnswer = final,
                )
            }
            val w = frame.getDouble("w")
            val h = frame.getDouble("h")
            require(w > 0 && h > 0) { "frame_mm must be positive" }
            LayoutPage(w, h, regions)
        } catch (e: JSONException) {
            throw FlutterError("layout_invalid", "Layout JSON is malformed: ${e.message}")
        } catch (e: IllegalArgumentException) {
            throw FlutterError("layout_invalid", "Layout JSON is malformed: ${e.message}")
        }

        private fun rect(o: JSONObject) = NormRect(
            o.getDouble("x"),
            o.getDouble("y"),
            o.getDouble("w"),
            o.getDouble("h"),
        )
    }
}
