/// Код входа из QR на экране ПК: ссылка вида https://zafiro.kz/pc-login/<40 символов>.
/// Другие QR-коды — null.
String? pcLoginCode(String? raw) {
  final uri = Uri.tryParse(raw?.trim() ?? '');
  if (uri == null || !(uri.isScheme('https') || uri.isScheme('http'))) {
    return null;
  }
  final segments = uri.pathSegments;
  if (segments.length != 2 || segments[0] != 'pc-login') return null;
  return RegExp(r'^[A-Za-z0-9]{40}$').hasMatch(segments[1])
      ? segments[1]
      : null;
}
