# Mini App

A small app inside Telegram and Bale for the admins of a bot: overview of the site, forms, saved submissions with search and status, and delivery problems with a resend button. It is served by your own site; no script of the messenger or of anybody else is loaded.

## Requirements

- HTTPS. The messengers open mini apps only from `https://` addresses.
- A connected bot and at least one admin on the bot screen ([bots](bots.md#admins)).
- Saved submissions (Settings, General) for the submission screens.

## Turn it on

1. Settings, Mini App: switch on **Enable the mini app**. Choose **Allow actions** (status change, resend) and **Show IP addresses**.
2. **Session length** (default 7200 s) and **Launch data age** (default 86400 s) limit how long a sign-in lasts.
3. The section **Connect the app to the bots** shows the address for each platform:
   - Telegram: `https://example.com/fnfe-app/telegram/`
   - Bale: `https://example.com/fnfe-app/bale/`
   Without pretty permalinks: `https://example.com/?fnfe_app=telegram`.

## Telegram

1. @BotFather, your bot, **Bot Settings**, **Configure Mini App**, **Enable Mini App**, send the address.
2. Optional direct links: `/newapp`, choose the bot and the same address, pick a short name and enter it as **Mini App short name** on the Telegram screen. Buttons in groups then open `https://t.me/your_bot/short_name?startapp=sub_12`.
3. Optional: switch on **Menu button opens the Mini App**.

## Bale

@botfather in Bale, choose the bot, mini app settings, paste the address; the menu button is set there too. Direct link: `https://ble.ir/your_bot?startapp=sub_12`.

## Sign-in and security

- The messenger signs the launch data with the bot token; the plugin checks the signature, its age and that the person is an admin with view permission. Then it issues a session token bound to the site and to the current bot token.
- Somebody who is not an admin sees their numeric id, so you can add them.
- Removing an admin ends their session at once. A new bot token, or Tools, **Sign out all**, ends every session.
- Data routes answer only with the token in `X-FNFE-Token`. File links in the app are signed and last one hour.
- Sign-in attempts are limited to 20 per minute and address. Behind a reverse proxy set `fnfe_client_ip`.

## Start parameters

`sub_12` opens submission 12, `form_3` the submissions of form 3. Buttons of the type "Mini App" or "Open the submission" use them.

## Screens

Overview (numbers, health, queue), Forms, Submissions (search, status filter, unread), one submission (fields, files, messages, status buttons with undo), Problems (failed and retrying messages with resend). The app follows the theme of the messenger and the language of the site.
