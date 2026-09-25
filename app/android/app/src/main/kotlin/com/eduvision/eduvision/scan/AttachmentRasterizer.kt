package com.eduvision.eduvision.scan

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.ImageDecoder
import android.graphics.pdf.PdfRenderer
import android.os.Build
import android.os.ParcelFileDescriptor
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Turns a Google Classroom attachment into JPEG pages the scan pipeline can
 * read (DESIGN §18.2): PDF pages through [PdfRenderer], pictures OpenCV's
 * imread cannot decode (HEIC/HEIF, WebP, ...) through [ImageDecoder].
 * Runs on the pipeline thread; one page bitmap is alive at a time.
 */
class AttachmentRasterizer(private val maxPdfPages: Int = MAX_PDF_PAGES) {

    /**
     * Writes the pages into [outDir] (created here) and returns their paths
     * with the file's page count, so a PDF cut at [maxPdfPages] is not
     * silently shortened.
     */
    fun rasterize(input: File, mimeType: String, outDir: File): RasterizedAttachment {
        if (!input.isFile) throw FlutterError("image_unreadable", "Missing file ${input.name}")
        val kind = AttachmentKind.detect(headerOf(input), mimeType)
        if (kind == AttachmentKind.UNSUPPORTED) {
            throw FlutterError("format_unsupported", "Unsupported attachment type $mimeType")
        }
        if (!outDir.isDirectory && !outDir.mkdirs()) {
            throw FlutterError("storage_failed", "Cannot create ${outDir.path}")
        }
        return when (kind) {
            AttachmentKind.PDF -> renderPdf(input, outDir)
            else -> RasterizedAttachment(listOf(decodeImage(input, kind, outDir)), 1L)
        }
    }

    private fun renderPdf(input: File, outDir: File): RasterizedAttachment {
        val fd = try {
            ParcelFileDescriptor.open(input, ParcelFileDescriptor.MODE_READ_ONLY)
        } catch (e: IOException) {
            throw FlutterError("pdf_unreadable", e.message ?: "Cannot open PDF")
        }
        val renderer = try {
            PdfRenderer(fd)
        } catch (e: Exception) {
            // Encrypted or broken PDFs throw IOException / SecurityException.
            fd.close()
            throw FlutterError("pdf_unreadable", e.message ?: "Cannot read PDF")
        }
        renderer.use {
            if (it.pageCount == 0) throw FlutterError("pdf_unreadable", "The PDF has no pages")
            val out = ArrayList<String>()
            for (index in 0 until RasterMath.renderedPageCount(it.pageCount, maxPdfPages)) {
                it.openPage(index).use { page ->
                    val size = RasterMath.pdfPagePixels(page.width, page.height)
                    val bitmap = Bitmap.createBitmap(size.width, size.height, Bitmap.Config.ARGB_8888)
                    try {
                        // PdfRenderer leaves unpainted areas transparent; paper is white.
                        Canvas(bitmap).drawColor(Color.WHITE)
                        page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                        out += writeJpeg(bitmap, File(outDir, "page_${index + 1}.jpg"))
                    } finally {
                        bitmap.recycle()
                    }
                }
            }
            return RasterizedAttachment(out, it.pageCount.toLong())
        }
    }

    private fun decodeImage(input: File, kind: AttachmentKind, outDir: File): String {
        val bitmap = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            try {
                ImageDecoder.decodeBitmap(ImageDecoder.createSource(input)) { decoder, info, _ ->
                    // Software bitmaps can be compressed; cap huge photos.
                    decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
                    val target = RasterMath.cappedSize(info.size.width, info.size.height, MAX_IMAGE_LONG_SIDE)
                    if (target.width != info.size.width) decoder.setTargetSize(target.width, target.height)
                }
            } catch (e: IOException) {
                throw FlutterError("image_unreadable", e.message ?: "Cannot decode ${input.name}")
            }
        } else {
            // API 26-27: no ImageDecoder and no HEIF support in BitmapFactory.
            if (kind == AttachmentKind.HEIF) {
                throw FlutterError("format_unsupported", "HEIC needs Android 9 or newer")
            }
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(input.path, bounds)
            val options = BitmapFactory.Options().apply {
                inSampleSize = RasterMath.sampleSize(bounds.outWidth, bounds.outHeight, MAX_IMAGE_LONG_SIDE)
            }
            BitmapFactory.decodeFile(input.path, options)
                ?: throw FlutterError("image_unreadable", "Cannot decode ${input.name}")
        }
        try {
            return writeJpeg(bitmap, File(outDir, "image.jpg"))
        } finally {
            bitmap.recycle()
        }
    }

    private fun writeJpeg(bitmap: Bitmap, file: File): String {
        FileOutputStream(file).use { out ->
            if (!bitmap.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out)) {
                throw FlutterError("storage_failed", "Cannot encode ${file.name}")
            }
        }
        return file.path
    }

    private fun headerOf(file: File): ByteArray = file.inputStream().use { input ->
        val buffer = ByteArray(HEADER_BYTES)
        val read = input.read(buffer)
        if (read <= 0) ByteArray(0) else buffer.copyOf(read)
    }

    companion object {
        const val MAX_PDF_PAGES = 20
        private const val MAX_IMAGE_LONG_SIDE = 4000
        private const val JPEG_QUALITY = 92
        private const val HEADER_BYTES = 16
    }
}

/** What an attachment is, from its first bytes (the Drive mimeType is only a hint). */
enum class AttachmentKind {
    PDF, JPEG, PNG, WEBP, HEIF, OTHER_IMAGE, UNSUPPORTED;

    companion object {
        fun detect(header: ByteArray, mimeType: String): AttachmentKind {
            fun ascii(from: Int, text: String): Boolean =
                header.size >= from + text.length &&
                    text.indices.all { header[from + it] == text[it].code.toByte() }

            return when {
                ascii(0, "%PDF") -> PDF
                header.size >= 3 && header[0] == 0xFF.toByte() && header[1] == 0xD8.toByte() &&
                    header[2] == 0xFF.toByte() -> JPEG
                header.size >= 4 && header[0] == 0x89.toByte() && ascii(1, "PNG") -> PNG
                ascii(0, "RIFF") && ascii(8, "WEBP") -> WEBP
                ascii(4, "ftyp") && HEIF_BRANDS.any { ascii(8, it) } -> HEIF
                // Headers not listed here (GIF, BMP, AVIF variants, a PDF
                // with leading bytes): let the decoder try, guided by Drive.
                mimeType.lowercase() == "application/pdf" -> PDF
                mimeType.lowercase().startsWith("image/") -> OTHER_IMAGE
                else -> UNSUPPORTED
            }
        }

        private val HEIF_BRANDS = listOf("heic", "heix", "hevc", "hevx", "heim", "heis", "mif1", "msf1", "avif")
    }
}

/** Pixel sizes for rasterizing, kept free of Android types for JVM tests. */
object RasterMath {
    /** DPI PDF pages are rendered at: the same as the warped frame (DESIGN §6.2 step 3). */
    const val PDF_DPI = 200.0

    /** Longest side of a rendered PDF page, so an oversized page cannot exhaust memory. */
    const val PDF_MAX_LONG_SIDE = 3400

    data class Size(val width: Int, val height: Int)

    /** PDF pages rendered: the first [maxPages]; the caller reports the rest. */
    fun renderedPageCount(pageCount: Int, maxPages: Int): Int = max(0, min(pageCount, maxPages))

    /** Page size in points (1/72 inch) -> pixels at [PDF_DPI], capped. */
    fun pdfPagePixels(widthPt: Int, heightPt: Int): Size {
        val w = max(1, (widthPt * PDF_DPI / 72.0).roundToInt())
        val h = max(1, (heightPt * PDF_DPI / 72.0).roundToInt())
        return cappedSize(w, h, PDF_MAX_LONG_SIDE)
    }

    /** Scales [width] x [height] down so the long side is at most [longSide]. */
    fun cappedSize(width: Int, height: Int, longSide: Int): Size {
        val long = max(width, height)
        if (long <= longSide || long <= 0) return Size(width, height)
        val scale = longSide.toDouble() / long
        return Size(max(1, (width * scale).roundToInt()), max(1, (height * scale).roundToInt()))
    }

    /** Power-of-two BitmapFactory sample size that keeps the long side above [longSide]. */
    fun sampleSize(width: Int, height: Int, longSide: Int): Int {
        var sample = 1
        val long = max(width, height)
        while (long / (sample * 2) >= longSide) sample *= 2
        return sample
    }
}
