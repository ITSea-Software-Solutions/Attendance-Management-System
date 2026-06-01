/**
 * Fingerprint Diagnostic — tests SecuGen SGIBIOSRV directly.
 * Calls https://localhost:8443/SGIFPCapture (same as the SecuGen demo).
 */

import { useState, useRef, useCallback } from "react";
import {
  Fingerprint, RefreshCw, Play, Square,
  CheckCircle, AlertTriangle, Info, Activity, Cpu, Zap, ExternalLink,
} from "lucide-react";
import {
  captureFingerprint, matchScore as sgiMatchScore,
  SGI_ERRORS, BIOMETRIC_URL,
} from "@/lib/biometric";

const SCAN = { IDLE: "idle", SCANNING: "scanning", DONE: "done", ERROR: "error" };

function QualityBar({ value, label }) {
  const color = value >= 65 ? "bg-green-500" : value >= 40 ? "bg-yellow-400" : "bg-red-400";
  const text  = value >= 65 ? "text-green-600" : value >= 40 ? "text-yellow-600" : "text-red-500";
  return (
    <div className="space-y-1">
      {label && (
        <div className="flex justify-between text-xs text-gray-500">
          <span>{label}</span>
          <span className={`font-semibold ${text}`}>{value}%</span>
        </div>
      )}
      <div className="w-full h-3 bg-gray-200 rounded-full overflow-hidden">
        <div className={`h-3 rounded-full transition-all duration-500 ${color}`} style={{ width: `${value}%` }} />
      </div>
      <p className={`text-xs font-medium ${text}`}>
        {value >= 65 ? "Good — ready for enrollment" :
         value >= 40 ? "Fair — press firmer" :
                       "Poor — clean sensor, centre fingertip"}
      </p>
    </div>
  );
}

function InfoRow({ label, value, mono }) {
  return (
    <div className="flex items-center justify-between py-1.5 border-b border-gray-100 last:border-0">
      <span className="text-xs text-gray-500">{label}</span>
      <span className={`text-xs font-medium text-gray-800 ${mono ? "font-mono" : ""}`}>
        {value ?? <span className="text-gray-300">—</span>}
      </span>
    </div>
  );
}

export default function FingerprintTest() {
  const [scan, setScan]           = useState(SCAN.IDLE);
  const [scanMsg, setScanMsg]     = useState("Click Start Capture to test the scanner.");
  const [lastResult, setLastResult] = useState(null);   // full SGI response
  const [certError, setCertError] = useState(false);
  const [minQuality, setMinQuality] = useState(50);

  const [history, setHistory]     = useState([]);
  const [templates, setTemplates] = useState([]);   // store up to 2 captures for match test
  const [matchScore, setMatchScore] = useState(null);
  const [log, setLog]             = useState([]);
  const logEndRef             = useRef(null);
  const abortRef              = useRef(null);

  const addLog = useCallback((msg, type = "info") => {
    const ts = new Date().toLocaleTimeString();
    setLog(prev => [...prev.slice(-99), { ts, msg, type }]);
    setTimeout(() => logEndRef.current?.scrollIntoView({ behavior: "smooth" }), 50);
  }, []);

  const startScan = async () => {
    setScan(SCAN.SCANNING);
    setCertError(false);
    setLastResult(null);
    setScanMsg("Place your finger firmly on the scanner…");
    addLog(`→ SGIFPCapture  Quality=${minQuality}  Timeout=10000`);

    abortRef.current = new AbortController();

    try {
      const data = await captureFingerprint({ quality: minQuality, signal: abortRef.current.signal });
      addLog(`← ErrorCode=${data.ErrorCode}  Quality=${data.ImageQuality ?? "—"}  NFIQ=${data.NFIQ ?? "—"}  ${data.ImageWidth ?? "?"}×${data.ImageHeight ?? "?"}px  ${data.ImageDPI ?? "?"}dpi`);

      if (data.ErrorCode === 0) {
        const q = parseInt(data.ImageQuality, 10);
        setScan(SCAN.DONE);
        setScanMsg(`Captured! Quality: ${q}%  NFIQ: ${data.NFIQ}`);
        setLastResult(data);
        setMatchScore(null);
        setTemplates(prev => {
          const next = [...prev, data.TemplateBase64].slice(-2);
          return next;
        });
        setHistory(prev => [{
          quality: q, nfiq: data.NFIQ, ts: new Date().toLocaleTimeString(),
          serial: data.SerialNumber,
        }, ...prev.slice(0, 19)]);
      } else {
        setScan(SCAN.ERROR);
        setScanMsg(SGI_ERRORS[data.ErrorCode] ?? `ErrorCode ${data.ErrorCode}`);
        addLog(`Error: ${SGI_ERRORS[data.ErrorCode] ?? data.ErrorCode}`, "error");
      }
    } catch (err) {
      if (err.name === "AbortError") {
        setScan(SCAN.IDLE);
        setScanMsg("Cancelled.");
        addLog("Cancelled");
        return;
      }
      setCertError(true);
      setScan(SCAN.ERROR);
      setScanMsg("Cannot reach SecuGen service at https://localhost:8443");
      addLog("Fetch failed — cert not trusted or service not running", "error");
    }
  };

  const cancelScan = () => {
    abortRef.current?.abort();
    setScan(SCAN.IDLE);
    setScanMsg("Cancelled.");
  };

  const di = lastResult;
  const bestQ = history.length ? Math.max(...history.map(h => h.quality)) : null;
  const avgQ  = history.length ? Math.round(history.reduce((s, h) => s + h.quality, 0) / history.length) : null;
  const lastQ = history[0]?.quality ?? null;

  const ringColor = {
    [SCAN.IDLE]:     "border-gray-200",
    [SCAN.SCANNING]: "border-brand-500 animate-pulse",
    [SCAN.DONE]:     lastQ >= 65 ? "border-green-400" : "border-yellow-400",
    [SCAN.ERROR]:    "border-red-300",
  }[scan];

  const iconColor = {
    [SCAN.IDLE]:     "text-gray-300",
    [SCAN.SCANNING]: "text-brand-500",
    [SCAN.DONE]:     lastQ >= 65 ? "text-green-500" : "text-yellow-500",
    [SCAN.ERROR]:    "text-red-400",
  }[scan];

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <Activity size={22} className="text-brand-600" /> Fingerprint Diagnostics
          </h1>
          <p className="text-gray-500 text-sm mt-1">
            Tests the SecuGen scanner via SGIBIOSRV at{" "}
            <a href="https://localhost:8443" target="_blank" rel="noreferrer"
               className="underline text-brand-600 inline-flex items-center gap-1">
              https://localhost:8443 <ExternalLink size={11} />
            </a>
          </p>
        </div>
      </div>

      {/* Cert trust banner */}
      {certError && (
        <div className="p-4 bg-amber-50 rounded-lg border border-amber-200 space-y-2">
          <div className="flex items-center gap-2 text-amber-700 font-medium">
            <AlertTriangle size={16} /> Browser blocked the SGIBIOSRV connection
          </div>
          <p className="text-xs text-amber-700">
            Open{" "}
            <a href="https://localhost:8443/SGIFPCapture" target="_blank" rel="noreferrer"
               className="underline font-semibold">
              https://localhost:8443
            </a>{" "}
            in a new tab → click <strong>Advanced → Proceed to localhost</strong> → come back and retry.
          </p>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* ── Left — settings + device info ─────────────────────────────────── */}
        <div className="space-y-4">

          {/* Service status */}
          <div className="card space-y-3">
            <h2 className="font-semibold text-gray-800 flex items-center gap-2">
              <Cpu size={16} /> SGIBIOSRV Service
            </h2>
            <InfoRow label="Endpoint" value="https://localhost:8443" mono />
            <InfoRow label="Status"
              value={scan === SCAN.DONE ? "✓ Responding" : scan === SCAN.ERROR && !certError ? "✗ Error" : certError ? "✗ Cert not trusted" : "Unknown — run a capture"} />
            {lastResult && (
              <>
                <InfoRow label="Serial"      value={lastResult.SerialNumber} mono />
                <InfoRow label="Resolution"  value={lastResult.ImageDPI ? `${lastResult.ImageDPI} DPI` : null} />
                <InfoRow label="Image size"  value={lastResult.ImageWidth ? `${lastResult.ImageWidth}×${lastResult.ImageHeight}px` : null} />
                <InfoRow label="WSQ size"    value={lastResult.WSQImageSize ? `${lastResult.WSQImageSize} bytes` : null} />
              </>
            )}
          </div>

          {/* Capture settings */}
          <div className="card space-y-3">
            <h2 className="font-semibold text-gray-800 flex items-center gap-2">
              <Zap size={16} /> Capture Settings
            </h2>
            <div>
              <label className="label">Min Quality: <strong>{minQuality}%</strong></label>
              <input type="range" min={20} max={90} value={minQuality}
                onChange={e => setMinQuality(Number(e.target.value))}
                className="w-full accent-brand-600" />
              <div className="flex justify-between text-xs text-gray-400 mt-0.5">
                <span>20% (loose)</span><span>90% (strict)</span>
              </div>
            </div>
          </div>
        </div>

        {/* ── Centre — scanner ───────────────────────────────────────────────── */}
        <div className="space-y-4">
          <div className="card space-y-4">
            <h2 className="font-semibold text-gray-800 flex items-center gap-2">
              <Fingerprint size={16} /> Scanner
            </h2>

            <div className="flex justify-center">
              <div className={`w-40 h-40 rounded-full border-4 ${ringColor} flex items-center justify-center bg-gray-50 transition-all`}>
                {scan === SCAN.DONE && lastQ != null
                  ? <div className="text-center">
                      <CheckCircle className={`w-12 h-12 mx-auto ${lastQ >= 65 ? "text-green-500" : "text-yellow-500"}`} />
                      <p className={`text-2xl font-bold mt-1 ${lastQ >= 65 ? "text-green-600" : "text-yellow-600"}`}>{lastQ}%</p>
                    </div>
                  : <Fingerprint className={`w-20 h-20 ${iconColor} transition-colors`} />
                }
              </div>
            </div>

            <p className="text-sm text-center text-gray-600">{scanMsg}</p>

            {scan === SCAN.DONE && lastQ != null && (
              <QualityBar value={lastQ} label="Captured quality" />
            )}

            <div className="flex gap-2">
              {scan === SCAN.SCANNING ? (
                <button onClick={cancelScan} className="btn-secondary flex-1">
                  <Square size={14} /> Cancel
                </button>
              ) : (
                <button onClick={startScan} className="btn-primary flex-1">
                  <Play size={14} />
                  {scan === SCAN.DONE ? "Scan Again" : "Start Capture"}
                </button>
              )}
            </div>
          </div>

          {/* Template preview */}
          {lastResult?.TemplateBase64 && (
            <div className="card space-y-2">
              <h2 className="font-semibold text-gray-800 flex items-center gap-2">
                <Info size={16} /> Template
              </h2>
              <InfoRow label="Format"   value="ISO 19794-2" />
              <InfoRow label="Size"     value={`~${Math.round(lastResult.TemplateBase64.length * 3 / 4)} bytes`} />
              <InfoRow label="NFIQ"     value={lastResult.NFIQ ? `${lastResult.NFIQ} / 5 (1=best)` : null} />
              <div className="mt-2">
                <p className="text-xs text-gray-400 mb-1">Base64 preview</p>
                <p className="text-xs font-mono bg-gray-50 rounded p-2 break-all text-gray-600">
                  {lastResult.TemplateBase64.slice(0, 80)}…
                </p>
              </div>
            </div>
          )}

          {/* BMP preview */}
          {lastResult?.BMPBase64 && (
            <div className="card space-y-2">
              <h2 className="font-semibold text-gray-800 flex items-center gap-2">
                <Fingerprint size={16} /> Fingerprint Image
              </h2>
              <img
                src={`data:image/bmp;base64,${lastResult.BMPBase64}`}
                alt="Fingerprint"
                className="w-full rounded-lg border border-gray-200"
              />
            </div>
          )}

          {/* Match test — capture 2 fingers then compare */}
          <div className="card space-y-3">
            <h2 className="font-semibold text-gray-800 flex items-center gap-2">
              <CheckCircle size={16} /> Match Test (SGIMatchScore)
            </h2>
            <p className="text-xs text-gray-500">
              Capture finger 1, then capture finger 2 — then click Match.
            </p>
            <div className="flex gap-2 text-xs">
              <span className={`px-2 py-1 rounded-full ${templates.length >= 1 ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-400"}`}>
                Template 1 {templates.length >= 1 ? "✓" : "—"}
              </span>
              <span className={`px-2 py-1 rounded-full ${templates.length >= 2 ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-400"}`}>
                Template 2 {templates.length >= 2 ? "✓" : "—"}
              </span>
            </div>
            {matchScore != null && (
              <div className={`p-3 rounded-lg text-center ${matchScore >= 40 ? "bg-green-50 border border-green-200" : "bg-red-50 border border-red-200"}`}>
                <p className={`text-2xl font-bold ${matchScore >= 40 ? "text-green-600" : "text-red-500"}`}>{matchScore}</p>
                <p className={`text-xs mt-0.5 ${matchScore >= 40 ? "text-green-600" : "text-red-500"}`}>
                  {matchScore >= 40 ? "MATCH ✓" : "NO MATCH ✗"} (threshold: 40)
                </p>
              </div>
            )}
            <button
              disabled={templates.length < 2}
              onClick={async () => {
                addLog(`→ SGIMatchScore  comparing 2 templates`);
                try {
                  const d = await sgiMatchScore(templates[0], templates[1]);
                  addLog(`← ErrorCode=${d.ErrorCode}  MatchingScore=${d.MatchingScore ?? "—"}`);
                  setMatchScore(d.ErrorCode === 0 ? (d.MatchingScore ?? 0) : null);
                } catch (e) {
                  addLog("Match request failed", "error");
                }
              }}
              className="btn-primary w-full text-sm"
            >
              Match Templates
            </button>
            {templates.length >= 2 && (
              <button onClick={() => { setTemplates([]); setMatchScore(null); }} className="text-xs text-gray-400 hover:text-red-500 w-full text-center">
                Clear templates
              </button>
            )}
          </div>
        </div>

        {/* ── Right — stats + history + log ─────────────────────────────────── */}
        <div className="space-y-4">

          <div className="card space-y-3">
            <h2 className="font-semibold text-gray-800 flex items-center gap-2">
              <Activity size={16} /> Session Stats
            </h2>
            <div className="grid grid-cols-3 gap-3">
              {[
                { label: "Scans", value: history.length },
                { label: "Best",  value: bestQ != null ? `${bestQ}%` : "—" },
                { label: "Avg",   value: avgQ  != null ? `${avgQ}%`  : "—" },
              ].map(s => (
                <div key={s.label} className="bg-gray-50 rounded-lg p-3 text-center">
                  <p className="text-xl font-bold text-gray-900">{s.value}</p>
                  <p className="text-xs text-gray-400 mt-0.5">{s.label}</p>
                </div>
              ))}
            </div>
          </div>

          <div className="card space-y-2">
            <h2 className="font-semibold text-gray-800 flex items-center justify-between gap-2">
              <span className="flex items-center gap-2"><Fingerprint size={16} /> History</span>
              {history.length > 0 && (
                <button onClick={() => setHistory([])} className="text-xs text-gray-400 hover:text-red-500">Clear</button>
              )}
            </h2>
            {history.length === 0 ? (
              <p className="text-xs text-gray-400 text-center py-4">No captures yet.</p>
            ) : (
              <div className="space-y-1.5 max-h-52 overflow-y-auto">
                {history.map((h, i) => (
                  <div key={i} className="flex items-center gap-3 py-1.5 border-b border-gray-100 last:border-0">
                    <span className="text-xs text-gray-400 w-16 shrink-0">{h.ts}</span>
                    <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                      <div
                        className={`h-2 rounded-full ${h.quality >= 65 ? "bg-green-500" : h.quality >= 40 ? "bg-yellow-400" : "bg-red-400"}`}
                        style={{ width: `${h.quality}%` }}
                      />
                    </div>
                    <span className={`text-xs font-semibold w-10 text-right shrink-0 ${h.quality >= 65 ? "text-green-600" : h.quality >= 40 ? "text-yellow-600" : "text-red-500"}`}>
                      {h.quality}%
                    </span>
                    <span className="text-xs text-gray-400 w-8 shrink-0">N{h.nfiq}</span>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="card space-y-2">
            <h2 className="font-semibold text-gray-800 flex items-center justify-between gap-2">
              <span className="flex items-center gap-2"><Info size={16} /> Log</span>
              <button onClick={() => setLog([])} className="text-xs text-gray-400 hover:text-red-500">Clear</button>
            </h2>
            <div className="bg-gray-950 rounded-lg p-2 h-52 overflow-y-auto font-mono text-xs space-y-0.5">
              {log.length === 0 && <p className="text-gray-600">Waiting…</p>}
              {log.map((l, i) => (
                <div key={i} className={l.type === "error" ? "text-red-400" : l.type === "warn" ? "text-yellow-400" : "text-green-300"}>
                  <span className="text-gray-600">{l.ts} </span>{l.msg}
                </div>
              ))}
              <div ref={logEndRef} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
