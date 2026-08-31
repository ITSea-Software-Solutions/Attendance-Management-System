# SecuGen Windows runtime DLLs (bundle-for-zero-setup)

Drop the SecuGen runtime DLLs here and every Windows build/zip ships them
beside truecrew.exe — the app's DLL discovery checks the exe folder FIRST,
so no SecuGen installation is needed on gate machines.

What to copy from a machine where the scanner already works
(PowerShell, run as-is):

    Compress-Archive -Path "C:\Program Files\SecuGen\**\*.dll" -DestinationPath "$env:USERPROFILE\Desktop\secugen-dlls.zip"

Typically that's sgfplib.dll plus its companion DLLs from
C:\Program Files\SecuGen\Drivers\HU20\ (and HU20A). 64-bit DLLs only —
the app is x64.

NOTE: the Windows USB *device driver* still comes from Windows Update
automatically on first plug (HU20 is certified); these DLLs remove the
SDK/software install, which is the manual step.
