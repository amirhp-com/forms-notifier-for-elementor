# اتصال به تلگرام و بله از راه ورکر کلادفلر

بعضی از سرویس‌های میزبانی به `api.telegram.org` یا `tapi.bale.ai` دسترسی ندارند و هر پیام با خطای «زمان پاسخ تمام شد» برمی‌گردد. ورکر یک برنامه‌ی کوچک روی شبکه‌ی کلادفلر است: سایت شما پیام را به ورکر می‌دهد و ورکر آن را به پیام‌رسان می‌رساند. برای اعلان فرم‌ها پلن رایگان کافی است.

ورکر فقط درخواست‌های سایت را به پیام‌رسان می‌برد. برای دریافت پیام (دستورها، دکمه‌ها و پیدا کردن گفت‌وگوها) اگر پیام‌رسان به سایت شما دسترسی دارد وب‌هوک کار می‌کند؛ در غیر این صورت در صفحه‌ی کانال گزینه‌ی «بررسی منظم» را انتخاب کنید.

## ۱. ساخت ورکر

۱. در [داشبورد کلادفلر](https://dash.cloudflare.com/) یک حساب رایگان بسازید.
۲. بخش **Workers & Pages** را باز کنید، **Create** و بعد **Worker** را بزنید (الگوی Hello World).
۳. یک نام بدهید، مثلاً `bot-forwarder`، و **Deploy** را بزنید.

## ۲. گذاشتن کد

دکمه‌ی **Edit code** را بزنید، هرچه در ویرایشگر هست پاک کنید، این کد را بچسبانید و **Deploy** را بزنید. این همان فایل `assets/worker/bot-api-proxy.js` افزونه است.

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

این کد چه می‌کند:

- فقط مسیرهای Bot API را می‌پذیرد، یعنی `/bot<token>/<method>` و `/file/bot<token>/<path>`. هر مسیر دیگری پاسخ ۴۰۴ می‌گیرد.
- فقط درخواست‌های GET و POST را عبور می‌دهد.
- اگر متغیر `ALLOWED_BOT_ID` را تنظیم کنید، ربات‌های دیگر با خطای ۴۰۳ رد می‌شوند و کسی نمی‌تواند از ورکر شما مجانی استفاده کند.
- سرآیندهایی را که نشانی سرور شما را لو می‌دهند (`cf-connecting-ip`، `x-forwarded-for` و `x-real-ip`) حذف می‌کند.
- تغییر مسیر (redirect) را دنبال نمی‌کند و چیزی را ذخیره نمی‌کند.

## ۳. تنظیم متغیرها

در ورکر به **Settings** و بعد **Variables and Secrets** بروید و این‌ها را اضافه کنید:

| نام | نوع | مقدار | لازم است؟ |
|---|---|---|---|
| `ALLOWED_BOT_ID` | Secret | بخش عددی توکن پیش از دونقطه، مثلاً `123456789`؛ برای چند ربات با ویرگول جدا کنید | توصیه می‌شود |
| `UPSTREAM` | Text | `https://tapi.bale.ai` | فقط برای بله |

نمونه: از توکن `123456789:TEST-TOKEN` مقدار `ALLOWED_BOT_ID = 123456789` به دست می‌آید. برای هر پیام‌رسان یک ورکر جدا بسازید؛ ورکری که `UPSTREAM` آن بله است، به کار تلگرام نمی‌آید.

## ۴. برداشتن نشانی

در صفحه‌ی ورکر نشانی آن را می‌بینید، مثلاً `https://bot-forwarder.account.workers.dev`. اگر دامنه‌ی `workers.dev` در شبکه‌ی شما فیلتر است، از **Settings** و **Domains & Routes** یک دامنه‌ی خودتان را به ورکر وصل کنید و همان را به کار ببرید.

## ۵. اتصال افزونه

۱. در پیشخوان، «Forms Notifier»، کانال‌ها و بعد تلگرام (یا بله) را باز کنید.
۲. نشانی را بدون اسلش پایانی و بدون `/bot` در فیلد **نشانی پایه‌ی API** بگذارید.
۳. دکمه‌ی **آزمایش اتصال** را بزنید. پاسخ، نام ربات و نشانی استفاده‌شده را نشان می‌دهد.
۴. ذخیره کنید.

## ۶. آزمایش با curl

از هر رایانه‌ای:

```bash
# توکن ساختگی: پاسخ Unauthorized یعنی درخواست به تلگرام رسیده است.
curl https://bot-forwarder.account.workers.dev/bot123456:TEST/getMe
{"ok":false,"error_code":401,"description":"Unauthorized"}

# با ALLOWED_BOT_ID = 123456789 همین درخواست را خود ورکر رد می‌کند:
{"ok":false,"error_code":403,"description":"Bot not allowed"}

# مسیری که Bot API نیست:
curl https://bot-forwarder.account.workers.dev/hello
{"ok":false,"error_code":404,"description":"Not found"}

# ارسال یک پیام آزمایشی:
curl -X POST https://bot-forwarder.account.workers.dev/bot<YOUR TOKEN>/sendMessage \
  -H 'Content-Type: application/json' \
  -d '{"chat_id":"-1001234567890","text":"Hello from the Worker"}'
```

برای اینکه مطمئن شوید سرور سایت به ورکر دسترسی دارد، این را روی همان سرور اجرا کنید؛ پاسخ باید ۴۰۱ باشد:

```bash
curl -sS -m 10 -o /dev/null -w '%{http_code}\n' https://bot-forwarder.account.workers.dev/bot123456:TEST/getMe
```

## ۷. اگر کار نکرد

| پاسخ | معنی |
|---|---|
| ۴۰۴ | مسیر اشتباه است؛ فقط نشانی ورکر را وارد کنید و چیزی پس از آن ننویسید |
| ۴۰۳ با پیام Bot not allowed | مقدار `ALLOWED_BOT_ID` با عدد ابتدای توکن یکی نیست |
| ۱۱۰۱ | کد خطا دارد؛ آن را دوباره و کامل بچسبانید |
| ۱۰۱۵ | درخواست‌ها زیاد شده یا سهمیه‌ی روزانه‌ی پلن رایگان تمام شده است |
| پایان زمان پاسخ | سرور شما به ورکر هم دسترسی ندارد؛ از دامنه‌ی خودتان استفاده کنید |

## امنیت

- توکن و متن پیام‌ها از ورکر **خودتان** عبور می‌کند. از ورکر کس دیگری استفاده نکنید، چون صاحب آن می‌تواند توکن شما را ببیند و به کار ببرد.
- متغیر `ALLOWED_BOT_ID` را خالی نگذارید.
- افزونه نشانی `http://` را نمی‌پذیرد، مگر اینکه در تنظیمات پیشرفته «نشانی‌های شبکه‌ی خصوصی» را مجاز کرده باشید.
- اگر توکن لو رفت، در @BotFather آن را باطل کنید و توکن تازه را وارد کنید.

## راه‌های دیگر

اگر سروری دارید که به پیام‌رسان دسترسی دارد، می‌توانید با nginx همین کار را بکنید:

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

سرور Bot API تلگرام ([telegram-bot-api](https://github.com/tdlib/telegram-bot-api)) را هم می‌توانید روی سرور خودتان نصب کنید تا فایل‌های تا ۲۰۰۰ مگابایت ارسال شوند.
