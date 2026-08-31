# SecuGen Android SDK (bundled)

`FDxSDKProFDAndroid.jar` + `AlCamera.jar` come from SecuGen's **FDx SDK Pro
for Android (FD) v4.22** (licensed to ITSea Software Solutions via SecuGen's
free SDK request form). Native device libs live in
`../src/main/jniLibs/<abi>/` (arm64-v8a + armeabi-v7a; includes HU20).

The app finds the SDK via reflection (`SecuGen.FDxSDKPro.JSGFPLib`) — v4.2x
`(Context, UsbManager)` constructor with fallback to the older
`(UsbManager)`. Nothing to install on phones: plug the scanner in via
USB-OTG, tap Allow, scan. To upgrade the SDK, replace these jars + the
jniLibs folders with the new version's files and rebuild.
