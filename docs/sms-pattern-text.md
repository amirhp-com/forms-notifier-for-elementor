# Pattern text for SMS and WhatsApp

Iranian gateways deliver approved **patterns** (templates) to every number, also to numbers that block advertising, and faster than normal texts. WhatsApp outside the 24-hour window accepts only approved **templates**. Pattern text lets you write both into the ordinary message field of a channel or form.

## Syntax

```
pattern:590428
pattern@smsir:123456
name:{field:name}
code:{submission_id}
---
Thank you {field:name}, your request #{submission_id} was received.
```

| Line | Meaning |
|---|---|
| `pattern:CODE` | First line. Code of the pattern for the gateway in use. Also accepted: `pcode`, `patterncode`, `bodyid`, `template` (WhatsApp) |
| `pattern@GATEWAY:CODE` | Code for one other gateway (ids below). A backup gateway without its own code sends the normal text |
| `name:value` | A named variable. The name may be written `{name}` or `%name%`, as gateway panels show it |
| `value` | A line without a name takes the next position (0, 1, 2 ...) |
| `0:value`, `1:value` | Variables by position |
| `lang:en_US` | Language of an approved WhatsApp template |
| `---` | End of the variables. The text after it is the normal message for gateways without patterns and for numbers the pattern cannot reach. It may be left out |

Only the line `pattern:` followed by one word starts a pattern: "Pattern: your design is ready" stays a normal message. Only the text **you** write is read as pattern text. What a visitor types never is, so a visitor cannot trigger a template. Values are filled with template tags and filters as usual: `{field:phone|phone}`, `{field:name|truncate:20}`.

## Gateways

| Gateway (id) | Pattern code is | Variables |
|---|---|---|
| Kavenegar (`kavenegar`) | Template name | `token`, `token2`, `token3`, `token10`, `token20`; other names fill free places in order. No spaces in token to token3 |
| IPPanel (`ippanel`), FarazSMS (`farazsms`), Mediana (`mediana`), legacy IPPanel (`farazlegacy`) | Pattern code | By the names of the pattern in your panel |
| MeliPayamak (`melipayamak`) | Body id | In order; `;` is removed from values |
| SMS.ir (`smsir`) | Template id | By name; values are cut to 25 characters |
| Ghasedak (`ghasedak`) | Template name | Named, or numbered as `param1` to `param10` |
| Amoot (`amoot`) | Pattern id | In order; commas are removed from values |
| LimoSMS (`limosms`) | Pattern id | In order |
| Asanak (`asanak`) | Template id | By name |
| Payam-resan (`payamresan`) | Template key | In order as `p1` to `p10` |
| ParsGreen (`parsgreen`) | Template id | One value: the variable `code`, or else the first one |
| Twilio (`twilio`) | Content SID starting with `HX` | Numbered as 1, 2, 3; named by name |
| Brevo (`brevo`) | Numeric template id | By name |
| MSG91 (`msg91`) | Template id (MSG91 sends templates only) | Case-sensitive names, numbered ones as `VAR1` ... |
| WP SMS plugin (`wpsms`) | Template id of its gateway | Only gateways with templates |
| Persian WooCommerce SMS (`pwsms`) | Switch "patterns" on in the gateway settings | |
| Custom HTTP gateway (`custom`) | Placeholders `{pattern_code}`, `{var:NAME}`, `{var:0}` in "Body for patterns" | |
| RayganSMS, Vonage, Plivo, Bird, ClickSend, Infobip, Telnyx, SMS.to, 46elks, SMSAPI, NetGSM, MessageBird | No patterns | The normal text after `---` is sent |

Gateways whose request format could not be fully confirmed with public documentation show a notice in the settings: **FarazSMS** (normal text and balance), **ParsGreen**, **legacy IPPanel/FarazSMS API**, **ClickSend**, **NetGSM**; for WhatsApp **UltraMsg**, **Evolution API** and **whapi.cloud**. Send a test before you rely on them.

## WhatsApp templates

```
template:order_received
lang:en_US
{field:name}
{submission_id}
---
Thank you {field:name}, we received request {submission_id}.
```

Numbered lines fill `{{1}}`, `{{2}}` of the approved template. Meta, 360dialog and Twilio support templates (Twilio: the content SID `HX...` as the code). Other gateways send the text after `---`.

## Examples

Kavenegar verify template with two values:

```
pattern:order-received
token:{submission_id}
token2:{field:name|nl2space}
---
{form_name}: request {submission_id} received.
```

Different codes per gateway with a backup chain IPPanel, then SMS.ir:

```
pattern:abc123xyz
pattern@smsir:458712
name:{field:name}
code:{submission_id}
```

## Test

Channels, SMS, **Test**: enter a number and pattern text and press the button. It works with unsaved values; the log shows the request the gateway answered.
