/// Central app configuration.
///
/// The server URL is chosen at login and persisted in the local DB (meta
/// table). Biometric behaviour:
///  - SIM mode (default when no scanner service is reachable): captures and
///    matches are simulated, mirroring the server demo (BIOMETRIC_SIM).
///  - On Windows desktop, if SecuGen's SGIBIOSRV service is running on
///    https://localhost:8443 we use it for REAL captures (same service the
///    web portal uses). Android USB-OTG SDK integration lands in a later
///    release (see CLIENT_APP_DESIGN.md).
class AppConfig {
  static const String defaultServer = 'http://142.93.88.143';
  static const String sgibiosrvUrl = 'https://localhost:8443';

  /// SecuGen match-score scale is 0..200; server threshold default.
  static const int matchThreshold = 40;

  static const String appVersion = '0.9.5-preview';
}
