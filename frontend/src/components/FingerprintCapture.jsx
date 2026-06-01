import { useState, useRef } from "react";
import { Fingerprint, AlertTriangle, CheckCircle, RefreshCw, ExternalLink } from "lucide-react";
import { captureFingerprint, SGI_ERRORS, BIOMETRIC_SIMULATION } from "@/lib/biometric";

const STATUS = {
  IDLE:     "idle",
  SCANNING: "scanning",
  SUCCESS:  "success",
  ERROR:    "error",
};

export default function FingerprintCapture({ worker, onCaptured, onSkip }) {
  const [status, setStatus]     = useState(STATUS.IDLE);
  const [message, setMessage]   = useState("Click Scan to capture fingerprint.");
  const [quality, setQuality]   = useState(null);
  const [certError, setCertError] = useState(false);
  const abortRef = useRef(null);

  const scan = async () => {
    setStatus(STATUS.SCANNING);
    setCertError(false);
    setMessage("👇 Place your finger on the scanner NOW and hold it");

    abortRef.current = new AbortController();

    try {
      const data = await captureFingerprint({ signal: abortRef.current.signal });

      if (data.ErrorCode === 0) {
        const q = parseInt(data.ImageQuality, 10);
        setStatus(STATUS.SUCCESS);
        setQuality(q);
        setMessage(`Captured! Quality: ${q}%`);
        onCaptured(data.TemplateBase64, q);
      } else {
        setStatus(STATUS.ERROR);
        setMessage(SGI_ERRORS[data.ErrorCode] ?? `Error code ${data.ErrorCode}`);
      }
    } catch (err) {
      if (err.name === "AbortError") {
        setStatus(STATUS.IDLE);
        setMessage("Cancelled.");
        return;
      }
      // HTTPS cert not trusted yet
      setCertError(true);
      setStatus(STATUS.ERROR);
      setMessage("Cannot reach SecuGen service.");
    }
  };

  const cancel = () => {
    abortRef.current?.abort();
    setStatus(STATUS.IDLE);
    setMessage("Cancelled. Click Scan to try again.");
  };

  const ringColor = {
    [STATUS.IDLE]:     "border-gray-200",
    [STATUS.SCANNING]: "border-brand-500 animate-pulse",
    [STATUS.SUCCESS]:  "border-green-400",
    [STATUS.ERROR]:    "border-red-300",
  }[status];

  const iconColor = {
    [STATUS.IDLE]:     "text-gray-300",
    [STATUS.SCANNING]: "text-brand-500",
    [STATUS.SUCCESS]:  "text-green-500",
    [STATUS.ERROR]:    "text-red-400",
  }[status];

  return (
    <div className="card space-y-6">
      <div>
        <h2 className="font-semibold text-gray-900">Step 3: Fingerprint Enrollment</h2>
        <p className="text-sm text-gray-500 mt-1">
          Register {worker.name}'s fingerprint using the SecuGen device.
        </p>
      </div>

      {/* Scanner visual */}
      <div className="flex flex-col items-center space-y-4 py-4">
        <div className={`w-36 h-36 rounded-full border-4 ${ringColor} flex items-center justify-center bg-gray-50 transition-all`}>
          {status === STATUS.SUCCESS
            ? <CheckCircle className="w-20 h-20 text-green-500" />
            : <Fingerprint className={`w-20 h-20 ${iconColor} ${status === STATUS.SCANNING ? "animate-pulse" : ""}`} />
          }
        </div>
        <p className="text-sm text-center text-gray-600 max-w-xs">{message}</p>

        {status === STATUS.SUCCESS && quality != null && (
          <div className="w-full max-w-xs space-y-1">
            <div className="flex justify-between text-xs text-gray-500">
              <span>Quality</span>
              <span className={quality >= 65 ? "text-green-600" : "text-yellow-600"}>{quality}%</span>
            </div>
            <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
              <div
                className={`h-2 rounded-full ${quality >= 65 ? "bg-green-500" : "bg-yellow-400"}`}
                style={{ width: `${quality}%` }}
              />
            </div>
          </div>
        )}
      </div>

      {/* Proxy not running */}
      {certError && (
        <div className="p-4 bg-amber-50 rounded-lg border border-amber-200 space-y-2">
          <div className="flex items-center gap-2 text-amber-700">
            <AlertTriangle size={16} />
            <span className="text-sm font-medium">Biometric proxy not running</span>
          </div>
          <p className="text-xs text-amber-700">Start the proxy on this PC, then scan again:</p>
          <div className="text-xs font-mono bg-amber-100 rounded p-2 text-amber-800">
            cd biometric-agent<br />
            py -3 sgibiosrv_proxy.py
          </div>
        </div>
      )}

      {/* Generic error */}
      {status === STATUS.ERROR && !certError && (
        <div className="p-3 bg-red-50 rounded-lg border border-red-200 flex items-start gap-2">
          <AlertTriangle size={16} className="text-red-500 mt-0.5 shrink-0" />
          <div>
            <p className="text-sm font-medium text-red-700">Scan failed</p>
            <p className="text-xs text-red-600 mt-0.5">{message}</p>
            <p className="text-xs text-red-500 mt-1">
              Make sure SGIBIOSRV is running and the device is plugged in.
            </p>
          </div>
        </div>
      )}

      {/* Actions */}
      <div className="flex flex-wrap gap-3">
        {status === STATUS.SCANNING ? (
          <button type="button" onClick={cancel} className="btn-secondary">
            Cancel
          </button>
        ) : (
          <button type="button" onClick={scan} className="btn-primary">
            <Fingerprint size={16} />
            {status === STATUS.SUCCESS ? "Re-scan" : "Scan Fingerprint"}
          </button>
        )}
        <button type="button" onClick={onSkip} className="btn-secondary">
          Skip for Now
        </button>
      </div>
    </div>
  );
}
