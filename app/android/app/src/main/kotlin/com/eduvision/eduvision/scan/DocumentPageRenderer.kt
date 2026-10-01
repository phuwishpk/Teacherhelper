package com.eduvision.eduvision.scan

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.ImageDecoder
import android.graphics.Paint
import android.graphics.pdf.PdfRenderer
import android.os.Build
import android.os.ParcelFileDescriptor
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.util.UUID
import kotlin.math.max
import kotlin.math.roundToInt

/**
 * Pages of a teacher's exam file for cropping figures (DESIGN §22.4): a PDF
 * page through PdfRenderer, a photo (HEIC and the rest) through ImageDecoder
 * or BitmapFactory, drawn on white and written as a JPEG in the cache. The
 * server crops the figures from the uploaded page with GD.
 */
class DocumentPageRenderer(context: Context) : DocumentPageApi {
    private val cacheRoot = File(context.cacheDir, OUTPUT_DIR)

    override suspend fun renderDocumentPage(
        path: String,
        mimeType: String,
        pageNo: Long,
        maxLongSide: Long,
    ): String = withContext(Dispatchers.IO) {
        val longSide = maxLongSide.toInt().coerceIn(MIN_LONG_SIDE, MAX_LONG_SIDE)
        val file = File(path)
        if (!file.isFile) throw FlutterError("document_unreadable", "No file at $path")
        try {
            val bitmap = if (mimeType == PDF) {
                renderPdf(file, pageNo.toInt(), longSide)
            } else {
                if (pageNo != 1L) throw FlutterError("page_out_of_range", "A photo has one page")
                decodePhoto(file, mimeType, longSide)
            }
            try {
                write(bitmap)
            } finally {
                bitmap.recycle()
            }
        } catch (e: FlutterError) {
            throw e
        } catch (e: OutOfMemoryError) {
            throw FlutterError("document_unreadable", "Not enough memory for this page")
        } catch (e: IOException) {
            throw FlutterError("document_unreadable", e.message ?: "Cannot read the file")
        } catch (e: SecurityException) {
            // A password-protected PDF.
            throw FlutterError("document_unreadable", e.message ?: "Cannot open the file")
        } catch (e: IllegalArgumentException) {
            throw FlutterError("document_unreadable", e.message ?: "Cannot decode the file")
        }
    }

    private fun renderPdf(file: File, pageNo: Int, longSide: Int): Bitmap {
        ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY).use { fd ->
            PdfRenderer(fd).use { pdf ->
                if (pageNo < 1 || pageNo > pdf.pageCount) {
                    throw FlutterError("page_out_of_range", "The file has ${pdf.pageCount} pages")
                }
                pdf.openPage(pageNo - 1).use { page ->
                    // Page size is in points (1/72 inch); scale the long side.
                    val scale = longSide.toDouble() / max(page.width, page.height)
                    val width = max(1, (page.width * scale).roundToInt())
                    val height = max(1, (page.height * scale).roundToInt())
                    val bitmap = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
                    bitmap.eraseColor(Color.WHITE)
                    page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                    return bitmap
                }
            }
        }
    }

    private fun decodePhoto(file: File, mimeType: String, longSide: Int): Bitmap {
        val decoded = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            // ImageDecoder reads HEIC and applies the EXIF orientation.
            val source = ImageDecoder.createSource(file)
            ImageDecoder.decodeBitmap(source) { decoder, info, _ ->
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
                val w = info.size.width
                val h = info.size.height
                val scale = longSide.toDouble() / max(w, h)
                if (scale < 1.0) {
                    decoder.setTargetSize(max(1, (w * scale).roundToInt()), max(1, (h * scale).roundToInt()))
                }
            }
        } else {
            if (mimeType == HEIC || mimeType == HEIF) {
                throw FlutterError("unsupported", "HEIC needs Android 9")
            }
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(file.path, bounds)
            var sample = 1
            while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= longSide) sample *= 2
            BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sample })
                ?: throw FlutterError("document_unreadable", "Cannot decode the photo")
        }
        return onWhite(scaled(decoded, longSide))
    }

    private fun scaled(bitmap: Bitmap, longSide: Int): Bitmap {
        val scale = longSide.toDouble() / max(bitmap.width, bitmap.height)
        if (scale >= 1.0) return bitmap
        val out = Bitmap.createScaledBitmap(
            bitmap,
            max(1, (bitmap.width * scale).roundToInt()),
            max(1, (bitmap.height * scale).roundToInt()),
            true,
        )
        if (out !== bitmap) bitmap.recycle()
        return out
    }

    /** Transparent pixels (PNG, WebP) would turn black in a JPEG. */
    private fun onWhite(bitmap: Bitmap): Bitmap {
        if (!bitmap.hasAlpha()) return bitmap
        val out = Bitmap.createBitmap(bitmap.width, bitmap.height, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(out)
        canvas.drawColor(Color.WHITE)
        canvas.drawBitmap(bitmap, 0f, 0f, Paint(Paint.FILTER_BITMAP_FLAG))
        bitmap.recycle()
        return out
    }

    private fun write(bitmap: Bitmap): String {
        cacheRoot.mkdirs()
        val cutoff = System.currentTimeMillis() - STALE_OUTPUT_MS
        cacheRoot.listFiles()?.forEach { if (it.lastModified() < cutoff) it.delete() }
        val file = File(cacheRoot, "page-${UUID.randomUUID()}.jpg")
        FileOutputStream(file).use { out ->
            if (!bitmap.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out)) {
                throw FlutterError("storage_failed", "Cannot encode ${file.name}")
            }
        }
        return file.path
    }

    companion object {
        private const val OUTPUT_DIR = "document_pages"
        private const val PDF = "application/pdf"
        private const val HEIC = "image/heic"
        private const val HEIF = "image/heif"
        private const val JPEG_QUALITY = 90
        private const val MIN_LONG_SIDE = 200
        private const val MAX_LONG_SIDE = 4000
        private const val STALE_OUTPUT_MS = 24L * 60 * 60 * 1000
    }
}
