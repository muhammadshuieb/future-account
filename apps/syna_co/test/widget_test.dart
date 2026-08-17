import 'package:flutter_test/flutter_test.dart';
import 'package:syna_co/main.dart';

void main() {
  test('production URL stays on synaacc.cloud', () {
    expect(synaAppUrl, 'https://synaacc.cloud');
  });
}
