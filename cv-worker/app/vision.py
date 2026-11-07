"""Bildverarbeitung der Fotostation (OpenCV).

Reine Funktionen ohne Netzwerkzugriff, damit sie sich einzeln testen lassen.
"""

from __future__ import annotations

import os
import subprocess
import tempfile
from collections.abc import Iterable
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np

# Für den Bildabgleich auf diese Kantenlänge verkleinern (Geschwindigkeit).
MATCH_EDGE = int(os.environ.get("MATCH_EDGE", "1600"))
RATIO = float(os.environ.get("MATCH_RATIO", "0.75"))
RANSAC_THRESHOLD = float(os.environ.get("RANSAC_THRESHOLD", "5.0"))
FACE_MODEL = os.environ.get("FACE_MODEL", "/models/face_detection_yunet_2023mar.onnx")
FACE_SCORE = float(os.environ.get("FACE_SCORE", "0.6"))


class VisionError(Exception):
    """Fehler, die als 422 an den Aufrufer gehen (kaputtes Bild o. Ä.)."""


@dataclass(frozen=True)
class Features:
    keypoints: np.ndarray  # (N, 2) Koordinaten im verkleinerten Bild
    descriptors: np.ndarray
    scale: float  # verkleinert = original * scale
    size: tuple[int, int]  # (Breite, Höhe) des Originals


@dataclass(frozen=True)
class MatchResult:
    inliers: int
    good_matches: int
    score: float
    homography: list[list[float]] | None


def decode(data: bytes) -> np.ndarray:
    array = np.frombuffer(data, dtype=np.uint8)
    image = cv2.imdecode(array, cv2.IMREAD_COLOR)
    if image is None:
        raise VisionError("Bild konnte nicht dekodiert werden.")
    return image


def encode_jpeg(image: np.ndarray, quality: int = 90) -> bytes:
    ok, buffer = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, quality, cv2.IMWRITE_JPEG_PROGRESSIVE, 1])
    if not ok:
        raise VisionError("JPEG konnte nicht erzeugt werden.")
    return buffer.tobytes()


def encode_webp(image: np.ndarray, quality: int = 80) -> bytes:
    ok, buffer = cv2.imencode(".webp", image, [cv2.IMWRITE_WEBP_QUALITY, quality])
    if not ok:
        raise VisionError("WebP konnte nicht erzeugt werden.")
    return buffer.tobytes()


def _sift():
    return cv2.SIFT_create(nfeatures=5000)


def features(image: np.ndarray) -> Features:
    height, width = image.shape[:2]
    scale = min(1.0, MATCH_EDGE / max(width, height))
    small = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA) if scale < 1 else image
    gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
    # Kontrastausgleich hilft bei wechselndem Licht und Jahreszeiten.
    gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
    keypoints, descriptors = _sift().detectAndCompute(gray, None)
    if descriptors is None or len(keypoints) < 8:
        return Features(np.empty((0, 2), np.float32), np.empty((0, 128), np.float32), scale, (width, height))
    points = np.array([kp.pt for kp in keypoints], dtype=np.float32)
    return Features(points, descriptors, scale, (width, height))


def match(image_features: Features, reference: Features) -> MatchResult:
    """Sucht die Homographie, die das Foto auf das Referenzbild abbildet."""
    if len(image_features.descriptors) < 8 or len(reference.descriptors) < 8:
        return MatchResult(0, 0, 0.0, None)

    matcher = cv2.FlannBasedMatcher({"algorithm": 1, "trees": 5}, {"checks": 50})
    pairs = matcher.knnMatch(image_features.descriptors, reference.descriptors, k=2)
    good = [p[0] for p in pairs if len(p) == 2 and p[0].distance < RATIO * p[1].distance]

    if len(good) < 8:
        return MatchResult(0, len(good), 0.0, None)

    src = np.float32([image_features.keypoints[m.queryIdx] for m in good]).reshape(-1, 1, 2)
    dst = np.float32([reference.keypoints[m.trainIdx] for m in good]).reshape(-1, 1, 2)
    small_h, mask = cv2.findHomography(src, dst, cv2.RANSAC, RANSAC_THRESHOLD)

    if small_h is None or mask is None:
        return MatchResult(0, len(good), 0.0, None)

    inliers = int(mask.sum())

    # Auf Originalauflösung umrechnen: H = S_ref^-1 * H_small * S_img
    s_img = np.diag([image_features.scale, image_features.scale, 1.0])
    s_ref_inv = np.diag([1.0 / reference.scale, 1.0 / reference.scale, 1.0])
    full_h = s_ref_inv @ small_h @ s_img
    full_h = full_h / full_h[2, 2]

    if not plausible(full_h, image_features.size, reference.size):
        return MatchResult(inliers, len(good), inliers / len(good), None)

    return MatchResult(inliers, len(good), inliers / len(good), full_h.tolist())


def plausible(h: np.ndarray, image_size: tuple[int, int], reference_size: tuple[int, int]) -> bool:
    """Verwirft entartete Homographien (Spiegelung, extreme Verzerrung)."""
    width, height = image_size
    corners = np.float32([[0, 0], [width, 0], [width, height], [0, height]]).reshape(-1, 1, 2)
    projected = cv2.perspectiveTransform(corners, h).reshape(-1, 2)

    if not np.all(np.isfinite(projected)):
        return False
    if not cv2.isContourConvex(projected.astype(np.float32)):
        return False

    area = cv2.contourArea(projected)
    reference_area = reference_size[0] * reference_size[1]
    return 0.15 * reference_area <= area <= 6.0 * reference_area


@dataclass(frozen=True)
class Aligned:
    image: np.ndarray
    coverage: float  # Anteil echter Fotopixel im Ausschnitt (0..1)


def align(
    image: np.ndarray,
    homography: list[list[float]],
    reference: np.ndarray,
    output_size: tuple[int, int],
    margin: float = 0.0,
) -> Aligned:
    """Entzerrt das Foto auf die Referenz, schneidet den Sicherheitsrand ab
    und füllt Lücken (vom Foto nicht abgedeckte Bereiche) aus dem Referenzbild.

    margin: Anteil, der an jeder Seite abgeschnitten wird (0.04 = 4 %).
    Weil an allen Seiten gleich viel wegfällt, bleibt das Seitenverhältnis
    und der Ausschnitt ist für alle Fotos einer Station identisch.
    """
    ref_h, ref_w = reference.shape[:2]
    mx, my = round(ref_w * margin), round(ref_h * margin)
    width, height = ref_w - 2 * mx, ref_h - 2 * my
    if width < 16 or height < 16:
        raise VisionError("Sicherheitsrand zu groß.")

    # Homographie um den Rand verschieben: direkt in den Ausschnitt rechnen.
    shift = np.array([[1, 0, -mx], [0, 1, -my], [0, 0, 1]], dtype=np.float64)
    h = shift @ np.array(homography, dtype=np.float64)
    size = (width, height)

    warped = cv2.warpPerspective(image, h, size, flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_CONSTANT, borderValue=0)
    mask = cv2.warpPerspective(
        np.full(image.shape[:2], 255, np.uint8), h, size, flags=cv2.INTER_NEAREST, borderMode=cv2.BORDER_CONSTANT, borderValue=0
    )
    # Interpolierte Kantenpixel sind mit Schwarz gemischt: knapp nach innen ziehen.
    mask = cv2.erode(mask, np.ones((3, 3), np.uint8), iterations=2)
    coverage = float(np.count_nonzero(mask)) / mask.size

    result = warped
    if coverage < 1.0:
        fill = reference[my : my + height, mx : mx + width]
        result = _blend(warped, _match_colors(fill, warped, mask), mask)

    if size != output_size:
        result = cv2.resize(result, output_size, interpolation=cv2.INTER_AREA)
    return Aligned(result, coverage)


def _match_colors(fill: np.ndarray, photo: np.ndarray, mask: np.ndarray) -> np.ndarray:
    """Gleicht Helligkeit und Farbe des Referenzbilds grob an das Foto an
    (anderes Licht, andere Jahreszeit), damit die Ergänzung weniger auffällt."""
    valid = mask > 0
    if np.count_nonzero(valid) < 1000:
        return fill
    photo_mean = photo[valid].reshape(-1, 3).mean(axis=0)
    fill_mean = np.maximum(fill[valid].reshape(-1, 3).mean(axis=0), 1.0)
    gain = np.clip(photo_mean / fill_mean, 0.6, 1.6)
    return np.clip(fill.astype(np.float32) * gain, 0, 255).astype(np.uint8)


def _blend(photo: np.ndarray, fill: np.ndarray, mask: np.ndarray) -> np.ndarray:
    """Weicher Übergang: Am Rand des Fotos über einige Pixel ins Referenzbild blenden."""
    feather = max(8.0, 0.015 * max(mask.shape))
    # Rahmen mit gültigen Pixeln, damit der Bildrand selbst nicht als Kante zählt.
    padded = cv2.copyMakeBorder(mask, 1, 1, 1, 1, cv2.BORDER_REPLICATE)
    distance = cv2.distanceTransform((padded > 0).astype(np.uint8), cv2.DIST_L2, 5)[1:-1, 1:-1]
    alpha = np.clip(distance / feather, 0.0, 1.0)[..., None]
    blended = photo.astype(np.float32) * alpha + fill.astype(np.float32) * (1.0 - alpha)
    return np.clip(blended, 0, 255).astype(np.uint8)


@lru_cache(maxsize=1)
def _face_detector_path() -> str:
    if not Path(FACE_MODEL).is_file():
        raise RuntimeError(f"Gesichtserkennungsmodell fehlt: {FACE_MODEL}")
    return FACE_MODEL


def faces(image: np.ndarray, padding: float = 0.25) -> list[dict]:
    """Gesichter mit YuNet finden; Boxen mit Rand, in Pixeln des Bildes."""
    height, width = image.shape[:2]
    detector = cv2.FaceDetectorYN.create(_face_detector_path(), "", (width, height), FACE_SCORE, 0.3, 5000)
    _, detections = detector.detect(image)

    boxes = []
    for row in detections if detections is not None else []:
        x, y, w, h = (float(v) for v in row[:4])
        pad_w, pad_h = w * padding, h * padding
        x0 = max(0, int(x - pad_w))
        y0 = max(0, int(y - pad_h))
        x1 = min(width, int(x + w + pad_w))
        y1 = min(height, int(y + h + pad_h))
        if x1 > x0 and y1 > y0:
            boxes.append({"x": x0, "y": y0, "w": x1 - x0, "h": y1 - y0, "score": round(float(row[-1]), 3)})
    return boxes


def pixelate(image: np.ndarray, boxes: list[dict], blocks: int = 12) -> np.ndarray:
    """Bereiche verpixeln und zusätzlich weichzeichnen (nicht umkehrbar)."""
    result = image.copy()
    height, width = result.shape[:2]
    for box in boxes:
        x0 = max(0, int(box["x"]))
        y0 = max(0, int(box["y"]))
        x1 = min(width, x0 + int(box["w"]))
        y1 = min(height, y0 + int(box["h"]))
        if x1 - x0 < 2 or y1 - y0 < 2:
            continue
        region = result[y0:y1, x0:x1]
        small = cv2.resize(region, (max(1, (x1 - x0) // blocks), max(1, (y1 - y0) // blocks)), interpolation=cv2.INTER_LINEAR)
        mosaic = cv2.resize(small, (x1 - x0, y1 - y0), interpolation=cv2.INTER_NEAREST)
        kernel = max(3, ((min(x1 - x0, y1 - y0) // 6) | 1))
        result[y0:y1, x0:x1] = cv2.GaussianBlur(mosaic, (kernel, kernel), 0)
    return result


def thumbnail(image: np.ndarray, width: int) -> np.ndarray:
    height, current = image.shape[0], image.shape[1]
    if current <= width:
        return image
    return cv2.resize(image, (width, round(height * width / current)), interpolation=cv2.INTER_AREA)


def fit(image: np.ndarray, size: tuple[int, int]) -> np.ndarray:
    """Auf exakt (Breite, Höhe) bringen; Seitenverhältnis bleibt, Rest schwarz."""
    width, height = size
    h, w = image.shape[:2]
    if (w, h) == (width, height):
        return image
    factor = min(width / w, height / h)
    resized = cv2.resize(image, (round(w * factor), round(h * factor)), interpolation=cv2.INTER_AREA)
    canvas = np.zeros((height, width, 3), dtype=np.uint8)
    y = (height - resized.shape[0]) // 2
    x = (width - resized.shape[1]) // 2
    canvas[y : y + resized.shape[0], x : x + resized.shape[1]] = resized
    return canvas


def render_video(frames: Iterable[np.ndarray], fps: int, size: tuple[int, int]) -> tuple[bytes, int, float]:
    """Zeitraffer als H.264-MP4 (in allen Browsern abspielbar).

    Die Bilder werden nacheinander auf die Platte geschrieben, damit auch lange
    Reihen nicht komplett im Speicher liegen. Rückgabe: (Video, Bilder, Sekunden).
    """
    width, height = size[0] - size[0] % 2, size[1] - size[1] % 2

    with tempfile.TemporaryDirectory() as tmp:
        count = 0
        for frame in frames:
            cv2.imwrite(str(Path(tmp) / f"{count:05d}.jpg"), fit(frame, (width, height)), [cv2.IMWRITE_JPEG_QUALITY, 92])
            count += 1

        if count < 2:
            raise VisionError("Für einen Zeitraffer werden mindestens zwei Bilder benötigt.")

        output = Path(tmp) / "out.mp4"
        command = [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-framerate", str(fps),
            "-i", str(Path(tmp) / "%05d.jpg"),
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
            "-pix_fmt", "yuv420p", "-movflags", "+faststart",
            str(output),
        ]
        completed = subprocess.run(command, capture_output=True, text=True, timeout=900)
        if completed.returncode != 0:
            raise RuntimeError(f"ffmpeg fehlgeschlagen: {completed.stderr.strip()[:500]}")

        return output.read_bytes(), count, count / fps
