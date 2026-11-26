# Multilingual sites

The plugin works on one language, with WPML and with Polylang. The panel itself is translated by WordPress (Persian is bundled).

## Texts per language

Every text you write (templates, subjects, button texts, alert and digest texts, labels of fields) can have one version per language. With WPML or Polylang active, a language row appears above such a field. An empty language uses the default text.

Which language a message uses:

- Replies to the person who sent the form: the language of the submission.
- Messages to receivers: the language of the receiver when set, otherwise Settings, General, **Language of messages for you** (site language, language of the submission, or a fixed one).
- Alerts, digests, bot replies: the language of the receiver, then of the linked WordPress user, then the site language.

Dates, the calendar (Jalali for Persian when the calendar is "automatic") and the built-in texts follow the language of the message.

## Language of a submission

The first of these that is known: the current language of WPML or Polylang; the language of the page the visitor was on; the language of the document that holds the form; the language in the address (`/en/`, sub-domain, `?lang=en`); the default language. Developers can change it with `fnfe_submission_language`.

Receivers can be limited to submissions in some languages, so Persian submissions go to one group and English ones to another without rules.

## WPML String Translation and Polylang strings

Besides the language rows, every default text is registered under the context **Forms Notifier for Elementor** with a stable name (`setting:channels.telegram.template.admin`, `form:45:a1b2c3d:channels.email.subject`). Texts are registered when settings or a form are saved; Tools, **Register texts for translation** or `wp fnfe strings sync` does it for all. A translation there is used when the language row of that text is empty.

The plugin's `wpml-config.xml` declares no `admin-texts`: if WPML translated the option `fnfe_settings` itself, messages for receivers in another language would get the wrong text and saving a setting in a second language would overwrite the default text. Translate the texts in the plugin or under the context above.

The file does declare the texts of the **Forms Notifier** action of the Elementor form widget: the message template and the text and link of each button. WPML lists them with the other texts of the page (Translation Editor), so a translated page sends its own template. Receivers and the other choices of the action are copied unchanged. The entry has its own name and matches the widget by type, so the texts WPML already translates in the form widget stay as they are.

## Translated pages

Forms of translated pages appear as their own forms. Link a form to the form of the original page (Forms, the form, **Link**) so one form keeps the settings and every text has a version per language. The template and buttons of the Elementor action are translated with the page in WPML (see above). With Polylang every translated page has its own form widget: set them there, or set per-language texts on the form screen.
