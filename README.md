# فيوتشر أكونت | Future Account

نظام محاسبة / ERP عربي RTL — **Laravel + React + PostgreSQL** عبر Docker.

Arabic accounting/ERP — Docker-first full stack with multi-currency (SYP / TRY / USD), barcodes, backups, and printable reports.

---

## التشغيل السريع | Quick start

**المتطلب:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) على Windows.

```powershell
cd "f:\Future Account"
docker compose up --build -d
```

| الخدمة | العنوان |
|--------|---------|
| الواجهة (UI) | http://localhost:8080 |
| API | http://localhost:8000/api |
| PostgreSQL | `localhost:5432` — user `future` / password `secret` / db `future_account` |

عند كل تشغيل للـ backend: انتظار Postgres → `migrate` → `db:seed` → ضمان حساب الأدمن التجريبي.

**حساب تجريبي:** `admin@future-account.test` / `password`

إذا ظهر «بيانات الدخول غير صحيحة» بعد استعادة نسخة احتياطية فارغة:

```powershell
docker compose exec backend php artisan db:seed --class=AdminUserSeeder --force
```

إيقاف:

```powershell
docker compose down
```

---

## الميزات المكتملة | Features

| المجال | المحتوى |
|--------|---------|
| محاسبة | دليل حسابات، قيود مزدوجة، إعدادات، لوحة تحكم |
| مخازن | مستودعات، أصناف، باركود، أرصدة، حركات، تحويلات، تنبيهات |
| مبيعات/مشتريات | فواتير، مرتجعات، قبض/صرف، كشوف حساب، QR على طباعة الفاتورة |
| نقدية وتقارير | صناديق، بنوك، ميزان مراجعة، دخل، ميزانية، تدفقات، ضريبة، طباعة |
| موارد بشرية | موظفون، حضور، إجازات، رواتب |
| فروع وتدقيق | شركات/فروع، سجل تدقيق، إشعارات |
| عملات | **SYP** (أساسية افتراضياً) + **TRY** + **USD** مع أسعار صرف |
| باركود | توليد Code128/EAN وطباعة ملصقات |
| نسخ احتياطي | `pg_dump` / استعادة — مدير فقط — مجلد Docker volume |

---

## العملات | Multi-currency

- العملة الأساسية الافتراضية: **SYP** (قابلة للتغيير من الإعدادات).
- أسعار صرف يدوية مع تاريخ (شاشة **الإعدادات → العملات**).
- الفواتير تخزّن: `currency` + `exchange_rate` + `base_amount`.
- القيود المحاسبية تُرحَّل بالمبلغ الأساسي.
- التقارير تعرض المبالغ بالعملة الأساسية.

أسعار تجريبية عند الـ seed (تقريبية): `1 USD ≈ 15000 SYP` ، `1 TRY ≈ 450 SYP`.

---

## الباركود | Barcodes

1. من القائمة: **الباركود والملصقات**
2. اختر الأصناف → **تجهيز ملصقات** → **طباعة الملصقات**
3. أو من شاشة المخازن/الملصقات: زر **توليد** لصنف بلا باركود

API: `GET /api/barcodes/labels` ، `POST /api/products/{id}/barcode`

---

## النسخ الاحتياطي | Backup / restore

متاح لدور **admin** فقط — **الإعدادات → النسخ الاحتياطي**.

| إجراء | ماذا يفعل |
|--------|-----------|
| إنشاء | `pg_dump -Fc` إلى volume `future_account_backups` |
| تنزيل | تحميل ملف `.dump` |
| استعادة | `pg_restore --clean` (يستبدل البيانات — تأكيد مطلوب) |

المسار داخل الحاوية: `/var/www/html/storage/app/backups`  
المتغير: `BACKUP_PATH`

```powershell
# مثال من داخل الـ API container
docker compose exec backend php artisan tinker
# أو عبر الواجهة مباشرة
```

---

## طباعة التقارير | Report printing

من **التقارير**: اختر التقرير والفلاتر → **طباعة / PDF**.  
أنماط `@media print` تخفي الشريط الجانبي والرأس. يمكن حفظ PDF من مربع حوار الطباعة في المتصفح.

التقارير: ميزان مراجعة، دخل، ميزانية، تدفقات، مبيعات، مشتريات، مخزون، ربح، ضريبة، كشف عميل/مورد، حركة صنف.

---

## الصلاحيات | Permissions

أدوار: `admin`, `accountant`, `warehouse`, `sales`, `purchasing`  
النسخ الاحتياطي: **admin فقط**.

---

## الاختبارات | Tests

```powershell
docker compose exec backend php artisan test
```

تغطي: ترحيل الفواتير، تحويل العملات، صلاحيات النسخ الاحتياطي، ملصقات الباركود.

---

## هيكل المشروع | Structure

```
Future Account/
├── backend/             # Laravel API
├── frontend/            # React + Vite + Tailwind (Cairo RTL)
├── docker-compose.yml   # postgres + backend + frontend + backups volume
└── README.md
```

---

## تشغيل محلي (اختياري) | Local without full Docker

1. `docker compose up -d postgres`
2. Backend: انسخ `.env.example`، `composer install`، `php artisan migrate --seed`، `php artisan serve`
3. Frontend: `npm install` && `npm run dev` → http://localhost:5173

---

## ملاحظات

- الواجهة عربية **RTL** بخط **Cairo** (+ IBM Plex Sans Arabic احتياطي)
- مفتاح `APP_KEY` في compose ثابت للتجربة المحلية — لا تستخدمه في الإنتاج
- لا ترفع أسراراً إنتاجية — قيم Docker للتجربة فقط
