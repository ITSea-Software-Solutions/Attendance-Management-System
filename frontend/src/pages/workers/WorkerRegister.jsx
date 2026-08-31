import { useState, useRef, useEffect } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useParams } from "react-router-dom";
import api from "@/lib/axios";
import toast from "react-hot-toast";
import AadhaarFlow from "@/components/AadhaarFlow";
import { useAuth } from "@/contexts/AuthContext";
import {
  CheckCircle, ChevronRight, User, CreditCard, Fingerprint,
  Upload, Camera, FileText, RefreshCw, AlertCircle, VideoOff, Download, Briefcase, IndianRupee, Landmark } from "lucide-react";
import { format } from "date-fns";

// ─── LivePhotoCapture ─────────────────────────────────────────────────────────
// Self-contained webcam component. Starts camera on mount, stops on unmount.

function LivePhotoCapture({ onCapture, initialPreview }) {
  const videoRef  = useRef(null);
  const streamRef = useRef(null);
  const fileRef   = useRef(null);
  const [ready,   setReady]   = useState(false);
  const [denied,  setDenied]  = useState(false);
  const [preview, setPreview] = useState(initialPreview ?? null);

  useEffect(() => {
    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } } })
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

  const capture = () => {
    const v = videoRef.current;
    if (!v || !ready) return;
    const canvas = document.createElement("canvas");
    canvas.width  = v.videoWidth  || 640;
    canvas.height = v.videoHeight || 480;
    canvas.getContext("2d").drawImage(v, 0, 0);
    canvas.toBlob(blob => {
      const url = URL.createObjectURL(blob);
      setPreview(url);
      onCapture(blob, url);
    }, "image/jpeg", 0.85);
  };

  const handleFile = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    setPreview(url);
    onCapture(file, url);
  };

  return (
    <div className="space-y-2">
      {!preview && (
        <div className="relative rounded-xl overflow-hidden bg-gray-900" style={{ aspectRatio: "4/3" }}>
          {denied ? (
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-white">
              <VideoOff size={24} className="text-gray-400" />
              <span className="text-sm text-gray-300">Camera not available</span>
            </div>
          ) : (
            <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />
          )}
          <div className="absolute top-2 left-2 flex items-center gap-1.5 px-2 py-0.5 bg-black/50 rounded-full">
            <span className={`w-1.5 h-1.5 rounded-full ${ready ? "bg-green-400 animate-pulse" : "bg-gray-400"}`} />
            <span className="text-xs text-white">{ready ? "Live" : denied ? "No camera" : "Starting…"}</span>
          </div>
        </div>
      )}

      {preview && (
        <div className="relative rounded-xl overflow-hidden" style={{ aspectRatio: "4/3" }}>
          <img src={preview} alt="Live photo" className="w-full h-full object-cover" />
          <div className="absolute top-2 left-2 px-2 py-0.5 bg-black/50 rounded-full text-xs text-white">
            Captured
          </div>
        </div>
      )}

      <div className="flex gap-2">
        {!denied && !preview && (
          <button type="button" onClick={capture} disabled={!ready}
            className="btn-primary flex-1 justify-center text-sm">
            <Camera size={14} /> {ready ? "Capture Photo" : "Starting camera…"}
          </button>
        )}
        {preview && (
          <button type="button" onClick={() => setPreview(null)}
            className="btn-secondary flex-1 justify-center text-sm">
            <RefreshCw size={14} /> Retake
          </button>
        )}
        {(denied || preview) && (
          <>
            <button type="button" onClick={() => fileRef.current?.click()}
              className="btn-secondary flex-1 justify-center text-sm">
              <Upload size={14} /> {preview ? "Upload instead" : "Upload Photo"}
            </button>
            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={handleFile} />
          </>
        )}
      </div>
    </div>
  );
}

// ─── helpers ─────────────────────────────────────────────────────────────────

function base64ToFile(b64, filename, mime = "image/png") {
  const bytes = atob(b64);
  const arr   = new Uint8Array(bytes.length);
  for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
  return new File([arr], filename, { type: mime });
}

function toDateInput(val) {
  if (!val) return "";
  return String(val).slice(0, 10);
}

// ─── config ───────────────────────────────────────────────────────────────────

const schema = z.object({
  vendor_id: z.coerce.number().min(1, "Please select a vendor").optional().or(z.literal("")),
  name:      z.string().min(2, "Name is required"),
  dob:       z.string().optional(),
  gender:    z.enum(["M", "F", "O"]).optional().or(z.literal("")),
  address:   z.string().optional(),
  city:      z.string().optional(),
  state:     z.string().optional(),
  pin:       z.string().regex(/^\d{6}$/, "Enter valid 6-digit PIN").optional().or(z.literal("")),
  phone:     z.string().optional(),
});

// Aadhaar is the ONLY identity document for worker registration (user
// decision 2026-08-16) — other doc types were removed from this flow.
const ID_TYPES = [
  { value: "aadhaar", label: "Aadhaar Card" },
];

const STEPS = [
  { id: "id_doc",      label: "Aadhaar",     icon: CreditCard  },
  { id: "details",     label: "Details",     icon: User        },
  { id: "employment",  label: "Employment",  icon: Briefcase   },
  { id: "photo",       label: "Photo",       icon: Camera      },
  { id: "confirm",     label: "Confirm",     icon: CheckCircle },
];

// ─── component ────────────────────────────────────────────────────────────────

export default function WorkerRegister() {
  const navigate    = useNavigate();
  const queryClient = useQueryClient();
  const { user }    = useAuth();
  const { id: workerId } = useParams();
  const isEdit      = !!workerId;
  const needsVendor = ["super_admin", "company_admin"].includes(user?.role);

  const docFileRef = useRef(null);

  // ── wizard state ─────────────────────────────────────────────────────────
  const [step, setStep]             = useState(0);

  // Step 0
  const [idType, setIdType]         = useState("aadhaar");
  const [idNumber, setIdNumber]     = useState("");
  const [idFile, setIdFile]         = useState(null);
  const [aadhaarData, setAadhaar]   = useState(null);
  const [aadhaarPdf, setAadhaarPdf] = useState(null);
  const [changeDoc, setChangeDoc]   = useState(false); // edit: toggle to re-upload
  // Aadhaar is MANDATORY: manual 12-digit entry when there is no PDF to extract
  const [manualEntry, setManualEntry]     = useState(false);
  const [manualAadhaar, setManualAadhaar] = useState("");

  // PAN — the alternative identity when a worker has no Aadhaar to hand.
  const [panNumber, setPanNumber] = useState("");
  const [panFile, setPanFile]     = useState(null);
  const [panData, setPanData]     = useState(null);
  const [panBusy, setPanBusy]     = useState(false);
  const validPan = /^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/.test(panNumber.trim());

  const readPanCard = async (file) => {
    if (!file) return;
    setPanBusy(true);
    try {
      const fd = new FormData();
      fd.append("file", file);
      const r = await api.post("/pan/extract", fd, { headers: { "Content-Type": "multipart/form-data" } });
      setPanData(r.data);
      setPanFile(file);
      setPanNumber(r.data.pan_number ?? "");
      if (r.data.name) setValue("name", r.data.name);
      if (r.data.dob) setValue("dob", r.data.dob);
      if (r.data.already_registered) {
        toast.error("This PAN is already registered to another worker.");
      } else {
        toast.success("PAN card read — check the details and continue.");
      }
    } catch (e) {
      toast.error(e.response?.data?.message ?? "Could not read the PAN card. Enter the number manually.");
      setPanFile(file);
    } finally {
      setPanBusy(false);
    }
  };

  const handlePanNext = () => {
    if (!consent) { toast.error("Please confirm the worker's consent first (checkbox at the top)."); return; }
    if (!validPan) { toast.error("Enter a valid PAN — ABCDE1234F."); return; }
    setStep(1);
  };
  // DPDP: registering org must confirm the worker's informed consent
  const [consent, setConsent] = useState(false);

  // Step 2

  // Step 3
  const [photoFile, setPhotoFile]           = useState(null);   // live capture blob/File
  const [photoPreview, setPhotoPreview]     = useState(null);   // live photo preview URL
  const [aadhaarPhoto, setAadhaarPhoto]     = useState(null);   // base64 from Aadhaar card
  const [rephoto, setRephoto]               = useState(false);  // edit: retake live photo
  const [uploadingPhoto, setUploadingPhoto] = useState(false);

  // Shared
  // Read-only: shown on the summary. Enrolment itself belongs to the apps,
  // which are the only clients that can talk to a scanner.
  const [fingerprint, setFP]    = useState(null);
  const [savedWorker, setSaved] = useState(null);

  // ── Employment & wages (step 2) ─────────────────────────────────────────
  const EMP_INIT = {
    designation: "", department: "", skill_category: "",
    uan: "", pf_number: "", esic_number: "",
    pf_applicable: true, esi_applicable: true,
    bank_account_number: "", bank_ifsc: "", bank_name: "",
    wage_type: "daily", daily_rate: "", monthly_rate: "", ot_multiplier: "",
  };
  const [emp, setEmp] = useState(EMP_INIT);
  const [wageHeads, setWageHeads] = useState({});   // { basic: 9000, da: 3600, ... }
  const [savingEmp, setSavingEmp] = useState(false);

  const { data: payCatalogue } = useQuery({
    queryKey: ["payroll-components"],
    queryFn: () => api.get("/payroll/components").then((r) => r.data),
    staleTime: 30 * 60_000,
  });

  // Fill an empty structure from the monthly rate, so the vendor starts from a
  // sensible split instead of a blank grid.
  const suggestHeads = async () => {
    const daily = emp.wage_type === "daily";
    const rate = Number(daily ? emp.daily_rate : emp.monthly_rate) || 0;
    if (rate <= 0) { toast.error(`Enter the ${daily ? "day" : "monthly"} rate first.`); return; }
    try {
      const div = payCatalogue?.defaults?.wage_divisor ?? 26;
      const r = await api.get("/payroll/components", {
        params: { monthly_rate: daily ? rate * div : rate },
      });
      const sug = r.data?.suggested ?? {};
      setWageHeads(daily
        ? Object.fromEntries(Object.entries(sug).map(([k, v]) => [k, Math.round(v / div)]))
        : sug);
      toast.success("Suggested split filled in — adjust as needed.");
    } catch { toast.error("Could not build a suggestion."); }
  };

  const headsTotal = Object.values(wageHeads).reduce((a, b) => a + (Number(b) || 0), 0);

  const saveEmployment = async (goNext = true) => {
    if (!savedWorker) return;
    setSavingEmp(true);
    try {
      const payload = {
        ...emp,
        daily_rate:    emp.daily_rate === "" ? null : Number(emp.daily_rate),
        monthly_rate:  emp.monthly_rate === "" ? null : Number(emp.monthly_rate),
        ot_multiplier: emp.ot_multiplier === "" ? null : Number(emp.ot_multiplier),
        wage_components: Object.fromEntries(
          Object.entries(wageHeads).filter(([, v]) => Number(v) > 0).map(([k, v]) => [k, Number(v)])),
      };
      const r = await api.put(`/workers/${savedWorker.id}`, payload);
      setSaved(r.data?.worker ?? r.data ?? savedWorker);
      toast.success("Employment details saved.");
      if (goNext) setStep(2);
    } catch (e) {
      const errs = e.response?.data?.errors;
      toast.error(errs ? Object.values(errs).flat()[0] : "Could not save employment details.");
    } finally {
      setSavingEmp(false);
    }
  };

  const { register, handleSubmit, setValue, formState: { errors } } = useForm({
    resolver: zodResolver(schema),
  });

  const { data: vendors } = useQuery({
    queryKey: ["vendors-list"],
    queryFn:  () => api.get("/vendors?per_page=100").then(r => r.data?.data ?? r.data),
    enabled:  needsVendor,
  });

  // ── Download ID document ─────────────────────────────────────────────────

  const downloadDoc = async (wId, docId, workerName, typeLabel) => {
    try {
      const r = await api.get(`/workers/${wId}/id-documents/${docId}/download`, { responseType: "blob" });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${workerName}_${typeLabel}`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download document.");
    }
  };

  // ── Edit: fetch existing worker ───────────────────────────────────────────

  const { data: existingWorker, isLoading: loadingWorker } = useQuery({
    queryKey: ["worker", workerId],
    queryFn:  () => api.get(`/workers/${workerId}`).then(r => r.data),
    enabled:  isEdit,
  });

  useEffect(() => {
    if (!existingWorker) return;

    // Pre-fill form fields
    setValue("name",    existingWorker.name    ?? "");
    setValue("dob",     toDateInput(existingWorker.dob));
    setValue("gender",  existingWorker.gender  ?? "");
    setValue("address", existingWorker.address ?? "");
    setValue("city",    existingWorker.city    ?? "");
    setValue("state",   existingWorker.state   ?? "");
    setValue("pin",     existingWorker.pin     ?? "");
    setValue("phone",   existingWorker.phone   ?? existingWorker.mobile ?? "");
    if (existingWorker.vendor_id) setValue("vendor_id", existingWorker.vendor_id);

    // Existing photo — shown in step 3
    if (existingWorker.photo_url) setPhotoPreview(existingWorker.photo_url);

    // Existing fingerprint — surfaced read-only on the summary
    if (existingWorker.fingerprint_enrolled_at) {
      setFP({ quality: existingWorker.fingerprint_quality ?? "?" });
    }

    // Primary ID document — shown in step 0
    const primaryDoc = existingWorker.idDocuments?.find(d => d.is_primary)
                    ?? existingWorker.idDocuments?.[0];
    if (primaryDoc) {
      setIdType(primaryDoc.id_type);
      setIdNumber(primaryDoc.id_number_masked ?? "");
    } else if (existingWorker.aadhaar_number_masked) {
      setIdType("aadhaar");
    }

    setSaved(existingWorker);
    setConsent(true); // existing worker — consent captured at original registration
    // Stay on step 0 so the user reviews existing data before continuing
  }, [existingWorker, setValue]);

  // ── Step 0 helpers ────────────────────────────────────────────────────────

  const handleAadhaarExtracted = (data, file) => {
    if (!consent) {
      toast.error("Please confirm the worker's consent first (checkbox at the top).");
      return;
    }
    setAadhaar(data);
    setAadhaarPdf(file);
    if (data.name)                 setValue("name", data.name);
    if (data.dob)                  setValue("dob", data.dob);
    if (data.gender)               setValue("gender", data.gender);
    if (data.address)              setValue("address", data.address);
    if (data.city)                 setValue("city", data.city ?? "");
    if (data.state)                setValue("state", data.state ?? "");
    if (data.pin)                  setValue("pin", data.pin ?? "");
    if (data.mobile || data.phone) setValue("phone", data.mobile ?? data.phone ?? "");
    if (data.photo_base64) {
      setAadhaarPhoto(data.photo_base64); // store as reference; live photo captured in step 3
    }
    // UIDAI "masked Aadhaar" PDFs carry only the last 4 digits — no hash can be
    // derived, and the server (rightly) refuses without one. Keep the auto-fill
    // but require the full 12-digit number before continuing.
    if (!data.aadhaar_hash) {
      setManualEntry(true);
      toast("Masked PDF detected — details auto-filled. Now type the full 12-digit Aadhaar number to verify.",
        { icon: "🔢", duration: 7000 });
      return;
    }
    setStep(1);
    toast.success("Aadhaar data auto-filled. Please review before saving.");
  };

  // Worker already has Aadhaar on file (edit mode) → no need to re-enter
  const hasExistingAadhaar = isEdit && !!existingWorker?.aadhaar_number_masked;

  const validManualAadhaar = /^\d{12}$/.test(manualAadhaar.trim());

  // Aadhaar path, no PDF: manual 12-digit entry
  const handleManualAadhaarNext = () => {
    if (!consent) { toast.error("Please confirm the worker's consent first (checkbox at the top)."); return; }
    if (!validManualAadhaar) { toast.error("Enter the 12-digit Aadhaar number."); return; }
    const pdfLast4 = aadhaarData?.aadhaar_number_masked?.slice(-4);
    if (pdfLast4 && manualAadhaar.trim().slice(-4) !== pdfLast4) {
      toast.error(`Number doesn't match the uploaded PDF (…${pdfLast4}). Check and re-enter.`);
      return;
    }
    setStep(1);
  };


  // ── Step 1: save / update worker ─────────────────────────────────────────

  const createWorker = useMutation({
    mutationFn: async (data) => {
      const payload = {
        ...data,
        vendor_id:              data.vendor_id ? Number(data.vendor_id) : undefined,
        aadhaar_number_masked:  aadhaarData?.aadhaar_number_masked,
        aadhaar_hash:           aadhaarData?.aadhaar_hash,          // extract path
        aadhaar_number:         manualAadhaar.trim() || undefined,  // manual path (hashed server-side)
        pan_number:             panNumber.trim().toUpperCase() || undefined,
        aadhaar_data_extracted: aadhaarData ?? undefined,
        consent: consent,
      };
      if (isEdit) return api.put(`/workers/${workerId}`, payload).then(r => r.data);
      return api.post("/workers", payload).then(r => r.data);
    },
    onSuccess: async (worker) => {
      setSaved(worker);
      if (!isEdit) {
        // Keep the PAN card with the worker, same as the Aadhaar PDF.
        if (panFile) {
          const panFd = new FormData();
          panFd.append("file", panFile);
          api.post(`/pan/upload/${worker.id}`, panFd,
            { headers: { "Content-Type": "multipart/form-data" } })
            .catch(() => toast.error("Worker saved, but the PAN card file did not upload."));
        }
        if (aadhaarPdf) {
          const fd = new FormData();
          fd.append("pdf", aadhaarPdf);
          fd.append("aadhaar_number_masked", aadhaarData?.aadhaar_number_masked ?? "");
          await api.post(`/aadhaar/upload/${worker.id}`, fd, {
            headers: { "Content-Type": "multipart/form-data" },
          }).catch(() => {});
        }
        const docFd = new FormData();
        docFd.append("id_type", idType);
        docFd.append("id_number_masked", idType === "aadhaar"
          ? (aadhaarData?.aadhaar_number_masked ?? "") : idNumber);
        docFd.append("is_primary", "1");
        if (idFile) docFd.append("document_image", idFile);
        await api.post(`/workers/${worker.id}/id-documents`, docFd, {
          headers: { "Content-Type": "multipart/form-data" },
        }).catch(() => {});
      } else if (isEdit && changeDoc) {
        // User chose to replace the ID document
        const docFd = new FormData();
        docFd.append("id_type", idType);
        docFd.append("id_number_masked", idType === "aadhaar"
          ? (aadhaarData?.aadhaar_number_masked ?? "") : idNumber);
        docFd.append("is_primary", "1");
        if (idFile) docFd.append("document_image", idFile);
        await api.post(`/workers/${worker.id}/id-documents`, docFd, {
          headers: { "Content-Type": "multipart/form-data" },
        }).catch(() => {});
      }
      setStep(3);
    },
    onError: (err) => {
      const errs = err.response?.data?.errors;
      toast.error(errs ? Object.values(errs).flat()[0] : "Failed to save worker details.");
    },
  });


  // ── Step 3: photo ─────────────────────────────────────────────────────────

  const handleLiveCapture = (blob, url) => {
    setPhotoFile(blob);
    setPhotoPreview(url);
  };

  const handlePhotoContinue = async () => {
    if (photoFile && savedWorker) {
      setUploadingPhoto(true);
      const fd = new FormData();
      fd.append("photo", photoFile);
      await api.post(`/workers/${savedWorker.id}/photo`, fd, {
        headers: { "Content-Type": "multipart/form-data" },
      }).catch(() => {});
      setUploadingPhoto(false);
    }
    setStep(4);
  };

  // ── Step 4: finish ────────────────────────────────────────────────────────

  const handleFinish = () => {
    queryClient.invalidateQueries({ queryKey: ["workers"] });
    if (isEdit) queryClient.invalidateQueries({ queryKey: ["worker", workerId] });
    navigate("/workers");
  };

  // ── Loading skeleton while fetching existing worker ───────────────────────

  if (isEdit && loadingWorker) {
    return (
      <div className="max-w-3xl mx-auto space-y-4">
        <div className="h-8 bg-gray-100 rounded w-48 animate-pulse" />
        <div className="card space-y-4">
          {[1, 2, 3, 4, 5, 6].map(i => (
            <div key={i} className="h-10 bg-gray-100 rounded animate-pulse" />
          ))}
        </div>
      </div>
    );
  }

  // Existing primary doc (for step 0 edit view)
  const primaryDoc = existingWorker?.id_documents?.find(d => d.is_primary)
                  ?? existingWorker?.id_documents?.[0];

  // ── render ────────────────────────────────────────────────────────────────

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEdit ? "Edit Worker" : "Register Worker"}
        </h1>
        <p className="text-gray-500 text-sm mt-1">
          {isEdit
            ? `Editing: ${existingWorker?.name ?? "…"}`
            : "ID Document → Details → Employment → Photo → Confirm"}
        </p>
      </div>

      {/* Stepper */}
      <div className="flex items-center gap-0">
        {STEPS.map((s, i) => (
          <div key={s.id} className="flex items-center flex-1 last:flex-none">
            <div className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
              i === step ? "bg-brand-600 text-white" :
              i < step   ? "bg-green-100 text-green-700" :
                           "text-gray-400"
            }`}>
              {i < step ? <CheckCircle size={16} /> : <s.icon size={16} />}
              <span className="hidden sm:block">{s.label}</span>
            </div>
            {i < STEPS.length - 1 && <ChevronRight size={16} className="text-gray-300 flex-shrink-0" />}
          </div>
        ))}
      </div>

      {/* ── Step 0: ID Document ───────────────────────────────────────────────── */}
      {step === 0 && (
        <div className="card space-y-5">
          <div>
            <h2 className="font-semibold text-gray-900">Identity document</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              {isEdit
                ? "Review or replace the worker's identity document."
                : "Aadhaar is preferred. If the worker does not have one to hand, a PAN card is enough to register them and start attendance."}
            </p>
          </div>

          {/* DPDP: informed-consent confirmation — required before any registration path */}
          {!isEdit && (
            <label className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer ${consent ? "border-brand-500 bg-brand-50" : "border-amber-300 bg-amber-50"}`}>
              <input
                type="checkbox"
                checked={consent}
                onChange={(e) => setConsent(e.target.checked)}
                className="mt-1 accent-brand-600"
              />
              <span className="text-sm text-gray-700">
                I confirm this worker has given <b>free and informed consent</b> for processing their
                identity documents and fingerprints for attendance purposes, as described in the{" "}
                <a href="/privacy.html" target="_blank" rel="noreferrer" className="underline text-brand-700">Privacy Policy</a>.
                <span className="block text-xs text-gray-500 mt-0.5">Required — recorded with a timestamp on the worker's record.</span>
              </span>
            </label>
          )}

          {/* ── EDIT: show existing doc ── */}
          {isEdit && !changeDoc && (
            <div className="space-y-4">
              {primaryDoc ? (
                <div className="flex items-start gap-4 p-4 rounded-xl bg-gray-50 border border-gray-200">
                  <div className="w-10 h-10 rounded-lg bg-brand-50 flex items-center justify-center shrink-0">
                    <CreditCard size={20} className="text-brand-600" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-medium text-gray-900">{primaryDoc.type_label}</p>
                    {primaryDoc.id_number_masked && (
                      <p className="text-sm text-gray-500 font-mono mt-0.5">{primaryDoc.id_number_masked}</p>
                    )}
                    <div className="flex flex-wrap items-center gap-2 mt-2">
                      {/* Aadhaar PDF (stored separately via Aadhaar upload) */}
                      {primaryDoc.id_type === "aadhaar" && existingWorker.has_aadhaar_pdf && (
                        <>
                          <span className="badge badge-green text-xs">
                            <FileText size={10} className="mr-1" /> Aadhaar PDF on file
                          </span>
                          <button
                            type="button"
                            onClick={async () => {
                              try {
                                const r = await api.get(`/aadhaar/download/${workerId}`, { responseType: "blob" });
                                const url = URL.createObjectURL(r.data);
                                const a = document.createElement("a");
                                a.href = url; a.download = `${existingWorker.name}_Aadhaar.pdf`;
                                document.body.appendChild(a); a.click();
                                document.body.removeChild(a); URL.revokeObjectURL(url);
                              } catch { toast.error("Could not download Aadhaar PDF."); }
                            }}
                            className="badge badge-blue text-xs cursor-pointer hover:opacity-80"
                          >
                            <Download size={10} className="mr-1" /> Download PDF
                          </button>
                        </>
                      )}
                      {/* Other ID document file */}
                      {primaryDoc.id_type !== "aadhaar" && primaryDoc.has_document && (
                        <>
                          <span className="badge badge-green text-xs">
                            <FileText size={10} className="mr-1" /> Document on file
                          </span>
                          <button
                            type="button"
                            onClick={() => downloadDoc(workerId, primaryDoc.id, existingWorker.name, primaryDoc.type_label)}
                            className="badge badge-blue text-xs cursor-pointer hover:opacity-80"
                          >
                            <Download size={10} className="mr-1" /> Download
                          </button>
                        </>
                      )}
                      {primaryDoc.id_type === "aadhaar" && !existingWorker.has_aadhaar_pdf && (
                        <span className="badge badge-gray text-xs">No PDF uploaded</span>
                      )}
                      {primaryDoc.id_type !== "aadhaar" && !primaryDoc.has_document && (
                        <span className="badge badge-gray text-xs">No file uploaded</span>
                      )}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-3 p-4 rounded-xl bg-amber-50 border border-amber-200">
                  <AlertCircle size={18} className="text-amber-500 shrink-0" />
                  <p className="text-sm text-amber-700">No ID document on record.</p>
                </div>
              )}

              <div className="flex gap-3 pt-2 border-t border-gray-100">
                <button type="button" onClick={() => setStep(1)} className="btn-primary">
                  Keep & Continue
                </button>
                <button
                  type="button"
                  onClick={() => { setChangeDoc(true); setAadhaar(null); setIdNumber(""); setIdFile(null); setAadhaarPhoto(null); }}
                  className="btn-secondary"
                >
                  <RefreshCw size={14} /> Change Document
                </button>
              </div>
            </div>
          )}

          {/* ── NEW or CHANGE: full doc form ── */}
          {(!isEdit || changeDoc) && (
            <>

              {!isEdit && (
                <div className="flex gap-2 flex-wrap">
                  {[["aadhaar", "Aadhaar"], ["pan", "PAN card"]].map(([v, label]) => (
                    <button key={v} type="button" onClick={() => setIdType(v)}
                      className={`rounded-lg px-3.5 py-2 text-sm font-medium border transition-colors ${
                        idType === v
                          ? "bg-brand-50 text-brand-700 border-brand-300"
                          : "bg-white text-gray-600 border-gray-200 hover:border-gray-300"}`}>
                      {label}
                    </button>
                  ))}
                  <span className="text-xs text-gray-400 self-center">
                    Either one registers the worker — the other can be added later.
                  </span>
                </div>
              )}

              {idType === "aadhaar" && !manualEntry && (
                <AadhaarFlow onExtracted={handleAadhaarExtracted} onSkip={() => setManualEntry(true)} />
              )}

              {idType === "pan" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-dashed border-gray-300 p-4 text-center">
                    <p className="text-sm text-gray-600">
                      Upload the PAN card — a photo of the card or an e-PAN PDF.
                      We read the number, name and date of birth from it.
                    </p>
                    <label className="btn-secondary mt-3 inline-flex cursor-pointer">
                      <Upload size={14} /> {panBusy ? "Reading…" : "Choose PAN card"}
                      <input type="file" accept="image/*,application/pdf" className="hidden"
                        disabled={panBusy}
                        onChange={(e) => readPanCard(e.target.files?.[0])} />
                    </label>
                    {panFile && (
                      <p className="text-xs text-gray-500 mt-2">{panFile.name}</p>
                    )}
                  </div>

                  {panData && (
                    <div className="rounded-lg bg-green-50 border border-green-200 p-3 text-sm space-y-0.5">
                      <p className="font-medium text-green-800">Read from the card</p>
                      <p className="text-green-700">
                        {panData.name}{panData.father_name ? ` · father: ${panData.father_name}` : ""}
                        {panData.dob ? ` · DOB ${panData.dob}` : ""}
                      </p>
                      {panData.holder_type && panData.holder_type !== "individual" && (
                        <p className="text-amber-700">
                          This PAN belongs to a {panData.holder_type}, not an individual — check the card.
                        </p>
                      )}
                    </div>
                  )}

                  <div>
                    <label className="label">
                      PAN number * <span className="text-gray-400 font-normal">(ABCDE1234F)</span>
                    </label>
                    <input
                      value={panNumber}
                      onChange={(e) => setPanNumber(e.target.value.toUpperCase().slice(0, 10))}
                      className="input font-mono tracking-widest uppercase"
                      placeholder="ABCDE1234F"
                    />
                    <p className="text-xs text-gray-400 mt-1">
                      Read from the card above, or type it in if the photo is unclear.
                    </p>
                  </div>

                  <div className="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
                    Registering on PAN alone is fine to get started. The worker stays
                    <b> pending</b> until a finger is enrolled from the TrueCrew app, and
                    the Aadhaar can be added later from the worker's page.
                  </div>

                  <button type="button" onClick={handlePanNext} className="btn-primary" disabled={!validPan}>
                    Continue to Details
                  </button>
                </div>
              )}

              {idType === "aadhaar" && manualEntry && (
                <div className="space-y-4">
                  <div>
                    <label className="label">Aadhaar Number * <span className="text-gray-400 font-normal">(12 digits — required)</span></label>
                    <input
                      value={manualAadhaar}
                      onChange={(e) => setManualAadhaar(e.target.value.replace(/\D/g, "").slice(0, 12))}
                      className="input font-mono tracking-widest"
                      placeholder="XXXXXXXXXXXX"
                      inputMode="numeric"
                    />
                    <p className="text-xs text-gray-400 mt-1">
                      Only the last 4 digits are stored; the full number is used once to
                      prevent duplicate registrations, then discarded.
                    </p>
                  </div>
                  <div className="flex gap-3">
                    <button type="button" onClick={() => setManualEntry(false)} className="btn-secondary">
                      Back to PDF upload
                    </button>
                    <button type="button" onClick={handleManualAadhaarNext} className="btn-primary" disabled={!validManualAadhaar}>
                      Continue to Details
                    </button>
                  </div>
                </div>
              )}

              {/* Cancel back to existing-doc view in edit mode for Aadhaar */}
              {isEdit && changeDoc && idType === "aadhaar" && (
                <button type="button" onClick={() => setChangeDoc(false)} className="btn-secondary w-full">
                  Cancel — keep existing document
                </button>
              )}
            </>
          )}
        </div>
      )}

      {/* ── Step 1: Details ───────────────────────────────────────────────────── */}
      {step === 1 && (
        <form onSubmit={handleSubmit((data) => createWorker.mutate(data))}>
          <div className="card space-y-5">
            <div className="flex items-center justify-between flex-wrap gap-2">
              <h2 className="font-semibold text-gray-900">Worker Details</h2>
              <div className="flex gap-2 flex-wrap">
                {aadhaarData && (
                  <span className="badge badge-green">
                    <CheckCircle size={12} className="mr-1" />Aadhaar auto-filled
                  </span>
                )}
                {(isEdit || idType !== "aadhaar") && (
                  <span className="badge badge-blue">
                    {ID_TYPES.find(t => t.value === idType)?.label}
                    {idNumber ? ` — ${idNumber}` : ""}
                  </span>
                )}
              </div>
            </div>

            {needsVendor && (
              <div>
                <label className="label">Vendor *</label>
                <select {...register("vendor_id")} className={`input ${errors.vendor_id ? "input-error" : ""}`}>
                  <option value="">— Select Vendor —</option>
                  {(vendors ?? []).map(v => (
                    <option key={v.id} value={v.id}>{v.name}</option>
                  ))}
                </select>
                {errors.vendor_id && <p className="text-red-500 text-xs mt-1">{errors.vendor_id.message}</p>}
              </div>
            )}

            <div>
              <label className="label">Full Name *</label>
              <input
                {...register("name")}
                className={`input ${errors.name ? "input-error" : ""}`}
                placeholder="Worker's full name"
              />
              {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name.message}</p>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Date of Birth</label>
                <input {...register("dob")} type="date" className="input" />
              </div>
              <div>
                <label className="label">Gender</label>
                <select {...register("gender")} className="input">
                  <option value="">Select</option>
                  <option value="M">Male</option>
                  <option value="F">Female</option>
                  <option value="O">Other</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="sm:col-span-2">
                <label className="label">Address</label>
                <textarea {...register("address")} rows={3} className="input resize-none" placeholder="Full address" />
              </div>
              <div>
                <label className="label">City</label>
                <input {...register("city")} className="input" placeholder="City" />
              </div>
              <div>
                <label className="label">State</label>
                <input {...register("state")} className="input" placeholder="State" />
              </div>
              <div>
                <label className="label">PIN Code</label>
                <input {...register("pin")} className="input" placeholder="6-digit PIN" maxLength={6} />
                {errors.pin && <p className="text-red-500 text-xs mt-1">{errors.pin.message}</p>}
              </div>
              <div>
                <label className="label">Mobile Number</label>
                <input {...register("phone")} type="tel" className="input" placeholder="Mobile number" />
              </div>
            </div>

            <div className="flex gap-3 pt-2 border-t border-gray-100">
              <button type="button" className="btn-secondary" onClick={() => setStep(0)}>Back</button>
              <button type="submit" disabled={createWorker.isPending} className="btn-primary">
                {createWorker.isPending ? "Saving..." : isEdit ? "Save Changes" : "Save & Continue"}
              </button>
            </div>
          </div>
        </form>
      )}


      {/* ── Step 2: Employment, statutory & wage structure ─────────────────── */}
      {step === 2 && savedWorker && (
        <div className="space-y-4">
          <div className="card space-y-4">
            <div>
              <h2 className="font-semibold text-gray-900 flex items-center gap-2">
                <Briefcase size={17} className="text-brand-600" /> Employment details
              </h2>
              <p className="text-sm text-gray-500 mt-0.5">
                Everything payroll needs. All optional here — you can complete it later from the
                worker's page, but wages cannot be computed until the monthly rate is set.
              </p>
            </div>

            <div className="grid md:grid-cols-3 gap-3">
              <div>
                <label className="label">Designation</label>
                <input className="input" maxLength={80} placeholder="e.g. Machine Operator"
                  value={emp.designation} onChange={(e) => setEmp({ ...emp, designation: e.target.value })} />
              </div>
              <div>
                <label className="label">Department</label>
                <input className="input" maxLength={80} placeholder="e.g. Press Shop"
                  value={emp.department} onChange={(e) => setEmp({ ...emp, department: e.target.value })} />
              </div>
              <div>
                <label className="label">Skill category</label>
                <select className="input" value={emp.skill_category}
                  onChange={(e) => setEmp({ ...emp, skill_category: e.target.value })}>
                  <option value="">Select…</option>
                  <option value="unskilled">Unskilled</option>
                  <option value="semi_skilled">Semi-skilled</option>
                  <option value="skilled">Skilled</option>
                  <option value="highly_skilled">Highly skilled</option>
                </select>
                <p className="text-[11px] text-gray-400 mt-1">Minimum wages are set against this grade.</p>
              </div>
            </div>
          </div>

          <div className="card space-y-4">
            <h2 className="font-semibold text-gray-900">Statutory (PF / ESI)</h2>
            <div className="grid md:grid-cols-3 gap-3">
              <div>
                <label className="label">UAN</label>
                <input className="input" inputMode="numeric" maxLength={12} placeholder="12 digits"
                  value={emp.uan} onChange={(e) => setEmp({ ...emp, uan: e.target.value.replace(/\D/g, "") })} />
              </div>
              <div>
                <label className="label">PF number</label>
                <input className="input" maxLength={30} value={emp.pf_number}
                  onChange={(e) => setEmp({ ...emp, pf_number: e.target.value })} />
              </div>
              <div>
                <label className="label">ESIC number</label>
                <input className="input" maxLength={20} value={emp.esic_number}
                  onChange={(e) => setEmp({ ...emp, esic_number: e.target.value })} />
              </div>
            </div>
            <div className="flex gap-5 flex-wrap">
              {[["pf_applicable", "PF applicable"], ["esi_applicable", "ESI applicable"]].map(([k, label]) => (
                <label key={k} className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" className="w-4 h-4" checked={emp[k]}
                    onChange={(e) => setEmp({ ...emp, [k]: e.target.checked })} />
                  {label}
                </label>
              ))}
              <span className="text-[11px] text-gray-400 self-center">
                ESI applies while gross stays under ₹{payCatalogue?.statutory?.esi?.gross_ceiling ?? 21000}.
              </span>
            </div>
          </div>

          <div className="card space-y-4">
            <h2 className="font-semibold text-gray-900 flex items-center gap-2">
              <Landmark size={16} className="text-brand-600" /> Bank account (for wage transfer)
            </h2>
            <div className="grid md:grid-cols-3 gap-3">
              <div>
                <label className="label">Account number</label>
                <input className="input" maxLength={24} value={emp.bank_account_number}
                  onChange={(e) => setEmp({ ...emp, bank_account_number: e.target.value.replace(/\s/g, "") })} />
              </div>
              <div>
                <label className="label">IFSC</label>
                <input className="input uppercase" maxLength={11} placeholder="HDFC0001234"
                  value={emp.bank_ifsc}
                  onChange={(e) => setEmp({ ...emp, bank_ifsc: e.target.value.toUpperCase() })} />
              </div>
              <div>
                <label className="label">Bank name</label>
                <input className="input" maxLength={80} value={emp.bank_name}
                  onChange={(e) => setEmp({ ...emp, bank_name: e.target.value })} />
              </div>
            </div>
          </div>

          <div className="card space-y-4">
            <div className="flex items-start justify-between gap-3 flex-wrap">
              <div>
                <h2 className="font-semibold text-gray-900 flex items-center gap-2">
                  <IndianRupee size={16} className="text-brand-600" /> Wage structure
                </h2>
                <p className="text-sm text-gray-500 mt-0.5">
                  Break the rate into heads so PF, ESI and the wage register are correct.
                  {emp.wage_type === "daily"
                    ? " Amounts are PER DAY and should add up to the day rate."
                    : " Amounts are per month and should add up to the monthly rate."}
                </p>
              </div>
              <button type="button" className="btn-secondary text-sm" onClick={suggestHeads}>
                <RefreshCw size={14} /> Suggest split
              </button>
            </div>

            <div className="grid md:grid-cols-3 gap-3">
              <div>
                <label className="label">Paid</label>
                <select className="input" value={emp.wage_type}
                  onChange={(e) => setEmp({ ...emp, wage_type: e.target.value })}>
                  <option value="daily">Per day (daily wage)</option>
                  <option value="monthly">Per month (salaried)</option>
                </select>
              </div>
              {emp.wage_type === "daily" ? (
                <div>
                  <label className="label">Rate per day (₹) *</label>
                  <input className="input" type="number" min="0" step="10" value={emp.daily_rate}
                    onChange={(e) => setEmp({ ...emp, daily_rate: e.target.value })} />
                  <p className="text-[11px] text-gray-400 mt-1">Paid for each day present.</p>
                </div>
              ) : (
                <div>
                  <label className="label">Monthly rate (₹) *</label>
                  <input className="input" type="number" min="0" step="100" value={emp.monthly_rate}
                    onChange={(e) => setEmp({ ...emp, monthly_rate: e.target.value })} />
                  <p className="text-[11px] text-gray-400 mt-1">
                    Day rate = this ÷ {payCatalogue?.defaults?.wage_divisor ?? 26}.
                  </p>
                </div>
              )}
              <div>
                <label className="label">Overtime multiplier</label>
                <input className="input" type="number" min="0" max="4" step="0.25"
                  placeholder="default" value={emp.ot_multiplier}
                  onChange={(e) => setEmp({ ...emp, ot_multiplier: e.target.value })} />
                <p className="text-[11px] text-gray-400 mt-1">
                  Blank = the company's rate for this grade. Holidays pay
                  {" "}{payCatalogue?.holiday_ot_multiplier ?? 2}× on top.
                </p>
              </div>
            </div>

            <div className="grid md:grid-cols-3 gap-3">
              {(payCatalogue?.components ?? []).filter((c) => c.type === "earning").map((c) => (
                <div key={c.code}>
                  <label className="label flex items-center gap-1.5">
                    {c.label}
                    {c.pf && <span className="text-[10px] bg-blue-50 text-blue-600 px-1 rounded">PF</span>}
                    {c.esi && <span className="text-[10px] bg-violet-50 text-violet-600 px-1 rounded">ESI</span>}
                  </label>
                  <input className="input" type="number" min="0" step="10"
                    value={wageHeads[c.code] ?? ""}
                    onChange={(e) => setWageHeads({ ...wageHeads, [c.code]: e.target.value })} />
                </div>
              ))}
            </div>

            {headsTotal > 0 && (
              <div className={`text-sm rounded-lg px-3 py-2 border ${
                Math.abs(headsTotal - (Number(emp.wage_type === "daily" ? emp.daily_rate : emp.monthly_rate) || 0)) < 1
                  ? "bg-emerald-50 border-emerald-200 text-emerald-800"
                  : "bg-amber-50 border-amber-200 text-amber-800"}`}>
                Heads total <b>₹{headsTotal.toLocaleString("en-IN")}</b>
                {emp.wage_type === "daily" ? " vs day rate " : " vs monthly rate "}
                <b>₹{(Number(emp.wage_type === "daily" ? emp.daily_rate : emp.monthly_rate) || 0).toLocaleString("en-IN")}</b>
                {Math.abs(headsTotal - (Number(emp.wage_type === "daily" ? emp.daily_rate : emp.monthly_rate) || 0)) < 1
                  ? " — matches."
                  : " — these should normally match."}
              </div>
            )}
          </div>

          <div className="flex gap-3">
            <button type="button" className="btn-primary" disabled={savingEmp}
              onClick={() => saveEmployment(true)}>
              Save &amp; Continue
            </button>
            <button type="button" className="btn-secondary" onClick={() => setStep(3)}>
              Skip for now
            </button>
            <button type="button" className="btn-secondary" onClick={() => setStep(1)}>Back</button>
          </div>
        </div>
      )}

      {step === 3 && savedWorker && (
        <div className="card space-y-5">
          <div>
            <h2 className="font-semibold text-gray-900">Worker Photos</h2>
            <p className="text-sm text-gray-500 mt-0.5">
              ID photo is from the identity document. Live photo is captured now for gate verification.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            {/* Left: ID Photo */}
            <div className="space-y-2">
              <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide flex items-center gap-1.5">
                <CreditCard size={12} /> ID Photo
              </p>
              <div className="rounded-xl overflow-hidden bg-gray-100 border border-gray-200" style={{ aspectRatio: "3/4" }}>
                {aadhaarPhoto ? (
                  <img
                    src={`data:image/png;base64,${aadhaarPhoto}`}
                    alt="ID photo"
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <div className="w-full h-full flex flex-col items-center justify-center text-gray-300 gap-2 px-3 text-center">
                    <CreditCard size={24} />
                    <span className="text-xs">
                      {idType === "aadhaar" ? "No photo in ID" : "Not available for this ID type"}
                    </span>
                  </div>
                )}
              </div>
              <p className="text-xs text-gray-400 text-center">From {ID_TYPES.find(t => t.value === idType)?.label}</p>
            </div>

            {/* Right: Live Photo */}
            <div className="space-y-2">
              <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide flex items-center gap-1.5">
                <Camera size={12} /> Live Photo
              </p>

              {/* Edit: show existing photo unless rephoto triggered */}
              {isEdit && existingWorker?.photo_url && !rephoto ? (
                <div className="space-y-2">
                  <div className="rounded-xl overflow-hidden border border-green-200" style={{ aspectRatio: "3/4" }}>
                    <img src={existingWorker.photo_url} alt="Current" className="w-full h-full object-cover" />
                  </div>
                  <button type="button" onClick={() => setRephoto(true)}
                    className="btn-secondary w-full text-xs justify-center">
                    <RefreshCw size={12} /> Retake
                  </button>
                </div>
              ) : (
                <LivePhotoCapture
                  key={rephoto ? "retake" : "initial"}
                  onCapture={handleLiveCapture}
                  initialPreview={!isEdit ? photoPreview : null}
                />
              )}

              <p className="text-xs text-gray-400 text-center">Captured at registration</p>
            </div>
          </div>

          <div className="flex gap-3 pt-2 border-t border-gray-100">
            <button type="button" className="btn-secondary" onClick={() => setStep(3)}>Back</button>
            <button
              type="button"
              onClick={handlePhotoContinue}
              disabled={uploadingPhoto}
              className="btn-primary"
            >
              {uploadingPhoto ? "Uploading…" : photoFile ? "Save Photo & Continue" : "Skip & Continue"}
            </button>
          </div>
        </div>
      )}

      {/* ── Step 4: Confirm ───────────────────────────────────────────────────── */}
      {step === 4 && savedWorker && (
        <div className="card text-center space-y-4">
          {/* Photos side-by-side */}
          <div className="flex gap-3 justify-center">
            {aadhaarPhoto && (
              <div className="text-center space-y-1">
                <img
                  src={`data:image/png;base64,${aadhaarPhoto}`}
                  alt="ID"
                  className="w-20 h-24 rounded-xl object-cover border-2 border-gray-200 shadow"
                />
                <p className="text-xs text-gray-400">ID Photo</p>
              </div>
            )}
            {photoPreview ? (
              <div className="text-center space-y-1">
                <img
                  src={photoPreview}
                  alt={savedWorker.name}
                  className="w-20 h-24 rounded-xl object-cover border-2 border-green-200 shadow"
                />
                <p className="text-xs text-gray-400">Live Photo</p>
              </div>
            ) : !aadhaarPhoto && (
              <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle className="w-10 h-10 text-green-600" />
              </div>
            )}
          </div>

          <div>
            <h2 className="text-xl font-bold text-gray-900">{savedWorker.name}</h2>
            <p className="text-gray-500 text-sm">
              {isEdit ? "Worker updated successfully" : "Worker registered successfully"}
            </p>
          </div>

          <div className="text-sm space-y-1">
            <p className="text-brand-600 font-medium">
              {ID_TYPES.find(t => t.value === idType)?.label}
              {idType !== "aadhaar" && idNumber ? ` — ${idNumber}` : ""}
            </p>
            {photoPreview
              ? <p className="text-green-600">✓ Live photo {isEdit && !photoFile ? "(unchanged)" : "saved"}</p>
              : <p className="text-amber-500">⚠ No live photo — can be added later from the worker list</p>
            }
            {fingerprint
              ? <p className="text-green-600 font-medium">
                  ✓ Fingerprint already enrolled (quality: {fingerprint.quality}%)
                </p>
              : <p className="text-amber-500">
                  ⚠ Fingerprint not enrolled — enrol it from the TrueCrew app on the
                  device with the scanner. The worker stays <b>pending</b> until then.
                </p>
            }
          </div>

          <button onClick={handleFinish} className="btn-primary mx-auto">
            Go to Worker List
          </button>
        </div>
      )}
    </div>
  );
}
