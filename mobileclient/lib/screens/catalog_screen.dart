import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:geolocator/geolocator.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../geo.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'club_screen.dart';

/// Первый экран приложения: клубы Zafiro в городе гостя. Город находится по геолокации
/// (или выбирается вручную) и запоминается.
class CatalogScreen extends StatefulWidget {
  const CatalogScreen({super.key, required this.session});

  final Session session;

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  List<City> _cities = const [];
  List<CatalogClub> _clubs = const [];
  bool _loading = true;
  bool _locating = false;
  String? _error;
  int? _busyId;

  Session get _session => widget.session;

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    await _load();
    if (_session.city == null && _error == null) await _locate();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final catalog = await _session.api.catalog(city: _session.city);
      if (!mounted) return;
      setState(() {
        _cities = catalog.cities;
        _clubs = catalog.clubs;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// Город по геолокации: ближайший из списка. Нет доступа — гость выберет город сам.
  Future<void> _locate() async {
    setState(() => _locating = true);
    final position = await _position();
    if (!mounted) return;
    setState(() => _locating = false);
    final city = position == null
        ? null
        : nearestCity(_cities, position.latitude, position.longitude);
    if (city == null) {
      _showMessage('Не удалось определить город. Выберите его из списка.');
      return;
    }
    await _chooseCity(city);
  }

  static Future<Position?> _position() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return null;
      }
      return await Geolocator.getLastKnownPosition() ??
          await Geolocator.getCurrentPosition(
            locationSettings: const LocationSettings(
              accuracy: LocationAccuracy.low,
              timeLimit: Duration(seconds: 15),
            ),
          );
    } catch (_) {
      return null;
    }
  }

  Future<void> _chooseCity(City city) async {
    await _session.setCity(city.key);
    await _load();
  }

  Future<void> _pickCity() async {
    final city = await showModalBottomSheet<City>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Brand.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (sheetContext) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.sizeOf(sheetContext).height * 0.8,
          ),
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
            children: [
              const SectionHeading(kicker: 'Город', title: 'Выберите город'),
              const SizedBox(height: 12),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const FaIcon(
                  FontAwesomeIcons.locationCrosshairs,
                  size: 18,
                  color: Brand.accentSoft,
                ),
                title: const Text('Определить по геолокации'),
                onTap: () {
                  Navigator.pop(sheetContext);
                  _locate();
                },
              ),
              const Divider(),
              for (final city in _cities)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(city.name),
                  subtitle: city.clubs > 0
                      ? Text(
                          _clubCount(city.clubs),
                          style: TextStyle(color: Brand.muted),
                        )
                      : null,
                  trailing: city.key == _session.city
                      ? const FaIcon(
                          FontAwesomeIcons.circleCheck,
                          size: 18,
                          color: Brand.accentSoft,
                        )
                      : null,
                  onTap: () => Navigator.pop(sheetContext, city),
                ),
            ],
          ),
        ),
      ),
    );
    if (city != null) await _chooseCity(city);
  }

  /// Клуб из «Моих клубов»: вошедший гость — сразу в клуб.
  Future<void> _open(int id, String name) async {
    if (_busyId != null) return;
    setState(() => _busyId = id);
    try {
      await openClub(context, _session, id, name);
    } on ApiException catch (e) {
      _showMessage(e.message);
    } finally {
      if (mounted) setState(() => _busyId = null);
    }
  }

  void _showClub(CatalogClub club) => Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => ClubScreen(session: _session, club: club),
    ),
  );

  void _showMessage(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  static String _clubCount(int n) {
    final mod10 = n % 10;
    final mod100 = n % 100;
    final word = mod10 == 1 && mod100 != 11
        ? 'клуб'
        : mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)
        ? 'клуба'
        : 'клубов';
    return '$n $word';
  }

  @override
  Widget build(BuildContext context) {
    final cityName = _cities
        .where((c) => c.key == _session.city)
        .map((c) => c.name)
        .firstOrNull;
    final myClubs = _session.signedIn ? _session.clubs : const <ClubInfo>[];

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
            children: [
              const Center(child: BrandMark(height: 36)),
              const SizedBox(height: 28),
              const SectionHeading(
                kicker: 'Клубы',
                title: 'Компьютерные клубы',
              ),
              const SizedBox(height: 14),
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  onPressed: _cities.isEmpty || _locating ? null : _pickCity,
                  icon: _locating
                      ? const SizedBox.square(
                          dimension: 14,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const FaIcon(FontAwesomeIcons.locationDot, size: 14),
                  label: Text(
                    _locating
                        ? 'Определяем город…'
                        : cityName ?? 'Выберите город',
                  ),
                ),
              ),
              if (myClubs.isNotEmpty) ...[
                const SizedBox(height: 24),
                Text(
                  'Мои клубы',
                  style: TextStyle(
                    color: Brand.muted,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 10),
                for (final club in myClubs)
                  _ClubCard(
                    name: club.name,
                    busy: _busyId == club.id,
                    current: club.id == _session.clubId,
                    onTap: () => _open(club.id, club.name),
                  ),
              ],
              const SizedBox(height: 24),
              if (cityName != null) ...[
                Text(
                  'Клубы в городе $cityName',
                  style: TextStyle(
                    color: Brand.muted,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 10),
              ],
              if (_loading)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 48),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (_error != null)
                EmptyState(
                  icon: FontAwesomeIcons.wifi,
                  text: _error!,
                  onRetry: _start,
                )
              else if (_session.city == null)
                EmptyState(
                  icon: FontAwesomeIcons.locationDot,
                  text: 'Выберите город, чтобы увидеть его клубы.',
                  onRetry: null,
                )
              else if (_clubs.isEmpty)
                const EmptyState(
                  icon: FontAwesomeIcons.store,
                  text: 'В этом городе пока нет клубов Zafiro.',
                )
              else
                for (final club in _clubs)
                  _CoverCard(
                    club: club,
                    member: _session.signedIn && _session.isMember(club.id),
                    onTap: () => _showClub(club),
                  ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Клуб аккаунта в «Моих клубах»: открывается сразу, без страницы клуба.
class _ClubCard extends StatelessWidget {
  const _ClubCard({
    required this.name,
    required this.onTap,
    this.current = false,
    this.busy = false,
  });

  final String name;
  final bool current;
  final bool busy;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: GlassCard(
        padding: const EdgeInsets.all(14),
        onTap: onTap,
        child: Row(
          children: [
            IconBadge(
              FontAwesomeIcons.store,
              size: 46,
              color: current ? Brand.accentSoft : Colors.white,
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                name,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            if (busy)
              const SizedBox.square(
                dimension: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            else if (current)
              const FaIcon(
                FontAwesomeIcons.circleCheck,
                size: 18,
                color: Brand.accentSoft,
              )
            else
              FaIcon(
                FontAwesomeIcons.chevronRight,
                size: 14,
                color: Brand.faint,
              ),
          ],
        ),
      ),
    );
  }
}

/// Карточка клуба в списке города: обложка, название, адрес, цена и свободные ПК.
class _CoverCard extends StatelessWidget {
  const _CoverCard({
    required this.club,
    required this.onTap,
    this.member = false,
  });

  final CatalogClub club;
  final bool member;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(22);
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Material(
        color: Brand.card,
        shape: RoundedRectangleBorder(
          borderRadius: radius,
          side: BorderSide(color: Brand.border),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AspectRatio(
                aspectRatio: 16 / 9,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    ClubCover(name: club.name, imageUrl: club.photoUrl),
                    DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [
                            Colors.transparent,
                            Colors.black.withValues(alpha: 0.75),
                          ],
                          stops: const [0.45, 1],
                        ),
                      ),
                    ),
                    if (club.priceFrom != null)
                      Positioned(
                        top: 12,
                        right: 12,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 6,
                          ),
                          decoration: BoxDecoration(
                            color: Brand.accent,
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Text(
                            'от ${money(club.priceFrom!, 'KZT')}/ч',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ),
                    if (member)
                      const Positioned(
                        top: 12,
                        left: 12,
                        child: InfoChip(
                          FontAwesomeIcons.circleCheck,
                          'Вы клиент',
                          color: Colors.white,
                        ),
                      ),
                    Positioned(
                      left: 16,
                      right: 16,
                      bottom: 12,
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          if (club.logoUrl != null) ...[
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Container(
                                width: 40,
                                height: 40,
                                color: Colors.black,
                                padding: const EdgeInsets.all(4),
                                child: Image.network(
                                  club.logoUrl!,
                                  fit: BoxFit.contain,
                                  errorBuilder: (_, _, _) =>
                                      const SizedBox.shrink(),
                                ),
                              ),
                            ),
                            const SizedBox(width: 10),
                          ],
                          Expanded(
                            child: Text(
                              club.name,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w800,
                                height: 1.1,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (club.address != null && club.address!.isNotEmpty)
                      Row(
                        children: [
                          const FaIcon(
                            FontAwesomeIcons.locationDot,
                            size: 12,
                            color: Brand.accentSoft,
                          ),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              club.address!,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(color: Brand.muted),
                            ),
                          ),
                        ],
                      ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (club.pcs > 0)
                          FreeChip(free: club.freePcs, total: club.pcs),
                        if (club.hours != null)
                          InfoChip(FontAwesomeIcons.clock, club.hours!),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
