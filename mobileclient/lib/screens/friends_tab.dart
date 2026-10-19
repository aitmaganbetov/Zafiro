import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'transfer_sheet.dart';

/// Друзья в клубе: заявки, список и перевод баланса (если клуб это включил).
class FriendsTab extends StatefulWidget {
  const FriendsTab({super.key, required this.session});

  final Session session;

  @override
  State<FriendsTab> createState() => _FriendsTabState();
}

class _FriendsTabState extends State<FriendsTab> {
  ApiClient get _api => widget.session.api;
  late Future<FriendsOverview> _overview = _api.friends();

  Future<void> _reload() async {
    final next = _api.friends();
    setState(() => _overview = next);
    await next.then((_) {}, onError: (_) {});
  }

  void _toast(String message) {
    if (!mounted || message.isEmpty) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  /// Действие с другом (принять, удалить…) и обновление списка.
  Future<void> _run(Future<void> Function() action) async {
    try {
      await action();
      await _reload();
    } on ApiException catch (e) {
      _toast(e.message);
    }
  }

  Future<void> _add() async {
    final message = await showDialog<String>(
      context: context,
      builder: (_) => _AddFriendDialog(api: _api),
    );
    if (message != null) {
      _toast(message);
      await _reload();
    }
  }

  Future<void> _remove(Friend friend) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Удалить ${friend.name} из друзей?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Отмена'),
          ),
          FilledButton(
            style: const ButtonStyle(
              minimumSize: WidgetStatePropertyAll(Size(96, 44)),
            ),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Удалить'),
          ),
        ],
      ),
    );
    if (confirmed == true) await _run(() => _api.removeFriend(friend.id));
  }

  Future<void> _transfer(Friend friend) async {
    final message = await showTransferSheet(
      context,
      api: _api,
      friend: friend,
      profile: widget.session.profile,
    );
    if (message != null) {
      _toast(message);
      try {
        await widget.session.refreshProfile();
      } on ApiException {
        // Баланс обновится при следующем открытии.
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<FriendsOverview>(
      future: _overview,
      builder: (context, snapshot) {
        final children = <Widget>[
          const SectionHeading(kicker: 'Друзья', title: 'Друзья в клубе'),
          const SizedBox(height: 20),
        ];
        final data = snapshot.data;
        if (snapshot.connectionState != ConnectionState.done) {
          children.add(
            const Padding(
              padding: EdgeInsets.all(48),
              child: Center(child: CircularProgressIndicator()),
            ),
          );
        } else if (snapshot.hasError) {
          children.add(
            EmptyState(
              icon: FontAwesomeIcons.circleExclamation,
              text: '${snapshot.error}',
              onRetry: _reload,
            ),
          );
        } else if (!data!.friendsEnabled && data.friends.isEmpty) {
          children.add(
            const EmptyState(
              icon: FontAwesomeIcons.userGroup,
              text: 'Друзья в этом клубе отключены',
            ),
          );
        } else {
          if (data.friendsEnabled) {
            children
              ..add(
                FilledButton.icon(
                  onPressed: _add,
                  icon: const FaIcon(FontAwesomeIcons.userPlus, size: 16),
                  label: const Text('Добавить друга'),
                ),
              )
              ..add(const SizedBox(height: 20));
          }
          if (!data.transfersEnabled && data.friends.isNotEmpty) {
            children
              ..add(
                Text(
                  'Переводы друзьям в этом клубе отключены.',
                  style: TextStyle(color: Brand.faint, fontSize: 13),
                ),
              )
              ..add(const SizedBox(height: 12));
          }
          void group(
            String title,
            List<Friend> list,
            List<Widget> Function(Friend) actions,
          ) {
            if (list.isEmpty) return;
            children.add(
              Padding(
                padding: const EdgeInsets.only(bottom: 10, top: 4),
                child: _GroupTitle(title),
              ),
            );
            for (final friend in list) {
              children
                ..add(_FriendCard(friend: friend, actions: actions(friend)))
                ..add(const SizedBox(height: 10));
            }
            children.add(const SizedBox(height: 10));
          }

          group(
            'Заявки вам',
            data.incoming,
            (f) => [
              _Action(
                icon: FontAwesomeIcons.check,
                tooltip: 'Принять',
                color: Brand.success,
                onTap: () => _run(() => _api.acceptFriend(f.id)),
              ),
              _Action(
                icon: FontAwesomeIcons.xmark,
                tooltip: 'Отклонить',
                onTap: () => _run(() => _api.removeFriend(f.id)),
              ),
            ],
          );
          group(
            'Друзья',
            data.friends,
            (f) => [
              if (data.transfersEnabled)
                _Action(
                  icon: FontAwesomeIcons.moneyBillTransfer,
                  tooltip: 'Перевести',
                  color: Brand.accentSoft,
                  onTap: () => _transfer(f),
                ),
              _Action(
                icon: FontAwesomeIcons.userMinus,
                tooltip: 'Удалить из друзей',
                onTap: () => _remove(f),
              ),
            ],
          );
          group(
            'Ждут ответа',
            data.outgoing,
            (f) => [
              _Action(
                icon: FontAwesomeIcons.xmark,
                tooltip: 'Отменить заявку',
                onTap: () => _run(() => _api.removeFriend(f.id)),
              ),
            ],
          );
          if (data.friends.isEmpty &&
              data.incoming.isEmpty &&
              data.outgoing.isEmpty) {
            children.add(
              const EmptyState(
                icon: FontAwesomeIcons.userGroup,
                text: 'Пока нет друзей. Добавьте друга по номеру телефона.',
              ),
            );
          }
        }
        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
            children: children,
          ),
        );
      },
    );
  }
}

class _GroupTitle extends StatelessWidget {
  const _GroupTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(
    text.toUpperCase(),
    style: TextStyle(
      color: Brand.faint,
      fontSize: 12,
      fontWeight: FontWeight.w600,
      letterSpacing: 1.6,
    ),
  );
}

class _FriendCard extends StatelessWidget {
  const _FriendCard({required this.friend, required this.actions});

  final Friend friend;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
      child: Row(
        children: [
          const IconBadge(FontAwesomeIcons.user, size: 42),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  friend.name,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                if (friend.phone.isNotEmpty)
                  Text(
                    friend.phone,
                    style: TextStyle(color: Brand.faint, fontSize: 12),
                  ),
              ],
            ),
          ),
          ...actions,
        ],
      ),
    );
  }
}

class _Action extends StatelessWidget {
  const _Action({
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.color,
  });

  final FaIconData icon;
  final String tooltip;
  final VoidCallback onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: tooltip,
    onPressed: onTap,
    icon: FaIcon(icon, size: 18, color: color ?? Brand.muted),
  );
}

class _AddFriendDialog extends StatefulWidget {
  const _AddFriendDialog({required this.api});

  final ApiClient api;

  @override
  State<_AddFriendDialog> createState() => _AddFriendDialogState();
}

class _AddFriendDialogState extends State<_AddFriendDialog> {
  final _phone = TextEditingController(text: '+7');
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await widget.api.addFriend(_phone.text);
      if (mounted) Navigator.pop(context, message);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Добавить друга'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Друг должен быть клиентом этого клуба.',
            style: TextStyle(color: Brand.muted),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _phone,
            autofocus: true,
            keyboardType: TextInputType.phone,
            onSubmitted: (_) => _send(),
            decoration: InputDecoration(
              labelText: 'Номер телефона друга',
              errorText: _error,
              errorMaxLines: 3,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Отмена'),
        ),
        FilledButton(
          style: const ButtonStyle(
            minimumSize: WidgetStatePropertyAll(Size(120, 44)),
          ),
          onPressed: _busy ? null : _send,
          child: const Text('Отправить'),
        ),
      ],
    );
  }
}
