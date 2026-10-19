import 'dart:async';

import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets.dart';

/// Диалоги с клиентами (клиенты пишут из агента на ПК).
class ChatListScreen extends StatefulWidget {
  const ChatListScreen({super.key, required this.session});

  final Session session;

  @override
  State<ChatListScreen> createState() => _ChatListScreenState();
}

class _ChatListScreenState extends State<ChatListScreen> {
  ChatList? _data;
  String? _error;
  Timer? _timer;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(
      const Duration(seconds: 10),
      (_) => _load(silent: true),
    );
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (_loading) return;
    _loading = true;
    try {
      final data = await widget.session.api.chats();
      if (!mounted) return;
      setState(() {
        _data = data;
        _error = null;
      });
      widget.session.updateCounters(chatUnread: data.unread);
    } on ApiException catch (e) {
      if (mounted && (!silent || _data == null)) {
        setState(() => _error = e.message);
      }
    } finally {
      _loading = false;
    }
  }

  Future<void> _open(Conversation c) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) =>
            ConversationScreen(session: widget.session, conversation: c),
      ),
    );
    _load(silent: true);
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final p = Palette.of(context);
    return LoadView(
      loading: data == null,
      error: _error,
      onRetry: _load,
      child: data == null
          ? null
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  Text(
                    'Клиенты пишут из агента на ПК. Ответ сразу придёт клиенту на ПК.',
                    style: TextStyle(color: p.muted, fontSize: 13),
                  ),
                  const SizedBox(height: 12),
                  if (data.conversations.isEmpty)
                    const EmptyState(
                      icon: FontAwesomeIcons.comments,
                      text: 'Сообщений от клиентов пока нет.',
                    ),
                  for (final c in data.conversations) ...[
                    Panel(
                      padding: const EdgeInsets.all(14),
                      borderColor: c.unread > 0
                          ? Brand.brand.withValues(alpha: 0.5)
                          : null,
                      onTap: () => _open(c),
                      child: Row(
                        children: [
                          _Avatar(c.name),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        c.name,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                          fontWeight: c.unread > 0
                                              ? FontWeight.w700
                                              : FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                    Text(
                                      c.lastAt != null
                                          ? dateTime(c.lastAt)
                                          : c.lastAtRaw,
                                      style: TextStyle(
                                        color: p.faint,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 2),
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        '${c.lastFromStaff ? 'Вы: ' : ''}${c.lastBody}',
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                          color: p.muted,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    CountBadge(c.unread),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),
                  ],
                ],
              ),
            ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar(this.name);

  final String name;

  @override
  Widget build(BuildContext context) {
    final dark = Palette.of(context).dark;
    return Container(
      width: 40,
      height: 40,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: Brand.brand.withValues(alpha: dark ? 0.25 : 0.1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        name.isEmpty ? '?' : name.characters.first.toUpperCase(),
        style: const TextStyle(color: Brand.brand, fontWeight: FontWeight.w700),
      ),
    );
  }
}

/// Переписка с клиентом: опрос новых сообщений каждые 5 секунд.
class ConversationScreen extends StatefulWidget {
  const ConversationScreen({
    super.key,
    required this.session,
    required this.conversation,
  });

  final Session session;
  final Conversation conversation;

  @override
  State<ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<ConversationScreen> {
  final List<ChatMessage> _messages = [];
  final _ids = <int>{};
  final _text = TextEditingController();
  final _scroll = ScrollController();
  Timer? _timer;
  bool _loaded = false;
  bool _polling = false;
  bool _sending = false;
  String? _error;

  ApiClient get _api => widget.session.api;
  bool get _canReply => widget.session.permissions.chatReply;

  @override
  void initState() {
    super.initState();
    _poll();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _poll());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _text.dispose();
    _scroll.dispose();
    super.dispose();
  }

  int get _lastId => _messages.isEmpty ? 0 : _messages.last.id;

  void _add(Iterable<ChatMessage> messages) {
    var added = false;
    for (final m in messages) {
      if (_ids.add(m.id)) {
        _messages.add(m);
        added = true;
      }
    }
    if (!added) return;
    _messages.sort((a, b) => a.id.compareTo(b.id));
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _poll() async {
    if (_polling) return;
    _polling = true;
    try {
      final result = await _api.chatPoll(
        widget.conversation.customerId,
        _lastId,
      );
      if (!mounted) return;
      setState(() {
        _add(result.messages);
        _loaded = true;
        _error = null;
      });
      widget.session.updateCounters(chatUnread: result.unread);
    } on ApiException catch (e) {
      if (mounted && !_loaded) setState(() => _error = e.message);
    } finally {
      _polling = false;
    }
  }

  Future<void> _send() async {
    final body = _text.text.trim();
    if (body.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      final message = await _api.chatSend(widget.conversation.customerId, body);
      if (!mounted) return;
      _text.clear();
      setState(() {
        if (message != null) _add([message]);
      });
      if (message == null) _poll();
    } on ApiException catch (e) {
      if (mounted) showError(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final c = widget.conversation;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(c.name),
            if (c.phone.isNotEmpty)
              Text(
                formatPhone(c.phone),
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                  color: p.muted,
                ),
              ),
          ],
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: !_loaded
                ? LoadView(
                    loading: true,
                    error: _error,
                    onRetry: _poll,
                    child: null,
                  )
                : _messages.isEmpty
                ? const EmptyState(
                    icon: FontAwesomeIcons.comments,
                    text: 'Сообщений пока нет.',
                  )
                : ListView.builder(
                    controller: _scroll,
                    padding: const EdgeInsets.all(16),
                    itemCount: _messages.length,
                    itemBuilder: (context, i) => _Bubble(_messages[i]),
                  ),
          ),
          Material(
            color: p.card,
            child: SafeArea(
              top: false,
              child: Container(
                decoration: BoxDecoration(
                  border: Border(top: BorderSide(color: p.border)),
                ),
                padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
                child: _canReply
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: TextField(
                              controller: _text,
                              minLines: 1,
                              maxLines: 4,
                              maxLength: 1000,
                              textCapitalization: TextCapitalization.sentences,
                              decoration: const InputDecoration(
                                hintText: 'Ответ клиенту…',
                                counterText: '',
                              ),
                            ),
                          ),
                          const SizedBox(width: 6),
                          IconButton.filled(
                            tooltip: 'Отправить',
                            onPressed: _sending ? null : _send,
                            style: IconButton.styleFrom(
                              backgroundColor: Brand.brand,
                              foregroundColor: Colors.white,
                            ),
                            icon: _sending
                                ? const SizedBox.square(
                                    dimension: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white,
                                    ),
                                  )
                                : const FaIcon(
                                    FontAwesomeIcons.paperPlane,
                                    size: 16,
                                  ),
                          ),
                        ],
                      )
                    : Padding(
                        padding: const EdgeInsets.all(6),
                        child: Text(
                          'Отвечают кассиры и операторы на смене; вам чат доступен для просмотра.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: p.muted, fontSize: 13),
                        ),
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble(this.message);

  final ChatMessage message;

  @override
  Widget build(BuildContext context) {
    final p = Palette.of(context);
    final mine = message.fromStaff;
    final meta = [
      if (message.author.isNotEmpty) message.author,
      if (message.computer.isNotEmpty) message.computer,
      if (message.at.isNotEmpty) message.at,
    ].join(' · ');
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.sizeOf(context).width * 0.78,
        ),
        child: Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            color: mine ? Brand.brand : p.card,
            border: mine ? null : Border.all(color: p.border),
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(16),
              topRight: const Radius.circular(16),
              bottomLeft: Radius.circular(mine ? 16 : 4),
              bottomRight: Radius.circular(mine ? 4 : 16),
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                message.body,
                style: TextStyle(
                  color: mine ? Colors.white : p.text,
                  height: 1.35,
                ),
              ),
              if (meta.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  meta,
                  style: TextStyle(
                    fontSize: 11,
                    color: mine
                        ? Colors.white.withValues(alpha: 0.75)
                        : p.faint,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
