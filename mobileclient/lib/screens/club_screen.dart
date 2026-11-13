import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'login_screen.dart';

/// Войти в клуб: вошедший гость попадает в него сразу, остальные — через экран входа.
/// После входа открывается главный экран клуба (страницы списка закрываются).
Future<void> openClub(
  BuildContext context,
  Session session,
  int id,
  String name,
) async {
  final navigator = Navigator.of(context);
  if (!session.signedIn) {
    await navigator.push(
      MaterialPageRoute<void>(
        builder: (_) => LoginScreen(
          session: session,
          club: CatalogClub(id: id, name: name),
        ),
      ),
    );
    return;
  }
  await session.enterClub(id);
  navigator.popUntil((route) => route.isFirst);
}

/// Страница клуба из списка: фото, описание, контакты, зоны с ценой, свободными ПК и оборудованием.
class ClubScreen extends StatefulWidget {
  const ClubScreen({super.key, required this.session, required this.club});

  final Session session;
  final CatalogClub club;

  @override
  State<ClubScreen> createState() => _ClubScreenState();
}

class _ClubScreenState extends State<ClubScreen> {
  ClubDetails? _details;
  String? _error;
  bool _entering = false;
  int _photo = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final details = await widget.session.api.club(widget.club.id);
      if (mounted) setState(() => _details = details);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _enter() async {
    setState(() => _entering = true);
    try {
      await openClub(context, widget.session, widget.club.id, widget.club.name);
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      if (mounted) setState(() => _entering = false);
    }
  }

  void _copy(String label, String value) {
    Clipboard.setData(ClipboardData(text: value));
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text('$label скопирован')));
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final details = _details;
    final photos = details?.photos ?? [?widget.club.photoUrl];
    final member = session.signedIn && session.isMember(widget.club.id);

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          slivers: [
            SliverAppBar(
              pinned: true,
              expandedHeight: 280,
              backgroundColor: Brand.background,
              flexibleSpace: FlexibleSpaceBar(
                background: Stack(
                  fit: StackFit.expand,
                  children: [
                    if (photos.isEmpty)
                      ClubCover(name: widget.club.name)
                    else
                      PageView.builder(
                        itemCount: photos.length,
                        onPageChanged: (i) => setState(() => _photo = i),
                        itemBuilder: (_, i) => ClubCover(
                          name: widget.club.name,
                          imageUrl: photos[i],
                        ),
                      ),
                    const IgnorePointer(child: _BottomShade()),
                    if (photos.length > 1)
                      Positioned(
                        bottom: 14,
                        left: 0,
                        right: 0,
                        child: IgnorePointer(
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              for (var i = 0; i < photos.length; i++)
                                AnimatedContainer(
                                  duration: const Duration(milliseconds: 200),
                                  margin: const EdgeInsets.symmetric(
                                    horizontal: 3,
                                  ),
                                  width: i == _photo ? 18 : 6,
                                  height: 6,
                                  decoration: BoxDecoration(
                                    color: i == _photo
                                        ? Brand.accent
                                        : Colors.white.withValues(alpha: 0.5),
                                    borderRadius: BorderRadius.circular(3),
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 120),
              sliver: SliverList.list(
                children: [
                  Text(
                    widget.club.name,
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w800,
                      height: 1.15,
                    ),
                  ),
                  if (widget.club.address != null) ...[
                    const SizedBox(height: 8),
                    _IconLine(
                      FontAwesomeIcons.locationDot,
                      [
                        details?.city,
                        widget.club.address,
                      ].whereType<String>().join(', '),
                    ),
                  ],
                  const SizedBox(height: 16),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      if (details != null && details.pcs > 0)
                        FreeChip(free: details.freePcs, total: details.pcs),
                      if (widget.club.priceFrom != null)
                        InfoChip(
                          FontAwesomeIcons.tag,
                          'от ${money(widget.club.priceFrom!, 'KZT')}/час',
                        ),
                      if (details?.hours != null)
                        InfoChip(FontAwesomeIcons.clock, details!.hours!),
                      if (member)
                        const InfoChip(
                          FontAwesomeIcons.circleCheck,
                          'Вы клиент',
                          color: Brand.accentSoft,
                        ),
                    ],
                  ),
                  if (_error != null && details == null)
                    EmptyState(
                      icon: FontAwesomeIcons.wifi,
                      text: _error!,
                      onRetry: _load,
                    )
                  else if (details == null)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 48),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else ...[
                    if (details.description != null) ...[
                      const SizedBox(height: 24),
                      Text(
                        details.description!,
                        style: TextStyle(color: Brand.muted, height: 1.5),
                      ),
                    ],
                    if (details.phone != null || details.instagram != null) ...[
                      const SizedBox(height: 20),
                      GlassCard(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Column(
                          children: [
                            if (details.phone != null)
                              ListTile(
                                leading: const FaIcon(
                                  FontAwesomeIcons.phone,
                                  size: 16,
                                  color: Brand.accentSoft,
                                ),
                                title: Text(details.phone!),
                                trailing: FaIcon(
                                  FontAwesomeIcons.copy,
                                  size: 14,
                                  color: Brand.faint,
                                ),
                                onTap: () => _copy('Телефон', details.phone!),
                              ),
                            if (details.instagram != null)
                              ListTile(
                                leading: const FaIcon(
                                  FontAwesomeIcons.instagram,
                                  size: 18,
                                  color: Brand.accentSoft,
                                ),
                                title: Text('@${details.instagram}'),
                                trailing: FaIcon(
                                  FontAwesomeIcons.copy,
                                  size: 14,
                                  color: Brand.faint,
                                ),
                                onTap: () =>
                                    _copy('Instagram', '@${details.instagram}'),
                              ),
                          ],
                        ),
                      ),
                    ],
                    if (details.zones.isNotEmpty) ...[
                      const SizedBox(height: 28),
                      const SectionHeading(kicker: 'Зоны', title: 'Зоны и ПК'),
                      const SizedBox(height: 14),
                      for (final zone in details.zones) _ZoneCard(zone),
                    ],
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(20, 8, 20, 16),
        child: FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54)),
          onPressed: _entering ? null : _enter,
          icon: _entering
              ? const SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : const FaIcon(FontAwesomeIcons.rightToBracket, size: 16),
          label: Text(
            !session.signedIn
                ? 'Войти в клуб'
                : member
                ? 'Открыть клуб'
                : 'Стать клиентом клуба',
          ),
        ),
      ),
    );
  }
}

class _ZoneCard extends StatelessWidget {
  const _ZoneCard(this.zone);

  final ClubZoneInfo zone;

  static const _icons = {
    'cpu': FontAwesomeIcons.microchip,
    'gpu': FontAwesomeIcons.display,
    'ram': FontAwesomeIcons.memory,
    'monitor': FontAwesomeIcons.tv,
    'keyboard': FontAwesomeIcons.keyboard,
    'mouse': FontAwesomeIcons.computerMouse,
    'headset': FontAwesomeIcons.headphones,
    'chair': FontAwesomeIcons.chair,
  };

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GlassCard(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(
                    zone.name,
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                Text(
                  '${money(zone.pricePerHour, 'KZT')}/час',
                  style: const TextStyle(
                    color: Brand.accentSoft,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
            if (zone.description != null) ...[
              const SizedBox(height: 4),
              Text(zone.description!, style: TextStyle(color: Brand.muted)),
            ],
            if (zone.pcs > 0) ...[
              const SizedBox(height: 10),
              FreeChip(free: zone.freePcs, total: zone.pcs),
            ],
            if (zone.devices.isNotEmpty) ...[
              const SizedBox(height: 14),
              for (final device in zone.devices)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      SizedBox(
                        width: 26,
                        child: FaIcon(
                          _icons[device.key] ?? FontAwesomeIcons.circle,
                          size: 14,
                          color: Brand.faint,
                        ),
                      ),
                      SizedBox(
                        width: 110,
                        child: Text(
                          device.label,
                          style: TextStyle(color: Brand.faint, fontSize: 13),
                        ),
                      ),
                      Expanded(
                        child: Text(
                          device.value,
                          style: const TextStyle(fontSize: 14),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _IconLine extends StatelessWidget {
  const _IconLine(this.icon, this.text);

  final FaIconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: FaIcon(icon, size: 14, color: Brand.accentSoft),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(text, style: TextStyle(color: Brand.muted)),
        ),
      ],
    );
  }
}

class _BottomShade extends StatelessWidget {
  const _BottomShade();

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            Colors.black.withValues(alpha: 0.35),
            Colors.transparent,
            Brand.background.withValues(alpha: 0.9),
          ],
          stops: const [0, 0.45, 1],
        ),
      ),
    );
  }
}

/// Фото клуба или, без фото, градиент с первой буквой названия.
class ClubCover extends StatelessWidget {
  const ClubCover({super.key, required this.name, this.imageUrl});

  final String name;
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    final placeholder = DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Brand.violet, Brand.accent],
        ),
      ),
      child: Center(
        child: Text(
          name.isEmpty ? 'Z' : name.characters.first.toUpperCase(),
          style: TextStyle(
            fontSize: 72,
            fontWeight: FontWeight.w800,
            color: Colors.white.withValues(alpha: 0.85),
          ),
        ),
      ),
    );
    if (imageUrl == null) return placeholder;
    return Image.network(
      imageUrl!,
      fit: BoxFit.cover,
      errorBuilder: (_, _, _) => placeholder,
      loadingBuilder: (_, child, progress) =>
          progress == null ? child : ColoredBox(color: Brand.surface),
    );
  }
}

/// «Свободно 12 из 59»: зелёная точка, если есть свободные ПК.
class FreeChip extends StatelessWidget {
  const FreeChip({super.key, required this.free, required this.total});

  final int free;
  final int total;

  @override
  Widget build(BuildContext context) {
    final color = free > 0 ? Brand.success : Brand.faint;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(
            'Свободно $free из $total ПК',
            style: TextStyle(
              color: color,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class InfoChip extends StatelessWidget {
  const InfoChip(this.icon, this.text, {super.key, this.color});

  final FaIconData icon;
  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final foreground = color ?? Colors.white.withValues(alpha: 0.85);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          FaIcon(icon, size: 11, color: foreground),
          const SizedBox(width: 6),
          Text(
            text,
            style: TextStyle(
              color: foreground,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}
