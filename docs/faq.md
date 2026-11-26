# Questions and answers

**Do I have to add an action to every form?**
No. Every form notifies by default. Settings, General, Forms: "selected" limits it.

**Nothing arrives. Where do I start?**
1. Submissions: is the submission there? If not, the form is off, or saving is off.
2. Log: no row means the form has no active channel with receivers; "skipped" says why.
3. A row that stays queued: background tasks do not run. Press Run queue now; then set up a real cron job or choose "Immediately".
4. Failed: open the row, the answer of the service is at the bottom. Fix the cause and press Send again.

**My host cannot reach Telegram or another service.**
Use a forwarder: [Cloudflare Worker](cloudflare-worker.md). For other services ask the host, or use a webhook to a tool that can reach them.

**Why is my group not in the list of chats?**
A bot knows only chats it heard from. Add it and send `/id` in the group (and in each topic).

**Why does a button not appear?**
Messengers reject links to local or private addresses, and the Mini App needs HTTPS. Such buttons are left out so the message still arrives.

**The same submission arrives twice.**
Another notification (for example Elementor's own email action) may send it too. Repeated identical submissions within 30 seconds are ignored (Settings, General).

**Are SMS messages billed twice when a gateway fails?**
A backup gateway is tried only when the first one clearly refused or could not be reached. An answer the plugin does not understand is treated as final. A gateway that did not answer in time is retried, unless you switch off **Retry after a timeout** on the SMS or WhatsApp screen.

**Which SMS gateways are not fully confirmed?**
FarazSMS (normal text, balance), ParsGreen, the legacy IPPanel/FarazSMS API, ClickSend, NetGSM, and for WhatsApp UltraMsg, Evolution API and whapi.cloud. Their settings show a notice; send a test first.

**Where are tokens stored?**
Encrypted in the database with a key made from the salts of the site. If the salts change, enter the tokens again. Constants in wp-config.php work too.

**Does the plugin send data anywhere by itself?**
No. Only to services you set up. No statistics, no remote code, no contact with the author's servers.

**Does it work without Elementor Pro?**
With Hello Plus forms and the atomic form of Elementor where available. The classic Form widget is part of Elementor Pro.

**Multisite?**
Yes. Every site has its own settings, bots, tables and files.

**WPML or Polylang?**
Both. See [multilingual sites](multilingual.md).

**Are uploaded files public?**
Names are random and links in messages are signed and can expire. Apache closes the folder with its `.htaccess`; nginx and OpenLiteSpeed do not read it. Site Health, **File storage**, asks the server and shows the rule to add, or move the folder with `FNFE_FILES_DIR` (see the [user guide](user-guide.md#uploaded-files)). Files stored before a move are not moved and keep working.

**What happens when I delete the plugin?**
Nothing is lost unless "Delete all data when the plugin is deleted" is on (Settings, Privacy). Deactivation only stops the scheduled tasks.

**Persian and Latin digits?**
Persian and Arabic digits in phone, number and date fields are converted for storage (Settings, General). SMS can be written with Persian digits (SMS channel, Text).
