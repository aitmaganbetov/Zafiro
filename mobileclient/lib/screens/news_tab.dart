import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../format.dart';
import '../theme.dart';
import '../widgets.dart';

class NewsTab extends StatefulWidget {
  const NewsTab({super.key, required this.api});

  final ApiClient api;

  @override
  State<NewsTab> createState() => _NewsTabState();
}

class _NewsTabState extends State<NewsTab> {
  late Future<NewsFeed> _feed = widget.api.news();

  Future<void> _reload() async {
    final next = widget.api.news();
    setState(() => _feed = next);
    await next.then((_) {}, onError: (_) {});
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<NewsFeed>(
      future: _feed,
      builder: (context, snapshot) {
        final children = <Widget>[
          const SectionHeading(kicker: 'Новости', title: 'Что нового'),
          const SizedBox(height: 20),
        ];
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
        } else {
          final feed = snapshot.data!;
          final items = [
            for (final item in feed.club) (item, false),
            for (final item in feed.platform) (item, true),
          ];
          if (items.isEmpty) {
            children.add(
              const EmptyState(
                icon: FontAwesomeIcons.newspaper,
                text: 'Новостей пока нет',
              ),
            );
          }
          for (final (item, platform) in items) {
            children
              ..add(_NewsCard(item: item, platform: platform))
              ..add(const SizedBox(height: 14));
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

class _NewsCard extends StatelessWidget {
  const _NewsCard({required this.item, required this.platform});

  final NewsItem item;

  /// Новость Zafiro для всех клубов, а не только этого клуба.
  final bool platform;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (item.imageUrl != null)
            AspectRatio(
              aspectRatio: 16 / 9,
              child: Image.network(
                item.imageUrl!,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => Container(
                  color: Brand.surface,
                  alignment: Alignment.center,
                  child: FaIcon(FontAwesomeIcons.image, color: Brand.faint),
                ),
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _Tag(platform ? 'Zafiro' : 'Клуб'),
                    const SizedBox(width: 10),
                    if (item.publishedAt != null)
                      Flexible(
                        child: Text(
                          dateTime(item.publishedAt),
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: Brand.faint, fontSize: 12),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  item.title,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w600,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  item.body,
                  style: TextStyle(color: Brand.muted, height: 1.5),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Tag extends StatelessWidget {
  const _Tag(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Brand.accent.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          color: Brand.accentSoft,
          fontSize: 11,
          fontWeight: FontWeight.w600,
          letterSpacing: 1.2,
        ),
      ),
    );
  }
}
