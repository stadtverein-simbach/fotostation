import cv2
import numpy as np
import pytest

from app import vision


def scene(seed: int, size=(1600, 1200)) -> np.ndarray:
    """Künstliche, gut strukturierte Szene (wie eine Häuserfront)."""
    rng = np.random.default_rng(seed)
    width, height = size
    image = np.full((height, width, 3), 200, np.uint8)
    for _ in range(260):
        x, y = int(rng.integers(0, width)), int(rng.integers(0, height))
        w, h = int(rng.integers(20, 180)), int(rng.integers(20, 180))
        color = tuple(int(c) for c in rng.integers(0, 255, 3))
        cv2.rectangle(image, (x, y), (x + w, y + h), color, -1)
    for _ in range(120):
        center = (int(rng.integers(0, width)), int(rng.integers(0, height)))
        cv2.circle(image, center, int(rng.integers(5, 40)), tuple(int(c) for c in rng.integers(0, 255, 3)), 2)
    noise = rng.normal(0, 6, image.shape)
    return np.clip(image + noise, 0, 255).astype(np.uint8)


def photographed(reference: np.ndarray, seed: int = 1) -> tuple[np.ndarray, np.ndarray]:
    """Simuliert ein Handyfoto: andere Perspektive, Auflösung und Helligkeit."""
    rng = np.random.default_rng(seed)
    h, w = reference.shape[:2]
    src = np.float32([[0, 0], [w, 0], [w, h], [0, h]])
    jitter = rng.uniform(-0.06, 0.06, (4, 2)) * [w, h]
    out_w, out_h = 3000, 2250
    dst = np.float32([[0, 0], [out_w, 0], [out_w, out_h], [0, out_h]]) + jitter * (out_w / w)
    m = cv2.getPerspectiveTransform(src, dst.astype(np.float32))
    photo = cv2.warpPerspective(reference, m, (out_w, out_h), borderMode=cv2.BORDER_REFLECT)
    photo = cv2.convertScaleAbs(photo, alpha=1.1, beta=-15)
    return photo, m


def test_match_finds_station_and_align_restores_reference():
    reference = scene(42)
    photo, _ = photographed(reference)

    result = vision.match(vision.features(photo), vision.features(reference))

    assert result.inliers > 100
    assert result.homography is not None

    aligned = vision.align(photo, result.homography, (1600, 1200), (1600, 1200))
    # Innenbereich vergleichen (Ränder können durch die Perspektive fehlen).
    inner = (slice(150, 1050), slice(150, 1450))
    a = cv2.cvtColor(aligned, cv2.COLOR_BGR2GRAY)[inner].astype(float)
    r = cv2.cvtColor(reference, cv2.COLOR_BGR2GRAY)[inner].astype(float)
    correlation = np.corrcoef(a.ravel(), r.ravel())[0, 1]
    assert correlation > 0.9


def test_match_rejects_unrelated_photo():
    reference = scene(42)
    other = cv2.resize(scene(7), (3000, 2250))

    result = vision.match(vision.features(other), vision.features(reference))

    assert result.inliers < 40


def test_match_handles_featureless_image():
    blank = np.full((1200, 1600, 3), 128, np.uint8)
    result = vision.match(vision.features(blank), vision.features(scene(1)))
    assert result.homography is None
    assert result.inliers == 0


def test_align_resizes_to_output_size():
    image = scene(3)
    identity = np.eye(3).tolist()
    aligned = vision.align(image, identity, (1600, 1200), (800, 600))
    assert aligned.shape[:2] == (600, 800)


def test_pixelate_changes_only_the_box():
    image = scene(5)
    box = {"x": 100, "y": 100, "w": 300, "h": 200}
    result = vision.pixelate(image, [box])

    region_before = image[100:300, 100:400].astype(int)
    region_after = result[100:300, 100:400].astype(int)
    assert np.abs(region_before - region_after).mean() > 5
    assert np.array_equal(image[500:, 500:], result[500:, 500:])


def test_pixelate_ignores_boxes_outside_image():
    image = scene(5)
    result = vision.pixelate(image, [{"x": 5000, "y": 5000, "w": 10, "h": 10}])
    assert np.array_equal(image, result)


def test_decode_rejects_garbage():
    with pytest.raises(vision.VisionError):
        vision.decode(b"kein bild")


def test_thumbnail_and_encodings():
    image = scene(9)
    thumb = vision.thumbnail(image, 480)
    assert thumb.shape[1] == 480
    assert vision.encode_webp(thumb)[:4] == b"RIFF"
    assert vision.encode_jpeg(image)[:2] == b"\xff\xd8"


def test_fit_pads_to_exact_size():
    image = np.zeros((1000, 1000, 3), np.uint8)
    assert vision.fit(image, (1920, 1440)).shape == (1440, 1920, 3)


def test_render_video_produces_mp4():
    frames = (scene(i, (640, 480)) for i in range(6))
    video, count, duration = vision.render_video(frames, 4, (641, 481))
    assert count == 6
    assert duration == pytest.approx(1.5)
    assert b"ftyp" in video[:64]


def test_render_video_needs_two_frames():
    with pytest.raises(vision.VisionError):
        vision.render_video(iter([scene(1, (320, 240))]), 4, (320, 240))


def test_faces_without_model_raises(monkeypatch):
    vision._face_detector_path.cache_clear()
    monkeypatch.setattr(vision, "FACE_MODEL", "/does/not/exist.onnx")
    with pytest.raises(RuntimeError):
        vision.faces(scene(1, (320, 240)))
    vision._face_detector_path.cache_clear()
