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
      setState(() => _error =
          'Login failed — check the server address, credentials, and that you are online.');
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
                  obscureText: true,
                  onSubmitted: (_) => _submit(),
                  decoration: const InputDecoration(
                      labelText: 'Password', prefixIcon: Icon(Icons.lock))),
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
