=== Forms Notifier for Elementor ===
Contributors: amirhpcom
Tags: elementor, forms, telegram, sms, notifications
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Elementor form submissions to Telegram, Bale, SMS, WhatsApp, email and webhooks, with templates, logs and retries.

== Description ==

Forms Notifier for Elementor sends every submission of your Elementor forms to the places where your team works: Telegram and Bale groups, topics and channels, SMS, WhatsApp, email, Slack, Discord, Microsoft Teams, n8n, Make, Zapier and more. Messages are sent in the background, retried when a service is down, logged with the answer of the service, and you are told when something keeps failing.

The plugin is free. It is not affiliated with or endorsed by Elementor or by any service it connects to. Product names are used only to describe compatibility.

= Features =

* Forms of Elementor Pro (classic and atomic) and Hello Plus. Forms are found on their own, also in popups, templates and global widgets. No form action is needed, but an action "Forms Notifier" in the editor can change settings per form.
* 19 channels: Telegram, Bale, SMS, WhatsApp, email, webhooks, Slack, Discord, Google Chat, Microsoft Teams, Mattermost, Rocket.Chat, ntfy, Pushover, Gotify, Eitaa, Rubika, Soroush Plus, Gap.
* Telegram and Bale bots find groups, topics and channels themselves, answer commands (/id, /last, /stats), and add buttons under a message to mark a submission as read, done or spam.
* A Mini App inside Telegram and Bale with forms, submissions and delivery problems. No remote script is loaded.
* 31 SMS gateways (Iranian and international), pass-through to the WP SMS and Persian WooCommerce SMS plugins, and a custom HTTP gateway. Gateway per country, backup gateways, pattern (template) messages.
* 10 WhatsApp gateways, the Cloud API of Meta first.
* Templates per channel and per form, with tags, filters and conditions, and a text per language.
* Webhooks with presets for n8n, Make, Zapier, Pabbly Connect and IFTTT, signed requests and stable delivery ids.
* Rules: send some submissions to other people, or only to some channels.
* Uploaded files are sent, linked through signed links, or left out, per channel and per form.
* Saved submissions with search, statuses, CSV export and import of older Elementor submissions.
* Message log, queue with retries, a backup channel, failure alerts, daily or weekly digest, quiet hours.
* WPML and Polylang. A full Persian translation with a bundled Persian font.
* Privacy tools of WordPress, retention settings, encrypted tokens, Site Health checks, WP-CLI, REST API and hooks for developers.

Documentation: https://github.com/amirhp-com/forms-notifier-for-elementor/tree/main/docs

= Requirements =

* WordPress 6.5 or newer, PHP 7.4 or newer. The mbstring extension is recommended (without it only Latin letters change case).
* Elementor. The form widget of Elementor Pro, its atomic form or the form of Hello Plus.
* HTTPS for bot webhooks and the Mini App. Without it the bots check for news every minute.

== Installation ==

1. Install and activate the plugin.
2. Open Forms Notifier, Forms, and press Scan now.
3. Open Channels, choose a channel, enter its connection and add receivers.
4. Press Send test, then send one of your forms.

== Frequently Asked Questions ==

= Do I need to add an action to every form? =

No. Every form notifies by default. Under Settings, General you can limit this to selected forms.

= My hosting cannot reach Telegram. What can I do? =

Put a small forwarder between the site and the messenger, for example a free Cloudflare Worker. The channel screen has a step-by-step guide and the code.

= Why is my group not in the list of chats? =

A bot only knows chats it heard from. Add the bot to the group and send /id there.

= Messages stay in the queue. =

Background tasks need WordPress cron. Press Run queue now on the Log screen; if that works, set up a real cron job or choose "Immediately" under Settings, General.

= Where are tokens stored? =

Encrypted in the database. They can also be defined as constants in wp-config.php.

= What happens when I delete the plugin? =

Your data is kept unless "Delete all data when the plugin is deleted" is switched on under Settings, Privacy.

== Screenshots ==

1. Dashboard with the delivery overview.
2. Channels.
3. Telegram channel with the chats the bot found.
4. Settings of one form.
5. Template editor with live preview.
6. Message log.
7. Mini App: overview.
8. Mini App: submissions.
9. Mini App: one submission.
10. The Forms Notifier action in the Elementor editor.

== External services ==

The plugin contacts no service until the site owner switches a channel on and enters its connection. It never contacts a server of the plugin author, sends no statistics and loads no remote code. When a channel is used, the plugin sends to that service, for each message: the message text (it contains the submitted form fields the template uses, the form name and page address), the receiver (chat id, phone number, email address or webhook address), uploaded files when file sending is on, and the credentials of the account. Tests and failure alerts send the same kind of data. Messages are sent when a form is submitted, when a test or a retry is started, and for alerts and digests.

Messengers:

* Telegram Bot API (api.telegram.org, or your own API base). Bots also receive updates from Telegram. Terms: https://telegram.org/tos/bot-developers Privacy: https://telegram.org/privacy
* Bale Bot API (tapi.bale.ai). Terms: https://bale.ai/terms
* Soroush Plus Bot API (api.splus.ir). Terms: https://soroushplus.com/p/rules Privacy: https://soroushplus.com/p/privacy
* Eitaa through Eitaayar (eitaayar.ir). Terms: https://eitaa.com/page/terms/ Privacy: https://eitaa.com/page/privacy/
* Rubika Bot API (botapi.rubika.ir). Terms: https://rubika.ir/rules Privacy: https://rubika.ir/policy
* Gap Bot API (api.gap.im). Privacy: https://gap.im/PrivacyPolicy
* Custom API base: a forwarder such as a Cloudflare Worker you deploy yourself receives the bot requests and passes them on. Cloudflare terms: https://www.cloudflare.com/terms/ Privacy: https://www.cloudflare.com/privacypolicy/

SMS gateways (phone number, text or pattern values, sender line, credentials):

* Kavenegar (api.kavenegar.com): https://kavenegar.com/
* IPPanel (edge.ippanel.com; legacy API ippanel.com): https://ippanel.com/
* FarazSMS / IranPayamak (api.iranpayamak.com). Terms: https://farazsms.com/rules/
* MeliPayamak (rest.payamak-panel.com). Terms: https://www.melipayamak.com/rules/
* SMS.ir (api.sms.ir). Terms: https://sms.ir/terms-and-conditions/
* Ghasedak (gateway.ghasedak.me): https://ghasedak.me/
* Mediana (api.mediana.ir): https://mediana.ir/
* Amoot SMS (portal.amootsms.com). Terms: https://hub.amootsoft.com/payamak/terms Privacy: https://hub.amootsoft.com/payamak/privacy-policy
* LimoSMS (api.limosms.com). Terms: https://limosms.com/rules
* Asanak (sms.asanak.ir). Terms: https://www.asanak.com/قوانین-و-مقررات/
* Payam-resan (api.sms-webservice.com): https://payam-resan.com/
* ParsGreen (sms.parsgreen.ir): https://www.parsgreen.com/
* RayganSMS (raygansms.com). Terms: https://raygansms.com/rules
* Twilio (api.twilio.com). Terms: https://www.twilio.com/en-us/legal/tos Privacy: https://www.twilio.com/en-us/legal/privacy
* Vonage (rest.nexmo.com). Terms: https://www.vonage.com/legal/communications-apis/terms-of-use/ Privacy: https://www.vonage.com/legal/privacy-policy/
* Plivo (api.plivo.com). Terms: https://www.plivo.com/legal/tos/ Privacy: https://www.plivo.com/legal/privacy/
* Bird (platform.bird.com) and MessageBird (rest.messagebird.com). Terms: https://bird.com/legal/terms Privacy: https://bird.com/legal/privacy
* ClickSend (rest.clicksend.com). Terms: https://www.clicksend.com/us/legal/terms-of-service/ Privacy: https://www.clicksend.com/us/legal/privacy-policy/
* Infobip (your account's API address). Terms: https://www.infobip.com/policies/service-terms-conditions Privacy: https://www.infobip.com/policies/privacy-notice
* Telnyx (api.telnyx.com). Terms: https://telnyx.com/terms-and-conditions-of-service Privacy: https://telnyx.com/privacy-policy
* Brevo (api.brevo.com). Terms: https://www.brevo.com/legal/termsofuse/ Privacy: https://www.brevo.com/legal/privacypolicy/
* SMS.to (api.sms.to). Terms: https://sms.to/terms-of-service/ Privacy: https://sms.to/privacy-policy/
* 46elks (api.46elks.com). Terms: https://46elks.com/terms Privacy: https://46elks.com/privacy-policy
* SMSAPI (api.smsapi.com, api.smsapi.pl). Terms: https://www.smsapi.com/en/terms Privacy: https://www.smsapi.com/en/privacy
* MSG91 (control.msg91.com). Terms: https://msg91.com/terms-of-use Privacy: https://msg91.com/privacy-policy
* NetGSM (api.netgsm.com.tr). Privacy: https://www.netgsm.com.tr/gizlilik-ve-guvenlik/
* WP SMS and Persian WooCommerce SMS: no request of this plugin; the text is handed to that plugin, which sends it through its own gateway.
* Custom HTTP gateway: the address you enter.

WhatsApp gateways (phone number, text or template values, files, credentials):

* WhatsApp Cloud API of Meta (graph.facebook.com). Terms: https://www.whatsapp.com/legal/business-terms Privacy: https://www.whatsapp.com/legal/privacy-policy
* 360dialog (waba-v2.360dialog.io). Privacy: https://360dialog.com/legal/privacy-policy
* Twilio WhatsApp (api.twilio.com). Terms and privacy as Twilio above.
* UltraMsg (api.ultramsg.com). Terms: https://ultramsg.com/terms-conditions.php Privacy: https://ultramsg.com/privacy-policy.php
* Green API (api.green-api.com or your instance address). Terms: https://green-api.com/en/user-agreement Privacy: https://green-api.com/en/privacy-policy
* Wassenger (api.wassenger.com). Terms: https://wassenger.com/terms Privacy: https://wassenger.com/privacy
* whapi.cloud (gate.whapi.cloud). Terms: https://whapi.cloud/terms Privacy: https://whapi.cloud/privacy
* WAHA and Evolution API: software you host yourself; data goes only to your server.
* Custom HTTP gateway: the address you enter.

Chat, push and automation (message text and the webhook address or topic you enter):

* Slack incoming webhooks. Terms: https://slack.com/main-services-agreement Privacy: https://slack.com/trust/privacy/privacy-policy
* Discord webhooks, also files. Terms: https://discord.com/terms Privacy: https://discord.com/privacy
* Google Chat webhooks. Terms: https://policies.google.com/terms Privacy: https://policies.google.com/privacy
* Microsoft Teams workflow webhooks. Terms: https://www.microsoft.com/servicesagreement Privacy: https://privacy.microsoft.com/privacystatement
* Mattermost and Rocket.Chat: your own server. Mattermost terms: https://mattermost.com/terms-of-use/ Rocket.Chat terms: https://docs.rocket.chat/docs/customer-terms-of-service
* ntfy (ntfy.sh or your own server). Terms: https://docs.ntfy.sh/terms/ Privacy: https://docs.ntfy.sh/privacy/
* Pushover (api.pushover.net). Terms: https://pushover.net/terms Privacy: https://pushover.net/privacy
* Gotify: your own server.
* Webhooks: the full submission as JSON (fields, form, page, file links) to the addresses you enter, for example n8n (https://n8n.io/legal/), Make (https://www.make.com/en/terms-and-conditions, https://www.make.com/en/privacy-notice), Zapier (https://zapier.com/legal/terms-of-service, https://zapier.com/privacy), Pabbly Connect (https://www.pabbly.com/terms-conditions/, https://www.pabbly.com/privacy-policy/) and IFTTT (https://ifttt.com/terms).
* Email goes through the mail system of your own site (wp_mail).

== Privacy ==

Submissions, files and the message log are stored in your own database and uploads folder, for as long as the retention settings say. Passwords and fields marked as sensitive are never stored or sent. The plugin adds suggested text to the privacy policy guide and supports the export and erase tools of WordPress. Data leaves the site only through the channels you set up, as described above.

== Credits ==

* Dastkhat font 1.220 by Dastyar, based on Vazirmatn by Saber Rastikerdar. SIL Open Font License 1.1. Source: https://github.com/dastyar-io/dastkhat-font
* Made by Amirhossein Hosseinpour, https://amirhp.com/

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
