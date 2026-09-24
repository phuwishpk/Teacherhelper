"""The generated markers must round-trip through OpenCV's detector (DESIGN §5.2)."""

import json
import sys
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
import gen_aruco  # noqa: E402

REPO_ARUCO_DIR = Path(__file__).resolve().parents[2] / "backend" / "resources" / "worksheet" / "aruco"


def _detector() -> cv2.aruco.ArucoDetector:
    dictionary = cv2.aruco.getPredefinedDictionary(cv2.aruco.DICT_4X4_50)
    return cv2.aruco.ArucoDetector(dictionary, cv2.aruco.DetectorParameters())


def test_rendered_markers_are_detected_with_their_ids():
    detector = _detector()
    for marker_id in gen_aruco.DEFAULT_IDS:
        image = gen_aruco.render_marker(marker_id)
        _, ids, _ = detector.detectMarkers(image)
        assert ids is not None and ids.flatten().tolist() == [marker_id]


def test_write_markers_creates_pngs_and_manifest(tmp_path: Path):
    written = gen_aruco.write_markers(tmp_path)
    names = sorted(p.name for p in written)
    assert names == ["0.png", "1.png", "2.png", "3.png", "manifest.json"]
    manifest = json.loads((tmp_path / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["dictionary"] == "DICT_4X4_50"
    assert manifest["print"]["marker_size_mm"] == 12.0
    assert [m["id"] for m in manifest["markers"]] == [0, 1, 2, 3]


def test_committed_markers_match_generator_output():
    """backend/resources/worksheet/aruco is what the PDF renderer embeds; keep it in sync."""
    for marker_id in gen_aruco.DEFAULT_IDS:
        committed = cv2.imread(str(REPO_ARUCO_DIR / f"{marker_id}.png"), cv2.IMREAD_GRAYSCALE)
        assert committed is not None, f"missing {marker_id}.png"
        assert np.array_equal(committed, gen_aruco.render_marker(marker_id))


def test_markers_survive_a_perspective_photo():
    """A printed page is photographed at an angle: the detector must still find all four ids."""
    page = np.full((1400, 1000), 255, np.uint8)
    size = 120
    corners = {0: (40, 40), 1: (1000 - size - 40, 40), 2: (1000 - size - 40, 1400 - size - 40), 3: (40, 1400 - size - 40)}
    for marker_id, (x, y) in corners.items():
        marker = cv2.resize(gen_aruco.render_marker(marker_id), (size, size), interpolation=cv2.INTER_AREA)
        page[y : y + size, x : x + size] = marker
    src = np.float32([[0, 0], [1000, 0], [1000, 1400], [0, 1400]])
    dst = np.float32([[60, 90], [930, 30], [980, 1370], [20, 1320]])  # tilted ~10°
    warped = cv2.warpPerspective(page, cv2.getPerspectiveTransform(src, dst), (1000, 1400), borderValue=255)
    _, ids, _ = _detector().detectMarkers(warped)
    assert ids is not None and sorted(ids.flatten().tolist()) == [0, 1, 2, 3]
