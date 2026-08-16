import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/scope.dart';
import 'signup.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _server = TextEditingController(text: AppConfig.defaultServer);
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _showPass = false;
  String? _error;

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await AppScope.of(context).login(
          _server.text.trim().replaceAll(RegExp(r'/+$'), ''),
          _email.text.trim(),
          _password.text);
    } catch (e) {
      final t = e.toString();
      String msg = 'Login failed — check your email and password.';
      if (t.contains('SocketException') || t.contains('connection') || t.contains('Connection')) {
        msg = 'Cannot reach the server — check the server address and your internet connection.';
      } else if (t.contains('401')) {
        msg = 'Wrong email or password.';
      } else if (t.contains('429')) {
        msg = 'Too many attempts — wait a minute and try again.';
      }
      setState(() => _error = msg);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 380),
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.all(24),
            children: [
              const Icon(Icons.fingerprint, size: 56, color: Color(0xFF10685A)),
              const SizedBox(height: 8),
              Text('TrueCrew',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineMedium),
              Text('Every worker verified — Aadhaar + biometric attendance',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 24),
              TextField(
                  controller: _server,
                  decoration: const InputDecoration(
                      labelText: 'Server', prefixIcon: Icon(Icons.dns))),
              const SizedBox(height: 12),
              TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(
                      labelText: 'Email', prefixIcon: Icon(Icons.mail))),
              const SizedBox(height: 12),
              TextField(
                  controller: _password,
                  obscureText: !_showPass,
                  onSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                      labelText: 'Password',
                      prefixIcon: const Icon(Icons.lock),
                      suffixIcon: IconButton(
                        icon: Icon(_showPass ? Icons.visibility_off : Icons.visibility),
                        onPressed: () => setState(() => _showPass = !_showPass),
                        tooltip: _showPass ? 'Hide password' : 'Show password',
                      ))),
              const SizedBox(height: 8),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(_error!,
                      style: const TextStyle(color: Colors.red, fontSize: 13)),
                ),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Sign in'),
              ),
              const SizedBox(height: 12),
              TextButton(
                onPressed: _busy
                    ? null
                    : () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const SignupScreen())),
                child: const Text('New organisation? Create your account — free trial'),
              ),
              const SizedBox(height: 4),
              Text('First sign-in needs internet. After that, the app works offline and syncs automatically.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 4),
              Text('v${AppConfig.appVersion}',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
