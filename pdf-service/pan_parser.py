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
import numpy as np
import pytesseract
from PIL import Image

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
        img = Image.open(io.BytesIO(image_bytes)).convert("L")
        # Cards are small and often photographed at an angle; upscaling and
        # hard thresholding recovers most of the printed text.
        if max(img.size) < 1400:
            scale = 1400 / max(img.size)
            img = img.resize((int(img.width * scale), int(img.height * scale)), Image.LANCZOS)
        arr = np.array(img)
        thresh = arr.mean() * 0.85
        img = Image.fromarray(((arr > thresh) * 255).astype("uint8"))
        return pytesseract.image_to_string(img, lang="eng", config="--psm 6")

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

        lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
        names = self._names(lines)

        dob = None
        m = DOB_RE.search(text)
        if m:
            d, mth, y = m.groups()
            dob = f"{y}-{mth}-{d}"

        return {
            "pan_number":  pan,
            "holder_type": HOLDER_TYPE.get(pan[3]) if pan else None,
            "name":        names[0] if names else None,
            "father_name": names[1] if len(names) > 1 else None,
            "dob":         dob,
        }

    def _names(self, lines: list[str]) -> list[str]:
        """The card prints the holder's name, then the father's, in caps."""
        out: list[str] = []
        for ln in lines:
            raw = ln.strip()
            # Test the ORIGINAL line for a PAN first: stripping non-letters
            # turns "ABCPK1234F" into "ABCPKF", which then reads as a name.
            if PAN_RE.search(raw.upper().replace(" ", "")):
                continue
            if re.search(r"[A-Z]{5}\s*[0-9]{4}\s*[A-Z]", raw.upper()):
                continue
            # A line with digits in it is a number, a date or an ID — not a name.
            if any(ch.isdigit() for ch in raw):
                continue
            clean = re.sub(r"[^A-Za-z .]", "", raw).strip()
            if len(clean) < 4 or len(clean) > 60:
                continue
            up = clean.upper()
            if any(n in up for n in NOISE):
                continue
            # Card text is printed in capitals; ignore stray mixed-case OCR.
            letters = [c for c in clean if c.isalpha()]
            if letters and sum(c.isupper() for c in letters) / len(letters) < 0.8:
                continue
            # Card furniture printed alongside the name ("Signature", "Photo")
            # gets OCR'd onto the same line — drop those trailing words.
            words = [w for w in clean.split()
                     if w.upper() not in {"PHOTO", "SIGNATURE", "SIGN", "CARD", "HOLDER"}]
            if not words:
                continue
            out.append(" ".join(w.capitalize() for w in words))
            if len(out) == 2:
                break
        return out


class _Protected(Exception):
    """The PDF needs a password we were not given."""
