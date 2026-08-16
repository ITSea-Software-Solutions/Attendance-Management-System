import 'package:dio/dio.dart';

import '../data/db.dart';

/// Thin authenticated HTTP client for the AMS Laravel API.
class Api {
  static Dio? _dio;
  static String? _base;

  static Future<Dio> client() async {
    final server = await LocalDb.getMeta('server') ?? '';
    if (_dio != null && _base == server) return _dio!;
    final token = await LocalDb.getMeta('token');
    _dio = Dio(BaseOptions(
      baseUrl: '$server/api',
      connectTimeout: const Duration(seconds: 8),
      receiveTimeout: const Duration(seconds: 20),
      headers: {
        'Accept': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      },
    ));
    _base = server;
    return _dio!;
  }

  static void reset() {
    _dio = null;
    _base = null;
  }

  /// Login against a server; returns the {token, user} payload.
  static Future<Map<String, dynamic>> login(
      String server, String email, String password) async {
    final dio = Dio(BaseOptions(
      baseUrl: '$server/api',
      connectTimeout: const Duration(seconds: 10),
      headers: {'Accept': 'application/json'},
    ));
    final r = await dio.post('/auth/login',
        data: {'email': email, 'password': password});
    return Map<String, dynamic>.from(r.data as Map);
  }
}
