# Telegram and Bale bots

Both messengers work the same way. Soroush Plus uses the same kind of bot for sending (no receiving, no topics). Eitaa (through Eitaayar), Rubika and Gap send messages only.

## Connect

1. Open @BotFather in Telegram (in Bale: @botfather), send `/newbot` and copy the token. Whoever has the token controls the bot.
2. Forms Notifier, Channels, Telegram: paste the token, press **Test the connection**, save. Or define `FNFE_CHANNELS_TELEGRAM_TOKEN` in `wp-config.php`.
3. Choose **How the bot receives messages** and press **Connect the bot**:
   - **Automatic**: webhook when the site has a public HTTPS address, otherwise regular checks.
   - **Webhook**: the messenger calls `https://example.com/wp-json/fnfe/v1/bot/telegram/hook/<secret>` with a secret header. Fast, needs HTTPS.
   - **Regular checks**: the site asks every minute (needs WordPress cron).
   - **Off**: send only.

Connect again after you change the token or the site address. A host that cannot reach the messenger needs a forwarder: [Cloudflare Worker guide](cloudflare-worker.md).

## Find chats

The plugin learns chats from what the bot sees. Press **Find chats** after:

| Chat | What to do |
|---|---|
| A person | Opens the bot and presses Start |
| A group | Add the bot and send `/id` in the group (a bot that is not an admin sees only commands) |
| A topic of a Telegram group | Send `/id` once inside each topic, or make the bot an admin |
| A channel | Telegram: add the bot as an admin and publish one post. Bale does not report channels: type the @username or id |

Addresses that can be typed: `123456789`, `-1001234567890`, `-1001234567890/45` (topic 45), `@channel_name`, `https://t.me/c/1234567890/45`. When a group becomes a supergroup, receivers move to the new id by themselves.

## Commands

| Command | Who | Answer |
|---|---|---|
| `/start` | everyone | Greeting; also completes the admin link |
| `/id` | everyone | Id of the chat and the address to use as a receiver |
| `/help` | everyone | Command list |
| `/forms` | admins | Forms with their number of submissions |
| `/last 5` | admins | Latest submissions, at most 10 |
| `/stats` | admins | Today and the last 7 days |
| `/app` | admins | Opens the Mini App |

In a group, admin commands are answered only when the group is a receiver. **Answer commands** switches all except `/start` off. Developers add commands with `fnfe_bot_commands`.

## Admins

On the bot screen choose a WordPress user and press **Add admin**: the plugin makes a link that works once, for 15 minutes. The person opens it in the messenger and presses Start. Permissions: **view** (commands, Mini App) and **act** (buttons and status changes). Admins can also be typed by numeric id.

## Buttons under a message

Types: **link**, **action** (mark read, done, spam, or send failed messages again), **Mini App**, **copy text**, **open the submission**. Actions are signed; **Who may press the action buttons** decides between admins only (default) and everyone in a chat that is a receiver. The pressed button shows a check mark and who pressed it. Buttons with local or private addresses are left out, because the messenger would reject the whole message.

## Sending options

Text format (HTML or MarkdownV2 on Telegram), no link previews, without sound, protected content, images as photos, largest upload (50 MB on the public API, up to 2000 MB with a self-hosted API server). Long messages are split into parts; buttons go on the last part. Each form can change silent, preview and protection on its Telegram tab.
