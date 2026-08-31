# SecuGen SDK is used ONLY via reflection (Class.forName) — R8 sees no static
# references and would strip it entirely. Keep every SDK class as-is; classes
# they reference (e.g. AlCamera's default-package helpers) are kept
# transitively by R8.
-keep class SecuGen.FDxSDKPro.** { *; }
-keep class SecuGen.** { *; }
-dontwarn SecuGen.**
