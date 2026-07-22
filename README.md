# شركة ساينا | Syna Co

نظام ERP / محاسبة عربي RTL — **Laravel + React + PostgreSQL** عبر Docker.

Syna Co — Arabic accounting/ERP with multi-currency (SYP / TRY / USD), barcodes, backups, and printable reports.

**التوقيت | Timezone:** النظام يعمل بتوقيت سوريا `Asia/Damascus` (`APP_TIMEZONE` / `TZ`). تواريخ الفواتير والقيود ولوحة التحكم والنسخ الاحتياطي المجدول تعتمد هذا التوقيت.

**دليل الاستخدام (عربي PDF):** [docs/SynaCo-User-Guide-AR.pdf](docs/SynaCo-User-Guide-AR.pdf) — غلاف، فهرس، وخطوات مرقّمة لكل الوحدات. إعادة التوليد: `python scripts/generate_arabic_user_guide.py` (يتطلب fpdf2 + arabic-reshaper + python-bidi وخطوط `docs/fonts`).

**الموقع المباشر:** https://synaacc.cloud

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

**حساب تجريبي:** `admin` / `password`

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
| محاسبة | دليل حسابات، قيود مزدوجة، إعدادات، لوحة تحكم (مبيعات/مشتريات يومية) |
| مخازن | مستودعات، أصناف، باركود، أرصدة، حركات، تحويلات، **جرد**، تنبيهات، **دفعات/تسلسلي** |
| مبيعات/مشتريات | **عروض وأوامر**، فواتير، مرتجعات (UI)، قبض/صرف، كشوف حساب، **فاتورة إلكترونية** |
| مشتريات | **طلبات وأوامر شراء** مع تحويل لفاتورة المورد |
| صلاحيات | **إدارة مستخدمين وأدوار** (admin) |
| لغات | **عربي (افتراضي) + English + Türkçe** عبر i18next |
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

تغطي: ترحيل الفواتير، تحويل العملات، صلاحيات النسخ الاحتياطي، ملصقات الباركود، عرض→أمر→فاتورة، طلب→أمر شراء، تحقق دفعة/تسلسلي، مرتجعات.

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
# APP_TIMEZONE=Asia/Damascus و TZ=Asia/Damascus (افتراضي في .env.prod.example)
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

## النشر على Hostinger | Deploy on Hostinger

فيوتشر أكونت يعمل عبر **Docker + PostgreSQL + Laravel** — لذلك تحتاج **VPS من Hostinger** (Ubuntu/Debian). **الاستضافة المشتركة (Shared Hosting) لا تدعم Docker** ولا PostgreSQL بهذا الشكل؛ لا تستخدمها لهذا المشروع.

### ماذا يفعل Hostinger API؟

مفتاح API من [hPanel → Profile → API](https://hpanel.hostinger.com/profile/api) يتيح التحكم **برمجياً** عبر `https://developers.hostinger.com` (Bearer token):

| الخدمة | أمثلة | مفيد لفيوتشر أكونت؟ |
|--------|--------|---------------------|
| **VPS** | تشغيل/إيقاف/إعادة تشغيل، معلومات الجهاز | إدارة السيرفر (اختياري) |
| **DNS** | A / CNAME / MX، تحديث السجلات | **نعم** — ربط الدومين بـ IP الـ VPS |
| **Domains** | قائمة النطاقات، WHOIS | إدارة النطاق |
| **Docker Manager** | نشر `docker-compose` عبر [deploy-on-vps](https://github.com/hostinger/deploy-on-vps) | بديل — **غير موصى به** لهذا المشروع (انظر أدناه) |

**ما لا يفعله API:** لا يستبدل SSH للنشر الكامل. لا ينفّذ `php artisan migrate` أو فحوصات الصحة الموجودة في `scripts/deploy.sh`.

### أين تضع مفتاح API؟

| المكان | متى |
|--------|-----|
| **GitHub Secret** `HOSTINGER_API_KEY` | إذا أردت أتمتة DNS أو استخدام action رسمي لاحقاً |
| **hPanel → Profile → API** | **هنا تُنشئ** المفتاح فقط — لا تلصقه في الكود |
| **`.env.prod` على VPS** | **لا** — مفتاح Hostinger ليس جزءاً من تطبيق Laravel |
| **المستودع / README** | **ممنوع** — لا ترفع المفتاح إلى Git |

> **أمان:** إذا ظهر المفتاح في محادثة أو رسالة، **ألغِه فوراً** من hPanel وأنشئ مفتاحاً جديداً، ثم ضعه في GitHub Secrets فقط.

### الطريقة الموصى بها: VPS + نشر SSH (الموجود حالياً)

المشروع يستخدم بالفعل `.github/workflows/deploy.yml` → SSH → `scripts/deploy.sh` (pull، build، migrate، فحص صحة). هذا **أنسب** من Hostinger Docker Manager لأنه يشغّل migrations و seeders بشكل آمن.

**الأسرار المطلوبة في GitHub** (Settings → Secrets and variables → Actions):

| Secret | الوصف |
|--------|--------|
| `DEPLOY_HOST` | IP عام لـ VPS (مثلاً من hPanel → VPS → Overview) |
| `DEPLOY_USER` | مستخدم SSH (مثلاً `root` أو `ubuntu`) |
| `DEPLOY_SSH_KEY` | المفتاح الخاص Ed25519 (كامل) |
| `DEPLOY_PATH` | `/opt/future-account` |
| `DEPLOY_PORT` | `22` *(اختياري)* |
| `HOSTINGER_API_KEY` | *(اختياري)* للـ DNS أو إدارة VPS — **ليس للنشر الأساسي** |

مفتاح Hostinger **لا يحل محل** `DEPLOY_SSH_KEY` — تحتاج **الاثنين** فقط إذا أردت أتمتة DNS؛ للنشر اليومي يكفي أسرار `DEPLOY_*`.

### إعداد VPS على Hostinger (خطوة بخطوة)

#### 1) إنشاء VPS

1. من [hPanel](https://hpanel.hostinger.com/) → **VPS** → أنشئ VPS (Ubuntu 22.04 أو 24.04 موصى به).
2. سجّل **IP العام** و**VM ID** (من الرابط `.../vps/123456/overview` أو من hostname `srv123456.hstgr.cloud` → ID = `123456`).
3. فعّل **SSH** (مفتاح أو كلمة مرور — يُفضّل مفتاح SSH).

#### 2) تثبيت Docker على VPS

```bash
ssh root@YOUR_VPS_IP
# اتبع: https://docs.docker.com/engine/install/ubuntu/
apt update && apt install -y git
```

#### 3) استنساخ المشروع وإعداد الإنتاج

```bash
mkdir -p /opt/future-account
git clone https://github.com/muhammadshuieb/future-account.git /opt/future-account
cd /opt/future-account
cp .env.prod.example .env.prod
nano .env.prod   # APP_KEY, POSTGRES_PASSWORD, APP_URL, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS
chmod +x scripts/*.sh
DEPLOY_ENV=prod ./scripts/deploy.sh
```

#### 4) مفتاح SSH لـ GitHub Actions

```bash
ssh-keygen -t ed25519 -C "github-deploy-future-account" -f ~/.ssh/future_account_deploy -N ""
cat ~/.ssh/future_account_deploy.pub >> ~/.ssh/authorized_keys
# انسخ المفتاح الخاص إلى GitHub Secret: DEPLOY_SSH_KEY
cat ~/.ssh/future_account_deploy
```

#### 5) جدار ناري (موصى به)

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
# للاختبار المباشر بدون reverse proxy فقط:
# ufw allow 8080/tcp
ufw enable
```

#### 6) HTTPS (اختياري لكن موصى به)

ثبّت **Nginx** أو **Caddy** كـ reverse proxy أمام المنفذ `8080` (frontend) و `8000` (API)، مع Let's Encrypt. حدّث `.env.prod`:

- `APP_URL=https://your-domain.example`
- `FRONTEND_URL=https://your-domain.example`
- `SANCTUM_STATEFUL_DOMAINS=your-domain.example,www.your-domain.example`

### ربط الدومين بالـ VPS

#### الطريقة أ — hPanel (يدوي، الأسهل)

1. hPanel → **Domains** → اختر النطاق → **DNS / DNS Zone**.
2. أضف أو عدّل:
   - **A** `@` → IP الـ VPS
   - **A** `www` → IP الـ VPS (أو **CNAME** `www` → `@`)
3. انتظر انتشار DNS (حتى 24 ساعة، غالباً أقل).

#### الطريقة ب — Hostinger API (أتمتة)

1. أضف `HOSTINGER_API_KEY` في GitHub Secrets.
2. استخدم [DNS API](https://developers.hostinger.com/) — مثال تحديث سجل A:

```bash
curl -X PUT "https://developers.hostinger.com/api/dns/v1/zones/your-domain.example" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"overwrite":false,"zone":[{"name":"@","type":"A","ttl":14400,"records":[{"content":"YOUR_VPS_IP"}]}]}'
```

يُفضّل التحقق أولاً عبر `POST .../zones/{domain}/validate` قبل التطبيق.

### لماذا لا يوجد `deploy-hostinger.yml`؟

Action الرسمي `hostinger/deploy-on-vps` ينشر `docker-compose` عبر API لكن **لا يشغّل** `scripts/deploy.sh` (migrations، AdminUserSeeder الشرطي، health checks). النشر عبر SSH الموجود أدق لهذا ERP. استخدم مفتاح Hostinger للـ **DNS/VPS** إن احتجت، وليس كبديل لـ `DEPLOY_SSH_KEY`.

### سير العمل بعد الإعداد

```bash
git push origin main
# CI → Deploy (SSH) → VPS يحدّث الحاويات تلقائياً
```

### استضافة مشتركة Hostinger؟

| الميزة | VPS | Shared |
|--------|-----|--------|
| Docker | ✅ | ❌ |
| PostgreSQL | ✅ | ❌ (غالباً MySQL فقط) |
| Laravel queue/cron | ✅ | محدود |
| فيوتشر أكونت | **مدعوم** | **غير مدعوم** |

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

---

## Changelog

### v1.2.0 (2026-07-20)
- **Rebrand** — Syna Co / شركة ساينا across UI, seeders, Docker, and print views
- **Sidebar** — Fixed sticky layout, grouped navigation, permission-based menu, mobile drawer
- **General Ledger** — Account ledger report with opening/closing balance
- **Categories & units** — CRUD tabs in Warehouse
- **Audit log UI** — Admin audit trail page
- **Companies & branches** — Management screens under Admin
- **Credit limit** — Enforced on sales invoice posting
- **Auto-backup** — Laravel `syna:backup` twice daily at configurable times (requires scheduler cron)

### v1.1.0 (2026-07-20)
- Sales quotes & orders — CRUD + Quote → Order → Invoice conversion
- Purchase requests & orders — CRUD + PR → PO → Supplier Invoice
- Returns UI — Sales/purchase return forms (Arabic RTL)
- Inventory count UI — Variance posting from Warehouse
- Permissions admin — Users & roles in Settings (admin)
- E-invoice — UUID, structured JSON, enhanced QR print (gov API = Phase 2)
- i18next — Arabic + English + Turkish
- Batch/serial tracking — Product flags, validation, invoice/movement fields
- Dashboard — Daily sales/purchases charts (7/30 days)

### v1.0.1 (2026-07-20)
- Test release for CI/CD auto-deploy pipeline.

