"""
AMS — PDF Processing Microservice
FastAPI service for Aadhaar PDF extraction.
"""

import io
import logging
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from aadhaar_parser import AadhaarParser
from pan_parser import PanParser

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("pdf-service")

app = FastAPI(
    title="AMS PDF Service",
    description="Aadhaar PDF extraction service",
    version="2.1.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Restricted via nginx in production
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

parser = AadhaarParser()
pan_parser = PanParser()


@app.get("/health")
def health():
    return {"status": "ok", "service": "ams-service"}


@app.post("/extract")
async def extract_aadhaar(
    pdf: UploadFile = File(..., description="Aadhaar PDF, or a photo of the card"),
    password: str = Form(default="", description="PDF password if protected"),
):
    """
    Extract Aadhaar data from uploaded PDF.

    Returns:
    - name, dob, gender, address, city, state, pin
    - aadhaar_number (last 4 only — full number is NOT returned)
    - photo_base64 (PNG encoded as base64)
    """
    name = (pdf.filename or "").lower()
    if not name.endswith((".pdf", ".jpg", ".jpeg", ".png", ".webp")):
        raise HTTPException(status_code=400, detail="Upload the Aadhaar as a PDF or an image.")

    content = await pdf.read()
    if len(content) > 10 * 1024 * 1024:  # 10 MB
        raise HTTPException(status_code=413, detail="File must not exceed 10 MB.")

    logger.info(f"Processing Aadhaar: {pdf.filename}, size={len(content)}")

    # A photographed card goes through OCR; the offline PDF keeps its
    # signed-text path, which is the only one that yields a trusted number.
    is_pdf = name.endswith(".pdf") or content[:5] == b"%PDF-"
    result = (parser.extract(pdf_bytes=content, password=password or None) if is_pdf
              else parser.extract_image(content))

    if not result["success"]:
        raise HTTPException(
            status_code=422,
            detail={
                "message": result["message"],
                "code": result.get("code", "PARSE_ERROR"),
            },
        )

    return JSONResponse(content=result["data"])

@app.post("/extract-pan")
async def extract_pan(
    file: UploadFile = File(..., description="PAN card — e-PAN PDF or a photo of the card"),
    password: str = Form(default="", description="e-PAN PDF password (usually DOB as DDMMYYYY)"),
):
    """
    Read a PAN card.

    Accepts an e-PAN PDF (text, sometimes password protected) or a photograph
    of the physical card, which is put through OCR.

    Returns: pan_number, holder_type, name, father_name, dob, photo_base64.
    """
    name = (file.filename or "").lower()
    if not name.endswith((".pdf", ".jpg", ".jpeg", ".png", ".webp")):
        raise HTTPException(status_code=400, detail="Upload the PAN card as a PDF or an image.")

    content = await file.read()
    if len(content) > 10 * 1024 * 1024:
        raise HTTPException(status_code=413, detail="File must not exceed 10 MB.")

    logger.info(f"Processing PAN card: {file.filename}, size={len(content)}")
    result = pan_parser.extract(content, filename=file.filename or "", password=password or None)

    if not result["success"]:
        raise HTTPException(
            status_code=422,
            detail={"message": result["message"], "code": result.get("code", "PARSE_ERROR")},
        )
    return JSONResponse(content=result["data"])


@app.post("/face/embed")
async def face_embed(image: UploadFile = File(..., description="Face image (JPEG/PNG)")):
    """
    Return the 512-D ArcFace embedding of the largest face in the image,
    or {"embedding": null} when no face is detected.
    The model is loaded lazily on first call (keeps startup light; the
    pdf-only code path never pays the memory cost).
    """
    content = await image.read()
    if len(content) > 8 * 1024 * 1024:
        raise HTTPException(status_code=413, detail="Image must not exceed 8 MB.")
    try:
        from face_encoder import encode_face  # lazy: loads InsightFace once per worker
        embedding, liveness = encode_face(content, with_liveness=True)
    except RuntimeError as e:
        raise HTTPException(status_code=422, detail=str(e))
    # liveness: 0..1 live-probability when a PAD model is installed; null otherwise
    return JSONResponse(content={"embedding": embedding, "liveness": liveness})
