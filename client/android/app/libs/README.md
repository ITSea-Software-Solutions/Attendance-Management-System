# SecuGen Android SDK (FDxSDKPro.aar)

Drop SecuGen's **FDxSDKPro.aar** (from "FDx SDK Pro for Android",
https://secugen.com/download-sdk/ — licensed download) into THIS folder and
rebuild the APK. The app auto-detects it (reflection) and switches from
simulated to REAL USB-OTG captures. Without the AAR everything still builds
and runs; Diagnostics shows "SDK AAR missing".
