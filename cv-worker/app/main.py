"""HTTP-API des Bildverarbeitungs-Workers.

Der Worker speichert nichts dauerhaft und hat keine Zugangsdaten zu den
Buckets. Laravel übergibt kurzlebige, vorsignierte URLs: Bilder werden über
Lese-URLs geladen, Ergebnisse per PUT direkt in den Bucket geschrieben.
"""

from __future__ import annotations

import logging
import os
import secrets
from collections import OrderedDict
from threading import Lock
from urllib.parse import urlsplit

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from . import vision

log = logging.getLogger("cv-worker")
logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"))
# Keine vorsignierten URLs ins Log schreiben.
logging.getLogger("httpx").setLevel(logging.WARNING)

TOKEN = os.environ.get("WORKER_TOKEN", "")
MAX_DOWNLOAD = int(os.environ.get("MAX_DOWNLOAD_MB", "30")) * 1024 * 1024
# Kommagetrennte Präfixe, z. B. "https://xyz.r2.cloudflarestorage.com/,https://fls-xyz.laravel.cloud/"
ALLOWED_PREFIXES = [p.strip() for p in os.environ.get("ALLOWED_URL_PREFIXES", "").split(",") if p.strip()]
# Nur für die lokale Entwicklung: "http://localhost:9000=>http://minio:9000"
URL_REWRITE = os.environ.get("URL_REWRITE", "")

app = FastAPI(title="Fotostation cv-worker", version="1.0.0", docs_url=None, redoc_url=None)

_client = httpx.Client(timeout=httpx.Timeout(60.0, connect=10.0), follow_redirects=False)


# ---------------------------------------------------------------------------
# Hilfen
# ---------------------------------------------------------------------------


def require_token(authorization: str = Header(default="")) -> None:
    if not TOKEN:
        raise HTTPException(503, "WORKER_TOKEN ist nicht gesetzt.")
    scheme, _, value = authorization.partition(" ")
    if scheme.lower() != "bearer" or not secrets.compare_digest(value, TOKEN):
        raise HTTPException(401, "Nicht autorisiert.")


def _rewrite(url: str) -> tuple[str, dict[str, str]]:
    """Entwicklungs-Hilfe: Host umschreiben, Host-Header für die Signatur behalten."""
    if URL_REWRITE and "=>" in URL_REWRITE:
        source, target = URL_REWRITE.split("=>", 1)
        if url.startswith(source):
            return target + url[len(source):], {"Host": urlsplit(url).netloc}
    return url, {}


def _check_allowed(url: str) -> None:
    if ALLOWED_PREFIXES and not any(url.startswith(prefix) for prefix in ALLOWED_PREFIXES):
        raise HTTPException(400, "URL ist nicht freigegeben.")


def download(url: str) -> bytes:
    _check_allowed(url)
    real_url, extra = _rewrite(url)
    with _client.stream("GET", real_url, headers=extra) as response:
        if response.status_code != 200:
            raise HTTPException(502, f"Download fehlgeschlagen ({response.status_code}).")
        chunks, size = [], 0
        for chunk in response.iter_bytes():
            size += len(chunk)
            if size > MAX_DOWNLOAD:
                raise HTTPException(413, "Datei ist zu groß.")
            chunks.append(chunk)
    return b"".join(chunks)


def upload(target: "UploadTarget", data: bytes, content_type: str) -> None:
    _check_allowed(target.url)
    real_url, extra = _rewrite(target.url)
    headers = {k: v for k, v in target.headers.items() if k.lower() != "host"} | extra
    if not any(k.lower() == "content-type" for k in headers):
        headers["Content-Type"] = content_type
    response = _client.put(real_url, content=data, headers=headers)
    if response.status_code not in (200, 201, 204):
        raise HTTPException(502, f"Upload fehlgeschlagen ({response.status_code}).")


def _cache_key(url: str) -> str:
    # Vorsignierte URLs ändern bei jedem Aufruf die Query; der Pfad bleibt.
    parts = urlsplit(url)
    return f"{parts.netloc}{parts.path}"


class _ReferenceCache:
    def __init__(self, size: int = 16):
        self._items: OrderedDict[str, vision.Features] = OrderedDict()
        self._size = size
        self._lock = Lock()

    def get(self, url: str) -> vision.Features:
        key = _cache_key(url)
        with self._lock:
            if key in self._items:
                self._items.move_to_end(key)
                return self._items[key]
        features = vision.features(vision.decode(download(url)))
        with self._lock:
            self._items[key] = features
            while len(self._items) > self._size:
                self._items.popitem(last=False)
        return features


references = _ReferenceCache()


# ---------------------------------------------------------------------------
# Modelle
# ---------------------------------------------------------------------------


class UploadTarget(BaseModel):
    url: str
    headers: dict[str, str] = Field(default_factory=dict)


class MatchRequest(BaseModel):
    image_url: str
    reference_url: str


class FacesRequest(BaseModel):
    image_url: str


class AlignRequest(BaseModel):
    image_url: str
    reference_url: str
    homography: list[list[float]] = Field(min_length=3, max_length=3)
    width: int = Field(gt=0, le=8000)
    height: int = Field(gt=0, le=8000)
    upload: UploadTarget


class Box(BaseModel):
    x: float
    y: float
    w: float
    h: float


class BlurRequest(BaseModel):
    image_url: str
    boxes: list[Box] = Field(default_factory=list)
    upload: UploadTarget
    thumb_upload: UploadTarget
    thumb_width: int = Field(default=480, gt=0, le=2000)


class RenderRequest(BaseModel):
    frame_urls: list[str] = Field(min_length=2, max_length=5000)
    fps: int = Field(default=4, ge=1, le=60)
    width: int = Field(gt=0, le=8000)
    height: int = Field(gt=0, le=8000)
    upload: UploadTarget


# ---------------------------------------------------------------------------
# Endpunkte
# ---------------------------------------------------------------------------


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "opencv": vision.cv2.__version__, "faces": os.path.isfile(vision.FACE_MODEL)}


def _vision_errors(func):
    try:
        return func()
    except vision.VisionError as error:
        raise HTTPException(422, str(error)) from error


@app.post("/match", dependencies=[Depends(require_token)])
def match(request: MatchRequest) -> dict:
    def run():
        reference = references.get(request.reference_url)
        image = vision.features(vision.decode(download(request.image_url)))
        result = vision.match(image, reference)
        log.info("match inliers=%s good=%s", result.inliers, result.good_matches)
        return {
            "inliers": result.inliers,
            "good_matches": result.good_matches,
            "score": round(result.score, 4),
            "homography": result.homography,
        }

    return _vision_errors(run)


@app.post("/faces", dependencies=[Depends(require_token)])
def faces(request: FacesRequest) -> dict:
    def run():
        image = vision.decode(download(request.image_url))
        try:
            boxes = vision.faces(image)
        except RuntimeError as error:
            raise HTTPException(503, str(error)) from error
        return {"boxes": boxes, "width": image.shape[1], "height": image.shape[0]}

    return _vision_errors(run)


@app.post("/align", dependencies=[Depends(require_token)])
def align(request: AlignRequest) -> dict:
    def run():
        reference = references.get(request.reference_url)
        image = vision.decode(download(request.image_url))
        aligned = vision.align(image, request.homography, reference.size, (request.width, request.height))
        upload(request.upload, vision.encode_jpeg(aligned), "image/jpeg")
        return {"width": aligned.shape[1], "height": aligned.shape[0]}

    return _vision_errors(run)


@app.post("/blur", dependencies=[Depends(require_token)])
def blur(request: BlurRequest) -> dict:
    def run():
        image = vision.decode(download(request.image_url))
        result = vision.pixelate(image, [box.model_dump() for box in request.boxes])
        upload(request.upload, vision.encode_jpeg(result), "image/jpeg")
        upload(request.thumb_upload, vision.encode_webp(vision.thumbnail(result, request.thumb_width)), "image/webp")
        return {"boxes": len(request.boxes), "width": result.shape[1], "height": result.shape[0]}

    return _vision_errors(run)


@app.post("/render", dependencies=[Depends(require_token)])
def render(request: RenderRequest) -> dict:
    def run():
        def frames():
            for url in request.frame_urls:
                try:
                    yield vision.decode(download(url))
                except (HTTPException, vision.VisionError) as error:
                    # Einzelne fehlende Bilder überspringen statt die ganze Reihe zu verwerfen.
                    log.warning("Frame übersprungen: %s", getattr(error, "detail", error))

        video, count, duration = vision.render_video(frames(), request.fps, (request.width, request.height))
        upload(request.upload, video, "video/mp4")
        return {"frames": count, "duration": duration, "bytes": len(video)}

    return _vision_errors(run)
