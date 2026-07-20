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
├── docker-compose.prod.yml
├── scripts/             # deploy.sh, watch-deploy.sh
├── .github/workflows/   # CI + auto-deploy
└── README.md
```

---

## النشر التلقائي من GitHub | Auto-deploy from GitHub

### سير العمل | Workflow

```bash
git add .
git commit -m "وصف التعديل"
git push origin main
# → GitHub Actions: CI (اختبارات) ثم Deploy تلقائياً على السيرفر
```

| Workflow | متى يعمل | ماذا يفعل |
|----------|-----------|-----------|
| **CI** (`ci.yml`) | push + PR على `main` | PHPUnit (SQLite) + بناء Frontend |
| **Deploy** (`deploy.yml`) | بعد نجاح CI على `main` | SSH إلى VPS وتشغيل `scripts/deploy.sh` |

### إعداد السيرفر (VPS) لأول مرة | First-time VPS setup

1. **تثبيت Docker** على Ubuntu/Debian ([Docker Engine](https://docs.docker.com/engine/install/)).
2. **استنساخ المشروع:**

```bash
sudo mkdir -p /opt/future-account
sudo chown "$USER":"$USER" /opt/future-account
git clone https://github.com/muhammadshuieb/future-account.git /opt/future-account
cd /opt/future-account
```

3. **إعداد أسرار الإنتاج:**

```bash
cp .env.prod.example .env.prod
# عدّل: APP_KEY, POSTGRES_PASSWORD, APP_URL, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS
# توليد APP_KEY:
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm backend php artisan key:generate --show
```

4. **تشغيل أول مرة:**

```bash
chmod +x scripts/*.sh
DEPLOY_ENV=prod ./scripts/deploy.sh
```

5. **مفتاح SSH للنشر** (يستخدمه GitHub Actions):

```bash
ssh-keygen -t ed25519 -C "github-deploy-future-account" -f ~/.ssh/future_account_deploy -N ""
cat ~/.ssh/future_account_deploy.pub >> ~/.ssh/authorized_keys
# انسخ المحتوى الخاص (private key) إلى GitHub Secret: DEPLOY_SSH_KEY
cat ~/.ssh/future_account_deploy
```

### أسرار GitHub | GitHub Secrets

في **Settings → Secrets and variables → Actions** للم repo `muhammadshuieb/future-account`:

| Secret | مثال | الوصف |
|--------|------|--------|
| `DEPLOY_HOST` | `203.0.113.10` | IP أو hostname للسيرفر |
| `DEPLOY_USER` | `deploy` | مستخدم SSH |
| `DEPLOY_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----...` | المفتاح **الخاص** (كامل) |
| `DEPLOY_PATH` | `/opt/future-account` | مسار المشروع على السيرفر |
| `DEPLOY_PORT` | `22` | *(اختياري)* منفذ SSH |

بعد إضافة الأسرار: أي `git push` إلى `main` يشغّل CI ثم ينشر تلقائياً.

### ماذا يفعل `scripts/deploy.sh`

1. `git pull origin main`
2. `docker compose build` (مع `docker-compose.prod.yml` في الإنتاج)
3. `docker compose up -d`
4. `php artisan migrate --force`
5. `AdminUserSeeder` **فقط** إذا لا يوجد مستخدمون (لا seed تجريبي في prod)
6. فحص صحة `/up` والواجهة

**Windows (يدوي):** `.\scripts\deploy.ps1`

**نسخ احتياطي قبل النشر (اختياري):**

```bash
RUN_PRE_DEPLOY_BACKUP=1 ./scripts/deploy.sh
```

### بدائل بدون VPS | Alternatives without GitHub SSH

#### 1) Cron + `watch-deploy.sh` (polling كل 5 دقائق)

```bash
chmod +x /opt/future-account/scripts/watch-deploy.sh
crontab -e
# أضف:
*/5 * * * * cd /opt/future-account && ./scripts/watch-deploy.sh >> /var/log/future-account-deploy.log 2>&1
```

#### 2) GitHub Self-hosted Runner

ثبّت [self-hosted runner](https://docs.github.com/en/actions/hosting-your-own-runners) على نفس الجهاز، ثم عدّل `deploy.yml` لاستخدام `runs-on: self-hosted` وتشغيل `./scripts/deploy.sh` محلياً بدون SSH.

#### 3) نشر يدوي

```bash
cd /opt/future-account
git pull origin main
DEPLOY_ENV=prod ./scripts/deploy.sh
```

### ملاحظات الإنتاج | Production notes

- `docker-compose.yml` يبقى للتطوير المحلي — **لا يتغير** سلوك `docker compose up` بدون `-f docker-compose.prod.yml`.
- في الإنتاج: `APP_DEBUG=false`، `SKIP_DB_SEED=1` — لا بيانات تجريبية تلقائية.
- **لا ترفع** `.env.prod` أو مفاتيح حقيقية إلى GitHub.
- احتفظ بـ `APP_KEY` ثابتاً على السيرver — تغييره يبطل الجلسات المشفرة.

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
