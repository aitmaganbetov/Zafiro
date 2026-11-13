import 'dart:math';

import 'api/models.dart';

/// Ближайший к точке город (по расстоянию на сфере); null — список пуст.
City? nearestCity(List<City> cities, double lat, double lng) {
  City? best;
  var bestDistance = double.infinity;
  for (final city in cities) {
    final d = _distance(lat, lng, city.lat, city.lng);
    if (d < bestDistance) {
      best = city;
      bestDistance = d;
    }
  }
  return best;
}

double _distance(double lat1, double lng1, double lat2, double lng2) {
  double rad(double deg) => deg * pi / 180;
  final dLat = rad(lat2 - lat1);
  final dLng = rad(lng2 - lng1);
  final a =
      pow(sin(dLat / 2), 2) +
      cos(rad(lat1)) * cos(rad(lat2)) * pow(sin(dLng / 2), 2);
  return 2 * asin(sqrt(a));
}
