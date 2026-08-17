# شركة ساينا — تطبيق أندرويد وويندوز

نفس نظام الموقع `https://synaacc.cloud` بالكامل: محاسبة، مخازن، مبيعات، مشتريات، عروض أسعار، طباعة.

الموقع يبقى المصدر. التطبيق يعرضه داخل نافذة أصلية (WebView) حتى الميزات تبقى 100% متطابقة.

## التشغيل

من مجلد المشروع بعد تثبيت [Flutter](https://docs.flutter.dev/get-started/install):

```powershell
cd "F:\Future Account\apps\syna_co"
flutter pub get
flutter run -d windows
flutter run -d android
```

بناء ملفات التثبيت:

```powershell
flutter build apk --release
flutter build windows --release
```

- أندرويد: `build/app/outputs/flutter-apk/app-release.apk`
- ويندوز: `build/windows/x64/runner/Release/syna_co.exe`

العنوان الافتراضي هو الإنتاج. للتجربة المحلية:

```powershell
flutter run --dart-define=SYNA_APP_URL=http://127.0.0.1:8080
```
