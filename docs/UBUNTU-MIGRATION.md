# انتقال کلینیک به Ubuntu 24.04

این شاخه شامل برنامه و ابزارهای نصب، خروجی و بازیابی است. وجود این شاخه به معنی دریافت نسخه واقعی سرور قبلی یا انتقال دیتابیس نیست. نسخه واقعی فقط پس از اجرای خروجی روی سرور قبلی، دریافت بسته و تأیید checksum قابل اعلام است.

## محتویات انتقال

| بخش | مقصد |
| --- | --- |
| کد قابل انتشار از نسخه واقعاً نصب‌شده و manifest فایل‌ها | شاخه جدا در GitHub، پس از بازبینی |
| تمام فایل‌های عادی برنامه، شامل تغییرات محلی و فایل‌های خارج از فهرست کد عمومی | `application-private.tar.gz` داخل بسته خصوصی |
| دیتابیس `masiha_clinic`، routine، trigger و event | `database.sql.gz` داخل بسته خصوصی |
| تنظیمات، کلید رمزگذاری، تنظیمات پیامک، مدارک و وضعیت Importer | `private.tar.gz` داخل بسته خصوصی |
| سرویس‌های کلینیک، تنظیمات Nginx/PHP/cron و نسخه بسته‌های سیستم | مرجع خصوصی برای بررسی روی مقصد |

مخزن فعلی عمومی است. بسته خصوصی، رمزها، رکوردها و مدارک بیماران را در GitHub عمومی قرار ندهید. هویت SentinelX، کلید SSH، کلید TLS و تنظیمات شبکه سیستم منتقل نمی‌شوند؛ روی مقصد مستقل نصب می‌شوند. محیط مجازی، Chromium و کش‌ها دوباره ساخته می‌شوند. فایل‌های لینک و socket قفل مرورگر در بسته قابل بازیابی قرار نمی‌گیرند؛ اگر برنامه از لینک برای فایل دائمی استفاده کرده است، ابتدا مقصد آن را بررسی و جداگانه منتقل کنید. بکاپ‌های تاریخی بیرون از پوشه برنامه و نرم‌افزارهای نامرتبط با کلینیک جزء این بسته نیستند.

## ۱. خروجی روی سرور قبلی

در کنسول/SSH سرور قبلی اجرا کنید؛ مسیر نصب فعلی تغییر نمی‌کند:

```bash
sudo -i
git clone --branch transfer/ubuntu-20261004 https://github.com/masiweb/masiha-clinic.git /root/masiha-transfer-tools
EXPORT_DIR="/root/clinic-transfer-$(date -u +%Y%m%dT%H%M%SZ)"
python3 /root/masiha-transfer-tools/deploy/export-server.py \
  --app-root /var/www/masiha-clinic --output "$EXPORT_DIR" --with-private
```

اگر Importer یا SMS worker در حال اجرا باشد، خروجی بدون توقف worker رد می‌شود؛ پس از پایان آن دوباره با مسیر خروجی جدید اجرا کنید. حین خروجی، timerهای فعال و PHP موقتاً متوقف می‌شوند تا نوشتن اطلاعات متوقف شود. در پایان، وضعیت اولیه برمی‌گردد. سایر کارهایی که مستقیماً دیتابیس یا فایل‌های کلینیک را می‌نویسند هم باید در این بازه متوقف باشند. تا پایان اجرای دستور کنسول را باز نگه دارید. اگر `EXPORT_FAILED` دیدید، خروجی را معتبر ندانید و وضعیت PHP/timerها را بررسی کنید.

خروجی موفق مسیر `PRIVATE_BUNDLE` و `PRIVATE_SHA256` را چاپ می‌کند. دو فایل زیر را با SCP/SFTP از سرور قبلی به مقصد منتقل کنید:

```text
clinic-transfer.tar.gz
clinic-transfer.tar.gz.sha256
```

مثال از کنسول مقصد، با جایگزین کردن `OLD_SERVER` و `EXPORT_DIRECTORY` با مقادیر واقعی:

```bash
sudo -i
mkdir -m 700 /root/clinic-transfer
scp root@OLD_SERVER:EXPORT_DIRECTORY/clinic-transfer.tar.gz /root/clinic-transfer/
scp root@OLD_SERVER:EXPORT_DIRECTORY/clinic-transfer.tar.gz.sha256 /root/clinic-transfer/
```

## ۲. انتشار نسخه واقعی کد در GitHub

پیش از انتشار، فایل `code-manifest.json` و کد داخل `deployed-code.tar.gz` را از نظر رمز، اطلاعات بیمار و تغییرات محلی بررسی کنید. اسکن خودکار چند الگوی شناخته‌شده رمز را رد می‌کند، اما جای بازبینی را نمی‌گیرد. کد عمومی شامل فایل‌های اجرایی، schemaهای شناخته‌شده، assets و راهنماها است؛ dump دیتابیس و تنظیمات خصوصی حذف می‌شوند.

پس از بازبینی، با GitHub CLI احرازشده برای همین حساب، روی سرور قبلی:

```bash
# gh باید نصب شده و gh auth status موفق باشد.
python3 /root/masiha-transfer-tools/deploy/export-server.py \
  --app-root /var/www/masiha-clinic --output "$EXPORT_DIR" \
  --publish-existing --publish masiweb/masiha-clinic \
  --base-ref transfer/ubuntu-20261004 --branch "server/$(basename "$EXPORT_DIR")"
```

رمز GitHub در کد یا خروجی چاپ نمی‌شود. یک شاخه جدید ساخته می‌شود و نسخه واقعی زیر `server-snapshots/<نام خروجی>/code` قرار می‌گیرد؛ `main` جایگزین نمی‌شود. اگر فایل‌ها از زمان خروجی عوض شده باشند، انتشار رد می‌شود. پوشه snapshot را مستقیم با `setup.sh` نصب نکنید؛ برای بازیابی کامل از بسته خصوصی و ابزار مرحله بعد استفاده کنید.

## ۳. نصب مقصد تازه

مقصد باید Ubuntu 24.04 تازه باشد. `NEW_SERVER_IP` را جایگزین کنید:

```bash
sudo -i
apt-get update
apt-get install -y git
git clone --branch transfer/ubuntu-20261004 https://github.com/masiweb/masiha-clinic.git /root/masiha-transfer-tools
cd /root/masiha-transfer-tools
bash setup.sh NEW_SERVER_IP --ip-only --migration
```

برای دامنه آماده می‌توان از `bash setup.sh clinic.example.com --skip-ssl --migration` استفاده کرد. نصب migration فقط روی مقصد بدون تنظیمات قبلی مجاز است. Importer خاموش می‌ماند و marker نصب تازه ساخته می‌شود. این مرحله ممکن است برای دانلود Chromium و وابستگی‌ها زمان ببرد. پیش از انتقال ترافیک، دسترسی مقصد به بُقراط را بررسی کنید:

```bash
bash deploy/check-boghrat-network.sh 1
```

جابه‌جایی سرور به‌تنهایی رفع مشکل شبکه را تضمین نمی‌کند؛ پاسخ HTTP/TLS سرویس‌های بُقراط و اتصال Agent مقصد باید روی خود مقصد بررسی شوند.

## ۴. تأیید و بازیابی

دو دستور زیر را از checkout ابزارها اجرا کنید؛ پس از بازیابی، کد داخل `/var/www/masiha-clinic` همان نسخه نصب‌شده قبلی خواهد بود:

```bash
cd /root/masiha-transfer-tools
python3 deploy/restore-server.py /root/clinic-transfer/clinic-transfer.tar.gz \
  --host NEW_SERVER_IP --verify-only
python3 deploy/restore-server.py /root/clinic-transfer/clinic-transfer.tar.gz \
  --host NEW_SERVER_IP
```

بازیابی checksum بیرونی و داخلی، دامنه مسیرهای آرشیو و تطابق کد را بررسی می‌کند. روی نصب موجود بدون marker اجرا نمی‌شود. دیتابیس موقتِ نصب تازه جایگزین می‌شود؛ تمام جدول‌ها و تعداد رکوردهای هر جدول باید دقیقاً مطابق manifest باشند. در خرابی import، اختلاف شمارش یا خرابی health، PHP خاموش می‌ماند تا مقصد ناقص سرویس ندهد. timerهای Importer/SMS فعال نمی‌شوند. جزئیات خصوصی خطای دیتابیس در خروجی عمومی چاپ نمی‌شوند؛ گزارش نهایی موفق در `/var/lib/masiha-migration-verified.json` قرار می‌گیرد.

اگر requirements نسخه نصب‌شده با checkout ابزارها فرق داشت، وابستگی‌های همان نسخه را دوباره نصب کنید:

```bash
bash /var/www/masiha-clinic/deploy/install-importer.sh --no-start
```

تنظیمات سرویس قدیمی در `service-reference.tar.gz` فقط مرجع است؛ تنظیمات دامنه/IP/TLS قدیمی روی مقصد بازنویسی نمی‌شود. تغییرات سفارشی واحدهای systemd و محیط runtime را پیش از فعال‌کردن workerها مقایسه کنید.

## ۵. انتقال نهایی ترافیک

با حساب‌های قبلی ورود، فایل خصوصی، پرونده، نوبت، حسابداری و تنظیمات Importer را بررسی کنید. برای تغییر آدرس به دامنه و TLS:

```bash
sudo bash /var/www/masiha-clinic/deploy/enable-domain.sh clinic.example.com
```

اگر پس از خروجی اولیه روی سرور قبلی اطلاعات جدید ثبت شده، پیش از انتقال نهایی آن را از دسترس نوشتن خارج کنید و خروجی تازه بگیرید. برای یک بازیابی آزمایشی موفق، مقصد از حالت «نصب تازه» خارج شده است؛ برای تمرین دوم/بسته نهایی، مقصد را دوباره از نصب تازه آماده کنید. marker را روی دیتابیس واقعی دستی نسازید.

پس از تأیید مقصد و خارج‌کردن سرور قبلی از سرویس، Importer را فقط روی مقصد فعال کنید:

```bash
sudo systemctl enable --now masiha-importer.timer
```

SMS فقط اگر قبلاً مجاز و فعال بوده است، بعد از بررسی صف و تنظیمات برنامه فعال شود. قبل از این مرحله، timerهای مشابه روی سرور قبلی خاموش شوند تا پیامک یا import دو بار اجرا نشود. Agent SentinelX مقصد باید هویت تازه داشته باشد؛ در طرح یک‌سروری، هم‌زمان تنها یک میزبان ظرفیت اجرای عملیات دارد.

## اعتبارسنجی ابزارها

`tests/server-transfer.py` با آرشیو و فایل‌های واقعیِ ساختگی و سرویس‌های شبیه‌سازی‌شده، خروجی/بازیابی موفق، worker فعال، race، خرابی dump، خرابی بازگشت PHP/timer، خرابی import، اختلاف شمارش، خرابی health و رد آرشیو خراب/مسیر مخرب/لینک را بررسی می‌کند. SQL تغییر رمز با MariaDB واقعی هم محلی آزمایش شده است. نصب کامل apt/Chromium و مهاجرت داده واقعی هنوز باید روی مقصد انجام و تأیید شود.
