# Worksheet fonts

`Sarabun-Regular.ttf` and `Sarabun-Bold.ttf` are Sarabun by the Sarabun Project
Authors (https://github.com/cadsondemak/Sarabun), taken from
`google/fonts` (`ofl/sarabun/`) and licensed under the SIL Open Font License 1.1
(`OFL.txt`). WorksheetPdfRenderer uses them through mPDF (DESIGN §5.2).

## Modification (FONTLOG)

2026-09-25: added one glyph, `uni200B` (U+200B ZERO WIDTH SPACE, no outline,
advance width 0), mapped in both Unicode cmap subtables, and removed the `DSIG`
table (the signature no longer matches). Nothing else changed.

Why: mPDF's Thai dictionary line breaking (`useDictionaryLBR`) inserts U+200B
at every word boundary. Upstream Sarabun has no glyph for it, so every word
boundary printed a `.notdef` box.

SHA-256 of the upstream files this was made from:

- Sarabun-Regular.ttf `226d4f368fbc0457990ddef2692679badfd2c1a4e89e5ac4d43c10ba7743b2f1`
- Sarabun-Bold.ttf `d38308ca27d067b9a1b79006337c1f9a66c29aa016d96a9d88d3801da7fa83b9`

To redo it (fontTools, e.g. `uv run --with fonttools python patch.py in.ttf out.ttf`):

```python
import sys
from fontTools.ttLib import TTFont
from fontTools.ttLib.tables._g_l_y_f import Glyph

font = TTFont(sys.argv[1])
if "uni200B" not in font.getGlyphOrder():
    font.setGlyphOrder(font.getGlyphOrder() + ["uni200B"])
    font["glyf"].glyphs["uni200B"] = Glyph()
    font["hmtx"].metrics["uni200B"] = (0, 0)
for table in font["cmap"].tables:
    if table.isUnicode():
        table.cmap[0x200B] = "uni200B"
if "DSIG" in font:
    del font["DSIG"]
font.save(sys.argv[2])
```

## After replacing a font file

mPDF caches font metrics in `storage/app/mpdf/mpdf/ttfontdata/` and only
notices a changed file when its size changes; the per-script GSUB/GPOS caches
are never refreshed. Delete `storage/app/mpdf/mpdf/ttfontdata/sarabun*` after
swapping a font, or Thai text rendering fails with an OTL read error.
