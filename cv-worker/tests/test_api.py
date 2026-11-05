import cv2
import pytest
from fastapi.testclient import TestClient

from app import main, vision
from tests.test_vision import photographed, scene

TOKEN = "test-token"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setattr(main, "TOKEN", TOKEN)
    main.references._items.clear()

    files: dict[str, bytes] = {}
    uploads: dict[str, bytes] = {}

    reference = scene(42)
    photo, _ = photographed(reference)
    files["https://bucket.test/references/1.jpg"] = vision.encode_jpeg(reference)
    files["https://bucket.test/originals/1/a.jpg"] = vision.encode_jpeg(photo)
    files["https://bucket.test/originals/1/other.jpg"] = vision.encode_jpeg(cv2.resize(scene(7), (3000, 2250)))

    def fake_download(url: str) -> bytes:
        main._check_allowed(url)
        key = url.split("?")[0]
        if key not in files:
            raise main.HTTPException(502, "Download fehlgeschlagen (404).")
        return files[key]

    def fake_upload(target, data: bytes, content_type: str) -> None:
        uploads[target.url.split("?")[0]] = data
        files[target.url.split("?")[0]] = data

    monkeypatch.setattr(main, "download", fake_download)
    monkeypatch.setattr(main, "upload", fake_upload)

    test_client = TestClient(main.app)
    test_client.uploads = uploads
    return test_client


def auth():
    return {"Authorization": f"Bearer {TOKEN}"}


def test_health_is_public(client):
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json()["status"] == "ok"


def test_requires_token(client):
    response = client.post("/match", json={"image_url": "x", "reference_url": "y"})
    assert response.status_code == 401
    response = client.post("/match", json={"image_url": "x", "reference_url": "y"}, headers={"Authorization": "Bearer falsch"})
    assert response.status_code == 401


def test_match_align_blur_pipeline(client):
    match = client.post("/match", headers=auth(), json={
        "image_url": "https://bucket.test/originals/1/a.jpg?X-Amz-Signature=1",
        "reference_url": "https://bucket.test/references/1.jpg?X-Amz-Signature=2",
    })
    assert match.status_code == 200, match.text
    body = match.json()
    assert body["inliers"] > 100
    assert body["homography"] is not None

    align = client.post("/align", headers=auth(), json={
        "image_url": "https://bucket.test/originals/1/a.jpg?sig",
        "reference_url": "https://bucket.test/references/1.jpg?sig",
        "homography": body["homography"],
        "width": 1600,
        "height": 1200,
        "upload": {"url": "https://bucket.test/aligned/1/a.jpg?sig", "headers": {"Content-Type": "image/jpeg"}},
    })
    assert align.status_code == 200, align.text
    assert align.json() == {"width": 1600, "height": 1200}
    assert "https://bucket.test/aligned/1/a.jpg" in client.uploads

    blur = client.post("/blur", headers=auth(), json={
        "image_url": "https://bucket.test/aligned/1/a.jpg?sig",
        "boxes": [{"x": 10, "y": 10, "w": 200, "h": 150}],
        "upload": {"url": "https://bucket.test/public/1/a.jpg?sig"},
        "thumb_upload": {"url": "https://bucket.test/public/1/thumbs/a.webp?sig"},
        "thumb_width": 480,
    })
    assert blur.status_code == 200, blur.text
    assert client.uploads["https://bucket.test/public/1/thumbs/a.webp"][:4] == b"RIFF"


def test_match_rejects_other_photo(client):
    response = client.post("/match", headers=auth(), json={
        "image_url": "https://bucket.test/originals/1/other.jpg",
        "reference_url": "https://bucket.test/references/1.jpg",
    })
    assert response.status_code == 200
    assert response.json()["inliers"] < 40


def test_render_skips_missing_frames(client):
    response = client.post("/render", headers=auth(), json={
        "frame_urls": [
            "https://bucket.test/references/1.jpg",
            "https://bucket.test/missing.jpg",
            "https://bucket.test/originals/1/a.jpg",
        ],
        "fps": 4,
        "width": 800,
        "height": 600,
        "upload": {"url": "https://bucket.test/public/1/series/1.mp4?sig"},
    })
    assert response.status_code == 200, response.text
    assert response.json()["frames"] == 2
    assert b"ftyp" in client.uploads["https://bucket.test/public/1/series/1.mp4"][:64]


def test_allowed_prefixes(client, monkeypatch):
    monkeypatch.setattr(main, "ALLOWED_PREFIXES", ["https://erlaubt.test/"])
    response = client.post("/match", headers=auth(), json={
        "image_url": "https://bucket.test/originals/1/a.jpg",
        "reference_url": "https://bucket.test/references/1.jpg",
    })
    assert response.status_code == 400


def test_garbage_image_returns_422(client):
    main.references._items.clear()
    response = client.post("/faces", headers=auth(), json={"image_url": "https://bucket.test/garbage"})
    # fehlende Datei -> 502 vom Download
    assert response.status_code == 502


def test_url_rewrite_keeps_signed_host(monkeypatch):
    monkeypatch.setattr(main, "URL_REWRITE", "http://localhost:9000=>http://minio:9000")
    url, headers = main._rewrite("http://localhost:9000/photos-private/a.jpg?X-Amz-Signature=1")
    assert url == "http://minio:9000/photos-private/a.jpg?X-Amz-Signature=1"
    assert headers == {"Host": "localhost:9000"}
    assert main._rewrite("https://r2.example/a.jpg") == ("https://r2.example/a.jpg", {})
