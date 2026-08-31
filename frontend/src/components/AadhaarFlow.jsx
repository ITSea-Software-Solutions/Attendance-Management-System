/**
 * AadhaarFlow Component
 * ─────────────────────
 * Handles the complete Aadhaar PDF upload and extraction flow:
 * 1. Shows button to open UIDAI Aadhaar download page
 * 2. Accepts PDF upload
 * 3. Asks for password if needed
 * 4. Calls backend to extract data
 * 5. Returns extracted data to parent for auto-fill
 */

import PasswordInput from "@/components/PasswordInput";
import { useState, useRef } from "react";
import api from "@/lib/axios";
import { ExternalLink, Upload, Lock, CheckCircle, AlertTriangle, FileText, ChevronRight } from "lucide-react";

const AADHAAR_URL = "https://myaadhaar.uidai.gov.in/genricDownloadAadhaar/en";

export default function AadhaarFlow({ onExtracted, onSkip }) {
  const [file, setFile]         = useState(null);
  const [password, setPassword] = useState("");
  const [needsPass, setNeedsPass] = useState(false);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);
  const [dragOver, setDragOver] = useState(false);
  const fileRef                 = useRef();

  const handleFile = (f) => {
    // People send whatever they have — the offline PDF from UIDAI, or a
    // photograph of the card. Both are accepted; the PDF is the better one
    // because its number is signed text rather than an OCR guess.
    const ok = f && (f.type === "application/pdf" || f.type.startsWith("image/"));
    if (!ok) {
      setError("Select the Aadhaar PDF, or a photo of the card.");
      return;
    }
    setFile(f);
    setError(null);
    setNeedsPass(false);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    setDragOver(false);
    handleFile(e.dataTransfer.files[0]);
  };

  const handleExtract = async () => {
    if (!file) { setError("Please upload the Aadhaar PDF first."); return; }

    setLoading(true);
    setError(null);

    const form = new FormData();
    form.append("pdf", file);
    if (password) form.append("password", password);

    try {
      const res = await api.post("/aadhaar/extract", form, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      onExtracted(res.data.data, file);

    } catch (err) {
      const code    = err.response?.data?.code;
      const message = err.response?.data?.message || err.response?.data?.detail?.message;

      if (code === "PDF_OPEN_FAILED" || message?.toLowerCase().includes("password")) {
        setNeedsPass(true);
        setError("This PDF is password protected. Enter the PDF password (first 4 letters of first name + birth year, e.g., NARE1955).");
      } else {
        setError(message || "Could not extract data from the PDF. Please check the file and try again.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Step 1: Aadhaar Verification</h2>
        <p className="text-sm text-gray-500 mt-1">
          Download the worker's Aadhaar from UIDAI website, then upload it here.
        </p>
      </div>

      {/* Step instructions */}
      <div className="bg-blue-50 rounded-xl p-4 space-y-3">
        <p className="text-sm font-semibold text-blue-800">Follow these steps:</p>
        <div className="space-y-2">
          {[
            "Click 'Open UIDAI Website' below",
            "Worker enters their Aadhaar number and completes OTP verification",
            "Download the Aadhaar PDF from UIDAI",
            "Upload the downloaded PDF here",
            "We'll extract the worker's details automatically",
          ].map((step, i) => (
            <div key={i} className="flex items-start gap-2 text-sm text-blue-700">
              <span className="w-5 h-5 bg-blue-200 text-blue-800 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">
                {i + 1}
              </span>
              {step}
            </div>
          ))}
        </div>
      </div>

      {/* Open UIDAI */}
      <div className="flex flex-col sm:flex-row gap-3">
        <a
          href={AADHAAR_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="btn-primary flex items-center justify-center gap-2"
        >
          <ExternalLink size={16} />
          Open UIDAI Aadhaar Download Page
        </a>
        <p className="text-xs text-gray-400 self-center">
          Opens in a new tab — complete OTP there, then come back to upload.
        </p>
      </div>

      {/* PDF Upload */}
      <div>
        <p className="text-sm font-medium text-gray-700 mb-2">
          Upload the Aadhaar — PDF or a photo of the card
        </p>
        <div
          className={`border-2 border-dashed rounded-xl p-8 text-center cursor-pointer transition-colors ${
            dragOver ? "border-brand-400 bg-brand-50" :
            file ? "border-green-300 bg-green-50" :
            "border-gray-200 hover:border-brand-300 hover:bg-gray-50"
          }`}
          onClick={() => fileRef.current?.click()}
          onDrop={handleDrop}
          onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
          onDragLeave={() => setDragOver(false)}
        >
          {file ? (
            <div className="space-y-2">
              <CheckCircle className="w-10 h-10 text-green-500 mx-auto" />
              <p className="text-sm font-medium text-green-700">{file.name}</p>
              <p className="text-xs text-green-600">{(file.size / 1024).toFixed(1)} KB</p>
              <button
                type="button"
                className="text-xs text-gray-500 underline"
                onClick={(e) => { e.stopPropagation(); setFile(null); setNeedsPass(false); }}
              >
                Change file
              </button>
            </div>
          ) : (
            <div className="space-y-2">
              <Upload className="w-10 h-10 text-gray-300 mx-auto" />
              <p className="text-sm text-gray-500">Drag & drop or click to upload</p>
              <p className="text-xs text-gray-400">
                Aadhaar PDF or a photo of the card • Max 10 MB
              </p>
            </div>
          )}
        </div>
        <input
          ref={fileRef}
          type="file"
          accept=".pdf,application/pdf,image/*"
          className="hidden"
          onChange={(e) => handleFile(e.target.files[0])}
        />
      </div>

      {/* Password field */}
      {(needsPass || file) && (
        <div className="p-4 bg-amber-50 rounded-lg border border-amber-200">
          <div className="flex items-start gap-2 mb-3">
            <Lock size={16} className="text-amber-600 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-amber-800">PDF Password (if required)</p>
              <p className="text-xs text-amber-600 mt-0.5">
                UIDAI Aadhaar PDFs are often password protected.
                The password is <strong>first 4 letters of first name (uppercase) + birth year</strong> (e.g., Narendra born 1955 → <code>NARE1955</code>).
              </p>
            </div>
          </div>
          <PasswordInput
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="input bg-white"
            placeholder="Enter PDF password (e.g., NARE1955)"
          />
        </div>
      )}

      {/* Error */}
      {error && (
        <div className="flex items-start gap-2 p-3 bg-red-50 rounded-lg border border-red-200">
          <AlertTriangle size={16} className="text-red-500 mt-0.5 flex-shrink-0" />
          <p className="text-sm text-red-700">{error}</p>
        </div>
      )}

      {/* Actions */}
      <div className="flex gap-3">
        <button
          type="button"
          onClick={handleExtract}
          disabled={!file || loading}
          className="btn-primary"
        >
          {loading ? (
            <>
              <svg className="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4l-3 3 3 3H4z" />
              </svg>
              Extracting data...
            </>
          ) : (
            <>
              <FileText size={16} />
              Extract & Auto-fill
            </>
          )}
        </button>

        <button
          type="button"
          onClick={onSkip}
          className="btn-secondary"
        >
          Skip — Enter Manually
          <ChevronRight size={16} />
        </button>
      </div>

      <p className="text-xs text-gray-400">
        Your Aadhaar data is processed securely and stored in encrypted form.
        We only keep the last 4 digits of your Aadhaar number.
      </p>
    </div>
  );
}
