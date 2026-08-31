"""
InsightFace ArcFace wrapper.
Loaded once at module import; encode_face() is called per request.
"""
import logging
import os
import numpy as np
import cv2
from insightface.app import FaceAnalysis

logger = logging.getLogger(__name__)

# Model pre-downloaded in Dockerfile — load once at startup
_fa = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
_fa.prepare(ctx_id=-1, det_size=(640, 640))
logger.info("InsightFace ArcFace model loaded.")

# ── Optional anti-spoofing (PAD) — dormant until a model is provided ─────────
# Drop an ONNX presentation-attack-detection model (MiniFASNet-class: input
# 80x80 RGB crop, output [spoof, live] or [spoof, live, other] logits) at
# PAD_MODEL_PATH and liveness scoring activates; without it, liveness is None
# and callers must treat marks as unscored (staffed-gate policy applies).
PAD_MODEL_PATH = os.environ.get("PAD_MODEL_PATH", "/app/models/pad.onnx")
_pad_session = None
if os.path.exists(PAD_MODEL_PATH):
    try:
        import onnxruntime as ort
        _pad_session = ort.InferenceSession(PAD_MODEL_PATH, providers=["CPUExecutionProvider"])
        logger.info("PAD (anti-spoofing) model loaded from %s", PAD_MODEL_PATH)
    except Exception as e:  # never block face flows on a bad PAD file
        logger.error("PAD model failed to load: %s", e)
        _pad_session = None


def _liveness_score(img, bbox) -> float | None:
    """0..1 probability the face is LIVE (None = PAD not active)."""
    if _pad_session is None:
        return None
    try:
        x1, y1, x2, y2 = [int(v) for v in bbox]
        # MiniFASNet convention: score a margin-expanded crop
        w, h = x2 - x1, y2 - y1
        mx, my = int(w * 0.3), int(h * 0.3)
        x1, y1 = max(0, x1 - mx), max(0, y1 - my)
        x2, y2 = min(img.shape[1], x2 + mx), min(img.shape[0], y2 + my)
        crop = cv2.resize(img[y1:y2, x1:x2], (80, 80))
        blob = crop.astype(np.float32).transpose(2, 0, 1)[None, ...]
        inp = _pad_session.get_inputs()[0].name
        out = _pad_session.run(None, {inp: blob})[0][0]
        e = np.exp(out - np.max(out))
        probs = e / e.sum()
        # index 1 = live in the common [spoof, live(, other)] layouts
        return float(probs[1]) if len(probs) >= 2 else None
    except Exception as e:
        logger.warning("PAD scoring failed: %s", e)
        return None


def encode_face(image_bytes: bytes, with_liveness: bool = False):
    """
    Detect the largest face in image_bytes and return its 512-D ArcFace embedding.
    Returns None if no face is detected.
    Raises RuntimeError if the image cannot be decoded.
    """
    if not image_bytes:
        raise RuntimeError("Empty image upload.")
    arr = np.frombuffer(image_bytes, np.uint8)
    try:
        img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    except cv2.error as e:
        raise RuntimeError("Failed to decode image — ensure it is a valid JPEG or PNG.") from e

    if img is None:
        raise RuntimeError("Failed to decode image — ensure it is a valid JPEG or PNG.")

    faces = _fa.get(img)
    if not faces:
        return (None, None) if with_liveness else None

    # Multiple faces? Pick the one with the highest detection confidence
    best = max(faces, key=lambda f: f.det_score)

    # Quality gates: a low-confidence detection or a tiny face crop produces
    # a junk embedding that matches nobody reliably (or worse, everybody a
    # little). Treat those as "no usable face" so callers ask for a retake.
    MIN_DET_SCORE = 0.50
    MIN_FACE_SIDE = 60  # pixels in the source image
    x1, y1, x2, y2 = best.bbox
    side = min(abs(x2 - x1), abs(y2 - y1))
    if best.det_score < MIN_DET_SCORE or side < MIN_FACE_SIDE:
        logger.info("face rejected by quality gate det=%.2f side=%dpx", best.det_score, side)
        return (None, None) if with_liveness else None

    if with_liveness:
        return best.embedding.tolist(), _liveness_score(img, best.bbox)
    return best.embedding.tolist()
