# Reach Telegram or Bale through a Cloudflare Worker

Some hosting companies cannot reach `api.telegram.org` (or `tapi.bale.ai`). Every message then fails with a timeout. A Worker is a small program on the network of Cloudflare: your site calls the Worker, the Worker calls the messenger. The free plan is enough for form notifications.

The Worker only carries requests **from** your site **to** the messenger. Receiving messages (commands, buttons, chat discovery) works through the webhook when the messenger can reach your site, otherwise choose "Regular checks" on the channel screen.

## 1. Create the Worker

1. Create a free account at [dash.cloudflare.com](https://dash.cloudflare.com/).
2. Open **Workers & Pages**, choose **Create**, then **Worker** (start from "Hello World").
3. Give it a name, for example `bot-forwarder`, and press **Deploy**.

## 2. Paste the code

Press **Edit code**, delete everything in the editor, paste this code and press **Deploy**. It is the file `assets/worker/bot-api-proxy.js` of the plugin.

```js
/**
 * Bot API forwarder for a Worker. This file is sample code: the plugin shows it, it never runs it.
 *
 * Forwards https://<worker>/bot<token>/<method>            to https://api.telegram.org/bot<token>/<method>
 * and      https://<worker>/file/bot<token>/<file path>    to https://api.telegram.org/file/bot<token>/<file path>
 *
 * Optional variables of the Worker:
 *   ALLOWED_BOT_ID  numeric part of the token before the colon (several: comma separated); other bots are refused
 *   UPSTREAM        other Bot API server, for example https://tapi.bale.ai
 */
function answer(status, description) {
  return new Response(JSON.stringify({ ok: false, error_code: status, description: description }), {
    status: status,
    headers: { 'content-type': 'application/json' },
  });
}

export default {
  async fetch(request, env) {
    const upstream = (env.UPSTREAM || 'https://api.telegram.org').replace(/\/+$/, '');
    const url = new URL(request.url);
    const match = url.pathname.match(/^\/(file\/)?bot(\d+):[A-Za-z0-9_-]+\/[A-Za-z0-9_\/.-]+$/);
    if (!match) {
      return answer(404, 'Not found');
    }
    if (request.method !== 'GET' && request.method !== 'POST') {
      return answer(405, 'Method not allowed');
    }
    const allowed = String(env.ALLOWED_BOT_ID || '').split(',').map((id) => id.trim()).filter(Boolean);
    if (allowed.length && allowed.indexOf(match[2]) === -1) {
      return answer(403, 'Bot not allowed');
    }
    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('cf-connecting-ip');
    headers.delete('x-forwarded-for');
    headers.delete('x-real-ip');
    const init = { method: request.method, headers: headers, redirect: 'manual' };
    if (request.method === 'POST') {
      init.body = request.body;
    }
    return fetch(upstream + url.pathname + url.search, init);
  },
};
```

What it does:

- Only paths of the Bot API are accepted: `/bot<token>/<method>` and `/file/bot<token>/<path>`. Everything else gets 404.
- Only GET and POST.
- With `ALLOWED_BOT_ID` set, other bots are refused with 403, so strangers cannot use your Worker as a free proxy.
- Headers that reveal the address of your server (`cf-connecting-ip`, `x-forwarded-for`, `x-real-ip`) are removed.
- Redirects are not followed. Nothing is logged or stored.

## 3. Set the variables

In the Worker open **Settings**, **Variables and Secrets**, and add:

| Name | Type | Value | Needed |
|---|---|---|---|
| `ALLOWED_BOT_ID` | Secret | Numeric part of your token before the colon, for example `123456789`. Several bots: comma separated | Recommended |
| `UPSTREAM` | Text | `https://tapi.bale.ai` | Only for Bale |

Sample data: token `123456789:TEST-TOKEN` gives `ALLOWED_BOT_ID = 123456789`.

Use one Worker per messenger: a Worker with `UPSTREAM` set to Bale cannot serve Telegram.

## 4. Copy the address

The overview shows the address, for example `https://bot-forwarder.account.workers.dev`. Where `workers.dev` is filtered, attach a domain of your own under **Settings**, **Domains & Routes**, and use that address.

## 5. Connect the plugin

1. Open Forms Notifier, Channels, Telegram (or Bale).
2. Paste the address into **API base URL**, without a slash at the end and without `/bot`.
3. Press **Test the connection**. The answer names the bot and the address that was used.
4. Save.

The address can also be set in code with the filter `fnfe_bot_api_base` (see the [developer guide](developer-guide.md#fnfe_bot_api_base)).

## 6. Test with curl

From any computer:

```bash
# A made-up token: "Unauthorized" proves that the request reached Telegram.
curl https://bot-forwarder.account.workers.dev/bot123456:TEST/getMe
{"ok":false,"error_code":401,"description":"Unauthorized"}

# With ALLOWED_BOT_ID = 123456789 the same request is refused by the Worker:
{"ok":false,"error_code":403,"description":"Bot not allowed"}

# A path that is not the Bot API:
curl https://bot-forwarder.account.workers.dev/hello
{"ok":false,"error_code":404,"description":"Not found"}

# Your real bot (keep the token out of shell history on shared machines):
curl https://bot-forwarder.account.workers.dev/bot<YOUR TOKEN>/getMe
{"ok":true,"result":{"id":123456789,"is_bot":true,"first_name":"Forms","username":"my_forms_bot"}}

# Sending a message through it:
curl -X POST https://bot-forwarder.account.workers.dev/bot<YOUR TOKEN>/sendMessage \
  -H 'Content-Type: application/json' \
  -d '{"chat_id":"-1001234567890","text":"Hello from the Worker"}'
```

From the server of your site, to prove the host can reach the Worker:

```bash
curl -sS -m 10 -o /dev/null -w '%{http_code}\n' https://bot-forwarder.account.workers.dev/bot123456:TEST/getMe
401
```

## 7. When it does not work

| Answer | Meaning |
|---|---|
| 404 | The path is wrong: enter only the address of the Worker, nothing after it |
| 403 "Bot not allowed" | `ALLOWED_BOT_ID` does not match the number at the start of your token |
| 1101 | The code has an error: paste it again completely |
| 1015 | Too many requests, or the daily limit of the free plan is reached |
| Timeout | Your host cannot reach the Worker either: use your own domain |

## Security

- The token and the messages pass through **your** Worker. Do not use a Worker that belongs to somebody else: its owner could read and use your token.
- Keep `ALLOWED_BOT_ID` set. Without it, anyone who finds the address can send through it (with their own token).
- The plugin keeps HTTPS between the site and the Worker; a plain `http://` address is refused unless private hosts are allowed under Advanced.
- If the token ever leaked, revoke it in @BotFather and enter the new one.

## Limits

The free plan limits requests per day and the size of a request body. Large files are better sent as links (Settings, Attachments). The public Bot API accepts uploads up to 50 MB.

## Other ways

**Your own server with nginx**, inside a server block with HTTPS, on a server that can reach the messenger:

```nginx
location ~ ^/(file/)?bot[0-9]+: {
    resolver 1.1.1.1;
    proxy_pass https://api.telegram.org$request_uri;
    proxy_set_header Host api.telegram.org;
    proxy_ssl_server_name on;
    client_max_body_size 60m;
    access_log off;
}
```

**A self-hosted Bot API server** ([telegram-bot-api](https://github.com/tdlib/telegram-bot-api)) allows uploads up to 2000 MB. Enter its address as the API base URL; a plain `http://` address in your own network also needs "Allow addresses on private networks" under Settings, Advanced.
