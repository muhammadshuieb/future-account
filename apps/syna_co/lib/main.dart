import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:url_launcher/url_launcher.dart';

const synaAppUrl = String.fromEnvironment(
  'SYNA_APP_URL',
  defaultValue: 'https://synaacc.cloud',
);

final _appUri = WebUri(synaAppUrl);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const SynaApp());
}

class SynaApp extends StatelessWidget {
  const SynaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'شركة ساينا',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF0D7377),
          brightness: Brightness.light,
        ),
        useMaterial3: true,
      ),
      home: const SynaShell(),
    );
  }
}

class SynaShell extends StatefulWidget {
  const SynaShell({super.key});

  @override
  State<SynaShell> createState() => _SynaShellState();
}

class _SynaShellState extends State<SynaShell> {
  InAppWebViewController? _controller;
  var _loading = true;
  var _progress = 0;

  InAppWebViewSettings get _settings => InAppWebViewSettings(
        javaScriptEnabled: true,
        domStorageEnabled: true,
        databaseEnabled: true,
        supportZoom: true,
        useShouldOverrideUrlLoading: true,
        useOnDownloadStart: true,
        javaScriptCanOpenWindowsAutomatically: true,
        supportMultipleWindows: true,
        mediaPlaybackRequiresUserGesture: false,
        allowsInlineMediaPlayback: true,
        iframeAllow: 'camera; microphone',
        iframeAllowFullscreen: true,
        mixedContentMode: MixedContentMode.MIXED_CONTENT_NEVER_ALLOW,
        transparentBackground: false,
        verticalScrollBarEnabled: true,
        horizontalScrollBarEnabled: true,
        allowFileAccess: true,
        allowContentAccess: true,
        geolocationEnabled: false,
      );

  bool _isAppHost(Uri uri) {
    final host = uri.host.toLowerCase();
    if (host.isEmpty) return true;
    final appHost = _appUri.host.toLowerCase();
    return host == appHost ||
        host.endsWith('.$appHost') ||
        host == 'localhost' ||
        host == '127.0.0.1';
  }

  Future<void> _openExternal(Uri uri) async {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  Future<bool> _handleBack() async {
    final controller = _controller;
    if (controller != null && await controller.canGoBack()) {
      await controller.goBack();
      return false;
    }
    return true;
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _handleBack()) {
          await SystemNavigator.pop();
        }
      },
      child: Scaffold(
        backgroundColor: const Color(0xFF0A1F2A),
        body: SafeArea(
          child: Stack(
            children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: _appUri),
                initialSettings: _settings,
                onWebViewCreated: (controller) => _controller = controller,
                onLoadStart: (controller, url) {
                  setState(() {
                    _loading = true;
                  });
                },
                onProgressChanged: (controller, progress) {
                  setState(() {
                    _progress = progress;
                    if (progress >= 100) _loading = false;
                  });
                },
                onLoadStop: (controller, url) {
                  setState(() => _loading = false);
                },
                onReceivedError: (controller, request, error) {
                  setState(() => _loading = false);
                },
                onPermissionRequest: (controller, request) async {
                  return PermissionResponse(
                    resources: request.resources,
                    action: PermissionResponseAction.GRANT,
                  );
                },
                shouldOverrideUrlLoading: (controller, action) async {
                  final uri = action.request.url;
                  if (uri == null) return NavigationActionPolicy.ALLOW;
                  if (_isAppHost(uri)) return NavigationActionPolicy.ALLOW;
                  await _openExternal(uri);
                  return NavigationActionPolicy.CANCEL;
                },
                onDownloadStartRequest: (controller, request) async {
                  final url = request.url;
                  await _openExternal(url);
                },
                onCreateWindow: (controller, action) async {
                  await showDialog<void>(
                    context: context,
                    barrierDismissible: false,
                    builder: (dialogContext) {
                      return Dialog.fullscreen(
                        child: Scaffold(
                          appBar: AppBar(
                            backgroundColor: const Color(0xFF0A1F2A),
                            foregroundColor: Colors.white,
                            title: const Text('معاينة / طباعة'),
                            leading: IconButton(
                              icon: const Icon(Icons.close),
                              onPressed: () => Navigator.of(dialogContext).pop(),
                            ),
                          ),
                          body: InAppWebView(
                            windowId: action.windowId,
                            initialSettings: _settings,
                            onCloseWindow: (_) {
                              if (dialogContext.mounted) {
                                Navigator.of(dialogContext).pop();
                              }
                            },
                            onPermissionRequest: (c, request) async {
                              return PermissionResponse(
                                resources: request.resources,
                                action: PermissionResponseAction.GRANT,
                              );
                            },
                          ),
                        ),
                      );
                    },
                  );
                  return true;
                },
              ),
              if (_loading)
                LinearProgressIndicator(
                  value: _progress == 0 ? null : _progress / 100,
                  minHeight: 3,
                  color: const Color(0xFF0D7377),
                  backgroundColor: const Color(0xFF123040),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
