"""
PAN card parser.

Two shapes arrive in practice:

  * an e-PAN PDF from NSDL/UTIITSL — real text, sometimes password protected
    with the holder's date of birth as DDMMYYYY;
  * a photograph or scan of the physical card — no text at all, so the image
    is put through OCR.

A PAN is ten characters, AAAAA9999A. The fourth character encodes the holder
type (P = individual) and the fifth is the first letter of the surname, which
gives a cheap sanity check that we read the card rather than random text.
"""

from __future__ import annotations

import base64
import io
import logging
import re
from typing import Any

import fitz  # PyMuPDF
from PIL import Image

import card_ocr

logger = logging.getLogger(__name__)

PAN_RE = re.compile(r"\b([A-Z]{5}[0-9]{4}[A-Z])\b")
DOB_RE = re.compile(r"\b(\d{2})[/\-.](\d{2})[/\-.](\d{4})\b")

# Lines that are card furniture, never the holder's name.
NOISE = (
    "INCOME TAX DEPARTMENT", "GOVT. OF INDIA", "GOVT OF INDIA", "GOVERNMENT OF INDIA",
    "PERMANENT ACCOUNT NUMBER", "PERMANENT ACCOUNT", "ACCOUNT NUMBER CARD",
    "आयकर विभाग", "भारत सरकार", "SIGNATURE", "DATE OF BIRTH", "FATHER",
    "NAME", "जन्म", "पिता", "नाम",
)

HOLDER_TYPE = {
    "P": "individual", "C": "company", "H": "huf", "F": "firm",
    "A": "aop", "T": "trust", "B": "boi", "L": "local authority",
    "J": "artificial juridical person", "G": "government",
}


class PanParser:
    """Extract the fields printed on a PAN card."""

    def extract(self, data: bytes, filename: str = "", password: str | None = None) -> dict[str, Any]:
        is_pdf = filename.lower().endswith(".pdf") or data[:5] == b"%PDF-"
        try:
            text, photo = (self._from_pdf(data, password) if is_pdf else self._from_image(data))
        except _Protected:
            return {
                "success": False,
                "code": "PASSWORD_REQUIRED",
                "message": "This e-PAN PDF is password protected. The password is usually the "
                           "date of birth as DDMMYYYY.",
            }
        except Exception as exc:                       # noqa: BLE001 — report, never crash the API
            logger.exception("PAN extraction failed")
            return {"success": False, "code": "PARSE_ERROR", "message": f"Could not read the file: {exc}"}

        fields = self._parse(text)
        if not fields.get("pan_number"):
            return {
                "success": False,
                "code": "PAN_NOT_FOUND",
                "message": "No PAN number found. Photograph the card straight on in good light, "
                           "or type the number in manually.",
            }

        fields["photo_base64"] = photo
        return {"success": True, "data": fields}

    # ── sources ─────────────────────────────────────────────────────────────
    def _from_pdf(self, data: bytes, password: str | None) -> tuple[str, str | None]:
        doc = fitz.open(stream=data, filetype="pdf")
        if doc.needs_pass and not doc.authenticate(password or ""):
            raise _Protected()

        text = "\n".join(page.get_text() for page in doc)
        # An e-PAN that carries no text layer is really a scan — OCR the page.
        if len(text.strip()) < 20:
            page = doc[0]
            pix = page.get_pixmap(dpi=300)
            text = self._ocr(pix.tobytes("png"))
        return text, self._photo_from_pdf(doc)

    def _from_image(self, data: bytes) -> tuple[str, str | None]:
        return self._ocr(data), self._photo_from_image(data)

    def _ocr(self, image_bytes: bytes) -> str:
        return card_ocr.read_text(image_bytes)

    # ── photo ───────────────────────────────────────────────────────────────
    def _photo_from_pdf(self, doc: fitz.Document) -> str | None:
        try:
            for img in doc[0].get_images(full=True):
                pix = fitz.Pixmap(doc, img[0])
                if pix.width < 80 or pix.height < 80:
                    continue                          # signature strip, logos
                if pix.n > 3:
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                return base64.b64encode(pix.tobytes("png")).decode()
        except Exception:                              # noqa: BLE001
            logger.warning("No embedded photo in the PAN PDF")
        return None

    def _photo_from_image(self, data: bytes) -> str | None:
        # The portrait sits in the lower-left of the card; crop generously and
        # let the face encoder decide whether it found a usable face.
        try:
            img = Image.open(io.BytesIO(data)).convert("RGB")
            w, h = img.size
            crop = img.crop((int(w * 0.02), int(h * 0.45), int(w * 0.30), int(h * 0.97)))
            buf = io.BytesIO()
            crop.save(buf, format="PNG")
            return base64.b64encode(buf.getvalue()).decode()
        except Exception:                              # noqa: BLE001
            return None

    # ── fields ──────────────────────────────────────────────────────────────
    def _parse(self, text: str) -> dict[str, Any]:
        upper = text.upper()
        pan = None
        for candidate in PAN_RE.findall(upper.replace(" ", "")):
            pan = candidate
            break
        if not pan:                                    # OCR often splits the groups
            loose = re.search(r"([A-Z]{5})\s*([0-9]{4})\s*([A-Z])", upper)
            if loose:
                pan = "".join(loose.groups())

        lines = card_ocr.lines_of(text)

        # The card labels every field, so read by label rather than position —
        # that survives the Devanagari half of the label being mangled.
        name = card_ocr.value_after_label(
            lines, ("NAME",), validator=card_ocr.looks_like_name)
        father = card_ocr.value_after_label(
            lines, ("FATHER",), validator=card_ocr.looks_like_name)

        # "Name" also matches "Father's Name"; if both resolved to the same
        # line, the first is the holder and the father's is the next match.
        if name and father and card_ocr.clean_name(name) == card_ocr.clean_name(father):
            father = None

        dob = card_ocr.find_date(text)

        return {
            "pan_number":  pan,
            "holder_type": HOLDER_TYPE.get(pan[3]) if pan else None,
            "name":        card_ocr.clean_name(name) if name else None,
            "father_name": card_ocr.clean_name(father) if father else None,
            "dob":         dob,
        }


class _Protected(Exception):
    """The PDF needs a password we were not given."""
