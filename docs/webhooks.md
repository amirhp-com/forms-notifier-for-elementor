# Webhooks and automation tools

The Webhook channel sends every submission as an HTTP request to addresses you choose. Use it for n8n, Make, Zapier, Pabbly Connect, IFTTT, a CRM or your own code.

## Set up an endpoint

1. Open Forms Notifier, Channels, Webhook, and switch it on.
2. Under **Endpoints** press **Add**, give it a name and choose a **preset**. The preset fills in the shape of the data and explains where to find the address.
3. Paste the **address**. Choose method (POST, PUT, PATCH or GET), body (JSON in the shape of the preset, form fields, or your own template), extra headers, authentication (none, user name and password, bearer token, own header), signing secret and timeout (1 to 60 seconds).
4. Under **Receivers** add the endpoint, so it gets the submissions of every form. A form can use other endpoints on its Webhook tab.
5. Press **Send test**. The test carries `"event": "test"` and sample values.

Addresses on private networks (`localhost`, `10.x`, `192.168.x`, ...) are refused unless **Settings, Advanced, Allow addresses on private networks** is on. Any port of a public host works, for example `:5678` of n8n. Redirects are not followed: enter the final address.

## The data

Standard JSON (presets Any webhook, n8n, Make):

```json
{
  "event": "submission.created",
  "delivery": "8f14e45fceea167a5a36dedd4bea2543",
  "sent_at": "2026-01-01T10:00:00Z",
  "site": { "name": "Example", "url": "https://example.com" },
  "form": { "id": 12, "key": "45:a1b2c3d", "name": "Contact", "source": "pro" },
  "submission": {
    "id": 981, "uuid": "8f14e45fceea167a5a36dedd4bea2543", "status": "new", "lang": "en",
    "created_at": "2026-01-01T09:59:58Z", "page_url": "https://example.com/contact/", "page_title": "Contact",
    "user_id": 0, "user_ip": "203.0.113.5",
    "admin_url": "https://example.com/wp-admin/admin.php?page=fnfe#/submissions/981"
  },
  "fields": { "name": "Sara", "email": "sara@example.com", "topics": [ "sales", "support" ] },
  "fields_meta": [ { "id": "name", "label": "Name", "type": "text", "value": "Sara" } ],
  "files": [ { "field": "cv", "name": "cv.pdf", "size": 48211, "mime": "application/pdf", "url": "https://example.com/wp-json/fnfe/v1/file/7?t=...&e=..." } ],
  "text": "plain text of the message"
}
```

- `event`: `submission.created`, `test`, `alert` (with an `alert` object) or `digest` (with a `digest` object).
- `fields`: field id => value; fields with several values are lists.
- `files[].url` is a signed link; it is empty when files are not linked. Links expire after the days set under Attachments.

Flat keys (presets Zapier and Pabbly Connect, the body "Form fields" and every GET request): `event`, `form_name`, `form_key`, `submission_id`, `page_url`, `created_at`, `text`, `field_<id>`, `file_1_url`, ...

IFTTT: `value1` form name, `value2` all fields as lines, `value3` page address.

Own template: template tags in any text. When the text starts with `{` or `[` it is sent as JSON and every value is escaped for JSON:

```json
{ "lead": { "name": "{field:name}", "mail": "{field:email}", "source": "{page_url}" } }
```

Developers can change the data with the filters `fnfe_webhook_payload` and `fnfe_webhook_headers` ([developer guide](developer-guide.md#fnfe_webhook_payload)).

## Headers

| Header | Value |
|---|---|
| `X-FNFE-Event` | Same as `event` |
| `X-FNFE-Delivery` | 32 hex characters, the same on every attempt of one message: drop repeated deliveries by it |
| `X-FNFE-Timestamp` | Unix time of this attempt |
| `X-FNFE-Signature` | `v1=` + hex HMAC-SHA256 of `{timestamp}.{raw body}` with the signing secret; only when a secret is set |
| `User-Agent` | `FormsNotifier/1.0.0` |

## Answers and retries

- Any 2xx status is success.
- A timeout, 408, 425, 429 or 5xx is tried again later with growing pauses (Settings, Queue). `Retry-After` is honoured.
- Any other status fails for good and can be sent again from the log. 410 from Make means that no scenario is connected to the webhook.

## Check the signature

PHP:

```php
<?php
$body      = file_get_contents( 'php://input' );
$timestamp = $_SERVER['HTTP_X_FNFE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_FNFE_SIGNATURE'] ?? '';
$expected  = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, 'YOUR SECRET' );

if ( abs( time() - (int) $timestamp ) > 300 || ! hash_equals( $expected, $signature ) ) {
	http_response_code( 401 );
	exit;
}
$data = json_decode( $body, true );
http_response_code( 204 );
```

Node.js (Express); read the raw body before parsing JSON:

```js
const crypto = require( 'crypto' );
const express = require( 'express' );
const app = express();

app.post( '/hook', express.raw( { type: '*/*' } ), ( req, res ) => {
  const t = req.get( 'x-fnfe-timestamp' ) || '';
  const given = req.get( 'x-fnfe-signature' ) || '';
  const expected = 'v1=' + crypto.createHmac( 'sha256', process.env.FNFE_SECRET ).update( t + '.' ).update( req.body ).digest( 'hex' );
  const fresh = Math.abs( Date.now() / 1000 - Number( t ) ) < 300;
  if ( ! fresh || given.length !== expected.length || ! crypto.timingSafeEqual( Buffer.from( given ), Buffer.from( expected ) ) ) {
    return res.sendStatus( 401 );
  }
  const data = JSON.parse( req.body.toString( 'utf8' ) );
  console.log( data.form.name, data.fields );
  res.sendStatus( 204 );
} );

app.listen( 3000 );
```

Test your receiver without the plugin:

```bash
BODY='{"event":"test","fields":{"name":"Sara"}}'; T=$(date +%s)
SIG="v1=$(printf '%s.%s' "$T" "$BODY" | openssl dgst -sha256 -hmac 'YOUR SECRET' | sed 's/^.* //')"
curl -X POST https://example.com/hook -H 'Content-Type: application/json' \
  -H "X-FNFE-Timestamp: $T" -H "X-FNFE-Signature: $SIG" -d "$BODY"
```

## n8n

1. Add a **Webhook** node. HTTP Method **POST**, Path for example `forms`, Respond **Immediately**.
2. To check signatures, open **Options** and switch on **Raw Body**.
3. Copy the **Production URL** (the Test URL only works while the editor listens) and **activate** the workflow.
4. In the plugin choose the preset **n8n** and paste the address. Self-hosted n8n inside your own network also needs "Allow addresses on private networks".
5. Optional signature check in a **Code** node after the webhook:

```js
const crypto = require('crypto');
const item = $input.first();
const raw = Buffer.from(item.binary.data.data, 'base64').toString('utf8');
const h = item.json.headers;
const expected = 'v1=' + crypto.createHmac('sha256', 'YOUR SECRET').update(h['x-fnfe-timestamp'] + '.' + raw).digest('hex');
if (h['x-fnfe-signature'] !== expected) throw new Error('Wrong signature');
return [{ json: JSON.parse(raw) }];
```

Fields are then at `{{ $json.body.fields.email }}` (without the Code node) or `{{ $json.fields.email }}`.

## Make

1. Create a scenario with the module **Webhooks**, **Custom webhook**, and press **Add**. Copy the address (`https://hook.<zone>.make.com/...`).
2. In the plugin choose the preset **Make**, paste the address and save.
3. In Make press **Run once**, then press **Send test** in the plugin. Make learns the structure of the data.
4. Add the next modules and map `fields.email`, `form.name` and so on. Switch the scenario on.

## Zapier

1. Create a Zap with the trigger **Webhooks by Zapier**, event **Catch Hook**. Copy the address.
2. In the plugin choose the preset **Zapier** (flat keys) and paste it.
3. Press **Send test** in the plugin, then **Test trigger** in Zapier. Fields appear as `field_email`, `form_name`, `file_1_url`.
4. Add actions and publish the Zap.

## Pabbly Connect

1. Create a workflow with the trigger **Webhook**. Copy the address.
2. In the plugin choose the preset **Pabbly Connect** and paste it.
3. While Pabbly shows "Waiting for webhook response", press **Send test** in the plugin.
4. Map the flat keys in the following actions and save.

## IFTTT

1. Create an applet with **Webhooks**, **Receive a web request**, and an event name, for example `form_sent`.
2. Your key is under the documentation page of the Webhooks service. The address is `https://maker.ifttt.com/trigger/form_sent/with/key/YOUR_KEY`.
3. In the plugin choose the preset **IFTTT** and paste the address.
4. Use `Value1` (form name), `Value2` (fields) and `Value3` (page address) in the action.

## Automation without webhooks

Developers can hook into `fnfe_submission_captured` instead ([developer guide](developer-guide.md#fnfe_submission_captured)).
