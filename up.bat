@echo off
title AMS - Starting Services

:: ── Create .env if missing ────────────────────────────────────────────────────
if not exist ".env" (
    copy ".env.example" ".env" >nul
    echo [AMS] Created .env from .env.example
)

:: ── Generate APP_KEY if blank ─────────────────────────────────────────────────
findstr /R "^APP_KEY=$" ".env" >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [AMS] Generating APP_KEY...
    for /f "delims=" %%K in ('powershell -NoProfile -Command "[Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))"') do set GENERATED_KEY=%%K
    powershell -NoProfile -Command "(Get-Content '.env') -replace '^APP_KEY=.*', 'APP_KEY=base64:%GENERATED_KEY%' | Set-Content '.env'"
    echo [AMS] APP_KEY saved to .env
)

:: ── Start Docker ──────────────────────────────────────────────────────────────
echo [AMS] Starting Docker containers...
docker compose up -d
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Failed to start. Is Docker Desktop running?
    pause
    exit /b 1
)

echo [AMS] Up. App: http://localhost   API: http://localhost/api
echo [AMS] Biometrics live in the desktop and Android apps — a browser cannot
echo         reach a scanner, so nothing extra runs here.
pause
