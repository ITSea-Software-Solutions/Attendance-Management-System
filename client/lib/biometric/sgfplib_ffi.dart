import 'dart:convert';
import 'dart:ffi';
import 'dart:io';

import 'package:ffi/ffi.dart';

/// Direct SecuGen FDx SDK access via `sgfplib.dll` (dart:ffi) — the same path
/// SecuGen's own diagnostic utility uses. Desktop apps don't need the WebAPI
/// service (that exists only because BROWSERS can't touch USB); with the SDK
/// installed, capture works with no extra service, port, or certificate.
///
/// Windows-only at runtime; safe to import everywhere (nothing loads unless
/// [SgfpDirect.instance] is used on Windows).
class SgfpDirect {
  SgfpDirect._();
  static final SgfpDirect instance = SgfpDirect._();

  DynamicLibrary? _lib;
  Pointer<Void> _h = nullptr;
  bool _inited = false;
  bool _opened = false;
  int _imgW = 0, _imgH = 0;
  String? loadError;

  /// Where the DLL was actually loaded from (shown in diagnostics).
  String? loadedFrom;

  // ── SDK constants (sgfplib.h) ────────────────────────────────────────────
  static const int _sgDevAuto = 0xFF; // SG_DEV_AUTO
  static const int _tplFormatIso = 0x0300; // TEMPLATE_FORMAT_ISO19794

  // ── function bindings ────────────────────────────────────────────────────
  late final int Function(Pointer<Pointer<Void>>) _create;
  late final int Function(Pointer<Void>) _terminate;
  late final int Function(Pointer<Void>, int) _init;
  late final int Function(Pointer<Void>, int) _openDevice;
  late final int Function(Pointer<Void>) _closeDevice;
  late final int Function(Pointer<Void>, Pointer<_SGDeviceInfo>) _getDeviceInfo;
  late final int Function(Pointer<Void>, Pointer<Uint8>, int, Pointer<Void>, int) _getImageEx;
  late final int Function(Pointer<Void>, int, int, Pointer<Uint8>, Pointer<Uint32>) _getImageQuality;
  late final int Function(Pointer<Void>, int) _setTemplateFormat;
  late final int Function(Pointer<Void>, Pointer<Uint32>) _getMaxTemplateSize;
  late final int Function(Pointer<Void>, Pointer<_SGFingerInfo>, Pointer<Uint8>, Pointer<Uint8>) _createTemplate;
  late final int Function(Pointer<Void>, Pointer<Uint8>, Pointer<Uint32>) _getTemplateSize;

  bool _bound = false;

  /// Every place a SecuGen install may have left sgfplib.dll. The bare name
  /// covers the standard search order (exe dir → System32 → PATH); the rest
  /// are explicit installs: FDx SDK, SGIBIOSRV (WebAPI), diagnostic utility.
  List<String> _candidatePaths() {
    final exeDir = File(Platform.resolvedExecutable).parent.path;
    final paths = <String>[
      'sgfplib.dll',
      '$exeDir\\sgfplib.dll',
      '$exeDir\\sdk\\sgfplib.dll',
      'C:\\Windows\\System32\\sgfplib.dll',
    ];
    // Scan SecuGen install folders for the DLL wherever their installers put it.
    for (final root in [
      'C:\\Program Files\\SecuGen',
      'C:\\Program Files (x86)\\SecuGen',
      'C:\\SecuGen',
    ]) {
      try {
        final dir = Directory(root);
        if (!dir.existsSync()) continue;
        for (final f in dir.listSync(recursive: true, followLinks: false)) {
          if (f is File &&
              f.path.toLowerCase().endsWith('\\sgfplib.dll')) {
            paths.add(f.path);
          }
        }
      } catch (_) {} // no permission / junction loops — skip
    }
    return paths;
  }

  /// Point Windows' DLL search at [dir] so sgfplib.dll can resolve its
  /// companion device DLLs (sgfdusdax64.dll etc.) living next to it.
  void _setDllDirectory(String dir) {
    try {
      final k32 = DynamicLibrary.open('kernel32.dll');
      final setDllDir = k32.lookupFunction<Int32 Function(Pointer<Utf16>),
          int Function(Pointer<Utf16>)>('SetDllDirectoryW');
      final p = dir.toNativeUtf16();
      setDllDir(p);
      calloc.free(p);
    } catch (_) {}
  }

  bool _bind() {
    if (_bound) return true;
    if (!Platform.isWindows) {
      loadError = 'Direct SDK is Windows-only.';
      return false;
    }
    final tried = <String>[];
    var archMismatch = false;
    for (final path in _candidatePaths()) {
      try {
        if (path.contains('\\')) {
          if (!File(path).existsSync()) continue;
          _setDllDirectory(File(path).parent.path);
        }
        _lib = DynamicLibrary.open(path);
        loadedFrom = path;
        break;
      } catch (e) {
        tried.add(path);
        // Windows error 193 = "%1 is not a valid Win32 application":
        // a 32-bit DLL found by a 64-bit app.
        if ('$e'.contains('193') || '$e'.contains('not a valid')) {
          archMismatch = true;
        }
      }
    }
    if (_lib == null) {
      loadError = archMismatch
          ? 'Found sgfplib.dll but it is 32-bit; TrueCrew is a 64-bit app. '
              'Install the 64-bit (x64) SecuGen FDx SDK, or copy the x64 '
              'sgfplib.dll next to truecrew.exe.'
          : 'sgfplib.dll not found (searched exe folder, System32, and '
              'C:\\Program Files\\SecuGen). Fix: copy the x64 sgfplib.dll '
              'next to truecrew.exe, or install the SecuGen FDx SDK. '
              'No WebAPI service is needed.';
      return false;
    }
    try {
      final lib = _lib!;
      _create = lib.lookupFunction<Uint32 Function(Pointer<Pointer<Void>>),
          int Function(Pointer<Pointer<Void>>)>('SGFPM_Create');
      _terminate = lib.lookupFunction<Uint32 Function(Pointer<Void>),
          int Function(Pointer<Void>)>('SGFPM_Terminate');
      _init = lib.lookupFunction<Uint32 Function(Pointer<Void>, Uint32),
          int Function(Pointer<Void>, int)>('SGFPM_Init');
      _openDevice = lib.lookupFunction<Uint32 Function(Pointer<Void>, Uint32),
          int Function(Pointer<Void>, int)>('SGFPM_OpenDevice');
      _closeDevice = lib.lookupFunction<Uint32 Function(Pointer<Void>),
          int Function(Pointer<Void>)>('SGFPM_CloseDevice');
      _getDeviceInfo = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Pointer<_SGDeviceInfo>),
          int Function(Pointer<Void>, Pointer<_SGDeviceInfo>)>('SGFPM_GetDeviceInfo');
      _getImageEx = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Pointer<Uint8>, Uint32, Pointer<Void>, Uint32),
          int Function(Pointer<Void>, Pointer<Uint8>, int, Pointer<Void>, int)>('SGFPM_GetImageEx');
      _getImageQuality = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Uint32, Uint32, Pointer<Uint8>, Pointer<Uint32>),
          int Function(Pointer<Void>, int, int, Pointer<Uint8>, Pointer<Uint32>)>('SGFPM_GetImageQuality');
      _setTemplateFormat = lib.lookupFunction<Uint32 Function(Pointer<Void>, Uint16),
          int Function(Pointer<Void>, int)>('SGFPM_SetTemplateFormat');
      _getMaxTemplateSize = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Pointer<Uint32>),
          int Function(Pointer<Void>, Pointer<Uint32>)>('SGFPM_GetMaxTemplateSize');
      _createTemplate = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Pointer<_SGFingerInfo>, Pointer<Uint8>, Pointer<Uint8>),
          int Function(Pointer<Void>, Pointer<_SGFingerInfo>, Pointer<Uint8>, Pointer<Uint8>)>('SGFPM_CreateTemplate');
      _getTemplateSize = lib.lookupFunction<
          Uint32 Function(Pointer<Void>, Pointer<Uint8>, Pointer<Uint32>),
          int Function(Pointer<Void>, Pointer<Uint8>, Pointer<Uint32>)>('SGFPM_GetTemplateSize');
      _bound = true;
      return true;
    } catch (e) {
      loadError = 'sgfplib.dll found but exports missing ($e) — SDK version mismatch.';
      return false;
    }
  }

  /// Ensure created+inited+device-open. Returns error text or null when ready.
  String? ensureReady() {
    if (!_bind()) return loadError;
    try {
      if (_h == nullptr) {
        final ph = calloc<Pointer<Void>>();
        final rc = _create(ph);
        if (rc != 0) {
          calloc.free(ph);
          return 'SGFPM_Create failed (code $rc).';
        }
        _h = ph.value;
        calloc.free(ph);
      }
      if (!_inited) {
        final rc = _init(_h, _sgDevAuto);
        if (rc != 0) return 'SGFPM_Init failed (code $rc) — is the FDx SDK installed?';
        _inited = true;
      }
      if (!_opened) {
        var rc = _openDevice(_h, 0);
        if (rc != 0) rc = _openDevice(_h, _sgDevAuto);
        if (rc != 0) {
          return 'Scanner not found (OpenDevice code $rc) — plug in the SecuGen device and check its driver.';
        }
        _opened = true;
        final info = calloc<_SGDeviceInfo>();
        if (_getDeviceInfo(_h, info) == 0) {
          _imgW = info.ref.imageWidth;
          _imgH = info.ref.imageHeight;
        }
        calloc.free(info);
        if (_imgW == 0 || _imgH == 0) {
          _imgW = 260; // HU20 sensor default
          _imgH = 300;
        }
        _setTemplateFormat(_h, _tplFormatIso);
      }
      return null;
    } catch (e) {
      return 'SDK call crashed: $e';
    }
  }

  /// Probe: device present + resolution. (ok, detail)
  (bool, String) probe() {
    final err = ensureReady();
    if (err != null) return (false, err);
    return (
      true,
      'SecuGen device OPEN via direct SDK '
          '(${loadedFrom ?? 'sgfplib.dll'}), sensor ${_imgW}x$_imgH — '
          'no WebAPI service needed.'
    );
  }

  ({String? template, int quality, String? error}) captureTemplate(
      {int timeoutMs = 10000}) {
    final err = ensureReady();
    if (err != null) return (template: null, quality: 0, error: err);
    final img = calloc<Uint8>(_imgW * _imgH);
    final q = calloc<Uint32>();
    try {
      final rc = _getImageEx(_h, img, timeoutMs, nullptr, 50);
      if (rc != 0) {
        return (
          template: null,
          quality: 0,
          error: rc == 54 || rc == 10004
              ? 'No finger detected — place a finger and retry.'
              : 'Capture failed (SDK code $rc).'
        );
      }
      _getImageQuality(_h, _imgW, _imgH, img, q);

      final maxSize = calloc<Uint32>();
      _getMaxTemplateSize(_h, maxSize);
      final tpl = calloc<Uint8>(maxSize.value == 0 ? 1024 : maxSize.value);
      final fi = calloc<_SGFingerInfo>();
      fi.ref.fingerNumber = 0; // unknown finger
      fi.ref.viewNumber = 0;
      fi.ref.impressionType = 0; // live scan plain
      fi.ref.imageQuality = q.value;
      final crc = _createTemplate(_h, fi, img, tpl);
      if (crc != 0) {
        calloc.free(tpl);
        calloc.free(fi);
        calloc.free(maxSize);
        return (template: null, quality: q.value, error: 'CreateTemplate failed (code $crc).');
      }
      final sz = calloc<Uint32>();
      var size = maxSize.value;
      if (_getTemplateSize(_h, tpl, sz) == 0 && sz.value > 0) size = sz.value;
      final bytes = tpl.asTypedList(size);
      final b64 = base64Encode(bytes);
      calloc.free(sz);
      calloc.free(tpl);
      calloc.free(fi);
      calloc.free(maxSize);
      return (template: b64, quality: q.value, error: null);
    } catch (e) {
      return (template: null, quality: 0, error: 'SDK call crashed: $e');
    } finally {
      calloc.free(img);
      calloc.free(q);
    }
  }

  void dispose() {
    try {
      if (_opened) _closeDevice(_h);
      if (_h != nullptr) _terminate(_h);
    } catch (_) {}
    _opened = false;
    _inited = false;
    _h = nullptr;
  }
}

/// typedef struct { DWORD DeviceID; BYTE DeviceSN[16]; DWORD ComPort;
///   DWORD ComSpeed; DWORD ImageWidth; DWORD ImageHeight; DWORD Contrast;
///   DWORD Brightness; DWORD Gain; DWORD ImageDPI; DWORD FWVersion; }
final class _SGDeviceInfo extends Struct {
  @Uint32()
  external int deviceId;
  @Array(16)
  external Array<Uint8> deviceSN;
  @Uint32()
  external int comPort;
  @Uint32()
  external int comSpeed;
  @Uint32()
  external int imageWidth;
  @Uint32()
  external int imageHeight;
  @Uint32()
  external int contrast;
  @Uint32()
  external int brightness;
  @Uint32()
  external int gain;
  @Uint32()
  external int imageDPI;
  @Uint32()
  external int fwVersion;
}

/// typedef struct { WORD FingerNumber; WORD ViewNumber; WORD ImpressionType;
///   WORD ImageQuality; }
final class _SGFingerInfo extends Struct {
  @Uint16()
  external int fingerNumber;
  @Uint16()
  external int viewNumber;
  @Uint16()
  external int impressionType;
  @Uint16()
  external int imageQuality;
}
