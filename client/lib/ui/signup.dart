import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/scope.dart';

/// SaaS self-service signup — mirrors the web portal: register a Company or
/// Vendor with minimal fields (GST/PAN can be added later on the web), start
/// on the free Trial; choosing a paid plan files an offline-payment upgrade
/// request that the AMS team activates.
class SignupScreen extends StatefulWidget {
  const SignupScreen({super.key});
  @override
  State<SignupScreen> createState() => _SignupScreenState();
}

class _SignupScreenState extends State<SignupScreen> {
  final _server = TextEditingController(text: AppConfig.defaultServer);
  final _orgName = TextEditingController();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _phone = TextEditingController();
  final _city = TextEditingController();
  String _orgType = 'company';
  String _plan = 'trial';
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    if (_orgName.text.trim().length < 3) {
      setState(() => _error = 'Organisation name is required (min 3 characters).');
      return;
    }
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'Your name is required.');
      return;
    }
    if (!RegExp(r'^\S+@\S+\.\S+$').hasMatch(_email.text.trim())) {
      setState(() => _error = 'Enter a valid email.');
      return;
    }
    if (_password.text.length < 8) {
      setState(() => _error = 'Password must be 8+ characters with letters and numbers.');
      return;
    }
    setState(() { _busy = true; _error = null; });
    try {
      final msg = await AppScope.of(context).signup(
        _server.text.trim().replaceAll(RegExp(r'/+$'), ''),
        {
          'org_type': _orgType,
          'org_name': _orgName.text.trim(),
          'name': _name.text.trim(),
          'email': _email.text.trim(),
          'password': _password.text,
          if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
          if (_city.text.trim().isNotEmpty) 'city': _city.text.trim(),
          'plan': _plan,
        },
      );
      if (mounted) {
        Navigator.pop(context); // AppScope user != null → root shows Home
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(msg), duration: const Duration(seconds: 6)));
      }
    } catch (e) {
      String m = 'Signup failed — check details and connectivity.';
      final s = e.toString();
      if (s.contains('org_name')) m = 'An organisation with this name already exists.';
      if (s.contains('email')) m = 'This email is already in use.';
      if (s.contains('429')) m = 'Too many signup attempts — wait a few minutes.';
      setState(() => _error = m);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Create your Hazri account')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'company', icon: Icon(Icons.factory), label: Text('Company')),
                  ButtonSegment(value: 'vendor', icon: Icon(Icons.engineering), label: Text('Vendor')),
                ],
                selected: {_orgType},
                onSelectionChanged: (s) => setState(() => _orgType = s.first),
              ),
              const SizedBox(height: 6),
              Text(
                _orgType == 'company'
                    ? 'We host workers at our sites and track their attendance.'
                    : 'We supply workers and deploy them to companies.',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 14),
              TextField(controller: _server, decoration: const InputDecoration(labelText: 'Server', prefixIcon: Icon(Icons.dns))),
              const SizedBox(height: 10),
              TextField(controller: _orgName, decoration: const InputDecoration(labelText: 'Organisation name *')),
              const SizedBox(height: 10),
              TextField(controller: _name, decoration: const InputDecoration(labelText: 'Your name *')),
              const SizedBox(height: 10),
              TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Email * (your login)')),
              const SizedBox(height: 10),
              TextField(controller: _password, obscureText: true, decoration: const InputDecoration(labelText: 'Password * (8+, letters & numbers)')),
              const SizedBox(height: 10),
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone')),
              const SizedBox(height: 10),
              TextField(controller: _city, decoration: const InputDecoration(labelText: 'City')),
              const SizedBox(height: 14),
              DropdownButtonFormField<String>(
                initialValue: _plan,
                decoration: const InputDecoration(labelText: 'Plan'),
                items: const [
                  DropdownMenuItem(value: 'trial', child: Text('Trial — free (3 users · 10 workers · 3 links)')),
                  DropdownMenuItem(value: 'professional', child: Text('Professional — ₹4,999/mo (25 · 500 · 25)')),
                  DropdownMenuItem(value: 'enterprise', child: Text('Enterprise — custom (100 · 5000 · 100)')),
                ],
                onChanged: (v) => setState(() => _plan = v ?? 'trial'),
              ),
              if (_plan != 'trial')
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Payment is settled offline: you start on Trial immediately and our team contacts you to activate the paid plan.',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(color: Colors.orange.shade800),
                  ),
                ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)),
                ),
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    : Text(_plan == 'trial' ? 'Start free on Trial' : 'Sign up & request upgrade'),
              ),
              const SizedBox(height: 10),
              Text(
                'Signup needs internet. By creating an account you agree to the Terms of Service and Privacy Policy (see the web portal).',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
