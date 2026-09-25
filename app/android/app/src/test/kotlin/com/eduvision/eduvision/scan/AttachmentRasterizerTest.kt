package com.eduvision.eduvision.scan

import org.junit.Assert.assertEquals
import org.junit.Test

/** Pure parts of [AttachmentRasterizer] (file sniffing and page sizes). */
class AttachmentRasterizerTest {
    private fun bytes(vararg values: Int) = ByteArray(values.size) { values[it].toByte() }

    private fun ascii(text: String) = text.toByteArray(Charsets.US_ASCII)

    @Test
    fun headerDecidesOverTheDriveMimeType() {
        assertEquals(AttachmentKind.PDF, AttachmentKind.detect(ascii("%PDF-1.7\n"), "image/jpeg"))
        assertEquals(AttachmentKind.JPEG, AttachmentKind.detect(bytes(0xFF, 0xD8, 0xFF, 0xE1), "application/pdf"))
        assertEquals(
            AttachmentKind.PNG,
            AttachmentKind.detect(bytes(0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A), "application/octet-stream"),
        )
        assertEquals(AttachmentKind.WEBP, AttachmentKind.detect(ascii("RIFF\u0000\u0000\u0000\u0000WEBPVP8 "), "image/webp"))
    }

    @Test
    fun iphonePhotosAreHeif() {
        val heic = bytes(0, 0, 0, 0x18) + ascii("ftypheic") + bytes(0, 0, 0, 0)
        assertEquals(AttachmentKind.HEIF, AttachmentKind.detect(heic, "image/heic"))
        val mif1 = bytes(0, 0, 0, 0x1C) + ascii("ftypmif1")
        assertEquals(AttachmentKind.HEIF, AttachmentKind.detect(mif1, "application/octet-stream"))
    }

    @Test
    fun unknownHeadersFallBackToTheMimeType() {
        assertEquals(AttachmentKind.OTHER_IMAGE, AttachmentKind.detect(ascii("GIF89a"), "image/gif"))
        assertEquals(AttachmentKind.PDF, AttachmentKind.detect(ascii("\n\n%PDF"), "application/pdf"))
        assertEquals(AttachmentKind.UNSUPPORTED, AttachmentKind.detect(ascii("PK\u0003\u0004"), "application/zip"))
        assertEquals(AttachmentKind.UNSUPPORTED, AttachmentKind.detect(ByteArray(0), "application/vnd.google-apps.document"))
    }

    @Test
    fun a4PdfPagesRenderAt200Dpi() {
        // A4 = 595 x 842 pt -> 1653 x 2339 px at 200 DPI.
        assertEquals(RasterMath.Size(1653, 2339), RasterMath.pdfPagePixels(595, 842))
    }

    @Test
    fun oversizedPdfPagesAreCapped() {
        // A2 (1191 x 1684 pt) at 200 DPI would be 3308 x 4678 px.
        val size = RasterMath.pdfPagePixels(1191, 1684)
        assertEquals(RasterMath.PDF_MAX_LONG_SIDE, size.height)
        assertEquals(2404, size.width)
    }

    @Test
    fun cappedSizeKeepsSmallImages() {
        assertEquals(RasterMath.Size(3000, 4000), RasterMath.cappedSize(3000, 4000, 4000))
        assertEquals(RasterMath.Size(3000, 4000), RasterMath.cappedSize(6000, 8000, 4000))
    }

    @Test
    fun sampleSizeNeverDropsBelowTheLongSide() {
        assertEquals(1, RasterMath.sampleSize(4000, 3000, 4000))
        assertEquals(2, RasterMath.sampleSize(8064, 6048, 4000))
        assertEquals(4, RasterMath.sampleSize(16000, 12000, 4000))
    }
}
