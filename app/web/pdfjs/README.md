# pdf.js (vendored)

Mozilla pdf.js `pdfjs-dist@4.10.38`, legacy build (`legacy/build/pdf.min.mjs`
and `pdf.worker.min.mjs`, renamed to `.js` so any web server sends them as
JavaScript), Apache-2.0 (see LICENSE). Used only by `../pdf_render.js` to draw
the page of a handed-in PDF the answer is on, so the review pane can mark the
answer with a red box (DESIGN §46). Served from this site: the web app's CSP
allows scripts from `'self'` only.

Update: download the tarball from the npm registry, check its `dist.integrity`,
copy the two files and LICENSE, and bump the version here and in DESIGN §46.
