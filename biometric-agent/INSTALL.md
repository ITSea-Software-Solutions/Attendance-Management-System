# TrueCrew — SecuGen Scanner Setup (Windows gate stations)

Device: **SecuGen Hamster Pro 20 (HU20 / HU20-AP)** — the reference scanner;
other SecuGen models supported by the same stack.

Both the **web portal** and the **TrueCrew Windows app** talk to the scanner
through SecuGen's local WebAPI service, **SGIBIOSRV**, at
`https://localhost:8443`. The SDK's diagnostic utility talks to the driver
directly — so "utility works but the app doesn't" means exactly one thing:
**the SGIBIOSRV service isn't installed/running.**

## Setup (once per gate PC)

1. **FDx SDK / driver** — https://secugen.com/download-sdk/ → *FDx SDK Pro for
   Windows*, install as Administrator (installs the USB driver). Verify with
   the bundled diagnostic utility: it should capture a finger.
2. **SecuGen WebAPI (SGIBIOSRV)** — same downloads page → *SecuGen WebAPI*.
   Install, then open `services.msc` and confirm **SGIBIOSRV** is Running
   (set Startup type: Automatic).
3. **Verify the service**: open `https://localhost:8443/SGIFPCapture` in a
   browser → accept the self-signed-certificate warning once → you should get
   a JSON response (an error JSON is fine — it proves the service answers).
4. **TrueCrew Windows app**: open Diagnostics (🔧 icon) → Fingerprint scanner
   should show green with latency; use **Test capture** with a finger on the
   device. The app trusts the service's self-signed cert automatically.
5. **Web portal only**: browsers additionally need the CORS proxy —
   `py -3 biometric-agent\sgibiosrv_proxy.py` (forwards :12345 → :8443).
   The Windows app does NOT need the proxy.

> The old Node/WebSocket agent (`server.js`, port 12345 WS) is dead code kept
> for reference — nothing uses it.
