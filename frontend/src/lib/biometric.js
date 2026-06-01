/**
 * Biometric capture — single source of truth.
 *
 * Previously the SecuGen SGIBIOSRV endpoint (https://localhost:8443) was
 * hardcoded in three separate components (FingerprintCapture, AttendanceMark,
 * FingerprintTest). It is now centralised here and made configurable, and a
 * hardware-free SIMULATION mode is available for demos / machines without the
 * SecuGen device + SGIBIOSRV proxy installed.
 *
 * Canonical hardware path:  browser → https://localhost:8443 (SecuGen
 * SGIBIOSRV proxy, started via `py -3 biometric-agent/sgibiosrv_proxy.py`)
 * → SecuGen SDK → USB device.  The old Node `biometric-agent/server.js`
 * WebSocket on :12345 is NOT used by the frontend and is dead code.
 *
 * Config (Vite env, read at dev-server start / build time):
 *   VITE_SGIBIOSRV_URL   override the SecuGen service URL (default below)
 *   VITE_BIOMETRIC_SIM   "true" → simulate captures/matches, no device needed
 */

export const BIOMETRIC_URL =
  import.meta.env.VITE_SGIBIOSRV_URL || "https://localhost:8443";

export const BIOMETRIC_SIMULATION =
  String(import.meta.env.VITE_BIOMETRIC_SIM ?? "").toLowerCase() === "true";

/** SecuGen SGIBIOSRV error codes → human messages. */
export const SGI_ERRORS = {
  51:    "Capture failed — try again",
  52:    "Memory failure",
  53:    "Device not found — check USB connection",
  54:    "No finger detected — place finger on scanner and try again",
  55:    "Device busy — wait a moment and retry",
  56:    "Poor image quality — clean the sensor and try again",
  57:    "Capture failed — try again",
  63:    "SecuGen service not responding",
  10004: "No finger detected — click Scan then immediately place your finger on the device",
};

const delay = (ms) => new Promise((r) => setTimeout(r, ms));

// Simulation produces opaque base64-ish tokens that are clearly fake but the
// same shape callers expect. `crypto.randomUUID` requires a secure context
// (https/localhost) which a plain-http demo (http://<ip>) does NOT have, so we
// use Math.random instead — safe everywhere.
const fakeToken = () =>
  btoa("SIMFMD:" + Date.now() + ":" + Math.random().toString(36).slice(2));

/**
 * Capture a fingerprint.
 * Resolves to the SGIBIOSRV shape: { ErrorCode, TemplateBase64, ImageQuality }.
 * In simulation mode it always succeeds with a fake template + plausible quality.
 * Throws (network error) only when the real proxy is unreachable — callers
 * treat that as "proxy not running".
 */
export async function captureFingerprint({ quality = 50, timeout = 10000, signal } = {}) {
  if (BIOMETRIC_SIMULATION) {
    await delay(900); // mimic device capture latency
    return {
      ErrorCode: 0,
      TemplateBase64: fakeToken(),
      ImageQuality: 80 + Math.floor(Math.random() * 15), // 80–94
      simulated: true,
    };
  }

  const res = await fetch(`${BIOMETRIC_URL}/SGIFPCapture`, {
    method: "POST",
    headers: { "Content-Type": "text/plain;charset=UTF-8" },
    body: `Timeout=${timeout}&Quality=${quality}&licstr=&templateFormat=ISO&imageWSQRate=0.75`,
    signal,
  });
  return res.json();
}

/**
 * Score two ISO templates against each other.
 * Resolves to the SGIBIOSRV shape: { ErrorCode, MatchingScore } (0–200 scale).
 * In simulation mode, identical tokens score 200, otherwise 0 — real identity
 * resolution for the demo is handled by pickSimulatedMatch() instead.
 */
export async function matchScore(template1, template2) {
  if (BIOMETRIC_SIMULATION) {
    return { ErrorCode: 0, MatchingScore: template1 === template2 ? 200 : 0 };
  }

  const res = await fetch(`${BIOMETRIC_URL}/SGIMatchScore`, {
    method: "POST",
    headers: { "Content-Type": "text/plain;charset=UTF-8" },
    body: `template1=${encodeURIComponent(template1)}&template2=${encodeURIComponent(template2)}&licstr=&templateFormat=ISO`,
  });
  return res.json();
}

/**
 * Demo-only: with no real finger there is no way to identify who is present,
 * so pick a random deployed worker and synthesise a believable match score.
 * Returns { worker, score } or null when the list is empty.
 */
export function pickSimulatedMatch(workers) {
  if (!workers?.length) return null;
  const worker = workers[Math.floor(Math.random() * workers.length)];
  return { worker, score: 150 + Math.floor(Math.random() * 50) }; // 150–199 / 200
}
