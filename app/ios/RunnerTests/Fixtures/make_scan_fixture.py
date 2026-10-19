"""Synthetic worksheet photo for ScanCoreTests (DESIGN §33). No real handwriting.

    cd app/ios/RunnerTests/Fixtures
    uv run --with opencv-python-headless==4.12.0.88 --with numpy python make_scan_fixture.py

Draws the four ArUco markers (DICT_4X4_50, ids 0..3 at the frame corners),
an Krucheck QR, two boxes (one with a written "5"), a row of four bubbles
(B filled) and three ruled lines with "x=5" and an empty final-answer box,
then photographs it: perspective tilt, uneven light, blur, noise, JPEG.
Writes scan_photo.jpg, layout_page.json and answer_sheet.json.
"""
import json

import cv2
import numpy as np

DPI = 200
FRAME_W_MM, FRAME_H_MM = 178.0, 265.0
FW = round(FRAME_W_MM / 25.4 * DPI)  # 1402
FH = round(FRAME_H_MM / 25.4 * DPI)  # 2087
MARGIN = 160
MARKER = round(12 / 25.4 * DPI)  # 12 mm
QR_PAYLOAD = "EV1.123.4567.1.2.K7Q3M2PA"

page = np.full((FH + 2 * MARGIN, FW + 2 * MARGIN), 255, np.uint8)


def px(nx, ny):
    return int(round(MARGIN + nx * FW)), int(round(MARGIN + ny * FH))


dictionary = cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_4X4_50)
for marker_id, (nx, ny) in enumerate([(0, 0), (1, 0), (1, 1), (0, 1)]):
    img = cv2.aruco.generateImageMarker(dictionary, marker_id, MARKER, borderBits=1)
    cx, cy = px(nx, ny)
    page[cy - MARKER // 2 : cy - MARKER // 2 + MARKER, cx - MARKER // 2 : cx - MARKER // 2 + MARKER] = img

qr = cv2.QRCodeEncoder.create().encode(QR_PAYLOAD)
qr = cv2.resize(qr, (qr.shape[1] * 8, qr.shape[0] * 8), interpolation=cv2.INTER_NEAREST)
qx, qy = px(0.70, 0.05)
page[qy : qy + qr.shape[0], qx : qx + qr.shape[1]] = qr


def box(rect, thickness=3):
    x, y, w, h = rect
    p0, p1 = px(x, y), px(x + w, y + h)
    cv2.rectangle(page, p0, p1, 0, thickness)


regions = []

q1 = (0.10, 0.30, 0.40, 0.06)
box(q1)
cv2.putText(page, "5", px(0.25, 0.352), cv2.FONT_HERSHEY_SIMPLEX, 3.0, 30, 9, cv2.LINE_AA)
regions.append({"region_id": "q1", "kind": "box", "numeric": True, "rect": dict(zip("xywh", q1))})

q2 = (0.55, 0.30, 0.35, 0.06)
box(q2)
regions.append({"region_id": "q2", "kind": "box", "numeric": False, "rect": dict(zip("xywh", q2))})

bubbles = []
for i, option in enumerate("ABCD"):
    cx, cy, r = 0.15 + 0.10 * i, 0.525, 0.012
    centre = px(cx, cy)
    radius = int(round(r * FW))
    cv2.circle(page, centre, radius, 90, 2, cv2.LINE_AA)
    cv2.putText(page, option, (centre[0] - 10, centre[1] + 10), cv2.FONT_HERSHEY_SIMPLEX, 0.8, 166, 2, cv2.LINE_AA)
    if option == "B":
        cv2.circle(page, centre, radius - 3, 25, -1, cv2.LINE_AA)
    bubbles.append({"option": option, "cx": cx, "cy": cy, "r": r})
regions.append({"region_id": "q3", "kind": "mcq", "rect": {"x": 0.10, "y": 0.50, "w": 0.45, "h": 0.05}, "bubbles": bubbles})

q4 = (0.10, 0.60, 0.80, 0.15)
for k in range(1, 4):
    y = q4[1] + q4[3] * k / 4
    cv2.line(page, px(q4[0], y), px(q4[0] + q4[2], y), 120, 2)
cv2.putText(page, "x=5", px(0.30, 0.63), cv2.FONT_HERSHEY_SIMPLEX, 2.0, 30, 6, cv2.LINE_AA)
final = (0.60, 0.77, 0.30, 0.05)
box(final)
regions.append({
    "region_id": "q4", "kind": "lines", "rect": dict(zip("xywh", q4)),
    "final_answer": {"rect": dict(zip("xywh", final)), "numeric": True},
})

layout = {"frame_mm": {"w": FRAME_W_MM, "h": FRAME_H_MM}, "regions": regions}
sheet = {
    "sheet": "exam",
    "frame_mm": {"w": FRAME_W_MM, "h": FRAME_H_MM},
    "answer_area": {"x": 0.10, "y": 0.50, "w": 0.45, "h": 0.05},
    "regions": [{"kind": "omr_row", "sheet_no": 1, "bubbles": [
        {"value": b["option"], "cx": b["cx"], "cy": b["cy"], "r": b["r"]} for b in bubbles
    ]}],
}

# Photograph it.
h, w = page.shape
src = np.float32([[0, 0], [w, 0], [w, h], [0, h]])
dst = np.float32([[120, 90], [w + 60, 150], [w + 130, h + 170], [70, h + 120]])
out_w, out_h = w + 250, h + 300
m = cv2.getPerspectiveTransform(src, dst)
photo = cv2.warpPerspective(page, m, (out_w, out_h), flags=cv2.INTER_LINEAR, borderValue=150)
light = np.linspace(1.0, 0.85, out_w, dtype=np.float32)[None, :] * np.linspace(1.0, 0.92, out_h, dtype=np.float32)[:, None]
photo = (photo.astype(np.float32) * light).clip(0, 255)
photo = cv2.GaussianBlur(photo, (3, 3), 0.8)
rng = np.random.default_rng(7)
photo = (photo + rng.normal(0, 3, photo.shape)).clip(0, 255).astype(np.uint8)
photo = cv2.cvtColor(photo, cv2.COLOR_GRAY2BGR)
cv2.imwrite("scan_photo.jpg", photo, [cv2.IMWRITE_JPEG_QUALITY, 85])

with open("layout_page.json", "w") as f:
    json.dump(layout, f, indent=1)
with open("answer_sheet.json", "w") as f:
    json.dump(sheet, f, indent=1)
print("scan_photo.jpg", photo.shape)
