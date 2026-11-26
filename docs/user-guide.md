# User guide

Forms Notifier for Elementor sends the submissions of your Elementor forms to messengers, SMS, WhatsApp, email, chat tools and webhooks. Everything is under **Forms Notifier** in the admin menu.

- [Quick start](#quick-start)
- [Forms](#forms)
- [Channels and receivers](#channels-and-receivers)
- [Templates](#templates)
- [Settings of one form](#settings-of-one-form)
- [The action in the Elementor editor](#the-action-in-the-elementor-editor)
- [Uploaded files](#uploaded-files)
- [Submissions](#submissions)
- [Log, queue and retries](#log-queue-and-retries)
- [Alerts and digest](#alerts-and-digest)
- [Settings](#settings)
- [Tools](#tools)

Other guides: [bots](bots.md), [Mini App](mini-app.md), [SMS pattern text](sms-pattern-text.md), [webhooks](webhooks.md), [Cloudflare Worker](cloudflare-worker.md), [multilingual sites](multilingual.md), [questions](faq.md).

## Quick start

1. **Forms**: press **Scan now**. Forms in pages, posts, popups, headers, footers, templates and global widgets appear. A form that was missed is added the first time somebody submits it.
2. **Channels**: choose a channel and fill in the connection. Email works without any account.
3. Add **receivers** on the same screen. They get the submissions of every form.
4. Press **Send test**. It works before you save. The log shows the answer of the service.
5. Submit one of your forms. Open a form to give it own receivers, texts, buttons, rules or a reply to the person who sent it.

## Forms

Supported: the Form widget of Elementor Pro, its atomic form (Elementor 4), and the form of Hello Plus. Forms are found twice a day, when a document is saved in Elementor, and when you press Scan now. A global widget is one form however many pages show it. A form whose widget was removed is marked **missing**; delete it or keep it for its submissions.

By default every form notifies (Settings, General, **Forms**: all). Choose **selected** to notify only forms switched on here or with the action in the editor.

## Channels and receivers

The Channels screen groups channels into messengers, SMS, email, automation, chat and push. A green lamp means ready; amber means something is missing and the channel screen says what.

| Channel | You need | Receivers |
|---|---|---|
| Telegram, Bale | Bot token ([bots](bots.md)) | People, groups, topics, channels |
| Soroush Plus | Bot token | Chat id or @channel |
| Eitaa | Eitaayar token | Channel or group added in Eitaayar |
| Rubika | Bot token | chat_id |
| Gap | Bot token | User id, mobile number or @name |
| SMS | A gateway account ([pattern text](sms-pattern-text.md)) | Phone numbers |
| WhatsApp | A gateway account | Phone numbers |
| Email | Nothing (the mailer of the site) | Email addresses |
| Webhook | An address ([webhooks](webhooks.md)) | Endpoints |
| Slack, Discord, Google Chat, Teams, Mattermost, Rocket.Chat | Incoming webhook address per receiver | Webhook addresses |
| ntfy | Server (default ntfy.sh), optional token | Topics |
| Pushover | Application token | User or group keys |
| Gotify | Server and application token | (one target) |

Tokens and passwords are stored encrypted and never shown again; constants in `wp-config.php` can replace them ([developer guide](developer-guide.md#constants)). A receiver can have its own message language and can be limited to submissions in some languages.

**If sending fails** on each channel names a backup channel that is used once after the last attempt failed.

**SMS**: choose a default gateway, backup gateways, and routes by country (the first route that contains the country of the number wins). Numbers are stored as `+989120000000`; national numbers are completed with the default country (Settings, General) or a country field of the form. Gateways with an unconfirmed request format show a notice: send a test first. "Sent" means the gateway accepted the message.

**WhatsApp**: the Cloud API of Meta is the official way; free text reaches a person only within 24 hours after they wrote to you, otherwise use a template. UltraMsg, Green API, WAHA, Evolution API, Wassenger and whapi.cloud drive a normal WhatsApp account, which WhatsApp's terms do not allow for automated messages; the account may be blocked.

## Templates

Leave a template empty to use the built-in text. Write formatting once; every channel gets its own version (HTML for Telegram and email, Markdown for Discord, `*bold*` for WhatsApp, plain for SMS). The editor has a tag palette and a live preview with the length and number of parts.

```
<b>New request: {form_name}</b>

{all_fields}

{if field:plan == "vip"}VIP customer{/if}
{if files_count > 0}Files: {files}{/if}
Page: {page_link}
Date: {datetime}
```

| Tags | |
|---|---|
| Fields | `{field:ID}`, `{label:ID}`, `{field_raw:ID}`, `{all_fields}` (options `only=a+b`, `exclude=a+b`, `empty=1`, `max=3`, `format=lines|inline|table`, `labels=0`, `sep=": "`), `{fields_table}` |
| Submission | `{name}`, `{email}`, `{phone}`, `{submission_id}`, `{submission_uuid}`, `{status}`, `{lang}`, `{files}`, `{files_count}`, `{file_links}` |
| Form and links | `{form_name}`, `{form_id}`, `{form_key}`, `{form_url}`, `{admin_submission_url}`, `{miniapp_submission_url}` |
| Page | `{page_link}`, `{page_title}`, `{page_url}`, `{referrer}`, `{utm:source}` (source, medium, campaign, term, content) |
| Visitor | `{user_name}`, `{user_login}`, `{user_email}`, `{user_id}`, `{user_ip}`, `{browser}`, `{device}`, `{user_agent}` |
| Site and date | `{site_name}`, `{site_url}`, `{admin_url}`, `{admin_email}`, `{date}`, `{time}`, `{datetime}`, `{date:Y-m-d}`, `{jdate:Y/m/d}` (Jalali), `{gdate:Y-m-d}`, `{timestamp}` |

The Elementor shortcodes `[field id="email"]` and `[all-fields]` work too.

Filters: `{field:name|upper}`, `lower`, `title`, `trim`, `truncate:200`, `default:"none"`, `digits:fa`, `digits:en`, `nl2space`, `first`, `last`, `join:", "`, `count`, `urlencode`, `date:Y-m-d`, `mask`, `phone`, `number`, `strip`.

Conditions: `{if field:x}`, `{if !field:x}` (empty), `==`, `!=`, `contains`, `!contains`, `>`, `<`, `in "a, b"`, `matches`; join two with `and` or `or`; `{elseif ...}`, `{else}`, `{/if}`. Write `\{` for a real brace.

Whatever a visitor types is shown as text: it is never read as a tag, as formatting or as pattern text.

## Settings of one form

A form follows the site-wide settings until you change something on its screen. More specific wins: site, then form, then the action in the editor, then rules.

Tabs of a form:

- **Channels**: on, off or inherit per channel; receivers inherit, add to, or replace the global ones; own template and subject; buttons inherit, add, replace or none; options of the channel.
- **Reply to sender**: email, SMS or WhatsApp to the person who sent the form, from the field you choose, optionally only when a consent field is filled. Replies to phone numbers are limited to a few per number and hour.
- **Rules**: conditions on fields (is, is not, contains, starts, ends, empty, greater, less, in list, matches). Effects: skip all notifications, only some channels, add receivers, replace receivers; optional "stop here".
- **Fields**: which field holds phone, email, name, country; fields that are sensitive (never stored or sent) or left out of `{all_fields}`; own labels per language.
- **Files**: own file rule. Also **Link** a form to another one (translations of one page).

**Send test submission** sends sample values through the real routing.

## The action in the Elementor editor

Add **Forms Notifier** under "Actions After Submit" to set, in the editor: notifications on or off, channels, extra or replacing receivers per channel (a form field works too: `[field id="phone"]`), a message template, buttons for Telegram and Bale, and the file rule. Empty controls follow the plugin settings. The link at the top opens the full settings of the form.

## Uploaded files

Settings, Attachments: **off**, **send links**, **send the files**, or **both**; file types (all, images, a list of extensions), largest file and number of files. Per channel, per form and in the editor the rule can differ. A file too large for a channel becomes a link.

**Keep a protected copy** stores files in `wp-content/uploads/fnfe-files` under names nobody can guess. Download links are signed and can expire (**Download links expire after**). On nginx also deny direct access to that folder:

```nginx
location ^~ /wp-content/uploads/fnfe-files/ { deny all; }
```

## Submissions

Saved on your site unless **Save submissions on this site** is off (then messages are forwarded and nothing is kept). Search, filter by form, status (new, read, done, spam, trash) and date, bulk actions, CSV export, **Resend**. **Import** copies submissions Elementor Pro collected earlier; nobody is notified and nothing is imported twice.

## Log, queue and retries

Every message is a row in the Log: channel, receiver, status (queued, sending, sent, retry, failed, skipped, cancelled), attempts, the answer of the service. Messages are sent right after the form answered, so visitors do not wait. A temporary failure is tried again after 1, 5, 15, 60 and 360 minutes (Settings, Queue). A permanent one (blocked bot, wrong number) is not. Both can be sent again by hand. **Run queue now** sends what waits.

Background sending needs WordPress cron or Action Scheduler. On sites with few visitors use a real cron job ([WP-CLI](developer-guide.md#wp-cli)) or **When to send: Immediately**.

## Alerts and digest

**Failure alerts** (Channels, Alerts) tell admins that a message could not be delivered, through channels and receivers of their own, at most N per hour, after the last attempt or the first failure. Choose a channel other than the one you watch.

**Digest** (Channels, Digest): daily or weekly at a time you choose, with submissions per form, sent and failed messages and a comparison with the period before. Can be skipped when nothing happened.

**Quiet hours** (Settings, General) hold messages of chosen channels (default SMS and WhatsApp) during the night; they go out afterwards.

## Settings

| Tab | Contains |
|---|---|
| General | Which forms notify, when to send, ignoring repeated submissions (30 s), field types to leave out, saving submissions, IP address (full, shortened, none), browser name, Persian digits, language of messages, calendar, default country, quiet hours |
| Attachments | See above |
| Queue and retries | Attempts (5), pauses, runner (automatic, WP-Cron, Action Scheduler), messages and seconds per run |
| Mini App | [Mini App](mini-app.md) |
| Privacy | Days to keep submissions, files, message log (90) and system log (30); keep message texts in the log; delete everything when the plugin is deleted |
| Advanced | Request timeout, addresses on private networks, detailed system log |

## Tools

Export and import settings and form settings as JSON (secrets are never exported; the file lists which to re-enter), register texts for translation, system information, system log, end all Mini App sessions.

Site Health (Tools, Site Health) checks forms, ready channels, cron, the queue, failure rate, secrets, the file folder, HTTPS and the database.

## Privacy

The plugin adds a suggested text to Settings, Privacy of WordPress and supports **Export personal data** and **Erase personal data** for submissions, files and messages. Passwords are never stored. Data leaves the site only through the channels you set up.
