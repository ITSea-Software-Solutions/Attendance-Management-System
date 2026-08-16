/**
 * In / Out — Fingerprint or Manual with auto-captured photo proof.
 *
 * Camera starts on mount and auto-snaps the moment a worker is confirmed
 * (fingerprint match or manual select). User can retake before submitting.
 */

import { useState, useRef, useEffect } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import api from "@/lib/axios";
import { useAuth } from "@/contexts/AuthContext";
import toast from "react-hot-toast";
import {
  Fingerprint, Camera, LogIn, LogOut, XCircle, AlertTriangle,
  RefreshCw, MapPin, Search, User, VideoOff, ScanFace,
} from "lucide-react";

import { captureFingerprint, SGI_ERRORS } from "@/lib/biometric";

const PHASE = { IDLE: "idle", SCANNING: "scanning", MATCHING: "matching", CONFIRMED: "confirmed", ERROR: "error" };

// ─── Camera hook ──────────────────────────────────────────────────────────────

function useCamera() {
  const videoRef = useRef(null);
  const streamRef = useRef(null);
  const [ready, setReady] = useState(false);
  const [denied, setDenied] = useState(false);

  useEffect(() => {
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: { ideal: "environment" }, width: { ideal: 640 }, height: { ideal: 480 } } })
      .then(s => {
        streamRef.current = s;
        if (videoRef.current) {
          videoRef.current.srcObject = s;
          videoRef.current.onloadedmetadata = () => setReady(true);
        }
      })
      .catch(() => setDenied(true));
    return () => streamRef.current?.getTracks().forEach(t => t.stop());
  }, []);

  const snap = () =>
    new Promise(resolve => {
      const v = videoRef.current;
      if (!v || !ready) { resolve(null); return; }
      const c = document.createElement("canvas");
      c.width  = v.videoWidth  || 640;
      c.height = v.videoHeight || 480;
      c.getContext("2d").drawImage(v, 0, 0);
      c.toBlob(b => resolve(b), "image/jpeg", 0.85);
    });

  return { videoRef, ready, denied, snap };
}

// ─── Location Selector ─────────────────────────────────────────────────────────

function LocationSelector({ locationType, locationName, onTypeChange, onNameChange }) {
  return (
    <div className="card flex flex-wrap items-center gap-3 py-3">
      <MapPin size={16} className="text-brand-500 shrink-0" />
      <span className="text-sm font-medium text-gray-700">Checkpoint:</span>
      <div className="flex gap-1">
        {[["main_gate", "Main Gate"], ["department", "Department"], ["checkpoint", "Checkpoint"]].map(([val, label]) => (
          <button
            key={val}
            onClick={() => onTypeChange(val)}
            className={`px-3 py-1 rounded-full text-xs font-medium transition-colors ${
              locationType === val ? "bg-brand-600 text-white" : "bg-gray-100 text-gray-600 hover:bg-gray-200"
            }`}
          >
            {label}
          </button>
        ))}
      </div>
      {locationType !== "main_gate" && (
        <input
          value={locationName}
          onChange={(e) => onNameChange(e.target.value)}
          placeholder="e.g. Production Floor, Building A..."
          className="input flex-1 min-w-[200px] py-1.5 text-sm"
        />
      )}
    </div>
  );
}

// ─── Confirmed worker card ─────────────────────────────────────────────────────

function ConfirmedCard({ worker, score, scoreLabel, photoPreview, cameraReady, onPhotoAction, onConfirm, onCancel, isPending }) {
  const isIn = worker.pending_type === "IN";
  return (
    <div className={`card border-2 space-y-4 ${isIn ? "border-green-200 bg-green-50" : "border-blue-200 bg-blue-50"}`}>
      <div className="flex items-center gap-4">
        {worker.photo_url ? (
          <img src={worker.photo_url} alt="" className="w-16 h-16 rounded-xl object-cover" />
        ) : (
          <div className="w-16 h-16 bg-gray-200 rounded-xl flex items-center justify-center">
            <User size={24} className="text-gray-400" />
          </div>
        )}
        <div>
          <p className="font-bold text-gray-900 text-lg">{worker.name}</p>
          <p className="text-sm text-gray-500">{worker.vendor}</p>
          <div className="flex items-center gap-2 mt-1">
            <span className={`badge ${isIn ? "badge-green" : "badge-blue"}`}>
              {isIn ? <LogIn size={10} className="mr-1 inline" /> : <LogOut size={10} className="mr-1 inline" />}
              Pending {worker.pending_type}
            </span>
            {score != null && <span className="text-xs text-gray-400">{scoreLabel ?? `Score: ${score}/200`}</span>}
          </div>
        </div>
      </div>

      {/* Photo proof */}
      {photoPreview ? (
        <div className="space-y-1">
          <p className="text-xs text-gray-500 font-medium">
            Photo proof <span className="text-green-600">✓ captured</span>
          </p>
          <img src={photoPreview} alt="proof" className="h-40 w-full object-cover rounded-lg border border-gray-200" />
          <button type="button" onClick={onPhotoAction} className="text-xs text-brand-600 hover:underline">
            {cameraReady ? "Retake" : "Change photo"}
          </button>
        </div>
      ) : (
        <button type="button" onClick={onPhotoAction} className="btn-secondary w-full text-sm">
          <Camera size={15} />
          {cameraReady ? "Capture Photo" : "Add Photo Proof"}
          <span className="text-gray-400 font-normal ml-1">(optional)</span>
        </button>
      )}

      <div className="flex gap-2 pt-1 border-t border-black/5">
        <button
          onClick={onConfirm}
          disabled={isPending}
          className={`flex-1 btn ${isIn ? "btn-success" : "btn-primary"} justify-center`}
        >
          {isPending ? "Marking…" : `Confirm ${worker.pending_type}`}
          {isIn ? <LogIn size={16} /> : <LogOut size={16} />}
        </button>
        <button onClick={onCancel} className="btn-secondary">Cancel</button>
      </div>
    </div>
  );
}

// ─── Main ──────────────────────────────────────────────────────────────────────

export default function AttendanceMark() {
  const { user }  = useAuth();
  const companyId = user?.company_id;
  const fileRef   = useRef(null);
  const camera    = useCamera();

  // Gate users have a fixed location; admins can choose freely
  const isGateUser   = user?.role === "company_gate";
  const fixedLocType = user?.location_type ?? "main_gate";
  const fixedLocName = user?.location_name;

  const [mode, setMode]            = useState("fingerprint");
  const [locationType, setLocType] = useState(fixedLocName ? fixedLocType : "main_gate");
  const [locationName, setLocName] = useState(fixedLocName ?? "Main Gate");

  // Fingerprint-specific
  const [phase, setPhase]         = useState(PHASE.IDLE);
  const [message, setMessage]     = useState("Click Scan to begin.");
  const [matched, setMatched]     = useState(null);
  const [probeTemplate, setProbe] = useState(null);
  const [certError, setCertError] = useState(false);

  // Manual-specific
  const [search, setSearch]      = useState("");
  const [selectedWorker, setSel] = useState(null);

  // Face-specific (camera match — no scanner hardware needed)
  const [faceBusy, setFaceBusy] = useState(false);
  const [faceMsg, setFaceMsg]   = useState("Point the camera at the worker's face, then scan.");
  const [matchMethod, setMatchMethod] = useState(null); // 'fingerprint' | 'face'

  // Shared proof photo
  const [photoFile, setPhotoFile]   = useState(null);
  const [photoPreview, setPhotoPrev] = useState(null);

  const confirmedWorker = matched || selectedWorker;
  const resolvedLocation = locationType === "main_gate" ? "Main Gate" : (locationName || "");

  const { data: assignedWorkers } = useQuery({
    queryKey: ["assigned-workers", companyId, search],
    queryFn:  () => api.get("/attendance/assigned-workers", {
      params: { company_id: companyId, search: search || undefined },
    }).then(r => r.data),
    enabled: mode === "manual" && !!companyId && !confirmedWorker,
  });

  const handleLocTypeChange = (val) => {
    setLocType(val);
    setLocName(val === "main_gate" ? "Main Gate" : "");
  };

  // ── Camera snap helpers ───────────────────────────────────────────────────

  const autoSnap = async () => {
    const blob = await camera.snap();
    if (blob) {
      setPhotoFile(blob);
      setPhotoPrev(URL.createObjectURL(blob));
    }
  };

  const handlePhotoAction = async () => {
    if (camera.ready) {
      await autoSnap();
    } else {
      fileRef.current?.click();
    }
  };

  const handleFileChange = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setPhotoFile(file);
    setPhotoPrev(URL.createObjectURL(file));
  };

  // ── Fingerprint scan ──────────────────────────────────────────────────────

  const scan = async () => {
    setPhase(PHASE.SCANNING);
    setCertError(false);
    setMatched(null);
    setMessage("👇 Place your finger on the scanner NOW and hold it");

    let capture;
    try {
      capture = await captureFingerprint();
    } catch {
      setCertError(true);
      setPhase(PHASE.ERROR);
      setMessage("Cannot reach SecuGen service at https://localhost:8443");
      return;
    }

    if (capture.ErrorCode !== 0) {
      setPhase(PHASE.ERROR);
      setMessage(SGI_ERRORS[capture.ErrorCode] ?? `Scanner error ${capture.ErrorCode}`);
      return;
    }

    setPhase(PHASE.MATCHING);
    setMessage("Identifying worker…");

    // Matching is performed SERVER-SIDE — the probe template is sent to the API
    // which compares it against stored templates and returns the matched worker.
    // Raw templates never reach the browser and the match cannot be forged here.
    let match;
    try {
      const res = await api.post("/attendance/identify", {
        company_id: companyId,
        probe_template: capture.TemplateBase64,
      });
      match = res.data;
    } catch (err) {
      setPhase(PHASE.ERROR);
      setMessage(err.response?.data?.message || "No fingerprint match found.");
      return;
    }

    setProbe(capture.TemplateBase64);
    setMatchMethod("fingerprint");
    setMatched(match); // { worker_id, name, photo_url, vendor, pending_type, score }
    setPhase(PHASE.CONFIRMED);
    setMessage("Worker identified!");
    await autoSnap(); // auto-capture photo at the moment of match
  };

  // ── Manual select ─────────────────────────────────────────────────────────

  const handleWorkerSelect = async (w) => {
    setSel(w);
    await autoSnap(); // auto-capture when worker is selected
  };

  // ── Face scan (camera 1:N, matched server-side) ───────────────────────────

  const faceScan = async () => {
    setFaceBusy(true);
    setFaceMsg("Capturing…");
    try {
      let blob = photoFile;
      if (camera.ready) blob = await camera.snap();
      if (!blob) {
        setFaceMsg("No camera available — use 'Add Photo' below to upload a face photo, then scan again.");
        fileRef.current?.click();
        return;
      }
      setFaceMsg("Identifying worker…");
      const fd = new FormData();
      fd.append("company_id", String(companyId));
      fd.append("photo", blob, "face.jpg");
      const res = await api.post("/attendance/identify-face", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      setPhotoFile(blob);                       // same photo = proof AND server re-verification probe
      setPhotoPrev(URL.createObjectURL(blob));
      setMatchMethod("face");
      setMatched(res.data);                     // { worker_id, name, …, face_score }
      setFaceMsg("Worker identified!");
    } catch (err) {
      setFaceMsg(err.response?.data?.message || "Face identification failed — try again.");
    } finally {
      setFaceBusy(false);
    }
  };

  // ── Mark (unified) ────────────────────────────────────────────────────────

  const markMutation = useMutation({
    mutationFn: async ({ payload, file }) => {
      if (file) {
        const fd = new FormData();
        Object.entries(payload).forEach(([k, v]) => v != null && fd.append(k, String(v)));
        fd.append("photo", file);
        return api.post("/attendance/mark", fd, {
          headers: { "Content-Type": "multipart/form-data" },
        }).then(r => r.data);
      }
      return api.post("/attendance/mark", payload).then(r => r.data);
    },
    onSuccess: (data) => { toast.success(data.message, { duration: 5000 }); reset(); },
    onError:   (err)  => toast.error(err.response?.data?.message || "Mark failed."),
  });

  const handleMark = () => {
    const worker = confirmedWorker;
    const method = matched ? (matchMethod ?? "fingerprint") : "manual";
    if (method === "face" && !photoFile) {
      toast.error("Face marks need the captured photo — scan again.");
      return;
    }
    markMutation.mutate({
      payload: {
        worker_id:         worker.worker_id,
        company_id:        companyId,
        assignment_id:     worker.assignment_id,
        type:              worker.pending_type,
        method,
        probe_template:    method === "fingerprint" ? probeTemplate : undefined,
        location_type:     locationType,
        location_name:     resolvedLocation,
      },
      file: photoFile,
    });
  };

  const reset = () => {
    setPhase(PHASE.IDLE);
    setMatched(null);
    setProbe(null);
    setMessage("Click Scan to begin.");
    setCertError(false);
    setSel(null);
    setPhotoFile(null);
    setPhotoPrev(null);
    setSearch("");
    setMatchMethod(null);
    setFaceBusy(false);
    setFaceMsg("Point the camera at the worker's face, then scan.");
  };

  if (!companyId) {
    return (
      <div className="max-w-lg mx-auto card p-6 flex items-start gap-3">
        <AlertTriangle className="text-amber-500 shrink-0 mt-0.5" size={20} />
        <div>
          <p className="font-semibold">No company assigned</p>
          <p className="text-sm text-gray-500 mt-1">Log in with a company gate or admin account.</p>
        </div>
      </div>
    );
  }

  const ringColor = {
    [PHASE.IDLE]:      "border-gray-200",
    [PHASE.SCANNING]:  "border-brand-400 animate-pulse",
    [PHASE.MATCHING]:  "border-yellow-400 animate-pulse",
    [PHASE.CONFIRMED]: "border-green-400",
    [PHASE.ERROR]:     "border-red-300",
  }[phase];

  return (
    <div className="max-w-lg mx-auto space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">In / Out</h1>
        <p className="text-gray-500 text-sm mt-1">Mark worker IN/OUT — photo captured automatically</p>
      </div>

      {/* Location */}
      {isGateUser && fixedLocName ? (
        <div className="card flex items-center gap-3 py-3">
          <MapPin size={16} className="text-brand-500 shrink-0" />
          <div>
            <p className="text-xs text-gray-400 uppercase tracking-wide font-medium">Marking at</p>
            <p className="text-sm font-semibold text-gray-800">{fixedLocName}</p>
          </div>
        </div>
      ) : (
        <LocationSelector
          locationType={locationType}
          locationName={locationName}
          onTypeChange={handleLocTypeChange}
          onNameChange={setLocName}
        />
      )}

      {/* Camera preview — shown while not yet confirmed */}
      {!confirmedWorker && (
        <div className="card p-0 overflow-hidden rounded-xl">
          {camera.denied ? (
            <div className="flex items-center gap-2 px-4 py-3 text-xs text-amber-700 bg-amber-50">
              <VideoOff size={14} />
              Camera not available — photo proof can be uploaded manually
            </div>
          ) : (
            <div className="relative bg-gray-900">
              <video
                ref={camera.videoRef}
                autoPlay
                muted
                playsInline
                className="w-full h-48 object-cover"
              />
              <div className="absolute top-2 left-2 flex items-center gap-1.5 px-2 py-0.5 bg-black/50 rounded-full">
                <span className={`w-2 h-2 rounded-full ${camera.ready ? "bg-green-400 animate-pulse" : "bg-gray-400"}`} />
                <span className="text-xs text-white">{camera.ready ? "Live" : "Starting…"}</span>
              </div>
              <div className="absolute bottom-2 right-2 px-2 py-0.5 bg-black/50 rounded text-xs text-gray-300">
                Point camera at worker
              </div>
            </div>
          )}
        </div>
      )}

      {/* Mode toggle */}
      <div className="flex rounded-lg border border-gray-200 overflow-hidden">
        {[
          ["fingerprint", <Fingerprint size={15} key="fp" />, "Fingerprint"],
          ["face",        <ScanFace    size={15} key="fc" />, "Face"],
          ["manual",      <User        size={15} key="mn" />, "Manual"],
        ].map(([m, icon, label]) => (
          <button
            key={m}
            onClick={() => { setMode(m); reset(); }}
            className={`flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium transition-colors ${
              mode === m ? "bg-brand-600 text-white" : "bg-white text-gray-600 hover:bg-gray-50"
            }`}
          >
            {icon}{label}
          </button>
        ))}
      </div>

      {/* Hidden file input — fallback when camera unavailable */}
      <input
        ref={fileRef}
        type="file"
        accept="image/*"
        capture="environment"
        onChange={handleFileChange}
        className="hidden"
      />

      {/* ── FINGERPRINT: scan UI ── */}
      {mode === "fingerprint" && !confirmedWorker && (
        <div className="card flex flex-col items-center space-y-5 py-8">
          <div className={`w-32 h-32 rounded-full border-4 ${ringColor} flex items-center justify-center bg-gray-50 transition-all`}>
            {phase === PHASE.ERROR
              ? <XCircle className="w-16 h-16 text-red-400" />
              : <Fingerprint className={`w-16 h-16 ${phase === PHASE.IDLE ? "text-gray-200" : "text-brand-400"}`} />
            }
          </div>

          <p className="text-sm text-center text-gray-600 max-w-xs">{message}</p>

          {certError && (
            <div className="w-full p-3 bg-amber-50 rounded-lg border border-amber-200 space-y-2">
              <p className="text-sm font-medium text-amber-700 flex items-center gap-2">
                <AlertTriangle size={14} /> Biometric proxy not running
              </p>
              <code className="block text-xs bg-amber-100 rounded p-2 text-amber-800 whitespace-pre">
                {"cd biometric-agent\npy -3 sgibiosrv_proxy.py"}
              </code>
            </div>
          )}

          {phase === PHASE.ERROR && !certError && (
            <div className="w-full p-3 bg-red-50 rounded-lg border border-red-200 flex gap-2">
              <AlertTriangle size={16} className="text-red-500 mt-0.5 shrink-0" />
              <p className="text-sm text-red-700">{message}</p>
            </div>
          )}

          <div className="flex gap-2">
            {(phase === PHASE.IDLE || phase === PHASE.ERROR) && (
              <button onClick={scan} className="btn-primary px-8 py-3 text-base">
                <Fingerprint size={20} /> Scan Fingerprint
              </button>
            )}
            {(phase === PHASE.SCANNING || phase === PHASE.MATCHING) && (
              <button onClick={reset} className="btn-secondary">
                <RefreshCw size={16} /> Cancel
              </button>
            )}
          </div>
        </div>
      )}

      {/* ── FACE: camera 1:N match — works on any device, no scanner ── */}
      {mode === "face" && !confirmedWorker && (
        <div className="card flex flex-col items-center space-y-5 py-8">
          <div className={`w-32 h-32 rounded-full border-4 ${faceBusy ? "border-brand-400 animate-pulse" : "border-gray-200"} flex items-center justify-center bg-gray-50`}>
            <ScanFace className={`w-16 h-16 ${faceBusy ? "text-brand-400" : "text-gray-300"}`} />
          </div>
          <p className="text-sm text-center text-gray-600 max-w-xs">{faceMsg}</p>
          <p className="text-xs text-center text-gray-400 max-w-sm -mt-2">
            Works with the device camera — no scanner needed. The worker must have a
            photo enrolled (registration Photo step). Matched and re-verified on the server.
          </p>
          <button onClick={faceScan} disabled={faceBusy} className="btn-primary px-8 py-3 text-base">
            <ScanFace size={20} /> {faceBusy ? "Scanning…" : "Scan Face"}
          </button>
        </div>
      )}

      {/* ── MANUAL: worker search + list ── */}
      {mode === "manual" && !confirmedWorker && (
        <div className="card space-y-4">
          <p className="text-sm font-medium text-gray-700">Select worker deployed today:</p>
          <div className="relative">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Type worker name..."
              className="input pl-9"
            />
          </div>
          <div className="max-h-64 overflow-y-auto divide-y divide-gray-50 rounded-lg border border-gray-100">
            {assignedWorkers?.length === 0 && (
              <p className="text-center py-8 text-gray-400 text-sm">No workers deployed today.</p>
            )}
            {assignedWorkers?.map(w => (
              <button
                key={w.worker_id}
                onClick={() => handleWorkerSelect(w)}
                className="w-full flex items-center gap-3 px-4 py-3 hover:bg-brand-50 text-left"
              >
                {w.photo_url ? (
                  <img src={w.photo_url} alt="" className="w-10 h-10 rounded-full object-cover" />
                ) : (
                  <div className="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center font-bold text-gray-400">
                    {w.name?.[0]}
                  </div>
                )}
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-gray-900 text-sm">{w.name}</p>
                  <p className="text-xs text-gray-400">{w.vendor}</p>
                </div>
                <span className={`badge ${w.pending_type === "IN" ? "badge-green" : "badge-blue"}`}>
                  {w.pending_type}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* ── CONFIRMED WORKER ── */}
      {confirmedWorker && (
        <ConfirmedCard
          worker={confirmedWorker}
          score={matched?.score ?? matched?.face_score}
          scoreLabel={matched?.face_score != null ? `Similarity: ${Math.round(matched.face_score * 100)}%` : undefined}
          photoPreview={photoPreview}
          cameraReady={camera.ready}
          onPhotoAction={handlePhotoAction}
          onConfirm={handleMark}
          onCancel={reset}
          isPending={markMutation.isPending}
        />
      )}
    </div>
  );
}
