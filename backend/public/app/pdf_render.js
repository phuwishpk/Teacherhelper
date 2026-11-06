// One page of a PDF as a PNG, for the review pane's red answer box (DESIGN
// §46). pdf.js (web/pdfjs, served from this site) is imported the first time
// a PDF page is drawn, not with the app.
let pdfjs;

async function load() {
  if (!pdfjs) {
    pdfjs = await import('./pdfjs/pdf.min.js');
    pdfjs.GlobalWorkerOptions.workerSrc = new URL('./pdfjs/pdf.worker.min.js', import.meta.url).href;
  }
  return pdfjs;
}

// bytes: Uint8Array of the PDF; pageNo: 1-based; maxLongSide: px of the
// longer side. Resolves to {png: Uint8Array, pages: number, page: number}
// (page is pageNo kept within the file).
window.kruRenderPdfPage = async function (bytes, pageNo, maxLongSide) {
  const lib = await load();
  // The worker takes the buffer: hand it a copy, the app keeps the original.
  const task = lib.getDocument({
    data: bytes.slice(),
    isEvalSupported: false,
    disableFontFace: true,
  });
  const doc = await task.promise;
  try {
    const page = Math.min(Math.max(1, pageNo | 0), doc.numPages);
    const pdfPage = await doc.getPage(page);
    const base = pdfPage.getViewport({ scale: 1 });
    const viewport = pdfPage.getViewport({ scale: maxLongSide / Math.max(base.width, base.height) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    await pdfPage.render({ canvasContext: context, viewport }).promise;
    const blob = await new Promise((resolve, reject) =>
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('png_failed'))), 'image/png'));
    return { png: new Uint8Array(await blob.arrayBuffer()), pages: doc.numPages, page };
  } finally {
    await task.destroy();
  }
};
