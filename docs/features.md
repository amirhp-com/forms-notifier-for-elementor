# Features

The full list behind the short one in `readme.txt`.

## Forms

- Forms of Elementor Pro (classic and atomic) and Hello Plus. Forms are found on their own, also in popups, headers, footers, templates and global widgets, twice a day, when a document is saved and when you scan on the Forms screen.
- No form action is needed. An action "Forms Notifier" in the Elementor editor can change channels, receivers, template, buttons and the file rule per form.
- Saved submissions with search, statuses (new, read, done, spam, trash), bulk actions, CSV export, resend, and import of older Elementor submissions (nobody is notified, nothing is imported twice).
- Duplicate guard, sensitive fields that are never stored or sent, a field map for name, email and phone.

## Channels

- 19 channels: Telegram, Bale, SMS, WhatsApp, email, webhooks, Slack, Discord, Google Chat, Microsoft Teams, Mattermost, Rocket.Chat, ntfy, Pushover, Gotify, Eitaa, Rubika, Soroush Plus, Gap.
- Telegram and Bale bots find groups, topics and channels themselves, answer commands (/start, /id, /last, /stats, /forms, /help), and add buttons under a message to mark a submission as read, done or spam, or to retry a delivery. Webhook or polling; a custom API base for a forwarder such as a Cloudflare Worker, with a step-by-step guide and the code.
- A Mini App inside Telegram and Bale with forms, submissions, one submission and delivery problems, for the admins you choose. No remote script is loaded.
- 28 SMS gateways, Iranian and international: Kavenegar, IPPanel (also its legacy API), FarazSMS / IranPayamak, MeliPayamak, SMS.ir, Ghasedak, Mediana, Amoot SMS, LimoSMS, Asanak, Payam-resan, ParsGreen, RayganSMS, Twilio, Vonage, Plivo, Bird, MessageBird, ClickSend, Infobip, Telnyx, Brevo, SMS.to, 46elks, SMSAPI, MSG91, NetGSM. Plus pass-through to the WP SMS and Persian WooCommerce SMS plugins and a custom HTTP gateway.
- Gateway per country, backup gateways, plain, pattern (template) and hybrid text, balance per gateway, a choice whether a timeout is retried.
- 10 WhatsApp gateways: the Cloud API of Meta first, 360dialog, Twilio, UltraMsg, Green API, Wassenger, whapi.cloud, WAHA, Evolution API and a custom HTTP gateway.
- Webhooks with presets for n8n, Make, Zapier, Pabbly Connect and IFTTT, HMAC-signed requests and stable delivery ids.
- Email through the mail system of the site, HTML with a plain text part, files attached.

## Messages

- Templates per channel and per form with a global default, tags, filters and conditions, a text per language, live preview.
- Buttons ("glass buttons") under bot messages, global and per form.
- Rules: send some submissions to other people, or only to some channels. Send a copy to the person who filled in the form.
- Uploaded files are sent, linked through signed links that can expire, both, or left out, per channel and per form, with file types, size and count limits. A file too large for a channel becomes a link.
- Jalali dates and Persian digits for Persian messages.

## Delivery

- Queue on Action Scheduler or WP-Cron, or immediate sending, retries with backoff, a backup channel.
- Message log with the answer of the service, attempts, retry, filters, CSV export and retention.
- Failure alerts through a separate channel, rate limited; a daily or weekly digest; quiet hours.
- Test send per channel and gateway.

## Site and developers

- WPML and Polylang, `wpml-config.xml` for the editor action. A full Persian translation with the Dastkhat font for the Persian admin screens.
- Privacy tools of WordPress (export and erase), retention settings, encrypted tokens, tokens as constants in `wp-config.php`, a movable folder for stored files (`FNFE_FILES_DIR`).
- Site Health checks, import and export of settings as JSON, WP-CLI, REST API, and hooks for channels, gateways, tags, conditions and form sources. See the [developer guide](developer-guide.md).
