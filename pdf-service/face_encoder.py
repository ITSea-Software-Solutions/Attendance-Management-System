"""
InsightFace ArcFace wrapper.
Loaded once at module import; encode_face() is called per request.
"""
import logging
import numpy as np
import cv2
from insightface.app import FaceAnalysis

logger = logging.getLogger(__name__)

# Model pre-downloaded in Dockerfile — load once at startup
_fa = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
_fa.prepare(ctx_id=-1, det_size=(640, 640))
logger.info("InsightFace ArcFace model loaded.")


def encode_face(image_bytes: bytes) -> list | None:
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
        return None

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
        return None

    return best.embedding.tolist()
