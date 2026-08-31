"""
Shared OCR for photographed identity cards.

Lessons from real cards, not synthetic ones:

  * Never hard-threshold. Indian ID cards are printed on coloured gradients
    (PAN is pink/blue, Aadhaar green/orange); thresholding on the mean wipes
    out the dark text sitting on the darker half of the gradient. Autocontrast
    on greyscale keeps it.
  * No single page-segmentation mode reads a whole card. psm 4 follows the
    label/value column layout, psm 11 finds sparse text the column mode walks
    past — the date of birth in particular. Run both and merge.
  * Digits read off a photograph cannot be trusted as identifiers. A 12-digit
    Aadhaar came back with one digit wrong in testing, which would corrupt
    the record and defeat duplicate detection, so numbers are offered as a
    suggestion for a human to confirm, never stored blind.
"""

from __future__ import annotations

import io
import re

from PIL import Image, ImageOps

try:
    import pytesseract
except Exception:                                       # noqa: BLE001
    pytesseract = None

# Bilingual labels: the card prints "नाम / Name" then the value beneath.
DATE_RE = re.compile(r"\b(\d{2})\s*[/\-.]\s*(\d{2})\s*[/\-.]\s*(\d{4})\b")


def prepare(image_bytes: bytes, min_side: int = 1600) -> Image.Image:
    """Greyscale, upscale small photos, and stretch contrast — no threshold."""
    img = Image.open(io.BytesIO(image_bytes)).convert("L")
    if max(img.size) < min_side:
        scale = min_side / max(img.size)
        img = img.resize((int(img.width * scale), int(img.height * scale)), Image.LANCZOS)
    return ImageOps.autocontrast(img)


def read_text(image_bytes: bytes, lang: str = "eng") -> str:
    """All the text on the card, from several passes merged."""
    if pytesseract is None:
        return ""
    img = prepare(image_bytes)
    out = []
    for psm in (4, 11, 6):
        try:
            out.append(pytesseract.image_to_string(img, lang=lang, config=f"--psm {psm}"))
        except Exception:                               # noqa: BLE001
            continue
    return "\n".join(out)


def lines_of(text: str) -> list[str]:
    return [ln.strip() for ln in text.splitlines() if ln.strip()]


def value_after_label(lines: list[str], labels: tuple[str, ...],
                      validator=None, lookahead: int = 3) -> str | None:
    """
    The value printed under a label.

    Cards put "Father's Name" on one line and the name on the next, so find
    the label and walk forward a line or two until something plausible turns
    up. OCR mangles the Devanagari half of a bilingual label, so matching is
    on the English word only.
    """
    for i, ln in enumerate(lines):
        upper = ln.upper()
        if not any(lb in upper for lb in labels):
            continue
        # Occasionally the value lands on the same line, after the slash.
        tail = re.split(r"/", ln)[-1].strip()
        candidates = [tail] + lines[i + 1: i + 1 + lookahead]
        for cand in candidates:
            cand = cand.strip()
            if not cand:
                continue
            if any(lb in cand.upper() for lb in labels):
                continue
            if validator is None or validator(cand):
                return cand
    return None


def looks_like_name(text: str) -> bool:
    """A printed personal name: letters and spaces, no digits, 2+ characters."""
    clean = re.sub(r"[^A-Za-z .']", "", text).strip()
    if len(clean) < 4 or len(clean) > 60:
        return False
    if any(ch.isdigit() for ch in text):
        return False
    words = [w for w in clean.split() if len(w) > 1]
    return len(words) >= 1


def clean_name(text: str) -> str:
    """Title-case a name and drop card furniture OCR'd onto the same line."""
    drop = {"PHOTO", "SIGNATURE", "SIGN", "CARD", "HOLDER", "NAME", "FATHER",
            "FATHERS", "DOB", "MALE", "FEMALE"}
    words = [w for w in re.sub(r"[^A-Za-z .']", " ", text).split() if w.upper() not in drop]
    return " ".join(w.capitalize() for w in words).strip()


def find_date(text: str) -> str | None:
    """First dd/mm/yyyy on the card, as an ISO date."""
    m = DATE_RE.search(text)
    if not m:
        return None
    d, mth, y = m.groups()
    if not (1 <= int(mth) <= 12 and 1 <= int(d) <= 31):
        return None
    return f"{y}-{mth}-{d}"
