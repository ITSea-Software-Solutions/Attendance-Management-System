@echo off
:: TrueCrew — browser CORS proxy for the SecuGen WebAPI (web portal ONLY).
:: The Windows/Android apps talk to the scanner DIRECTLY and do NOT need this.
:: Requires: SecuGen WebAPI service (SGIBIOSRV) running on https://localhost:8443.

echo Starting SGIBIOSRV CORS proxy on http://localhost:12345 ...
cd /d "%~dp0biometric-agent"
py -3 sgibiosrv_proxy.py
pause
