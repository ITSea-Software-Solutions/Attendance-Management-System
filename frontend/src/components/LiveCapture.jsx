import { useEffect, useRef, useState } from "react";
import { Camera, RefreshCw, VideoOff, Upload } from "lucide-react";

/**
 * Live photo capture for a gate.
 *
 * The camera is the point — a visitor pass is only worth something if the
 * picture was taken at the gate, now. Upload stays available as a fallback for
 * machines with no working camera, and is labelled so it is obvious which was
 * used.
 *
 * facingMode: "user" for a person at a desk, "environment" for the rear camera
 * on a tablet, which is what you want when photographing a number plate.
 */
export default function LiveCapture({
  onCapture,
  label = "Photo",
  facingMode = "user",
  allowUpload = true,
  compact = false,
}) {
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const fileRef = useRef(null);
  const [ready, setReady] = useState(false);
  const [denied, setDenied] = useState(false);
  const [preview, setPreview] = useState(null);

  useEffect(() => {
    let cancelled = false;
    navigator.mediaDevices
      ?.getUserMedia({ video: { facingMode, width: { ideal: 640 }, height: { ideal: 480 } } })
      .then((s) => {
        if (cancelled) { s.getTracks().forEach((t) => t.stop()); return; }
        streamRef.current = s;
        if (videoRef.current) {
          videoRef.current.srcObject = s;
          videoRef.current.onloadedmetadata = () => setReady(true);
        }
      })
      .catch(() => setDenied(true));
    return () => {
      cancelled = true;
      streamRef.current?.getTracks().forEach((t) => t.stop());
    };
  }, [facingMode]);

  const capture = () => {
    const v = videoRef.current;
    if (!v || !ready) return;
    const c = document.createElement("canvas");
    c.width = v.videoWidth || 640;
    c.height = v.videoHeight || 480;
    c.getContext("2d").drawImage(v, 0, 0);
    c.toBlob((blob) => {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      setPreview(url);
      onCapture(blob, url, "camera");
    }, "image/jpeg", 0.85);
  };

  const onFile = (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    const url = URL.createObjectURL(f);
    setPreview(url);
    onCapture(f, url, "upload");
  };

  const retake = () => {
    setPreview(null);
    onCapture(null, null, null);
  };

  return (
    <div className="space-y-2">
      <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">{label}</p>
      <div className="relative rounded-xl overflow-hidden bg-gray-900"
        style={{ aspectRatio: compact ? "4/3" : "4/3" }}>
        {preview ? (
          <img src={preview} alt={label} className="w-full h-full object-cover" />
        ) : denied ? (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-gray-300">
            <VideoOff size={22} />
            <span className="text-xs">Camera not available</span>
          </div>
        ) : (
          <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />
        )}
      </div>

      <div className="flex gap-2">
        {!preview && !denied && (
          <button type="button" onClick={capture} disabled={!ready}
            className="btn-primary flex-1 justify-center text-sm py-1.5">
            <Camera size={14} /> {ready ? "Capture" : "Starting…"}
          </button>
        )}
        {preview && (
          <button type="button" onClick={retake}
            className="btn-secondary flex-1 justify-center text-sm py-1.5">
            <RefreshCw size={14} /> Retake
          </button>
        )}
        {allowUpload && (denied || preview) && (
          <>
            <button type="button" onClick={() => fileRef.current?.click()}
              className="btn-secondary flex-1 justify-center text-sm py-1.5">
              <Upload size={14} /> Upload
            </button>
            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={onFile} />
          </>
        )}
      </div>
    </div>
  );
}
