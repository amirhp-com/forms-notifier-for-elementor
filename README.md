# Forms Notifier for Elementor

Send Elementor form submissions to Telegram, Bale, SMS, WhatsApp, email, webhooks and automation tools, with templates, logs and retries.

Free WordPress plugin by [Amirhossein Hosseinpour](https://amirhp.com/). Licence: GPLv2 or later. Not affiliated with or endorsed by Elementor or any service it connects to.

## What it does

- Notices every submission of Elementor Pro forms (classic and atomic) and Hello Plus forms, without extra setup.
- 19 channels: Telegram, Bale, SMS (28 gateways plus pass-through and custom HTTP), WhatsApp (10 gateways), email, webhooks (n8n, Make, Zapier, Pabbly Connect, IFTTT), Slack, Discord, Google Chat, Microsoft Teams, Mattermost, Rocket.Chat, ntfy, Pushover, Gotify, Eitaa, Rubika, Soroush Plus, Gap.
- Bots that find groups and topics, answer commands and carry action buttons; a Mini App inside Telegram and Bale.
- Templates per channel and form with tags, filters, conditions and texts per language (WPML, Polylang).
- Queue, retries, message log, backup channel, failure alerts, digest, quiet hours, rules.
- Saved submissions, CSV export, privacy tools, REST API, WP-CLI and hooks.

## Requirements

WordPress 6.5+, PHP 7.4+, Elementor. HTTPS for bot webhooks and the Mini App.

## Install

Download the zip of a [release](https://github.com/amirhp-com/forms-notifier-for-elementor/releases) and upload it under Plugins, Add New, or copy the folder `forms-notifier-for-elementor` into `wp-content/plugins`.

## Documentation

| Guide | |
|---|---|
| [User guide](docs/user-guide.md) | Forms, channels, templates, files, log, settings |
| [Bots](docs/bots.md) | Telegram and Bale: connect, chats, commands, buttons |
| [Mini App](docs/mini-app.md) | Set up the app in Telegram and Bale |
| [SMS pattern text](docs/sms-pattern-text.md) | Patterns and WhatsApp templates per gateway |
| [Webhooks](docs/webhooks.md) | Payload, signatures, n8n, Make, Zapier, Pabbly, IFTTT |
| [Cloudflare Worker](docs/cloudflare-worker.md) | Reach a blocked messenger through your own forwarder |
| [Multilingual sites](docs/multilingual.md) | WPML, Polylang, texts per language |
| [Features](docs/features.md) | Everything the plugin does |
| [Questions](docs/faq.md) | Troubleshooting |
| [Developer guide](docs/developer-guide.md) | Hooks, channels, gateways, REST, JavaScript, CLI, database |
| [Changelog](CHANGELOG.md) | |

## Development

```bash
bash tools/lint.sh             # php -l, coding standard, script checks
php tools/check-docs.php       # every hook, route and command of the code is in the developer guide
wp eval-file tests/run.php     # integration tests, on a site with the plugin active
php tests/unit.php             # tests without WordPress
bash tools/build-zip.sh        # dist/forms-notifier-for-elementor-<version>.zip
```

## فارسی

افزونه‌ی «Forms Notifier for Elementor» هر فرمی را که در المنتور ارسال می‌شود به تلگرام، بله، پیامک، واتس‌اپ، ایمیل، وب‌هوک و ابزارهای اتوماسیون می‌فرستد. پیام‌ها در پس‌زمینه ارسال می‌شوند، اگر سرویسی در دسترس نباشد دوباره تلاش می‌شود و پاسخ هر سرویس در گزارش ثبت می‌شود.

- سی‌ویک درگاه پیامک ایرانی و خارجی، با ارسال پترن، درگاه پشتیبان و درگاه جدا برای هر کشور
- ربات تلگرام و بله با شناسایی خودکار گروه‌ها و تاپیک‌ها، دستورها و دکمه‌های شیشه‌ای
- مینی‌اپ داخل تلگرام و بله، بدون بارگذاری اسکریپت از سرورهای دیگر
- قالب پیام برای هر کانال و هر فرم، با متن جدا برای هر زبان
- رابط کاربری کامل فارسی و راست‌چین با قلم دستخط

راهنمای فارسی: [راهنمای کاربر](docs/fa/user-guide.md) و [اتصال از راه ورکر کلادفلر](docs/fa/cloudflare-worker.md).

## Credits

Persian panel font: [Dastkhat](https://github.com/dastyar-io/dastkhat-font) 1.220 by Dastyar, based on Vazirmatn by Saber Rastikerdar, SIL Open Font License 1.1.
