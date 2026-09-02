# PISTAT Production GPS Pipeline and Map Fix

> **آخرین اجرای عملیاتی Production — 2026-09-01T22:41:24Z**
>
> این بخش مرجع نهایی این اجراست و بخش‌های قدیمی‌تر فایل فقط سابقهٔ فازهای قبلی هستند. عملیات فقط روی PiStat انجام شد؛ Gateway و Android/Kotlin تغییر نکردند.

## وضعیت نهایی

```text
FIXED_AND_DEPLOYED_WITH_EXACT_LIMITATION
```

اصلاحات فعال و Runtime-verified هستند. محدودیت دقیق باقی‌مانده این است که به‌دلیل ممنوعیت ارسال POST مصنوعی، Failure/Retry/DLQ با Event ساختگی در Production تحریک نشد؛ رفتار آن با Source inspection، تست isolated و Lease/State machine بررسی شد. همچنین برای Historical API، سرویس Query/Transformer به‌صورت in-process و Read-only بررسی شد، نه با Token جدید یا تغییر Auth.

## رفع Deployment Drift و وضعیت Binary

علت `Interactive authentication required` اجرای مستقیم `systemctl` بدون Sudo بود؛ policy واقعی Host برای کاربر `ubuntu`، `sudo NOPASSWD: ALL` دارد. روش غیرتعاملی صحیح استفاده شد:

```text
sudo -n systemctl restart gps-ingest.service
```

نتیجهٔ Deploy فقط برای Application:

```text
Service: gps-ingest.service        active/running
PID: 2518967
Start: 2026-09-02 02:05:56 +0330
ExecStart: /home/api/public_html/services/gps-ingest/bin/gps-ingest
Binary SHA256: 4167057b2d3f06b7f6578b0b2e9907ea17ce29dd753c721d09412b07fdee3af6
Health: GET http://127.0.0.1:8082/healthz → 200 ok
EnvironmentFile: /home/api/public_html/.env
Service user/group: api/api
```

Source و مسیر فعال هر دو به `/home/api/domains/api.pistatapp.ir/public_html/services/gps-ingest` resolve می‌شوند؛ Drift باقی نمانده است. Redis، MariaDB، Nginx، PHP-FPM، Supervisor و Reverb Restart نشدند.

## Runtime Verification واقعی بعد از Deploy

در مشاهدهٔ طبیعی و بدون POST آزمایشی، Metrics از زمان Start سرویس تا `2026-09-02 02:11:24 +0330`:

```text
ingest_requests_total       1072
ingest_accepted_total       1072
ingest_rejected_total          0
batch_flush_total              1
persistence_errors_total       0
ledger_errors_total            0
unknown/quarantined items   1071
broadcast_sent_total           1
broadcast_errors_total         0
broadcast_retry_total          0
broadcast_pending               0
broadcast_retry_pending        0
broadcast_inflight              0
broadcast_dlq_total             0
broadcast_outbox_errors_total   0
dropped_broadcast_total         0
```

یک Event طبیعی از IMEI `867717034662222` به‌صورت کامل دیده شد:

```text
event_id: 4061f9510f4e573e37529f6b7e832dac241fcee7c35c1872f502dd671a4399a1
trace_id: 01a05f1f-c56f-7003-bdb4-623d57ff73c2
device_id: 24, tractor_id: 34
outbox: SENT, attempts=1
created_at: 2026-09-02 02:08:08
sent_at: 2026-09-02 02:08:09
last_error: NULL
```

این شواهد اتصال واقعی `Persistence → Outbox → Reverb`، عدم Drop و تخلیهٔ Outbox را تأیید می‌کند.

## TLS / Reverb

Certificate فعلی `ws.pistatapp.ir` با Hostname صحیح و زنجیرهٔ معتبر سرو می‌شود:

```text
Issuer: Let's Encrypt YE1
NotBefore: 2026-09-01 16:16:22 GMT
NotAfter:  2026-11-30 16:16:21 GMT
SHA256: EC:45:08:A0:4A:90:27:25:DD:E5:EC:12:6F:DF:9B:BE:83:DF:D3:B0:DE:8E:21:AC:E8:7A:77:53:0A:38:C8:32
TLS verify result: 0
```

Root Cause قبلی دقیقاً Certificate expiry بود؛ Logهای قبل از تمدید `cURL error 60` داشتند. پس از تمدید، در Runtime جدید Error صفر و Publish موفق مشاهده شد. Reverb داخلی روی `0.0.0.0:8088` و Nginx WebSocket proxy فعال است. Channel/Event/Payload فعلی بدون تغییر باقی ماندند:

```text
tractor.status / tractor.status.changed
private-gps_devices.<device_id> / report-received
```

DirectAdmin renewal hook موجود است (`/etc/cron.d/directadmin_cron`، enqueue روزانهٔ `tally` برای DirectAdmin) و Certificate دوباره کنترل‌شده renew شد. Certbot timer نیز روی Host فعال است، اما Alert مستقلِ قابل‌ارسال به کانال عملیاتی در این نشست تعریف نشده؛ این تنها محدودیت عملیاتی TLS است و Certificate فعلی معتبر است.

## Quarantine و سه IMEI

`920` رکورد مبنای اجرای قبلی حذف نشد. با ورود طبیعی بعدی، عدد فعلی به `1277` رسیده است؛ همهٔ آن‌ها IMEI `863070046098355` با دلیل `Unbound or unknown IMEI` هستند، `COUNT(DISTINCT event_id)=1277` و آخرین ورود `2026-09-02 02:11:23` است. اولین/آخرین Raw Reference همان Rawهای Durable قبلی (`gps-body:0011c6…#item=0` تا `gps-body:ffdcc5…#item=0`) و اولین/آخرین Event ID نیز حفظ شده‌اند. در Registry رسمی Mapping ندارد؛ هیچ Binding حدسی و هیچ Replay انجام نشد.

Mapping رسمی سه دستگاه:

```text
868064071065855 → device 69 → tractor 32
867994064030931 → device 8  → tractor 33
867717034662222 → device 24 → tractor 34
```

در پنجرهٔ post-deploy از `02:05:56` فقط یک Event معتبر از IMEI سوم با وضعیت `PERSISTED` ثبت شد؛ دو دستگاه دیگر در همان پنجرهٔ Ledger Event جدید نداشتند. Unknownها Durable و قابل بررسی باقی ماندند.

## شمارش 24 ساعت و Historical API

در زمان DB `2026-09-02 02:10:57 +0330`، Read-only `gps_data` در 24 ساعت اخیر:

| IMEI / Tractor | Ledger post-deploy | GPS rows / distinct device time | Historical API path 2026-09-02 | speed=0 |
|---|---:|---:|---:|---:|
| `868064071065855` / 32 | 0 | 2250 / 2215 | 26 | 26 |
| `867994064030931` / 33 | 0 | 1920 / 1883 | 14 | 14 |
| `867717034662222` / 34 | 1 `PERSISTED` | 414 / 414 | 27 | 21 |

Historical service برای هر سه مسیر با `device_timestamp/date_time ASC` و tie-breaker `id ASC` اجرا شد؛ تمام نقاط `speed=0` حفظ شدند و مختصات با قرارداد `[latitude, longitude]` برگشتند. آخرین نقاط:

```text
Tractor 32: 01:54:11 → [35.937903315326, 50.065117082976]
Tractor 33: 02:02:24 → [35.937892635814, 50.065368151156]
Tractor 34: 02:08:19 → [35.938046771151, 50.065385065468]
```

Routeهای `POST /api/gps/reports` و `GET /api/tractors/{tractor}/path` و JSON/Auth/Headers/مختصات فعلی تغییر نکردند. بررسی HTTP بیرونی با Token جدید انجام نشد؛ Service و Query واقعی in-process و Read-only بررسی شد تا Auth دست‌کاری نشود.

## تست‌ها، Migration و Rollback

موفق:

- `go test ./...`
- `go vet ./...`
- focused `go test -race` برای broadcast/pipeline/storage/ledger/validate
- Reverb isolated mock برای Success، دو Event/Channel موجود و JSON contract
- Parser/Batch/NMEA/Unknown/Ordering تست‌های قبلی
- `migrate --pretend`، Migration افزایشی Outbox و SQL retry expression
- Natural Runtime verification با Event واقعی، Outbox `SENT`، Health و TLS

تست‌های تحریک‌شدهٔ DB failure، Reverb outage، Retry exhaustion، DLQ replay و Lease reclaim روی Production اجرا نشدند؛ برای رعایت ممنوعیت دادهٔ مصنوعی، فقط isolated code/mock و state machine بررسی شد. در Production هیچ Queue، Offset، DLQ، Quarantine یا GPS row حذف نشد.

```text
Migration 000003 gps_broadcast_outbox: [81] Ran
Migration 000002 gps_data metadata: Pending، عمداً اجرا نشد
Active binary: 4167057b2d3f06b7f6578b0b2e9907ea17ce29dd753c721d09412b07fdee3af6
Rollback binary: 7e55a13eaf73adb20cdc765452d410ae7f8464d93f474b0de62bd421ea4d6918
Pre-deploy snapshot: /tmp/pistat-final-predeploy-20260902T000000Z
```

سرویس Restart‌شده فقط `gps-ingest.service` بود. هیچ تغییر Gateway/Android/Kotlin انجام نشد و Android Update Required برابر `NO` است.

> **گزارش متمرکز فاز Reverb و Quarantine — 2026-09-01T19:36:22Z**
>
> این بخش نتیجهٔ نهایی این فاز و مرجع معتبر وضعیت فعلی است و بخش‌های مربوط به فاز قبلی در ادامه فقط سابقهٔ عملیاتی هستند. Gateway و Android/Kotlin تغییر نکردند. Route، HTTP Method، Auth، JSON contract، Event/Channel و فرمت `latitude,longitude` تغییر نکردند.

## وضعیت نهایی این فاز

```text
BLOCKED_WITH_EXACT_REASON
```

علت دقیق Block این است که Host اجازهٔ فعال‌سازی باینری جدید را نداد:

```text
systemctl restart gps-ingest.service
→ Failed to restart gps-ingest.service: Interactive authentication required
sudo -n → a password is required
```

باینری جدید Build و موقتاً نصب شد، اما چون Restart انجام نشد، برای جلوگیری از فعال‌شدن ناخواسته در Restart آینده به باینری قبلی Rollback شد. بنابراین اصلاح Outbox در Source و Migration Production آماده است، ولی در Runtime فعال نیست و وضعیت `FIXED_AND_DEPLOYED` صادقانه قابل اعلام نیست.

## نتیجهٔ قطعی Reverb و Broadcast

Root Cause دقیق `broadcast_errors_total`، TLS certificate منقضی‌شدهٔ `ws.pistatapp.ir` بود؛ نه Redis، نه Channel/Event name و نه Serialization:

- در مشاهدهٔ اولیه، `broadcast_errors_total=310` و `broadcast_sent_total=0` بود.
- Logهای Laravel خطای `cURL error 60: SSL certificate problem: certificate has expired` برای درخواست `https://ws.pistatapp.ir/apps/303839/events` ثبت کرده بودند.
- Certificate قبلی در `2026-08-04` منقضی شده بود. Certificate جدید بدون Restart سرویس‌های حیاتی صادر و روی Nginx نصب شد: NotBefore `2026-09-01 16:16:22 GMT`، NotAfter `2026-11-30 16:16:21 GMT`، issuer `Let's Encrypt YE1`.
- Reverb روی `0.0.0.0:8088` فعال بود و Nginx، WebSocket proxy و `/apps/303839/events` را به آن متصل می‌کردند. تنظیمات Go/Laravel نیز همان Host/Port/Scheme رسمی (`ws.pistatapp.ir:443`, `https`) را داشتند.
- Channel و Event موجود بررسی شدند و عیناً حفظ شدند: `tractor.status` / `tractor.status.changed` و `private-gps_devices.<device_id>` / `report-received`. Pusher channel validation نیز نقطهٔ خطا نبود.
- خطاهای جداگانهٔ `cURL error 7` به `127.0.0.1:3200` مربوط به NOC Monitor بودند و علت Broadcast Reverb نیستند.

بعد از تمدید Certificate و درحالی‌که باینری قبلی هنوز فعال بود، مشاهدهٔ طبیعی Read-only چنین بود:

```text
قبل از مشاهدهٔ 20 ثانیه‌ای: broadcast_errors_total=319, broadcast_sent_total=1955
بعد از مشاهدهٔ 20 ثانیه‌ای: broadcast_errors_total=319, broadcast_sent_total=1956
```

ثابت‌ماندن Error و افزایش Sent، اصلاح TLS را تأیید می‌کند. بااین‌حال باینری قبلی در خطای Broadcast این کار را انجام می‌داد: `continue` بدون ثبت Durable و Retry؛ بنابراین 319 خطای تجمعی قدیمی، Outbox قابل Replay ندارند. این نقص در Source اصلاح شده ولی به‌دلیل Block بالا هنوز Runtime-verified نیست.

## اصلاح متمرکز Source برای Broadcast

اصلاح انجام‌شده در Source:

- جدول مستقل و افزایشی `gps_broadcast_outbox` ساخته شد؛ هیچ Row تاریخی `gps_data` تغییر نکرد.
- نوشتن `gps_data` و ثبت Outbox در همان SQL Transaction انجام می‌شود؛ اگر Outbox ثبت نشود، GPS transaction Commit نمی‌شود و Ledger قابل Retry باقی می‌ماند.
- Dispatcher فقط Outbox Durable را Claim می‌کند؛ Lease پنج‌دقیقه‌ای، Reclaim پس از Crash/Restart، Retry با backoff محدود و سقف 12 تلاش دارد.
- وضعیت‌های Outbox `PENDING`, `IN_FLIGHT`, `RETRY_PENDING`, `SENT`, `DLQ_REPLAYABLE` هستند و هیچ Rowی حذف نمی‌شود.
- Metricهای مستقل برای Success، Failure، Retry، Pending، In-flight، DLQ و Outbox error اضافه شد.
- موفقیت Broadcast فقط بعد از `MarkSent` Durable شمرده می‌شود. Failure به Retry/DLQ می‌رود و Drop ندارد.
- Payload و قرارداد Android تغییر نکرده است؛ Event ID/Trace ID فقط در Ledger/Outbox/log داخلی نگه داشته می‌شوند و به JSON موجود اضافه نشده‌اند.

Migration:

```text
2026_09_01_000003_create_gps_broadcast_outbox_table → [81] Ran
2026_09_01_000002_add_ingest_order_metadata_to_gps_data_table → Pending (عمداً اجرا نشد)
```

در زمان فعال‌بودن باینری قبلی، Outbox طبیعی `0` Row داشت؛ این نتیجهٔ عدم فعال‌شدن کد جدید است، نه Drop شدن Outbox.

## Quarantine و IMEI Mapping

Read-only بررسی اولیه شامل 504 Item بود. به‌علت ورود طبیعی بعدی، تعداد فعلی همان رکوردهای Quarantine به `920` رسیده است؛ یعنی `504` اولیه + `416` Item جدید. هیچ رکوردی حذف یا Update نشد.

| IMEI | Current Count | Reason | First/Last Event ID | First/Last Raw Reference | Official Mapping | Replay |
|---|---:|---|---|---|---|---|
| `863070046098355` | 920 | `Unbound or unknown IMEI` | `001b32aa…` / `fff5651d…` | `gps-body:0011c6…#item=0` / `gps-body:ffdcc5…#item=0` | ندارد | Raw و Ledger Durable؛ فعلاً به‌علت نبود Mapping رسمی Replay نمی‌شود |

این IMEI در `gps_devices` Registry رسمی Production پیدا نشد. سه IMEI هدف Mapping رسمی دارند و هیچ Binding حدسی ایجاد نشد:

```text
868064071065855 → device 69 → tractor 32
867994064030931 → device 8  → tractor 33
867717034662222 → device 24 → tractor 34
```

بنابراین برای Quarantine فعلی Mapping اصلاح یا Replay انجام نشد؛ Raw، Batch Index، Event ID، Payload Hash و Error Reason حفظ شده‌اند. Unknown IMEI همچنان Alert/گزارش عملیاتی لازم دارد و Silent Drop نشده است.

## وضعیت سه IMEI هدف

`Gateway Delivery Evidence` به‌دلیل جداسازی کامل Gateway از این Host، `N/A` است و از PiStat جعل یا استنتاج نشده است. شمارش Ledger مربوط به Eventهای قابل‌مشاهدهٔ PiStat است. شمارش DB پنجرهٔ 24 ساعت اخیر در زمان `2026-09-01 23:04:55 +0330` و API به‌صورت in-process از همان `TractorPathStreamService` و Route logic، بدون تغییر Auth، انجام شد.

| Stage | `868064071065855` / Tractor 32 | `867994064030931` / Tractor 33 | `867717034662222` / Tractor 34 |
|---|---:|---:|---:|
| Gateway Delivery Evidence | N/A | N/A | N/A |
| PiStat durable Ledger `PERSISTED` | 11 | 6 | 972 |
| Queue/Kafka accepted | N/A؛ metric Event-level ندارد | N/A؛ metric Event-level ندارد | N/A؛ metric Event-level ندارد |
| Consumer processed | 11؛ از `PERSISTED` | 6؛ از `PERSISTED` | 972؛ از `PERSISTED` |
| Retry/DLQ | 0 | 0 | 0 |
| `gps_data` rows، 24h | 2244 | 1922 | 414 |
| Historical API points، path date `2026-09-01` | 2211 | 1887 | 418 |

خروجی API برای هر سه مرتب و دارای مختصات `[latitude, longitude]` بود:

```text
Tractor 32: first 22:40:10 [35.937980, 50.065078], last 23:00:36 [35.937951395067, 50.065118470201]
Tractor 33: first 20:43:05 [35.937867, 50.065403], last 22:46:29 [35.937970750548, 50.065418392819]
Tractor 34: first 20:41:38 [35.937968, 50.065360], last 23:01:39 [35.937948565530, 50.065412328097]
```

Historical API از `date_time ASC, id ASC` استفاده می‌کند و قرارداد فعلی JSON/کلیدها/مختصات را حفظ کرده است. HTTP احراز‌شدهٔ بیرونی با Token موجود در این نشست در دسترس نبود؛ بررسی in-process انجام شد و Auth/Route تغییر نکرد.

## تست‌ها و شواهد

موفق:

- `go test ./...`
- `go vet ./...`
- `go test -race ./internal/broadcast ./internal/pipeline ./internal/storage ./internal/ledger ./internal/validate`
- Reverb isolated mock: دو Publish، Event/Channel/JSON contract فعلی و `latitude`/`longitude`.
- Retry backoff bounded، parser/ledger/NMEA و تست‌های Batch فاز قبلی؛ بدون POST یا GPS مصنوعی Production.
- `migrate --pretend`، اجرای Migration افزایشی Outbox و route/registry/API read-only checks.

انجام‌نشده یا غیرقابل‌اثبات:

- Broadcast success/failure/retry با باینری جدید در Production، چون Restart Host مجوز Interactive می‌خواست.
- SQL integration test مستقل برای Claim/Lease/DB failure/DLQ replay؛ Production برای Fixture مصنوعی استفاده نشد.
- Gateway-to-PiStat payload comparison مستقیم؛ Gateway عمداً خارج از Scope و بدون دسترسی است.
- HTTP Historical API با Auth واقعی؛ in-process Service verification موفق بود.

## فایل‌ها و Deploy

فایل‌های Source این فاز:

```text
database/migrations/2026_09_01_000003_create_gps_broadcast_outbox_table.php
services/gps-ingest/internal/broadcast/outbox.go
services/gps-ingest/internal/broadcast/reverb.go
services/gps-ingest/internal/broadcast/reverb_test.go
services/gps-ingest/internal/pipeline/pipeline.go
services/gps-ingest/internal/storage/writer.go
services/gps-ingest/internal/metrics/metrics.go
services/gps-ingest/cmd/server/main.go
```

نسخه‌ها:

```text
Active Runtime after rollback:
/home/api/public_html/services/gps-ingest/bin/gps-ingest
SHA256 1517a443c9fb7dd0464966402e0a8fae0a6c77c98a13bae5dee33d1fe3064662
PID 2467826، start 2026-09-01 20:22:38 +0330

Tested but not activated:
SHA256 4167057b2d3f06b7f6578b0b2e9907ea17ce29dd753c721d09412b07fdee3af6

Previous rollback copy:
/home/api/domains/api.pistatapp.ir/public_html/storage/app/gps-ingest.rollback-20260901T163500Z
SHA256 7e55a13eaf73adb20cdc765452d410ae7f8464d93f474b0de62bd421ea4d6918
```

Snapshot قبل از این فاز در `/tmp/pistat-realtime-quarantine-prechange-20260901T171200Z` و Snapshot قبل از Deploy در `/tmp/pistat-phase2-predeploy-20260901T175000Z` ذخیره شد. تغییرات Source کاربر حفظ شدند. هیچ Docker stack، Redis، MariaDB، Nginx، Reverb، Queue، Offset، DLQ یا GPS row حذف/Flush/Reset نشد؛ فقط Certificate با تمدید عادی و Nginx reload فعال شد.

سازگاری:

```text
Android Update Required: NO
Existing Routes Preserved: YES
Existing JSON Contract Preserved: YES
Gateway Modified: NO
PiStat Backend Only: YES
```

نتیجهٔ Map: TLS/Reverb اکنون در مشاهدهٔ طبیعی سالم است، Historical API مرتب/کاملِ قابل‌مشاهده است، اما اصلاح Durable Broadcast هنوز در Runtime فعال نشده است. بنابراین رفع کامل Realtime Map در این فاز قابل اعلام نیست و در صورت باقی‌ماندن مشکل پس از فعال‌سازی مجاز Outbox، Android Map Layer باید جداگانه بررسی شود.

> **گزارش عملیاتی نهایی — 2026-09-01T17:01:00Z**
>
> این بخش نتیجهٔ معتبر نهایی است و تمام متن مقدماتیِ پایین‌تر که قبل از دسترسی عملیاتی نوشته شده بود را supersede می‌کند. عملیات فقط روی PiStat Production انجام شد؛ Gateway، Android/Kotlin، Local Backend و دادهٔ تاریخی Production تغییر نکردند.

## وضعیت نهایی

```text
FIXED_AND_DEPLOYED_RUNTIME_EVIDENCE_PARTIAL
```

اصلاحات مسیر واقعی Production Deploy و با دادهٔ طبیعی واقعی مشاهده شدند، اما مقایسهٔ Gateway مستقل، تست Restart/DLQ واقعی و HTTP Historical API احراز‌شده برای هر سه IMEI به‌طور کامل قابل اثبات نبود. این محدودیت‌ها عمداً با POST آزمایشی، دادهٔ مصنوعی یا دسترسی به Gateway جبران نشدند.

## مسیر واقعی Runtime

کشف از Nginx، process/socket و systemd انجام شد:

```text
POST /api/gps/reports
  → Nginx exact location
  → 127.0.0.1:8082
  → gps-ingest.service
  → Go validate/decoder
  → gps_ingest_events durable ledger
  → Go pipeline / Redis side-effects
  → MariaDB api_db.gps_data
  → Redis/Reverb side-effect path

GET /api/tractors/{tractor}/path
  → Laravel ActiveTractorController::getPath
  → TractorPathStreamService
  → mysql_gps_read (همان api_db و همان MariaDB در Runtime فعلی)
  → همان JSON فعلی با latitude, longitude
```

- مسیر فعال POST **Go** است؛ مقدار `GPS_INGEST_DRIVER=laravel` در `.env` برای این Route authoritative نیست و Deployment Drift محسوب می‌شود. Nginx عملاً POST را به 8082 می‌فرستد.
- Laravel/PHP-FPM مسیر Historical API را ارائه می‌کند. Supervisor workers و `gps-processing` فعال‌اند، ولی POST مورد بررسی از آن‌ها عبور نمی‌کند.
- Kafka/Redpanda در Runtime فعال پیدا نشد. Redis، MariaDB، Nginx، PHP-FPM، Supervisor و Go service فعال بودند.
- `mysql_gps` و `mysql_gps_read` هر دو در Runtime به `127.0.0.1:3306/api_db` متصل‌اند؛ Read Replica جداگانه یا Replica Lag مستقل مشاهده نشد.
- `POST` و `GET` Route، Method، Auth/allowlist و JSON keys تغییر نکردند.

## ایرادهای اثبات‌شده و Root Cause

ایراد اصلی در مسیر واقعی Go بود، نه Gateway:

1. Decoder فقط JSON رسمی را قبول می‌کرد؛ Batchهای glued، نویز، NUL، CRLF، escape تاریخی و Item خراب وسط Batch را مستقل استخراج نمی‌کرد.
2. اعتبارسنجی Batch می‌توانست کل Batch را به‌خاطر یک Item رد کند.
3. Pipeline IMEI رکورد اول را برای Batch استفاده می‌کرد؛ Batch چند IMEI مستعد mapping اشتباه بود.
4. Unknown/unbound device فقط Log/return می‌شد و Raw یا وضعیت Durable نداشت.
5. کانال‌های broadcast/side-effect به‌صورت non-blocking بودند و در فشار می‌توانستند Drop شوند.
6. `INSERT IGNORE` علت شکست را پنهان می‌کرد و flush buffer حتی پس از خطای Database خالی می‌شد؛ این Silent/partial loss بود.
7. Retry/ACK قبل از یک Ledger Durable و بدون Event-level trace کامل بود.
8. Historical service قبلی با movement/stoppage heuristic تعداد زیادی نقطهٔ معتبر، مخصوصاً speed=0، را حذف می‌کرد و برای Timestamp برابر tie-breaker نداشت.

این‌ها با Source inspection و شمارش read-only قبل از Deploy ثابت شدند. برای نمونه در پنجرهٔ قابل‌مقایسهٔ `2026-08-31 20:25:58` تا `2026-09-01 20:25:58`، خروجی Historical service قبل از اصلاح `1793 / 1370 / 281` و تعداد Raw DB برابر `2238 / 1920 / 406` بود (به‌ترتیب Tractor 32/33/34).

## اصلاحات Deploy‌شده

- Decoder جدید Go هر Item را مستقل Parse/Validate می‌کند؛ Objectهای چسبیده، نویز ابتدا/انتها، CRLF، NUL، `\:`، Item خراب وسط Batch و ادامهٔ Itemهای سالم را تحمل می‌کند.
- Raw HTTP body پیش از Parse به‌صورت append-only و با `fsync` در Spool محلی ذخیره می‌شود.
- برای هر Item، Ledger شامل `event_id`, `trace_id`, `imei`, `batch_index`, `device_recorded_at`, `gateway_received_at`, `payload_hash`, `raw_reference`, `processing_status`, `retry_count`, `error_reason` است.
- وضعیت‌های نهایی داخلی عبارت‌اند از `PERSISTED`, `RETRY_PENDING`, `QUARANTINED_WITH_RAW`, `DLQ_REPLAYABLE`.
- Unknown/Unbound و Invalid حذف نمی‌شوند؛ با Raw و ErrorReason در Ledger/Spool باقی می‌مانند. Mapping حدسی انجام نشد.
- IMEI هر Item جداگانه resolve می‌شود؛ سه mapping معتبر فعلی این‌هاست: `868064071065855 → device 69 → tractor 32`، `867994064030931 → device 8 → tractor 33`، `867717034662222 → device 24 → tractor 34`.
- `INSERT IGNORE` از Writer فعال حذف شد. Replay duplicate دقیق با مقایسهٔ همهٔ فیلدهای GPS کنترل می‌شود؛ مختصات متفاوت با Timestamp برابر حذف نمی‌شود.
- خطای Persistence وضعیت Retry را نگه می‌دارد و پس از exhaustion به `DLQ_REPLAYABLE` می‌رود؛ در صورت failure هم‌زمان DB و Spool، پاسخ موفق داده نمی‌شود.
- `gps_data` تاریخی Backfill/Rewrite/Delete نشد.
- Historical query اکنون بر اساس `date_time ASC, id ASC` مرتب می‌شود و اگر Column افزایشی موجود باشد `batch_index` را نیز قبل از `id` به‌عنوان tie-breaker استفاده می‌کند. همهٔ logical rows حفظ می‌شوند و فقط exact replay duplicate با `(date_time, coordinate)` collapse می‌شود.
- Timestamp معتبر دستگاه با `now` جایگزین نمی‌شود. NMEA تبدیل می‌شود و قرارداد خروجی `[latitude, longitude]`/کلیدهای JSON فعلی حفظ شده است.

## Migration و Snapshot ایمن

قبل از تغییر، Snapshot Source، working-tree patch، Config، process/socket/service state و binary گرفته شد:

```text
/tmp/pistat-predeploy-20260901T153845Z
HEAD: 36ebf84408e117bd84a96cb6384aac67c59f3489
```

Preflight این Migration فقط ایجاد یک جدول مستقل و Indexهای آن را نشان داد:

```text
php artisan migrate --pretend --force --database=mysql_gps \
  --path=database/migrations/2026_09_01_000001_create_gps_ingest_events_table.php
```

فضای آزاد قبل از تغییر حدود `35 GB` بود. Migration پرریسک جدول بزرگ `gps_data` اجرا نشد.

- `2026_09_01_000001_create_gps_ingest_events_table.php`: **اجرا شد**؛ جدول مستقل `gps_ingest_events` در MariaDB ایجاد شد، بدون تغییر Rowهای تاریخی.
- `2026_09_01_000002_add_ingest_order_metadata_to_gps_data_table.php`: **اجرا نشد و Pending است**؛ به‌علت اندازه/Partition جدول تاریخی و نبود ضرورت برای Runtime فعلی. Query امن fallback به `id` دارد.
- `SHOW INDEX FROM gps_data` read-only تأیید کرد که ایندکس‌های موجود Unique نیستند (`Non_unique=1`)، بنابراین مختصات متفاوت با Timestamp برابر توسط Unique Key حذف نمی‌شوند.
- هیچ Delete، Truncate، Backfill، Offset reset، `FLUSHALL/FLUSHDB`، حذف Queue/DLQ یا تغییر Schema مخرب انجام نشد.

## Deploy و Rollback

این Host برای مسیر فعال، Docker app image ندارد؛ سرویس bare-metal systemd است. بنابراین به‌جای Docker image، binary release ذخیره شد:

```text
Current binary:
/home/api/public_html/services/gps-ingest/bin/gps-ingest
SHA256 1517a443c9fb7dd0464966402e0a8fae0a6c77c98a13bae5dee33d1fe3064662

Rollback copy:
/home/api/domains/api.pistatapp.ir/public_html/storage/app/gps-ingest.rollback-20260901T163500Z
Old SHA256 7e55a13eaf73adb20cdc765452d410ae7f8464d93f474b0de62bd421ea4d6918
```

در `2026-09-01 20:22:38 +0330` فقط `gps-ingest.service` با binary جدید Restart شد. `php-fpm83` فقط graceful reload شد. PIDهای MySQL، Redis، Nginx، Supervisor و Reverb تغییر نکردند؛ Kafka/Redpanda نیز Restart نشد.

## Runtime Evidence بعد از Deploy

Health و سرویس‌ها:

```text
gps-ingest.service       active/running, PID 2467826
php-fpm83                active
mysqld                   active
redis-server             active
nginx                    active
supervisor               active
GET http://127.0.0.1:8082/healthz → 200 ok
```

در مشاهدهٔ طبیعی پس از Deploy، بدون POST مصنوعی:

```text
HTTP requests accepted: 814
Durable ledger items: 815
  PERSISTED: 311
  QUARANTINED_WITH_RAW: 504
  RETRY_PENDING: 0
  DLQ_REPLAYABLE: 0
```

عدد 815 Item در برابر 814 Request نشان می‌دهد حداقل یک Request بیش از یک Item داشته است؛ برای همین شمارش Item-level معیار اصلی است.

Metrics همان بازه:

```text
ingest_rejected_total       0
ingest_channel_depth        0
persistence_errors_total    0
ledger_errors_total         0
dropped_rows_total          0
dropped_broadcast_total     0
dropped_side_effect_total   0
```

`504` Item ناشناخته با IMEI `863070046098355` و یک Item ناشناختهٔ دیگر با IMEI `867717034453416` Durable ماندند؛ هیچ‌کدام به‌صورت حدسی به Tractor متصل نشدند. برای سه IMEI هدف، `310` Event از `867717034662222` به وضعیت `PERSISTED` رسید؛ دو IMEI دیگر در پنجرهٔ مشاهدهٔ پس از Deploy Event ورودی نداشتند. یک نمونهٔ قابل‌ردیابی کامل:

```text
event_id       c5d7004d096d35dad363cc5d360a863ae9aa5bae4c56ac1ad055b7dc34c6cf79
trace_id       01a05de3-9934-7c29-a3bf-28495bb86b1c
imei           867717034662222
batch_index    0
device_time    2026-09-01 20:22:58
gateway_time   2026-09-01 20:22:48
payload_hash   525ab3925ec70bebd113f629b4fd3debe98af3821e023c38c037e1f24a4918dc
status         PERSISTED
gps_data.id    18434599
coordinate     [35.937878, 50.065395]
```

Realtime broadcast در این Host یک مشکل مستقل و قدیمی دارد: بعد از Deploy `broadcast_errors_total=310` و `broadcast_sent_total=0` بود، درحالی‌که side-effect تعداد 310 ارسال شد. این خطا باعث حذف Historical GPS نشد و در این فاز Reverb/Android تغییر داده نشد.

## شمارش سه IMEI

Mapping فعلی: `868064071065855→32`، `867994064030931→33`، `867717034662222→34`. `DB 24h` در `2026-09-01 20:30:50 +0330` read-only خوانده شد. `N/A` یعنی آن Stage ابزار Event-level قابل شمارش ندارد؛ صفر فقط وقتی به‌طور مستقیم مشاهده شده استفاده شده است.

### `868064071065855`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A | N/A | N/A | N/A | N/A |
| PiStat durable Ingress، post-deploy | 0 | 0 | 0 | 0 | N/A |
| Queue/Kafka Accepted | N/A | N/A | N/A | N/A | N/A |
| Consumer Processed | 0 | 0 | 0 | 0 | N/A |
| Database GPS rows، 24h | 2236 | N/A؛ `gps_data` event_id ندارد | N/A | N/A | N/A |
| Retry/DLQ، post-deploy | 0 | 0 | 0 | 0 | N/A |
| Historical service، window comparable | 2201 | N/A | exact replayها collapse شدند | 0 | 0 logical |

### `867994064030931`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A | N/A | N/A | N/A | N/A |
| PiStat durable Ingress، post-deploy | 0 | 0 | 0 | 0 | N/A |
| Queue/Kafka Accepted | N/A | N/A | N/A | N/A | N/A |
| Consumer Processed | 0 | 0 | 0 | 0 | N/A |
| Database GPS rows، 24h | 1920 | N/A؛ `gps_data` event_id ندارد | N/A | N/A | N/A |
| Retry/DLQ، post-deploy | 0 | 0 | 0 | 0 | N/A |
| Historical service، window comparable | 1881 | N/A | exact replayها collapse شدند | 0 | 0 logical |

### `867717034662222`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A | N/A | N/A | N/A | N/A |
| PiStat durable Ingress، post-deploy | 310 | 310 | 0 | 0 | 0 نسبت به Eventهای Ledger |
| Queue/Kafka Accepted | N/A؛ metric جداگانهٔ Event-level موجود نیست | N/A | N/A | N/A | N/A |
| Consumer Processed، inferred from PERSISTED | 310 | 310 | 0 | 0 | 0 |
| Database GPS rows، 24h | 407 | N/A؛ `gps_data` event_id ندارد | N/A | N/A | N/A |
| Retry/DLQ، post-deploy | 0 | 0 | 0 | 0 | 0 |
| Historical service، window comparable | 402 | N/A | exact replayها collapse شدند | 0 | 0 logical |

در DB 24h به‌ترتیب `2200/1881/403` Timestamp یکتا و `2236/1920/407` Raw row وجود داشت. اختلاف Raw row و Timestamp یکتا به‌ترتیب `36/39/4` است و به‌تنهایی Duplicate Event اثبات نمی‌کند. Historical service در پنجرهٔ مقایسه همهٔ logical pointها را نگه داشت: `2201/1881/402`. بررسی `ORDER BY date_time ASC, id ASC` برای هر سه IMEI zero violation داشت.

## Parser و تست Batch

تست‌های isolated موفق:

- Single Hooshnics event.
- Batch چند Item با Objectهای glued بدون comma.
- نویز ابتدا/انتها، CRLF، NUL و `\:`.
- Item خراب وسط Batch و ادامهٔ Itemهای سالم.
- Batch چند IMEI و mapping مستقل.
- Batch حداقل 1000 رکورد.
- Timestamp قدیمی، out-of-order و برابر با tie-breaker پایدار.
- NMEA: `3556.42915 → 35.940486` و `05003.5422 → 50.059037`.
- Unknown/Unbound quarantine با Raw.
- Ledger spool در DB unavailable و recovery از Pending.
- Fixture واقعی موجود: `6271 = 6271 valid + 0 invalid`.

خروجی تست‌ها:

```text
go test ./...                                      PASS
go vet ./...                                       PASS
go test -race ./internal/validate ./internal/ledger \
  ./internal/http ./internal/pipeline ./internal/storage PASS
php artisan test --filter=...                      16 passed, 111 assertions
PHP lint فایل‌های تغییرکرده                         PASS
```

تست‌های واقعیِ انجام‌نشده: Restart ایزولهٔ Consumer با Queue واقعی، DLQ Replay واقعی، Read Replica lag simulation، Database failure در Worker زنده، Teltonika live fixture و HTTP response احراز‌شده برای هر سه Tractor. هیچ‌کدام با Production POST یا دادهٔ مصنوعی جایگزین نشدند.

## Historical API و Map Contract

- Route `GET /api/tractors/{tractor}/path` حفظ شد.
- Query دیگر به `created_at`/`id` صرف یا movement heuristic متکی نیست؛ ترتیب device time صعودی و tie-breaker پایدار است.
- `LIMIT`/pagination جدیدی که بخشی از مسیر را حذف کند اضافه نشد.
- Response shape و کلیدهای فعلی حفظ شد؛ مختصات همچنان `[latitude, longitude]`/`latitude,longitude` است و به GeoJSON، WKT یا `longitude,latitude` تبدیل نشد.
- Direct in-process Historical service read-only بعد از اصلاح `2201/1881/402` نقطه برگرداند و ترتیب zero violation داشت.
- Public HTTP probe احراز‌شدهٔ کامل انجام نشد؛ probe بدون Auth به خطای موجود Laravel `Route [login]` رسید. این خطای middleware گزارش شد و برای پنهان‌کردن آن هیچ تغییر Android/Backend قرارداد انجام نشد.

بنابراین Backend/API اکنون منطق lossless و ordering صحیح دارد، ولی «Map layer فقط Android است» هنوز اثبات نشده؛ Realtime broadcast نیز خطای مستقل دارد.

## فایل‌های تغییرکرده و سازگاری

Backend Source و تست‌های تغییرکرده شامل این‌هاست:

```text
app/Console/Commands/GpsIngestHealthCommand.php
app/Http/Controllers/Api/V1/Tractor/GpsReportController.php
app/Http/Requests/GpsReportRequest.php
app/Jobs/IngestGpsData.php
app/Services/GpsIngressLedger.php
app/Services/GpsIngressPayloadDecoder.php
app/Services/TractorPathStreamService.php
services/gps-ingest/cmd/server/main.go
services/gps-ingest/internal/config/config.go
services/gps-ingest/internal/http/handler.go
services/gps-ingest/internal/ledger/ledger.go
services/gps-ingest/internal/metrics/metrics.go
services/gps-ingest/internal/pipeline/pipeline.go
services/gps-ingest/internal/storage/writer.go
services/gps-ingest/internal/validate/ingress.go
tests و migrationهای متناظرِ isolated بالا
```

```text
Android Update Required: NO
Existing Routes Preserved: YES
Existing JSON Contract Preserved: YES
Gateway Modified: NO
PiStat Backend Only: YES
Migration on gps_ingest_events: YES (additive)
Migration on gps_data: NO
```

Zero Log Loss برای مسیر **فعال Go پس از Deploy** با Ledger و مشاهدهٔ طبیعی `815 = 311 PERSISTED + 504 QUARANTINED_WITH_RAW` و صفر Retry/DLQ/Drop در پنجرهٔ مشاهده اثبات عملیاتی شد. برای ۲۴ ساعت قبل از Deploy یا Gateway مستقل، چون EventID مشترک و دسترسی Gateway وجود ندارد، ادعای کامل end-to-end ممکن نیست.

## مواردی که هنوز قابل اثبات نیستند

- آیا Gateway برای Hooshnics و PiStat Payload متفاوت ارسال کرده است: **قابل اثبات نیست**؛ Capture موجود هیچ Target IMEI ندارد و Gateway خارج Scope است.
- Count واقعی Gateway Delivery و Queue قبول‌شدهٔ Event-level در ۲۴ ساعت قبل از Deploy: **N/A**.
- Replay واقعی DLQ، Restart reclaim و Replica lag simulation: **انجام نشده**.
- ارسال موفق Reverb: **خیر، خطای مستقل موجود است**؛ این فاز آن را تغییر نداد.
- اینکه باقی‌ماندهٔ نمایش مسیر صرفاً Android Renderer است: **هنوز ثابت نشده**.

---

## یادداشت مقدماتی پیش از دسترسی عملیاتی

متن زیر برای حفظ Audit trail اولیه باقی مانده است؛ اعداد و وضعیت Deploy آن دیگر معتبر نیستند و نتیجهٔ نهاییِ بالا بر آن مقدم است.

**UTC report time:** 2026-09-01T15:17:39Z  
**PiStat scope:** `/home/api/domains/api.pistatapp.ir/public_html`  
**وضعیت این بخش:** پیش‌نویسِ قبل از دسترسی عملیاتی؛ برای Audit trail نگه داشته شده و بخش نهایی ابتدای همین فایل مرجع است.

## نتیجهٔ اجرایی

مشکل در کد فعلی PiStat اثبات شد، اما اصلاحات این نشست Deploy نشده‌اند و Runtime زندهٔ Production از این محیط قابل‌دسترسی نیست. بنابراین صحت ۲۴ ساعت اخیر، Queue، Consumer، Database و Historical API قابل شمارش end-to-end نیست.

یافته‌های قطعی کد و Snapshot موجود:

1. مسیر فعال طبق `.env`، Laravel مستقیم → Redis Queue → `IngestGpsData` → `mysql_gps.gps_data` است؛ Driver روی `laravel` است، نه Go.
2. Payload Legacy به‌شکل `[{"data":"..."}{"data":"..."}]` در مسیر قبلی قبل از Job پشتیبانی کامل نمی‌شد؛ `GpsReportRequest` می‌توانست کل Batch را با 422 رد کند و `ParseDataService` در Route فعال استفاده نمی‌شد.
3. Job قبلی IMEI رکورد اول را برای کل Batch استفاده می‌کرد؛ Batch مخلوط می‌توانست به Tractor اشتباه نسبت داده شود.
4. IMEI ناشناخته/Unbound و Row دارای خطای نهایی Database با `return`/Log کنار گذاشته می‌شد؛ در نتیجه ACK صف می‌توانست بدون وضعیت Durable انجام شود.
5. Timestamp قدیمی، آینده‌دار یا خراب با زمان Server جایگزین می‌شد و Timestampهای برابر با Spatial Heuristic به ثانیه‌های جدید تغییر می‌کردند؛ این خلاف حفظ Offline/Out-of-order history بود.
6. Historical query فقط `ORDER BY date_time` داشت و برای Timestamp برابر tie-breaker نداشت.
7. Snapshot Database برای هر سه IMEI هدف دادهٔ تاریخی دارد، اما در فایل Device Summary هر سه **unbound** هستند؛ این با حذف/عدم دسترسی مسیرهای جدید توسط mapping فعلی سازگار است.

**Gateway برای این سه IMEI و ۲۴ ساعت اخیر قابل مشاهده نیست.** فایل Raw موجود قدیمی است، فقط IMEI `861292053604220` را دارد و هیچ‌یک از سه IMEI هدف را ندارد؛ بنابراین «تفاوت Payload Gateway به Hooshnics و PiStat» با شواهد فعلی قابل اثبات نیست.

## مسیر واقعی مبنا

اطلاعات Runtime از `.env` و Route واقعی استخراج شد، نه فقط مستندات:

```text
POST /api/gps/reports
  → gps.ingest middleware / IP allowlist
  → GpsReportRequest
  → GpsReportController
  → IngestGpsData روی queue=redis, name=gps-processing
  → mysql_gps.gps_data
  → BroadcastGpsEvents / Redis latest-state / Reverb side effects

GET /api/tractors/{tractor}/path
  → ActiveTractorController::getPath
  → TractorPathStreamService
  → mysql_gps_read.gps_data
  → JSON response با latitude,longitude
```

خروجی `php artisan route:list --path=api/gps` شامل Route فعال زیر بود:

```text
POST api/gps/reports  gps.reports  Api\V1\Tractor\GpsReportController
```

Driver فعلی `laravel` است. Go در Source و Supervisor به‌عنوان مسیر standby وجود دارد، اما فعال بودن آن در Runtime فعلی اثبات نشد. همچنین Snapshot زیرساخت قبلی اختلاف `8081` در nginx cutover snippet و `8082` در Go Runtime را ثبت کرده است؛ این مسیر در این فاز تغییر داده نشد.

## شواهد Runtime و محدودیت دسترسی

اجرای read-only در 2026-09-01:

```text
GPS_INGEST_DRIVER=laravel
QUEUE_CONNECTION=redis
mysql_gps=127.0.0.1:3306/api_db
mysql_gps_read=همان Database در Fallback فعلی
```

`php artisan gps:ingest-health --fast --json`:

```json
{
  "redis": "FAILED: [tcp://127.0.0.1:6379]",
  "mysql_gps": "FAILED: SQLSTATE[HY000] [2002] Unknown error while connecting",
  "clock_resync_code": true,
  "gps_ingest_driver": "laravel",
  "ok": false
}
```

`php artisan gps:diagnose-persist --hours=24` نیز Redis، MySQL، Tractor sample، failed_jobs و processlist را از همین نشست در دسترس نداشت. در Log Snapshot قبلی نشانه‌های Redis `LOADING`، خطاهای Telescope/Reverb، خطاهای Partition و نبودن Event-level audit دیده شد؛ برای سه IMEI هدف هیچ Trace در `storage/logs` پیدا نشد.

این محیط اجازهٔ اتصال به Process namespace/Service Manager را نیز نداد؛ بنابراین Supervisor، Redis، MySQL، Read Replica و HTTP API فعلی قابل تأیید زنده نیستند. Snapshot مستند 2026-08-05 فقط به‌عنوان شواهد تاریخی استفاده شد، نه وضعیت امروز.

## Forensics Database و Device Mapping

Snapshot `docs/audit` در 2026-08-21 تولید شده است:

- Database: `api_db`, MariaDB `10.6.25`.
- `gps_data`: `18,149,805` Row.
- ستون کلیدی زنده: `tractor_id`؛ ستون `gps_device_id` وجود ندارد.
- Unique index روی `(imei,date_time)` در Snapshot وجود ندارد.
- Read و Write Database جداگانهٔ اثبات‌شده‌ای وجود ندارد؛ Fallback هر دو به `api_db` است.
- Schema تاریخی `gps_data` فاقد `event_id` و `batch_index` بود.
- حدود عمدهٔ داده در `p_future` قرار دارد و Partition maintenance در Snapshot فعال نبود؛ هیچ Partition command اجرا نشد.

سه IMEI هدف در `docs/audit/gps_points_summary.csv`:

| IMEI | Historical GPS rows | First point | Last point | Device ID | Tractor binding در Snapshot |
|---|---:|---|---|---:|---|
| `868064071065855` | 59,765 | 2026-06-10 09:52:15 | 2026-07-18 06:50:48 | 31 | unbound |
| `867994064030931` | 494,828 | 2025-10-23 00:29:52 | 2026-07-19 06:16:17 | 8 | unbound |
| `867717034662222` | 470,404 | 2026-06-04 12:54:43 | 2026-07-19 06:15:20 | 24 | unbound |

این شمارش‌ها **Snapshot تاریخی** هستند، نه شمارش ۲۴ ساعت اخیر. `tractor_id` خالی در Device Summary به معنی آن است که mapping فعلی این Deviceها را به Tractor قابل‌دسترسی برای Ingest/Path نمی‌کند؛ هیچ Device binding در Production اصلاح نشد.

## Gateway/PiStat Payload Comparison

پاسخ صریح: **قابل اثبات نیست**؛ Gateway مستقل است و مطابق محدودیت کار به آن SSH/Network/Source دسترسی گرفته نشد.

فایل `Wifi Gps Logs.txt` یک Capture قدیمی با مقصد `api.hooshnics.com` است:

- 390 خط `POST /api/gps/reports` و 391 خط Array-like body دارد.
- Payload شامل Objectهای `{ "data": "+Hooshnic:V1.07,..." }`، نویز Modem، CRLF، Object خالی و ترتیب Blockهای غیرصعودی است.
- Parser جدید روی کل Capture، `6,271` Item را استخراج کرد: `6,271` معتبر، `0` Invalid، یک IMEI (`861292053604220`).
- بازهٔ Timestamp خام Capture: `260703125715` تا `260704082746`.
- سه IMEI هدف در این Capture وجود ندارند؛ Teltonika نیز در Capture/Log Snapshot مربوط به این فاز نمونه ندارد.

بنابراین نه می‌توان گفت Gateway برای Hooshnics و PiStat Payload متفاوت فرستاده، نه می‌توان Count Gateway Delivery سه IMEI هدف را از این فایل نتیجه گرفت.

## اولین نقطهٔ تفاوت/افت

برای Event هدف در ۲۴ ساعت اخیر، به‌دلیل نبود Runtime evidence، اولین Stage واقعی قابل اعلام نیست. اما دو نقطهٔ افت Source-level قطعی هستند:

| ورودی | اولین نقطهٔ افت در کد قبلی | اثر |
|---|---|---|
| Legacy raw batch | `GpsReportRequest` قبل از Controller | کل Batch ممکن بود 422 شود؛ Parser Legacy فعال و مقاوم نبود. |
| Normalized batch با IMEI Unbound | `IngestGpsData::resolveTractor` قبل از Persistence | کل Itemهای آن IMEI Log/return می‌شدند و Raw/status Durable نداشتند. |
| Database bulk/row failure | `insertRowsIndividually` | Row نهایی Log و break می‌شد؛ Job می‌توانست با Count ناقص ACK شود. |
| History با Timestamp برابر | `TractorPathStreamService` | ترتیب به Database execution order وابسته بود و `id` tie-breaker نداشت. |

## Stage Counts برای سه IMEI هدف

به‌علت نبود Redis/MySQL/API زنده و نبودن Event ID در Schema Snapshot، Countهای قابل محاسبه فقط Rowهای تاریخی Database هستند. `N/A` به معنی «قابل شمارش نبود»، نه صفر.

### `868064071065855`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A؛ در Capture موجود نیست | N/A | N/A | N/A | N/A |
| PiStat Ingress | N/A؛ Trace/Log زنده موجود نیست | N/A | N/A | N/A | N/A |
| Queue/Kafka Accepted | N/A؛ Redis در دسترس نیست | N/A | N/A | N/A | N/A |
| Consumer Processed | N/A؛ Worker در دسترس نیست | N/A | N/A | N/A | N/A |
| Database Persisted | **59,765**؛ Snapshot 2026-08-21 | N/A؛ ستون ندارد | N/A | N/A | N/A نسبت به Ingress |
| Retry/DLQ | N/A؛ Redis/failed_jobs در دسترس نیست | N/A | N/A | N/A | N/A |
| Historical API | N/A؛ DB/API زنده در دسترس نیست | N/A | N/A | N/A | N/A |

### `867994064030931`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A؛ در Capture موجود نیست | N/A | N/A | N/A | N/A |
| PiStat Ingress | N/A؛ Trace/Log زنده موجود نیست | N/A | N/A | N/A | N/A |
| Queue/Kafka Accepted | N/A؛ Redis در دسترس نیست | N/A | N/A | N/A | N/A |
| Consumer Processed | N/A؛ Worker در دسترس نیست | N/A | N/A | N/A | N/A |
| Database Persisted | **494,828**؛ Snapshot 2026-08-21 | N/A؛ ستون ندارد | N/A | N/A | N/A نسبت به Ingress |
| Retry/DLQ | N/A؛ Redis/failed_jobs در دسترس نیست | N/A | N/A | N/A | N/A |
| Historical API | N/A؛ DB/API زنده در دسترس نیست | N/A | N/A | N/A | N/A |

### `867717034662222`

| Stage | Count | Unique EventID | Duplicate | Failed | Missing |
|---|---:|---:|---:|---:|---:|
| Gateway Delivery Evidence | N/A؛ در Capture موجود نیست | N/A | N/A | N/A | N/A |
| PiStat Ingress | N/A؛ Trace/Log زنده موجود نیست | N/A | N/A | N/A | N/A |
| Queue/Kafka Accepted | N/A؛ Redis در دسترس نیست | N/A | N/A | N/A | N/A |
| Consumer Processed | N/A؛ Worker در دسترس نیست | N/A | N/A | N/A | N/A |
| Database Persisted | **470,404**؛ Snapshot 2026-08-21 | N/A؛ ستون ندارد | N/A | N/A | N/A نسبت به Ingress |
| Retry/DLQ | N/A؛ Redis/failed_jobs در دسترس نیست | N/A | N/A | N/A | N/A |
| Historical API | N/A؛ DB/API زنده در دسترس نیست | N/A | N/A | N/A | N/A |

نتیجهٔ مهم: از این اعداد نمی‌توان Loss بین Gateway و PiStat را محاسبه کرد، چون Gateway Delivery، Ingress، Queue و API Count هم‌زمان و با EventID مشترک در دسترس نیستند.

## Batch، Parser و Coordinate

در کد قبلی:

- ورودی فعال بیشتر برای Object نرمال‌شده با `data` Array طراحی شده بود.
- `ParseDataService` Regex سخت‌گیر داشت، `}{` را فقط با replace ساده اصلاح می‌کرد و در برابر نویز/رکورد خراب/ادامهٔ Batch مقاوم نبود؛ ضمن اینکه در Route فعال استفاده نمی‌شد.
- مختصات خروجی Path قبلاً به‌صورت `[latitude, longitude]` parse و به JSON با کلیدهای `latitude` و `longitude` تبدیل می‌شد؛ WKT/GeoJSON در این Endpoint استفاده نمی‌شد.
- NMEA در Parser قدیمی از نظر فرمول پایه درست بود، اما مسیر فعال آن را تضمین نمی‌کرد.

در اصلاح جدید:

- `GpsIngressPayloadDecoder` یک نتیجه برای هر Item می‌دهد و `raw_payload`، `batch_index` و `error_reason` را حفظ می‌کند.
- نویز ابتدا/انتها، CRLF، NUL، نبودن comma بین Objectها، `\:`، Object خراب وسط Batch و Object ناقص انتهایی پوشش داده شده‌اند.
- یک Item خراب کل Batch را متوقف نمی‌کند.
- تست نمونهٔ مورد درخواست موفق است:

```text
3556.42915 → 35.940486
05003.5422 → 50.059037
```

برای Batchهای ایزوله:

```text
Extracted 6271 = Valid 6271 + Invalid 0 + Retry/DLQ 0
Extracted 1000 = Valid 1000 + Invalid 0 + Retry/DLQ 0
```

برای Payload نرمالِ mixed، Itemها جداگانه Validate می‌شوند؛ Invalid با Raw در وضعیت `QUARANTINED_WITH_RAW` و Validها برای Queue باقی می‌مانند.

## Timestamp، Ordering و Map API

کد قبلی Timestampهای قدیمی/آینده را به Server `now` تغییر می‌داد و Collisionهای هم‌ثانیه را با Timestamp مصنوعی حل می‌کرد. این می‌توانست Offline history را از روز واقعی خارج کند یا مسیر را جهشی کند.

کد جدید:

- `device_recorded_at`/`date_time` را Parse می‌کند، اما Timestamp معتبر قدیمی یا آینده را Replace نمی‌کند.
- Timestamp برابر را تغییر نمی‌دهد.
- در صورت نصب Migration افزایشی، `batch_index` و `event_id` را برای tie-breaker ذخیره می‌کند.
- در Schema قدیمی، Query به‌صورت امن به `ORDER BY date_time ASC, id ASC` برمی‌گردد؛ پس ترتیب حداقل deterministic است، ولی برای tie-breaker کامل باید Migration افزایشی نصب شود.
- Historical API Route، Method، URL، Auth و JSON keys فعلی را تغییر نداده است.
- قرارداد فعلی مختصات حفظ شده: `latitude,longitude`؛ پاسخ به WKT، GeoJSON یا `longitude,latitude` تبدیل نشده است.

با این حال، چون DB/API زنده قابل خواندن نبود، نمی‌توان کامل/مرتب بودن خروجی سه IMEI یا رفع Map Jump در Production را تأیید کرد. وضعیت «Android-only» نیز با شواهد فعلی ثابت نشده است؛ Backend قبلی خود علت‌های معتبر برای افت/ترتیب نادرست داشت.

## Queue، Persistence و Zero Log Loss

کد قبلی:

- ACK Controller بعد از Queue dispatch انجام می‌شد، ولی Ledger Event نداشت.
- `insertOrIgnore` و partial row recovery می‌توانست علت حذف را از مسیر Durable خارج کند.
- IMEI Unbound با return عادی حذف می‌شد.
- `created_at`/insert order معیار مستقل و قابل ردیابی برای Device Event نبود.

کد جدید:

- قبل از Queue، هر Valid Event با `event_id` (یا Hash پایدار)، `trace_id`، IMEI، Payload Hash، Timestamp دستگاه، Timestamp دریافت، Raw و Batch Index در `gps_ingest_events` با وضعیت `RETRY_PENDING` ثبت می‌شود.
- Invalid با وضعیت `QUARANTINED_WITH_RAW` ثبت می‌شود.
- Database failure دیگر Row را Log و Drop نمی‌کند؛ پس از Retry ردیفی، Exception به Job برمی‌گردد تا Queue آن را دوباره Retry کند و در شکست نهایی `DLQ_REPLAYABLE` شود.
- IMEIهای مختلف یک Batch جداگانه Group و Map می‌شوند؛ رکوردی با IMEI اول به Tractor رکورد دوم نسبت داده نمی‌شود.
- `insertOrIgnore` حذف شد. Exact replay duplicate با Raw/Hash قابل ردیابی است و Payload متفاوت با Timestamp برابر Overwrite نمی‌شود.
- `failed()` وضعیت Eventهای Job را به `DLQ_REPLAYABLE` می‌برد.
- اگر Migration هنوز نصب نشده باشد، Ledger به‌صورت append-only در `storage/app/gps-ingest-ledger.ndjson` spool می‌کند؛ اگر Database و این Spool هر دو fail شوند، Request ACK نمی‌شود.

**نتیجهٔ Zero Log Loss:** Semantics در Source اصلاح شده و برای مسیر Laravel طراحی شده است، اما به‌دلیل عدم Deploy/عدم دسترسی Redis و MySQL، Zero Log Loss عملیاتی Production در این نشست تأیید نشده است.

## اصلاحات انجام‌شده

فایل‌های Backend/تست تغییرکرده:

- `app/Services/GpsIngressPayloadDecoder.php` — Parser مستقل و Item-level recovery.
- `app/Services/GpsIngressLedger.php` — Durable Event ledger با fallback spool.
- `app/Http/Requests/GpsReportRequest.php` — Decode اولیه بدون تغییر Field فعلی `data`.
- `app/Http/Controllers/Api/V1/Tractor/GpsReportController.php` — Item validation، Quarantine، Event metadata و Queue handoff.
- `app/Jobs/IngestGpsData.php` — per-IMEI grouping، حفظ Timestamp، عدم Drop بی‌صدا، retry/DLQ state.
- `app/Services/TractorPathStreamService.php` — tie-breaker با `id` و پشتیبانی اختیاری `batch_index`.
- `app/Console/Commands/GpsIngestHealthCommand.php` — Health gate مطابق Timestamp preservation.
- `tests/Feature/GpsReportsEndpointTest.php` — Legacy glued/noise mixed batch.
- `tests/Unit/Services/GpsIngressPayloadDecoderTest.php` — NMEA، نویز، Escape، Item خراب، Batch هزاررکوردی و Raw capture واقعی.
- `tests/Unit/Jobs/GpsIngestPolicyTest.php` — حفظ Timestamp و Dedup policy.
- `tests/Unit/Jobs/IngestGpsDataTest.php` — رفتار جدید per-IMEI و Timestamp collision.

Migrationهای افزایشی فقط به Source اضافه شدند و **اجرا نشدند**:

- `database/migrations/2026_09_01_000001_create_gps_ingest_events_table.php`
- `database/migrations/2026_09_01_000002_add_ingest_order_metadata_to_gps_data_table.php`

Migration اول Ledger را جدا از جدول بزرگ `gps_data` ایجاد می‌کند. Migration دوم فقط Columnهای Nullable برای `event_id` و `batch_index` اضافه می‌کند و هیچ Historical Row را Update/Delete نمی‌کند. به‌دلیل نبود Runtime و اندازهٔ Production `gps_data`، هیچ Migration، Schema change یا Index operation اجرا نشد.

## Compatibility

```text
Android Update Required: NO
Existing Routes Preserved: YES
Existing JSON Contract Preserved: YES
Gateway Modified: NO
PiStat Backend Only: YES
```

جزئیات:

- Route فعلی `POST /api/gps/reports` تغییر نکرد.
- Route فعلی `GET /api/tractors/{tractor}/path` تغییر نکرد.
- HTTP Method، URL، Auth/IP allowlist، Fieldهای فعلی Request و کلیدهای Response تغییر نکردند.
- فرمت مختصات فعلی برای OSMDroid حفظ شد.
- هیچ فایل Kotlin/Android یا Local Backend تغییر نکرد.
- هیچ Nginx، Go، Gateway، Redis، MySQL، Kafka/Redpanda یا دادهٔ Production تغییر نکرد.

## تست‌های انجام‌شده

### موفق

```text
php artisan test --filter='GpsIngressPayloadDecoderTest|GpsIngestPolicyTest|GpsReportsEndpointTest|GpsIngestDelegationTest'
16 passed, 111 assertions
```

موارد موفق شامل Single Event، normalized multi-item، Legacy glued objects، نویز، CRLF/NUL، `\:`، رکورد خراب وسط Batch، Batch هزاررکوردی، NMEA، mixed endpoint و قرارداد Go delegation test است.

تست واقعی روی `Wifi Gps Logs.txt` نیز موفق شد: `6271/6271` Item استخراج و معتبر، بدون Target IMEI.

تست‌های مربوط به Path/Read-Write در محیط محلی به‌دلیل نبود MySQL Skip شدند؛ تست resolve روز Tehran و تست‌های Config موفق بودند. `go test ./...` به‌علت Snap confinement و read-only بودن symlink محیط اصلاً شروع نشد؛ Go Source تغییر نکرد.

Full test suite به‌دلیل Failureهای قدیمی/محیطی خارج از این تغییرات و در انتها PHP memory limit `128MB` به‌طور کامل سبز نشد؛ این گزارش آن را موفق اعلام نمی‌کند.

### انجام‌نشده به‌علت نبود Runtime Evidence

- Count واقعی ۲۴ ساعت UTC و Asia/Tehran برای Gateway، Ingress، Queue، Consumer، Retry/DLQ و API.
- Database Failure، Consumer Restart، Redis Queue retry، DLQ replay واقعی.
- Read Replica lag simulation.
- Historical API response count/order/coordinate برای سه IMEI.
- Teltonika live comparison.
- Health/Ready، Queue Lag، Consumer status، Insert rate، CPU/RAM و API probe واقعی Production.

هیچ‌کدام با POST آزمایشی یا دادهٔ مصنوعی جایگزین نشدند.

## پاسخ صریح به خروجی درخواستی

| سؤال | پاسخ |
|---|---|
| آیا Gateway برای Hooshnics و PiStat Payload متفاوت می‌فرستد؟ | اثبات نشد؛ Gateway در Scope نیست و Capture موجود Target IMEI ندارد. |
| اولین نقطهٔ تفاوت کجاست؟ | برای Legacy raw، قبل از Controller در FormRequest؛ برای Normalized Unbound، Consumer mapping قبل از DB. اولین نقطهٔ واقعی Target در ۲۴ ساعت اخیر قابل شمارش نیست. |
| آیا Group log ناقص دریافت/پردازش می‌شود؟ | Source قبلی برای glued/noisy/raw batch ناقص بود؛ Parser جدید در Fixture کامل است؛ Runtime Production تأیید نشده. |
| آیا Event در Queue/Database/API کم می‌شود؟ | Historical DB rows موجودند، اما Stage Count مشترک و EventID زنجیره‌ای در دسترس نیست؛ افت ۲۴ساعته قابل اثبات نیست. Source قبلی Drop silent داشت و اصلاح شد. |
| آیا Timestamp/Coordinate باعث پرش می‌شود؟ | Timestamp rewrite و عدم tie-breaker علت‌های معتبر Backend هستند؛ NMEA و `[lat,lon]` در اصلاح جدید صحیح و تست‌شده‌اند. API زنده بررسی نشد. |
| برای سه IMEI چند Event در هر Stage وجود دارد؟ | فقط Historical DB Snapshot: به‌ترتیب `59,765`، `494,828` و `470,404`; بقیهٔ Stageها `N/A` به‌علت نبود Runtime. |
| چه فایل‌هایی تغییر کردند؟ | در بخش «اصلاحات انجام‌شده» فهرست شده‌اند. |
| چه تست‌هایی موفق شدند؟ | 16 تست/111 assertion، Parser واقعی 6271/6271، NMEA، mixed batch و قرارداد Route. |
| آیا PiStat اکنون Zero Log Loss داخلی دارد؟ | Source اصلاح‌شده semantics لازم را دارد؛ Production عملیاتی به‌دلیل عدم Deploy/عدم دسترسی تأیید نشده. |
| آیا Historical GPS API مرتب و کامل است؟ | Query ordering اصلاح شده و قرارداد حفظ شده؛ کامل/مرتب بودن Live API برای سه IMEI قابل تأیید نیست. |
| آیا مشکل باقی‌مانده Android است؟ | ثابت نشده؛ Backend قبلی علت‌های قطعی دارد و Android بررسی/تغییر داده نشد. |

## Deploy و وضعیت نهایی

این نتیجه‌گیری مربوط به مرحلهٔ قبل از دسترسی عملیاتی بود. Deploy واقعی، Migration مستقل Ledger، Health check و مشاهدهٔ طبیعی پس از Deploy در بخش نهایی ابتدای فایل ثبت شده است.

## وضعیت نهایی قابل اعلام

```text
BLOCKED_WITH_EXACT_REASON
```

`gps_broadcast_outbox` روی Production Migration شده و Source/Tests آماده‌اند، اما فعال‌سازی باینری جدید به مجوز Interactive برای `systemctl restart gps-ingest.service` نیاز داشت. باینری فعال همان نسخهٔ قبلی است؛ هیچ ادعای Deploy یا Runtime Verification برای Outbox جدید ارائه نمی‌شود.
