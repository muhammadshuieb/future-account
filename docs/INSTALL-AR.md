# تثبيت وتشغيل SynaAcc (ساينا)

نظام محاسبة ERP — Laravel + React + PostgreSQL عبر Docker.

## المتطلبات

- Docker Desktop (Windows) أو Docker Engine + Compose (Linux)
- منفذان حرّان: `8080` (واجهة) و `8000` (API) — قابلة للتغيير

## تثبيت سريع من أرشيف الكود

1. فك ضغط الملف `03_كود-البرمجة-وطريقة-التثبيت_SynaAcc.zip`
2. من مجلد المشروع:

```bash
docker compose up --build -d
```

| الخدمة | العنوان |
|--------|---------|
| الواجهة | http://localhost:8080 |
| API | http://localhost:8000/api |

3. عند أول تشغيل: migrations + seed تلقائياً.
4. حساب تجريبي (بيئة تطوير فقط): `admin` / `password`

إيقاف:

```bash
docker compose down
```

## الإنتاج

1. انسخ `.env.prod.example` إلى `.env.prod` واملأ الأسرار (`APP_KEY`, `POSTGRES_PASSWORD`, …).
2. انشر:

```bash
DEPLOY_ENV=prod ./scripts/deploy.sh
```

3. النسخ الاحتياطي: من الإعدادات ← النسخ الاحتياطي، أو:

```bash
docker compose exec backend php artisan syna:backup
```

يُرسل عادةً ثلاثة ملفات عبر تيليجرام (إن كان مربوطاً):

| الملف | المعنى |
|-------|--------|
| `01_نسخة-احتياطية-قاعدة-بيانات_…` | نسخة PostgreSQL (`pg_dump`) |
| `02_بيانات-Excel_…` | بيانات النظام في Excel |
| `03_كود-البرمجة-وطريقة-التثبيت_SynaAcc.zip` | الكود + هذا الدليل (يُحدَّث مع كل نشر ويُحتفظ به) |

## استعادة قاعدة البيانات

من الواجهة: الإعدادات ← النسخ الاحتياطي ← استعادة،  
أو عبر API بعد رفع ملف `.dump`.

بعد استعادة فارغة قد تحتاج:

```bash
docker compose exec backend php artisan db:seed --class=AdminUserSeeder --force
```

## ملاحظات أمان

- لا تشارك `.env` / `.env.prod` داخل الأرشيف.
- غيّر كلمة مرور الأدمن فوراً في الإنتاج.
- راجع `README.md` للتفاصيل الكاملة.
