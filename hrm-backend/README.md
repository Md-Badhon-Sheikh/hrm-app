# HRM Backend (Laravel 11)

Backend API for the **Onest HRM Flutter app** (`hrm_app`). It is built to match the app's existing
API contract exactly (same URLs under `/api/V11`, same JSON keys and types), so the app works
after changing **one line**: the base URL.

- Laravel 11 + Sanctum (Bearer token auth), PHP 8.2+, MySQL / MariaDB (or SQLite)
- 104 API routes covering every screen in the app
- **Web admin panel** (Filament v3) at `/admin` for HR & admins
- Tested: 147 automated checks call every endpoint and compare each response with the app's
  Dart models (`tests/api-contract`), so none of the responses should crash the app

---

## 1. Install

```bash
composer install              # also publishes the admin panel assets
cp .env.example .env          # set DB_*, APP_URL, MAIL_*
php artisan key:generate
php artisan migrate --seed    # --seed adds demo company data (see below)
php artisan storage:link      # uploaded files (avatars, attachments) are served from /storage
php artisan serve             # local: http://127.0.0.1:8000/api/V11
```

**Production (cPanel / VPS):** point the domain's document root to `public/`, use HTTPS
(the Android app does not allow plain `http://`), set `APP_ENV=production` and `APP_DEBUG=false`,
then run `php artisan config:cache route:cache`.

### Demo logins (from the seeder) — password `12345678`

| Role | Email |
|---|---|
| Admin | admin@hrm.test |
| HR | hr@hrm.test |
| Manager (approves leave for his team) | manager@hrm.test |
| Employee | employee@hrm.test, sadia@hrm.test, arif@hrm.test, mitu@hrm.test |

For a real company, run `php artisan migrate:fresh` (no seed) and create users with the command below.

---

## 2. Admin panel — `/admin`

Log in with an **HR** or **Admin** account (`hr@hrm.test` / `admin@hrm.test` in the demo). Regular employees are refused.

| Section | What HR can do |
|---|---|
| Dashboard | Present / late / on leave / absent today, pending approvals, 14-day attendance chart, today's attendance list |
| Employees | Add/edit employees (official, personal, emergency, bank, app password, HR/Admin role), set manager, activate/deactivate, reset phone binding, log out from app |
| Departments / Designations / Appreciations | Master data |
| Attendance | All check-ins with filters (date range, employee, late), manual add/correct, breaks, live locations (open in Google Maps) |
| Leave | Approve / reject (one by one or bulk), add leave for an employee, daily leave approvals, leave types & yearly days, holidays & events |
| Work | Assign visits, meetings (with participants), appointments, video conferences (Jitsi/Zoom link), tasks |
| Finance | Expense claims: view items, approve/reject, **record payment** (partial or full); expenses; categories; payslips — **Generate month** button + PDF download |
| Communication | Publish notices (all or one department), support tickets (close), **send a notification** to everyone / a department / selected people |
| Settings | Office time, weekends, late grace, reasons, multi check-in, office-IP lock, one-phone lock, live tracking interval, hide app menus, app pages (privacy policy etc.) |

Employees get an in-app notification (and push, if Firebase is set) when HR approves/rejects or assigns something.

---

## 3. Connect the Flutter app

In the app, `lib/utils/app_const.dart` (updated file included in `flutter-app-patch/`):

```dart
static String baseUrlApi = const String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'https://hrm.webase.com.bd/api/V11',
);
```

Build with your server:

```bash
flutter build apk --dart-define=API_BASE_URL=https://hrm.yourdomain.com/api/V11
```

Local testing on a real phone: run `php artisan serve --host=0.0.0.0`, use your PC's LAN IP
(`http://192.168.x.x:8000/api/V11`), and temporarily add `android:usesCleartextTraffic="true"`
to `<application>` in `AndroidManifest.xml` (remove it before release).

---

## 4. Admin commands

```bash
# Create an employee (department/designation are created if missing)
php artisan hrm:create-user "Rahim Uddin" rahim@company.com secret123 --department=Engineering --designation="Software Engineer" --manager=3
php artisan hrm:create-user "HR Person" hr@company.com secret123 --hr

# Monthly payslips from users.basic_salary (default: last month)
php artisan hrm:payslips 2026-11
```

Payslips are downloaded by the app as PDF through a signed link (valid 7 days), generated on the fly.

---

## 5. Settings (`settings` table)

All of these can be changed from **Admin panel → Settings**.

| key | default | what it does |
|---|---|---|
| `company_name` | Webase Solutions | Shown on payslips |
| `office_start` / `office_end` | 09:00 / 18:00 | Duty time — late / early detection |
| `late_grace_minutes` | 15 | Minutes after start before "late" |
| `require_late_reason` / `require_early_reason` | 1 | App asks for a reason on late in / early out |
| `weekends` | Friday | Comma list, e.g. `Friday,Saturday` |
| `multi_checkin` | 0 | Allow several check-in/out per day |
| `ip_restriction` + `allowed_ips` | 0 / empty | Only allow attendance from office IPs |
| `single_device_login` | 0 | Lock each account to its first phone |
| `location_service` | 1 | App sends location with attendance |
| `app_sync_time` / `live_data_store_time` | 5 / 5 | Live tracking interval (minutes) |
| `app_theme` | app_theme_1 | `app_theme_1`, `app_theme_2`, `app_theme_3` |
| `currency_code` | BDT | |
| `hidden_menus` | empty | Hide menu items by slug, e.g. `payroll,video_conference` |

Settings are cached; after editing them directly in the DB run `php artisan cache:clear`.

---

## 6. Roles & approvals

- **Employee:** own attendance, leave, visits, expenses, tickets, etc.
- **Manager:** anyone set as another user's `manager_id`. Sees/approves their team's leave and daily leave.
- **HR / Admin** (`is_hr`, `is_admin`): sees everyone, approves anything, publishes notices, sees company reports.

Notifications are stored in `app_notifications` (shown in the app). For phone push, set
`FIREBASE_CREDENTIALS` to your Firebase service-account JSON — it must be the **same Firebase
project** as the app's `google-services.json` / `GoogleService-Info.plist`.

---

## 7. API modules

| Module | Endpoints |
|---|---|
| Auth | `login`, `check-token`, `reset-password` (emails a code), `change-password`, `user/password-update`, `logout` |
| App | `app/base-settings`, `app/home-screen`, `app/all-contents/{slug}`, `dashboard/statistics`, `upcoming-events/get-list` |
| Attendance | `user/attendance/check-in`, `check-out/{id}`, `get-checkin-checkout-status`, `late-in-reason/{id}`, `live-location-store` |
| Break | `user/attendance/break-back/{start|back}`, `break-status`, `break-history` |
| Leave | `user/leave/summary`, `available`, `list/view`, `details/{id}`, `request`, `request/update/{id}`, `request/cancel/{id}`, `approval/list/view`, `approval/status-change/{id}/{1|6}` |
| Daily leave | `daily-leave/store`, `leave-list`, `staff-list-view`, `single-view`, `approve-reject` |
| Visit | `visit/list`, `create`, `update`, `show/{id}`, `change-status`, `create-note`, `create-schedule`, `image-upload`, `history` |
| Meeting / Appointment | `meeting`, `meeting/create`, `meeting/show/{id}`, `appoinment/get-list`, `appoinment/create`, `appoinment/details/{id}`, `conference/my-meeting` |
| Notice / Support | `notice/list`, `show/{id}`, `add`, `clear`, `support-ticket/list`, `add`, `show/{id}` |
| Profile | `user/profile/{official|personal|financial|emergency}`, `user/profile/update/*`, `user/avatar-update`, `appreciate/create`, `user/profile/appreciates` |
| Phonebook | `app/get-all-users/{designation?}`, `user/details/{id}`, `user/search/{q}`, departments, designations, managers... |
| Expense | `accounts/expense/*` and `expense/*` (list, add, update, delete, send-claim, claim-history, claim-details, payment-history) |
| Payroll | `report/payslip/list` → signed PDF link |
| Tasks | `tasks`, `tasks/list`, `tasks/{id}`, `tasks/change-status`, `tasks/create` |
| Reports | `report/attendance/*`, `report/break/*`, `report/leave/*` |

Every response uses the app's envelope: `{"result": bool, "message": string, "data": ...}`.
Validation errors return `422` with `error: {field: [messages]}`.

---

## 8. Contract tests

```bash
php artisan migrate:fresh --seed && php artisan serve &
pip install requests
python3 tests/api-contract/apitest.py http://127.0.0.1:8000/api/V11
```

`schema.json` was generated from the app's Dart models. If you change a model in the app,
regenerate it or update the test.

---

## 9. Still to do

- **Salary breakdown** (allowances, deductions, tax) — payslips currently use one net salary per employee.
- **Firebase / Google Maps keys and package name** in the Flutter app still belong to the original vendor
  (`com.onesttech.hrm`, `google-services.json`, Maps API keys). Replace them with your own before publishing.
