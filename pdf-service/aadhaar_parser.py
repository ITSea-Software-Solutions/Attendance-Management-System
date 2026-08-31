"""
Aadhaar PDF Parser
Extracts: name, DOB, gender, address, photo, and masked Aadhaar number
from UIDAI Aadhaar PDFs (both e-Aadhaar and m-Aadhaar).
"""

import base64
import io
import logging
import re
from typing import Any

import fitz  # PyMuPDF
import pdfplumber
from PIL import Image

import card_ocr

logger = logging.getLogger("aadhaar-parser")


class AadhaarParser:

    # ─── Regex patterns ────────────────────────────────────────────────────────

    AADHAAR_PATTERN = re.compile(r"\b(\d{4}[\s\-]?\d{4}[\s\-]?\d{4})\b")
    DOB_PATTERN     = re.compile(r"(?:DOB|Date of Birth|Birth)[:\s]*(\d{2}[\/\-]\d{2}[\/\-]\d{4})", re.IGNORECASE)
    YEAR_PATTERN    = re.compile(r"Year of Birth[:\s]*(\d{4})", re.IGNORECASE)
    GENDER_PATTERN  = re.compile(r"\b(Male|Female|Transgender|MALE|FEMALE)\b")
    PIN_PATTERN     = re.compile(r"\b(\d{6})\b")
    MOBILE_PATTERN  = re.compile(r"(?:Mobile(?:\s*No\.?)?|Mob(?:\s*No\.?)?|Phone(?:\s*No\.?)?|Contact(?:\s*No\.?)?)[:\s]*([6-9][\d\s]{9,11})", re.IGNORECASE)
    PO_PATTERN      = re.compile(r"\bPO[:\s]+([A-Za-z][A-Za-z\s\-\.]{1,40}?)(?=\s*,|\s*\n|\s*Dist|\s*PIN|\s*\d{6})", re.IGNORECASE)

    STATES = [
        "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar", "Chhattisgarh",
        "Goa", "Gujarat", "Haryana", "Himachal Pradesh", "Jharkhand", "Karnataka",
        "Kerala", "Madhya Pradesh", "Maharashtra", "Manipur", "Meghalaya", "Mizoram",
        "Nagaland", "Odisha", "Punjab", "Rajasthan", "Sikkim", "Tamil Nadu",
        "Telangana", "Tripura", "Uttar Pradesh", "Uttarakhand", "West Bengal",
        "Delhi", "Jammu and Kashmir", "Ladakh", "Puducherry", "Chandigarh",
    ]

    def extract_image(self, image_bytes: bytes) -> dict[str, Any]:
        """
        A photograph of the physical Aadhaar card.

        Name, date of birth and gender are read and offered as autofill. The
        12-digit number is NOT trusted: OCR returned it with one digit wrong
        in testing, and a silently wrong Aadhaar would corrupt the record and
        break duplicate detection. It comes back as a suggestion the operator
        must confirm.
        """
        text = card_ocr.read_text(image_bytes)
        if not text.strip():
            return {"success": False, "code": "OCR_EMPTY",
                    "message": "Nothing could be read from that image. Photograph the card "
                               "straight on in good light, or upload the Aadhaar PDF."}

        lines = card_ocr.lines_of(text)
        upper = text.upper()

        # The card prints the name twice: Devanagari first, English beneath.
        # OCR turns the Devanagari into Latin gibberish that still passes a
        # "looks like a name" test, so take the LAST name-like line before the
        # date of birth — that is the English one.
        dob_at = next((i for i, ln in enumerate(lines)
                       if re.search(r"DOB|D\.O\.B|BIRTH|जन्म", ln, re.I)), len(lines))
        name = None
        for ln in lines[:dob_at]:
            up = ln.upper()
            if any(w in up for w in ("GOVERNMENT", "INDIA", "AADHAAR", "UNIQUE", "AUTHORITY")):
                continue
            if not card_ocr.looks_like_name(ln):
                continue
            cleaned = card_ocr.clean_name(ln)
            if len(cleaned.split()) >= 2:
                name = cleaned          # keep overwriting: the last one wins

        gender = None
        if re.search(r"\bFEMALE\b", upper):
            gender = "F"
        elif re.search(r"\bMALE\b", upper):
            gender = "M"

        # The number is printed as three spaced groups. Require real
        # whitespace so a year from the date of birth cannot be swept into
        # the match, and skip any candidate containing the birth year.
        dob_iso = card_ocr.find_date(text)
        birth_year = dob_iso[:4] if dob_iso else None
        suggested = None
        for grp in re.findall(r"(?<!\d)(\d{4})\s+(\d{4})\s+(\d{4})(?!\d)", text):
            if birth_year and birth_year in grp:
                continue
            suggested = "".join(grp)
            break

        return {"success": True, "data": {
            "name":   name,
            "dob":    card_ocr.find_date(text),
            "gender": gender,
            "address": None, "city": None, "state": None, "pin": None, "mobile": None,
            # Deliberately not aadhaar_number: an OCR'd identifier is a guess.
            "aadhaar_number_suggested": suggested,
            "aadhaar_number_masked": f"XXXX-XXXX-{suggested[-4:]}" if suggested else None,
            "photo_base64": None,
            "source": "image",
            "needs_number_confirmation": True,
        }}

    def extract(self, pdf_bytes: bytes, password: str | None = None) -> dict[str, Any]:
        """
        Main extraction entry point.
        Returns {'success': True, 'data': {...}} or {'success': False, 'message': ...}
        """
        doc = self._open_pdf(pdf_bytes, password)
        if doc is None:
            return {
                "success": False,
                "message": "Failed to open PDF. If the PDF is password-protected, provide the correct password.",
                "code": "PDF_OPEN_FAILED",
            }

        text   = self._extract_text_fitz(doc)
        photo  = self._extract_photo(doc)

        if not text.strip():
            # Fallback: try pdfplumber
            text = self._extract_text_pdfplumber(pdf_bytes, password)

        if not text.strip():
            return {
                "success": False,
                "message": "Could not extract text from PDF. The PDF may be scanned/image-only.",
                "code": "TEXT_EXTRACTION_FAILED",
            }

        logger.debug(f"Extracted text length: {len(text)}")

        data = self._parse_fields(text)
        # SECURITY: raw_text intentionally NOT returned — it contained the full
        # Aadhaar number and risked landing in downstream logs (H1).
        data["photo_base64"] = photo

        return {"success": True, "data": data}

    # ─── PDF opening ──────────────────────────────────────────────────────────

    def _open_pdf(self, pdf_bytes: bytes, password: str | None) -> fitz.Document | None:
        try:
            doc = fitz.open(stream=pdf_bytes, filetype="pdf")
            if doc.needs_pass:
                if not password:
                    return None
                result = doc.authenticate(password)
                if result == 0:
                    return None  # wrong password
            return doc
        except Exception as e:
            logger.error(f"PDF open error: {e}")
            return None

    # ─── Text extraction ──────────────────────────────────────────────────────

    def _extract_text_fitz(self, doc: fitz.Document) -> str:
        text = ""
        for page in doc:
            text += page.get_text("text")
        return text

    def _extract_text_pdfplumber(self, pdf_bytes: bytes, password: str | None) -> str:
        try:
            with pdfplumber.open(io.BytesIO(pdf_bytes), password=password) as pdf:
                return "\n".join(page.extract_text() or "" for page in pdf.pages)
        except Exception as e:
            logger.error(f"pdfplumber error: {e}")
            return ""

    # ─── Photo extraction ─────────────────────────────────────────────────────

    def _extract_photo(self, doc: fitz.Document) -> str | None:
        """Extract the first embedded image (usually the Aadhaar photo)."""
        try:
            for page_num in range(min(len(doc), 2)):
                page   = doc[page_num]
                images = page.get_images(full=True)

                for img_info in images:
                    xref  = img_info[0]
                    image = doc.extract_image(xref)
                    img_bytes = image["image"]
                    img_ext   = image.get("ext", "png")

                    pil_img = Image.open(io.BytesIO(img_bytes))

                    # Skip very small images (icons/logos) — Aadhaar photo is ~100x120+
                    if pil_img.width < 60 or pil_img.height < 60:
                        continue

                    # Skip very large images (background/patterns)
                    if pil_img.width > 800 or pil_img.height > 800:
                        continue

                    # Convert to PNG and encode
                    output = io.BytesIO()
                    pil_img.convert("RGB").save(output, format="PNG")
                    return base64.b64encode(output.getvalue()).decode("utf-8")

        except Exception as e:
            logger.warning(f"Photo extraction error: {e}")

        return None

    # ─── Field parsing ────────────────────────────────────────────────────────

    def _parse_fields(self, text: str) -> dict:
        lines = [line.strip() for line in text.split("\n") if line.strip()]

        return {
            "name":                  self._extract_name(lines, text),
            "dob":                   self._extract_dob(text),
            "gender":                self._extract_gender(text),
            "address":               self._extract_address(lines, text),
            "city":                  self._extract_city(text),
            "state":                 self._extract_state(text),
            "pin":                   self._extract_pin(text),
            "mobile":                self._extract_mobile(text),
            "aadhaar_number_masked": self._extract_aadhaar_masked(text),
        }

    def _extract_name(self, lines: list[str], text: str) -> str | None:
        """
        Name is typically the first non-header, non-number line after
        'Government of India' header or the line before DOB.
        """
        skip_keywords = {
            "government", "india", "aadhaar", "unique", "authority",
            "enrollment", "enrolment", "dob", "date", "birth", "male",
            "female", "address", "resident", "s/o", "d/o", "w/o", "c/o",
        }

        for i, line in enumerate(lines[:20]):
            lower = line.lower()
            # Skip UIDAI headers and very short or numeric lines
            if any(kw in lower for kw in skip_keywords):
                continue
            if len(line) < 3 or line.replace(" ", "").isdigit():
                continue
            # Looks like a name (mostly alpha chars)
            alpha_ratio = sum(c.isalpha() or c == " " for c in line) / len(line)
            if alpha_ratio > 0.75 and len(line) <= 60:
                return line.title()

        return None

    def _extract_dob(self, text: str) -> str | None:
        m = self.DOB_PATTERN.search(text)
        if m:
            dob_str = m.group(1).replace("/", "-")
            # Convert DD-MM-YYYY to YYYY-MM-DD for DB
            parts = dob_str.split("-")
            if len(parts) == 3 and len(parts[2]) == 4:
                return f"{parts[2]}-{parts[1]}-{parts[0]}"
            return dob_str

        # Year of birth only
        m2 = self.YEAR_PATTERN.search(text)
        if m2:
            return m2.group(1)  # just the year

        return None

    def _extract_gender(self, text: str) -> str | None:
        m = self.GENDER_PATTERN.search(text)
        if m:
            g = m.group(1).upper()
            if g == "MALE":    return "M"
            if g == "FEMALE":  return "F"
            return "O"
        return None

    def _extract_address(self, lines: list[str], text: str) -> str | None:
        """Extract address block — typically follows 'Address' label."""
        addr_match = re.search(
            r"(?:Address|Addr)[:\s]*(.+?)(?=\n{2,}|\bPIN\b|\d{6}|$)",
            text,
            re.DOTALL | re.IGNORECASE,
        )
        if addr_match:
            addr = addr_match.group(1).strip()
            addr = re.sub(r"\s+", " ", addr)
            return addr[:300] if addr else None

        # Fallback: collect lines that look like address components
        return None

    def _extract_city(self, text: str) -> str | None:
        """
        Extract city from Aadhaar address.
        PO (Post Office) label is the city equivalent in Indian addresses.
        Falls back to inferring from the address segment before the PIN.
        """
        # 1. Explicit PO label — "PO: Sinnar" → city = "Sinnar"
        po_match = self.PO_PATTERN.search(text)
        if po_match:
            return po_match.group(1).strip().title()

        # 2. Fallback: segment just before the 6-digit PIN
        pin_match = re.search(r"(?:PIN|Pin Code|Pincode|[\-\s])(\d{6})", text, re.IGNORECASE)
        if pin_match:
            before_pin = text[:pin_match.start()]
            parts = [p.strip() for p in re.split(r"[,\n]", before_pin) if p.strip()]
            for candidate in reversed(parts):
                if any(s.lower() in candidate.lower() for s in self.STATES):
                    continue
                if len(candidate) > 2 and re.match(r"^[A-Za-z\s\-\.]+$", candidate):
                    return candidate.title()

        return None

    def _extract_mobile(self, text: str) -> str | None:
        """Extract 10-digit Indian mobile number (starts with 6–9)."""
        # 1. Try labelled mobile number (handles spaces within number)
        m = self.MOBILE_PATTERN.search(text)
        if m:
            digits = re.sub(r"\s+", "", m.group(1))
            if len(digits) == 10:
                return digits

        # 2. Fallback: any standalone 10-digit number starting with 6-9
        #    Skip Aadhaar-like 12-digit sequences
        for match in re.finditer(r"\b([6-9]\d{9})\b", text):
            # Make sure it's not part of a 12-digit Aadhaar number
            start = match.start()
            if start > 0 and text[start - 1].isdigit():
                continue
            end = match.end()
            if end < len(text) and text[end].isdigit():
                continue
            return match.group(1)

        return None

    def _extract_state(self, text: str) -> str | None:
        for state in self.STATES:
            if re.search(r"\b" + re.escape(state) + r"\b", text, re.IGNORECASE):
                return state
        return None

    def _extract_pin(self, text: str) -> str | None:
        # Look for 6-digit number near "PIN" keyword
        pin_match = re.search(r"(?:PIN|Pin Code|Pincode)[:\s]*(\d{6})", text, re.IGNORECASE)
        if pin_match:
            return pin_match.group(1)

        # Fallback: standalone 6-digit number
        standalone = self.PIN_PATTERN.findall(text)
        # Filter out numbers that look like years or Aadhaar segments
        for pin in standalone:
            if 100000 <= int(pin) <= 999999:
                return pin
        return None

    def _extract_aadhaar_masked(self, text: str) -> str | None:
        """Return masked Aadhaar: XXXX-XXXX-XXXX with last 4 visible."""
        numbers = self.AADHAAR_PATTERN.findall(text)
        for num in numbers:
            clean = re.sub(r"[\s\-]", "", num)
            if len(clean) == 12 and clean.isdigit():
                # UIDAI masks first 8 digits in e-Aadhaar
                # We always mask everything except last 4
                return f"XXXX-XXXX-{clean[-4:]}"
        return None
