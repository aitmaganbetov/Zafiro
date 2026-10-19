import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

/// Сразу после входа: гость с несколькими клубами выбирает, в каком клубе работать.
class ClubChoiceScreen extends StatelessWidget {
  const ClubChoiceScreen({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 32, 20, 32),
          children: [
            const Center(child: BrandMark(height: 36)),
            const SizedBox(height: 32),
            const SectionHeading(kicker: 'Вход', title: 'Выберите клуб'),
            const SizedBox(height: 8),
            Text(
              'Ваш аккаунт есть в нескольких клубах. Клуб можно сменить потом в профиле.',
              style: TextStyle(color: Brand.muted, height: 1.4),
            ),
            const SizedBox(height: 20),
            ClubList(clubs: session.clubs, onChoose: session.chooseClub),
          ],
        ),
      ),
    );
  }
}

/// Окно смены клуба из профиля.
Future<void> showClubSwitcher(BuildContext context, Session session) {
  return showModalBottomSheet<void>(
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
            const SectionHeading(kicker: 'Клуб', title: 'Сменить клуб'),
            const SizedBox(height: 20),
            ClubList(
              clubs: session.clubs,
              onChoose: (club) async {
                await session.switchClub(club);
                if (sheetContext.mounted) Navigator.pop(sheetContext);
              },
            ),
          ],
        ),
      ),
    ),
  );
}

/// Список клубов; нажатие переключает, пока идёт запрос — крутилка у выбранного клуба.
class ClubList extends StatefulWidget {
  const ClubList({super.key, required this.clubs, required this.onChoose});

  final List<ClubInfo> clubs;
  final Future<void> Function(ClubInfo club) onChoose;

  @override
  State<ClubList> createState() => _ClubListState();
}

class _ClubListState extends State<ClubList> {
  int? _busyId;
  String? _error;

  Future<void> _choose(ClubInfo club) async {
    if (_busyId != null) return;
    setState(() {
      _busyId = club.id;
      _error = null;
    });
    try {
      await widget.onChoose(club);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busyId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final club in widget.clubs) ...[
          GlassCard(
            padding: const EdgeInsets.all(14),
            onTap: () => _choose(club),
            child: Row(
              children: [
                IconBadge(
                  FontAwesomeIcons.store,
                  size: 42,
                  color: club.current ? Brand.accentSoft : Colors.white,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Text(
                    club.name,
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                if (_busyId == club.id)
                  const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                else if (club.current)
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
          const SizedBox(height: 10),
        ],
        if (_error != null)
          Text(_error!, style: const TextStyle(color: Brand.danger)),
      ],
    );
  }
}
