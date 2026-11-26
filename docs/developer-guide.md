# Developer guide

Forms Notifier for Elementor, version 1.0.0. Author: [Amirhossein Hosseinpour](https://amirhp.com/).

- [How it works](#how-it-works)
- [PHP functions](#php-functions)
- [Objects you meet in hooks](#objects-you-meet-in-hooks)
- [Actions](#actions)
- [Filters](#filters)
- [Write a channel](#write-a-channel)
- [Write an SMS gateway](#write-an-sms-gateway)
- [Write a WhatsApp gateway](#write-a-whatsapp-gateway)
- [Add template tags and filters](#add-template-tags-and-filters)
- [Add condition operators](#add-condition-operators)
- [Write a form source](#write-a-form-source)
- [Settings schema](#settings-schema)
- [REST API](#rest-api)
- [JavaScript API](#javascript-api)
- [WP-CLI](#wp-cli)
- [Constants](#constants)
- [Database](#database)
- [Options, user meta, cron](#options-user-meta-cron)

Every identifier is prefixed: PHP namespace `AmirhpCom\FormsNotifier`, functions, hooks, options, tables and cron events `fnfe_`, constants `FNFE_`, REST namespace `fnfe/v1`, script global `window.fnfe`.

## How it works

```
Elementor form (Pro, atomic, Hello Plus)  or  fnfe_send()
      |
      v
Form source       reads the native record, builds a draft Submission
      v
Capture           switches, redaction, duplicate guard, language, files, save       -> fnfe_submission_captured
      v
Resolver          site settings, form settings, editor settings, rules              -> list of Delivery
      v
Dispatcher        renders template, buttons, files in the language of the receiver  -> list of Message
      v
Queue             table fnfe_messages, sent after the response or by cron
      v
Channel::send()   returns a SendResult: sent, retry later, or failed for good
      v
Log, backup channel, failure alert, digest
```

Two rules hold everywhere:

1. Submitted values are data. They are never read as a template tag, as markup or as pattern text.
2. A listener that throws cannot lose a submission: errors are caught, logged under Tools, System log, and the unfiltered value is used.

Load your code on `fnfe_loaded` (it fires on `plugins_loaded`, priority 5) when it extends a class of this plugin. Plain `add_filter()` calls can be made at any time before `init`.

## PHP functions

| Function | Purpose |
|---|---|
| `fnfe(): string` | Plugin version. `function_exists( 'fnfe' )` is the presence check. |
| `fnfe_get_setting( string $path, $default = null )` | One setting by dot path, for example `channels.telegram.enabled`. Secrets are never returned. |
| `fnfe_register_channel( string $id, $class_or_instance ): void` | Register a channel. |
| `fnfe_register_sms_provider( string $id, $class_or_instance ): void` | Register an SMS gateway. |
| `fnfe_register_whatsapp_provider( string $id, $class_or_instance ): void` | Register a WhatsApp gateway. |
| `fnfe_send( array $args ): int` | Send a notification for your own data through capture, routing, templates and log. Returns the submission id. 0 means that it failed, or that saving submissions is switched off (nothing gets an id then). |
| `fnfe_send_message( string $channel, string $address, string $text, array $args = [] ): SendResult` | Send one text to one address at once. `$args`: `name`, `subject`, `lang`, `options`, `buttons`. |

```php
if ( function_exists( 'fnfe_send' ) ) {
	$id = fnfe_send(
		[
			'form_name' => 'Callback request',
			'form_key'  => 'api:callback', // Own row on the Forms screen. Default: api:custom.
			'fields'    => [
				'name'  => 'Sara',
				'phone' => [
					'label' => 'Phone',
					'type'  => 'tel',
					'value' => '+989120000000',
				],
			],
			'channels'  => [ 'telegram', 'email' ], // Empty: every channel that is switched on.
		]
	);
}

$result = fnfe_send_message( 'telegram', '-1001234567890', 'The backup is finished.' );
if ( ! $result->ok ) {
	error_log( $result->error );
}
```

## Objects you meet in hooks

All classes are in `AmirhpCom\FormsNotifier\Model` unless another namespace is given. They are plain objects with public properties and the methods `to_array()` and `from_array()`.

**Submission**: `id`, `uuid`, `form_id`, `form_key` (`{post id}:{element id}`), `post_id`, `element_id`, `source` (`pro`, `atomic`, `ehp`, `api`), `form_name`, `fields`, `files` (Attachment list), `lang`, `status` (`new`, `read`, `done`, `spam`, `trash`), `created_gmt`, `meta`. Methods: `field( $id )`, `value( $id, $default )`, `first_of_type( $type )`, `files_for( $field_id )`.
A field is `[ id, type, label, value, values, raw, is_file, redacted, hidden ]`. Meta keys: `page_url`, `page_title`, `queried_id`, `referrer`, `user_id`, `user_login`, `user_email`, `user_ip`, `user_agent`, `locale`, `via` (`action`, `auto`, `api`, `test`, `import`), `elementor_submission_id`, `action_errors`, `editor`, `utm`.

**FormConfig**: settings of one form. `form_id`, `form_key`, `get( 'fields.phone' )`, `enabled()` (`inherit`, `on`, `off`), `route( 'telegram' )`, `to_array()`.

**Delivery**: one planned message: `channel`, `recipient`, `purpose`, `lang`, `template`, `subject`, `buttons`, `options`, `attachment_policy`, `rule_id`.

**Message**: one rendered message: `channel`, `recipient`, `purpose` (`admin`, `submitter`, `alert`, `digest`, `test`), `lang`, `format`, `subject`, `body` (in `format`, not split), `plain`, `pattern` (`code`, `vars`, `lang`, `codes`; empty for a normal text), `buttons` (rows of `[ text, type, value, style ]`), `attachments`, `links` (`[ name, url, size ]`), `options`, `meta` (`submission_id`, `form_id`, `form_key`, `message_id`, `attempt`, `rule_id`, `fallback_of`, `test`), `data` (structured data for webhooks).

**Recipient**: `channel`, `address`, `name`, `lang`, `role`, `meta`.

**SendResult**: `ok`, `provider`, `response` (at most 2000 characters, shown in the log), `error`, `permanent` (true: do not retry), `retry_after` (seconds), `remote_id`, `http_code`, `meta`. Factories: `SendResult::success( $provider, $response, $remote_id )` and `SendResult::failure( $provider, $error, $permanent, $response, $http_code )`.

**Attachment**: `id`, `field_id`, `name`, `path`, `url`, `mime`, `size`, `ext`, `stored`; `is_image()`, `exists()`.

**Template\Context**: what a template is rendered for: `submission`, `lang`, `channel`, `purpose`, `recipient`, `form`, `extra`, `policy`; `language()`, `is_persian()`.

**Receiver** (array, as stored): `[ id, address, label, enabled, lang, langs, meta ]`. `lang` is the language of the message, `langs` limits the receiver to submissions in these languages. Addresses of channels with receiver kind `secret` (chat webhooks, ntfy topics, Pushover keys) are stored as `secret:{receiver id}`.

**Button** (array, as stored): `[ id, text (per language), type (url, callback, web_app, copy, submission), value, row, style ]`.

**Rule** (array): `[ id, name, enabled, match (all, any), conditions [ field, op, value ], effect (skip, only, add, replace), channels, receivers, stop ]`.

**Message row** (array): the columns of `fnfe_messages`, see [Database](#database).

## Actions

### Life cycle

#### `fnfe_before_modules`

No parameters. The core is ready, the modules are not registered yet.

```php
add_action( 'fnfe_before_modules', function () {
	// The earliest point at which classes of the plugin can be used.
} );
```

#### `fnfe_loaded`

No parameters. Every module is registered. The place to load your own channel or gateway classes.

```php
add_action( 'fnfe_loaded', function () {
	require_once __DIR__ . '/class-desk-channel.php';
	fnfe_register_channel( 'desk', 'DeskChannel' );
} );
```

#### `fnfe_register`

`( string $registry_class )`. On `init`. Register channels and gateways here if you prefer an action to a filter.

```php
add_action( 'fnfe_register', function ( $registry_class ) {
	fnfe_register_sms_provider( 'mygate', 'My_Sms_Gateway' );
} );
```

#### `fnfe_rest_api_init`

No parameters. After the routes of the plugin were registered.

```php
add_action( 'fnfe_rest_api_init', function () {
	register_rest_route( 'my-plugin/v1', '/ping', [
		'methods'             => 'GET',
		'callback'            => function () {
			return [ 'notifier' => fnfe() ];
		},
		'permission_callback' => function () {
			return AmirhpCom\FormsNotifier\Core\Capabilities::can( 'fnfe_manage' );
		},
	] );
} );
```

#### `fnfe_cleanup`

No parameters. Daily cron event, also fired by `wp fnfe cleanup`. Apply your own retention here.

```php
add_action( 'fnfe_cleanup', function () {
	delete_expired_transients();
} );
```

### Submissions

#### `fnfe_submission_captured`

`( Submission $submission, FormConfig $form )`. A submission was accepted and, unless saving is off, stored. The notifications are planned by a listener at priority 10.

```php
add_action( 'fnfe_submission_captured', function ( $submission, $form ) {
	if ( $submission->value( 'newsletter' ) !== '' ) {
		do_action( 'my_newsletter_subscribe', $submission->value( 'email' ) );
	}
}, 20, 2 );
```

#### `fnfe_submission_imported`

`( Submission $submission, FormConfig $form )`. A submission that Elementor stored earlier was imported. Fires instead of `fnfe_submission_captured`; nobody is notified.

```php
add_action( 'fnfe_submission_imported', function ( $submission, $form ) {
	update_option( 'my_last_imported', $submission->id, false );
}, 10, 2 );
```

#### `fnfe_submission_skipped`

`( Submission $draft, string $reason )`. Nothing was saved or sent. Reasons: `disabled`, `duplicate`, `filtered`, `empty`.

```php
add_action( 'fnfe_submission_skipped', function ( $draft, $reason ) {
	error_log( "Form {$draft->form_key} skipped: $reason" );
}, 10, 2 );
```

#### `fnfe_submission_status_changed`

`( int $id, string $new, string $old, string $by )`. `$by` is `admin`, `bot` or `app`.

```php
add_action( 'fnfe_submission_status_changed', function ( $id, $new, $old, $by ) {
	if ( $new === 'done' ) {
		do_action( 'my_crm_close_lead', $id );
	}
}, 10, 4 );
```

#### `fnfe_submission_deleted`

`( int $id )`. A submission, its files and its messages were deleted.

```php
add_action( 'fnfe_submission_deleted', function ( $id ) {
	delete_option( "my_note_$id" );
} );
```

### Forms and settings

#### `fnfe_forms_scanned`

`( array $result )` with `found`, `added`, `updated`, `missing`, `done`, `documents`.

```php
add_action( 'fnfe_forms_scanned', function ( $result ) {
	if ( ! empty( $result['added'] ) ) {
		wp_mail( get_option( 'admin_email' ), 'New forms', $result['added'] . ' new forms were found.' );
	}
} );
```

#### `fnfe_form_config_saved`

`( int $form_id, array $config )`. The settings of one form were saved.

```php
add_action( 'fnfe_form_config_saved', function ( $form_id, $config ) {
	update_option( 'my_last_changed_form', $form_id, false );
}, 10, 2 );
```

#### `fnfe_setting_updated`

`( string $path, mixed $value, mixed $old )`. One setting was written.

```php
add_action( 'fnfe_setting_updated', function ( $path, $value, $old ) {
	if ( strpos( $path, 'channels.telegram.' ) === 0 ) {
		delete_transient( 'my_telegram_cache' );
	}
}, 10, 3 );
```

#### `fnfe_settings_saved`

`( array $changed_paths )`. A batch of settings was saved.

```php
add_action( 'fnfe_settings_saved', function ( $changed ) {
	error_log( 'Notifier settings changed: ' . implode( ', ', $changed ) );
} );
```

### Delivery

#### `fnfe_before_send`

`( Message $message )`. Right before every attempt. The object may be changed for this attempt.

```php
add_action( 'fnfe_before_send', function ( $message ) {
	if ( $message->channel === 'telegram' && (int) wp_date( 'G' ) < 8 ) {
		$message->options['silent'] = true;
	}
} );
```

#### `fnfe_after_send`

`( Message $message, SendResult $result )`. After every attempt, successful or not.

```php
add_action( 'fnfe_after_send', function ( $message, $result ) {
	if ( $result->http_code === 429 ) {
		error_log( "{$message->channel} asks to slow down" );
	}
}, 10, 2 );
```

#### `fnfe_message_sent`

`( array $row, SendResult $result )`. The message reached the status `sent`.

```php
add_action( 'fnfe_message_sent', function ( $row, $result ) {
	if ( $row['channel'] === 'sms' ) {
		update_option( 'my_sms_count', 1 + (int) get_option( 'my_sms_count' ), false );
	}
}, 10, 2 );
```

#### `fnfe_message_failed`

`( array $row, SendResult $result, bool $final )`. An attempt failed. `$final` is true when there will be no further attempt.

```php
add_action( 'fnfe_message_failed', function ( $row, $result, $final ) {
	if ( $final ) {
		error_log( "Message {$row['id']} failed: {$result->error}" );
	}
}, 10, 3 );
```

#### `fnfe_digest_sent`

`( array $data )`. The daily or weekly digest was sent. See `fnfe_digest_data` for the keys.

```php
add_action( 'fnfe_digest_sent', function ( $data ) {
	update_option( 'my_last_digest', $data['to'], false );
} );
```

### Bots and mini app

#### `fnfe_bot_update`

`( string $platform, array $update )`. Every accepted update of a bot, before the built-in handling. `$platform` is `telegram` or `bale`. The update comes from the messenger: treat it as untrusted.

```php
add_action( 'fnfe_bot_update', function ( $platform, $update ) {
	if ( isset( $update['message']['contact']['phone_number'] ) ) {
		set_transient( 'my_contact_' . $platform, sanitize_text_field( $update['message']['contact']['phone_number'] ), HOUR_IN_SECONDS );
	}
}, 10, 2 );
```

#### `fnfe_bot_command`

`( string $command, array $message, string $platform, BotClient $client )`. A command nobody registered. Use `fnfe_bot_commands` for commands of your own.

```php
add_action( 'fnfe_bot_command', function ( $command, $message, $platform, $client ) {
	if ( $command === 'ping' ) {
		$client->send_message( (string) $message['chat']['id'], 'pong' );
	}
}, 10, 4 );
```

#### `fnfe_bot_callback`

`( string $action, int $submission_id, array $callback_query, string $platform )`. A signed button with an action of your own was pressed by somebody who may press it. See `fnfe_callback_actions`.

```php
add_action( 'fnfe_bot_callback', function ( $action, $submission_id, $query, $platform ) {
	if ( $action === 'assign' ) {
		update_option( "my_owner_$submission_id", sanitize_text_field( $query['from']['first_name'] ?? '' ), false );
	}
}, 10, 4 );
```

#### `fnfe_chat_discovered`

`( array $chat_row, bool $is_new )`. A bot saw a person, group, topic or channel.

```php
add_action( 'fnfe_chat_discovered', function ( $chat, $is_new ) {
	if ( $is_new ) {
		error_log( "New chat on {$chat['platform']}: {$chat['title']} ({$chat['chat_id']})" );
	}
}, 10, 2 );
```

#### `fnfe_miniapp_auth`

`( string $platform, array $user, bool $allowed )`. Somebody with valid launch data opened the mini app.

```php
add_action( 'fnfe_miniapp_auth', function ( $platform, $user, $allowed ) {
	if ( ! $allowed ) {
		error_log( "Mini app refused for $platform user {$user['id']}" );
	}
}, 10, 3 );
```

## Filters

### Registries

#### `fnfe_channels`

`array $map` of channel id => class name or object that implements `Contracts\ChannelInterface`. Unset an id to remove a channel.

```php
add_filter( 'fnfe_channels', function ( $channels ) {
	unset( $channels['gap'] );
	return $channels;
} );
```

#### `fnfe_{channel}_providers`

`array $map` of gateway id => class name or object. Concrete names: `fnfe_sms_providers` and `fnfe_whatsapp_providers`; a channel of your own may use `Registry::providers( 'my_channel' )`.

```php
add_filter( 'fnfe_sms_providers', function ( $providers ) {
	$providers['mygate'] = 'My_Sms_Gateway';
	return $providers;
} );
```

#### `fnfe_formatters`

`array $map` of format id => class that implements `Contracts\FormatterInterface`. Built in: `telegram_html`, `telegram_md`, `bale_md`, `whatsapp`, `slack`, `markdown`, `email_html`, `text`.

```php
add_filter( 'fnfe_formatters', function ( $formatters ) {
	$formatters['my_text'] = AmirhpCom\FormsNotifier\Template\Format\TextFormatter::class;
	return $formatters;
} );
```

#### `fnfe_form_sources`

`array $map` of source id => class that implements `Contracts\FormSourceInterface`. See [Write a form source](#write-a-form-source).

```php
add_filter( 'fnfe_form_sources', function ( $sources ) {
	$sources['mybuilder'] = 'My_Form_Source';
	return $sources;
} );
```

### Settings and access

#### `fnfe_default_settings`

`array $tree`. Default settings.

```php
add_filter( 'fnfe_default_settings', function ( $tree ) {
	$tree['queue']['max_attempts'] = 8;
	return $tree;
} );
```

#### `fnfe_settings_tabs`

`array $tabs` of id => `[ label, icon ]`.

```php
add_filter( 'fnfe_settings_tabs', function ( $tabs ) {
	$tabs['my_tab'] = [
		'label' => 'My options',
		'icon'  => 'settings',
	];
	return $tabs;
} );
```

#### `fnfe_settings_sections`

`array $sections`. See [Settings schema](#settings-schema). A section with the id of an existing one replaces it.

```php
add_filter( 'fnfe_settings_sections', function ( $sections ) {
	$sections[] = [
		'id'     => 'my_section',
		'tab'    => 'advanced',
		'title'  => 'My plugin',
		'fields' => [
			[
				'key'     => 'my_plugin.note',
				'type'    => 'text',
				'label'   => 'Note',
				'default' => '',
			],
		],
	];
	return $sections;
} );
// Read it with fnfe_get_setting( 'my_plugin.note' ).
```

#### `fnfe_channel_schema`

`array $sections, ChannelInterface $channel`. Sections of one channel screen.

```php
add_filter( 'fnfe_channel_schema', function ( $sections, $channel ) {
	if ( $channel->id() === 'email' ) {
		$sections = array_filter( $sections, function ( $section ) {
			return $section['id'] !== 'email_fallback';
		} );
	}
	return array_values( $sections );
}, 10, 2 );
```

#### `fnfe_capability`

`string $wp_cap, string $fnfe_cap`. WordPress capability behind a plugin capability; the default is `manage_options` for all four: `fnfe_manage` (settings, channels, bots, tools, deleting), `fnfe_manage_forms` (settings of forms), `fnfe_view_submissions` (submissions and files), `fnfe_view_logs` (message log, retry, queue).

```php
add_filter( 'fnfe_capability', function ( $wp_cap, $fnfe_cap ) {
	return $fnfe_cap === 'fnfe_view_submissions' ? 'edit_pages' : $wp_cap;
}, 10, 2 );
```

#### `fnfe_help_topics`

`array $topics` of id => `[ title, group, body (limited HTML), steps [ title, body, code, lang, copy ] ]`. Groups: `start`, `channels`, `templates`, `bots`, `sms`, `webhooks`, `developers`, `troubleshooting`, `other`, `credits`.

```php
add_filter( 'fnfe_help_topics', function ( $topics ) {
	$topics['my_topic'] = [
		'title' => 'Our internal rules',
		'group' => 'other',
		'body'  => '<p>Answer every lead within one day.</p>',
	];
	return $topics;
} );
```

### Capture

#### `fnfe_should_capture`

`bool $capture, Submission $draft, FormConfig $form`. Return false to refuse a submission: it is not saved and nobody is notified.

```php
add_filter( 'fnfe_should_capture', function ( $capture, $draft, $form ) {
	return $draft->value( 'email' ) === 'test@example.com' ? false : $capture;
}, 10, 3 );
```

#### `fnfe_submission`

`Submission $submission`. Last change before it is saved.

```php
add_filter( 'fnfe_submission', function ( $submission ) {
	$submission->meta['utm']['source'] = $submission->meta['utm']['source'] ?? 'direct';
	return $submission;
} );
```

#### `fnfe_field_value`

`array $field, Submission $submission`. One normalised field.

```php
add_filter( 'fnfe_field_value', function ( $field, $submission ) {
	if ( $field['type'] === 'email' ) {
		$field['value']  = strtolower( $field['value'] );
		$field['values'] = [ $field['value'] ];
	}
	return $field;
}, 10, 2 );
```

#### `fnfe_skip_field_types`

`string[] $types`. Field types that are dropped. The default is the setting "Field types to leave out".

```php
add_filter( 'fnfe_skip_field_types', function ( $types ) {
	$types[] = 'hidden';
	return $types;
} );
```

#### `fnfe_redact_field_types`

`string[] $types`, default `[ 'password' ]`. The value of these fields is masked before anything is saved or sent.

```php
add_filter( 'fnfe_redact_field_types', function ( $types ) {
	$types[] = 'iban';
	return $types;
} );
```

#### `fnfe_submission_language`

`string $lang, Submission $submission`. Language code of a submission. Codes the site does not know are ignored.

```php
add_filter( 'fnfe_submission_language', function ( $lang, $submission ) {
	return strpos( $submission->meta['page_url'] ?? '', '/en/' ) !== false ? 'en' : $lang;
}, 10, 2 );
```

#### `fnfe_client_ip`

`string $ip`. Address of the visitor: `REMOTE_ADDR`, or the header chosen under Settings, Advanced (`advanced.proxy_header`) behind a trusted reverse proxy. Use the filter for setups the setting does not cover. Only trust a header your own proxy overwrites.

```php
add_filter( 'fnfe_client_ip', function ( $ip ) {
	$proxies = [ '10.0.0.5' ]; // Address of your own proxy.
	if ( in_array( $ip, $proxies, true ) && isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
	}
	return $ip;
} );
```

### Routing

#### `fnfe_recipients`

`array $receivers, string $channel, Submission $submission, FormConfig $form`. Receivers of one channel, as stored.

```php
add_filter( 'fnfe_recipients', function ( $receivers, $channel, $submission, $form ) {
	if ( $channel === 'email' && $submission->value( 'department' ) === 'Support' ) {
		$receivers[] = [
			'id'      => 'r_support1',
			'address' => 'support@example.com',
			'label'   => 'Support',
			'enabled' => true,
		];
	}
	return $receivers;
}, 10, 4 );
```

#### `fnfe_deliveries`

`Delivery[] $plan, Submission $submission, FormConfig $form`. The final plan, after all rules.

```php
add_filter( 'fnfe_deliveries', function ( $plan, $submission, $form ) {
	return array_filter( $plan, function ( $delivery ) use ( $submission ) {
		return $delivery->channel !== 'sms' || $submission->value( 'urgent' ) !== '';
	} );
}, 10, 3 );
```

#### `fnfe_submitter_limit`

`int $limit, Delivery $delivery, Submission $submission`. Largest number of automatic replies to one address per hour; 0 means no limit. Replies to phone numbers are limited by default because each one costs money.

```php
add_filter( 'fnfe_submitter_limit', function ( $limit, $delivery, $submission ) {
	return $delivery->channel === 'sms' ? 2 : $limit;
}, 10, 3 );
```

#### `fnfe_sms_route`

`string[] $provider_ids, string $e164, string $country, Message $message`. Gateways to try for a number, in this order.

```php
add_filter( 'fnfe_sms_route', function ( $ids, $number, $country, $message ) {
	return $country === 'DE' ? [ 'twilio', 'vonage' ] : $ids;
}, 10, 4 );
```

#### `fnfe_dial_codes`

`array $codes` of ISO country code => dial code. Countries you remove are not offered and not recognised.

```php
add_filter( 'fnfe_dial_codes', function ( $codes ) {
	unset( $codes['AQ'] );
	return $codes;
} );
```

### Messages and templates

#### `fnfe_message`

`Message $message, Delivery $delivery, Submission $submission`. After rendering, before the message is queued.

```php
add_filter( 'fnfe_message', function ( $message, $delivery, $submission ) {
	if ( $message->channel === 'email' && $submission->value( 'priority' ) === 'high' ) {
		$message->subject = '[!] ' . $message->subject;
	}
	return $message;
}, 10, 3 );
```

#### `fnfe_template`

`string $template, string $channel, string $purpose, Context $context`. Template source before it is parsed.

```php
add_filter( 'fnfe_template', function ( $template, $channel, $purpose, $context ) {
	return $purpose === 'admin' ? $template . "\n\n<i>{site_name}</i>" : $template;
}, 10, 4 );
```

#### `fnfe_default_template`

`string $template, string $format, string $purpose`. Built-in text that is used while a template is empty.

```php
add_filter( 'fnfe_default_template', function ( $template, $format, $purpose ) {
	return $format === 'text' && $purpose === 'admin' ? '{form_name}: {name} {phone}' : $template;
}, 10, 3 );
```

#### `fnfe_template_context`

`Context $context`. Runs once per rendering; add values to `$context->extra`.

```php
add_filter( 'fnfe_template_context', function ( $context ) {
	$context->extra['office'] = 'Tehran';
	return $context;
} );
```

#### `fnfe_template_tags`

`array $resolvers` of tag name => `callable( string $arg, Context $context ): string`. Names: `[a-z0-9_.]+`. The returned text is escaped for every channel.

```php
add_filter( 'fnfe_template_tags', function ( $tags ) {
	$tags['order_total'] = function ( $arg, $context ) {
		return (string) get_option( 'my_order_total', '' );
	};
	return $tags;
} );
```

#### `fnfe_template_tags_catalog`

`array $catalog, int $form_id, string $purpose`. Entries of the tag palette.

```php
add_filter( 'fnfe_template_tags_catalog', function ( $catalog, $form_id, $purpose ) {
	$catalog[] = [
		'tag'      => '{order_total}',
		'label'    => 'Order total',
		'group'    => 'submission',
		'desc'     => '',
		'purposes' => [ 'admin', 'submitter' ],
	];
	return $catalog;
}, 10, 3 );
```

#### `fnfe_template_filters`

`array $filters` of name => `callable( string $value, string $arg, Context $context ): string`.

```php
add_filter( 'fnfe_template_filters', function ( $filters ) {
	$filters['stars'] = function ( $value, $arg, $context ) {
		return str_repeat( '*', max( 0, min( 10, (int) $value ) ) );
	};
	return $filters;
} );
// {field:rating|stars}
```

#### `fnfe_condition_ops`

`array $ops` of name => `callable( string $actual, string $expected, array $values ): bool`. Operators of rules.

```php
add_filter( 'fnfe_condition_ops', function ( $ops ) {
	$ops['longer_than'] = function ( $actual, $expected, $values ) {
		return mb_strlen( $actual ) > (int) $expected;
	};
	return $ops;
} );
```

#### `fnfe_buttons`

`array $rows, Message $message, Submission $submission`. Rendered buttons: rows of `[ text, type, value, style ]`.

```php
add_filter( 'fnfe_buttons', function ( $rows, $message, $submission ) {
	$rows[] = [
		[
			'text'  => 'Open CRM',
			'type'  => 'url',
			'value' => 'https://crm.example.com/lead/' . $submission->id,
			'style' => '',
		],
	];
	return $rows;
}, 10, 3 );
```

### Files

#### `fnfe_attachment_policy`

`array $policy, string $channel, ?FormConfig $form`. Keys: `mode` (`off`, `link`, `send`, `both`), `types` (`all`, `images`, `custom`), `extensions`, `max_mb`, `max_files`, `keep_copy`, `link_ttl_days`.

```php
add_filter( 'fnfe_attachment_policy', function ( $policy, $channel, $form ) {
	if ( $channel === 'discord' ) {
		$policy['mode'] = 'link';
	}
	return $policy;
}, 10, 3 );
```

#### `fnfe_attachments`

`array $result, Message $message, Submission $submission`. Result of a policy: `send` (Attachment list), `links`, `skipped`. The message carries only the channel and the ids of submission and form. Files you add must be inside an allowed folder.

```php
add_filter( 'fnfe_attachments', function ( $result, $message, $submission ) {
	$result['send'] = array_slice( $result['send'], 0, 3 );
	return $result;
}, 10, 3 );
```

#### `fnfe_files_dir`

`string $dir`. Absolute folder of stored files, without trailing slash. The default is `fnfe-files` in the uploads folder; the constant `FNFE_FILES_DIR` replaces the default and this filter wins over both. Stream wrappers (`scheme://`) are refused. Files stored before a change stay where they are: they are still served, cleaned up and deleted, because the default folder stays an allowed one. They are not moved. The filter is not applied during uninstall (the plugin is not loaded then), so a folder chosen with the filter is not emptied by "Delete all data": use the constant for that.

```php
add_filter( 'fnfe_files_dir', function ( $dir ) {
	return '/srv/private/fnfe-files/site-' . get_current_blog_id();
} );
```

#### `fnfe_allowed_file_roots`

`string[] $folders`. Folders files may be read from. The default is the upload folder of Elementor forms, the storage folder (`fnfe_files_dir`), the default folder `fnfe-files` and the uploads folder.

```php
add_filter( 'fnfe_allowed_file_roots', function ( $folders ) {
	$folders[] = '/srv/private/forms';
	return $folders;
} );
```

#### `fnfe_denied_extensions`

`string[] $extensions`. File types that are never stored or sent (php, phtml, sh, exe and so on).

```php
add_filter( 'fnfe_denied_extensions', function ( $extensions ) {
	$extensions[] = 'zip';
	return $extensions;
} );
```

### Queue, alerts, digest

#### `fnfe_retry_delay`

`int $seconds, int $attempt, array $row, SendResult $result`. Pause before the next attempt.

```php
add_filter( 'fnfe_retry_delay', function ( $seconds, $attempt, $row, $result ) {
	return $row['channel'] === 'sms' ? min( $seconds, 300 ) : $seconds;
}, 10, 4 );
```

#### `fnfe_max_attempts`

`int $max, Message $message`.

```php
add_filter( 'fnfe_max_attempts', function ( $max, $message ) {
	return $message->purpose === 'submitter' ? 2 : $max;
}, 10, 2 );
```

#### `fnfe_alert_message`

`string $template, array $row, SendResult $result`. Template of a failure alert, before it is rendered. Tags: `{alert.title}`, `{alert.channel}`, `{alert.recipient}`, `{alert.form}`, `{alert.error}`, `{alert.attempts}`, `{alert.log_url}`.

```php
add_filter( 'fnfe_alert_message', function ( $template, $row, $result ) {
	return $template . "\nHTTP " . (int) $result->http_code;
}, 10, 3 );
```

#### `fnfe_digest_data`

`array $data`: `frequency`, `from`, `to`, `submissions`, `sent`, `failed`, `by_channel`, `by_form`, `queue`, `health`, `previous`.

```php
add_filter( 'fnfe_digest_data', function ( $data ) {
	$data['by_form'] = array_slice( $data['by_form'], 0, 3 );
	return $data;
} );
```

### Network

#### `fnfe_http_args`

`array $args, string $url, string $method`. Arguments of every outgoing request, as for `wp_remote_request()`.

```php
add_filter( 'fnfe_http_args', function ( $args, $url, $method ) {
	if ( wp_parse_url( $url, PHP_URL_HOST ) === 'api.telegram.org' ) {
		$args['timeout'] = 40;
	}
	return $args;
}, 10, 3 );
```

#### `fnfe_webhook_payload`

`array $payload, Message $message, array $endpoint`. Standard payload of a webhook, before it is put into the shape of the preset. See [Webhooks](webhooks.md).

```php
add_filter( 'fnfe_webhook_payload', function ( $payload, $message, $endpoint ) {
	$payload['environment'] = wp_get_environment_type();
	return $payload;
}, 10, 3 );
```

#### `fnfe_webhook_headers`

`array $headers, Message $message, array $endpoint`. The `X-FNFE-*` headers are added afterwards.

```php
add_filter( 'fnfe_webhook_headers', function ( $headers, $message, $endpoint ) {
	$headers['X-Source'] = wp_parse_url( home_url(), PHP_URL_HOST );
	return $headers;
}, 10, 3 );
```

### Bots

#### `fnfe_bot_api_base`

`string $base, string $platform` (`telegram`, `bale`, `soroush`). Address of the Bot API without a slash at the end.

```php
add_filter( 'fnfe_bot_api_base', function ( $base, $platform ) {
	return $platform === 'telegram' && wp_get_environment_type() === 'production' ? 'https://bot.example.com' : $base;
}, 10, 2 );
```

#### `fnfe_bot_max_upload_bytes`

`int $bytes, string $platform`. Larger files become links.

```php
add_filter( 'fnfe_bot_max_upload_bytes', function ( $bytes, $platform ) {
	return min( $bytes, 10 * MB_IN_BYTES );
}, 10, 2 );
```

#### `fnfe_bot_allowed_updates`

`string[] $types, string $platform`. Update types the bot asks for.

```php
add_filter( 'fnfe_bot_allowed_updates', function ( $types, $platform ) {
	if ( $platform === 'telegram' ) {
		$types[] = 'chat_join_request';
	}
	return $types;
}, 10, 2 );
```

#### `fnfe_bot_commands`

`array $commands, string $platform`: name => `[ desc, admin (bool), handler ]`. The handler gets `( string $args, array $message, string $platform, BotClient $client )` and returns canonical markup, or `[ 'text' => ..., 'buttons' => rows ]`, or an empty string to stay silent. Escape values you put into the markup.

```php
add_filter( 'fnfe_bot_commands', function ( $commands, $platform ) {
	$commands['orders'] = [
		'desc'    => 'Orders of today',
		'admin'   => true,
		'handler' => function ( $args, $message, $platform, $client ) {
			return '<b>Orders today:</b> ' . esc_html( (string) get_option( 'my_orders_today', '0' ) );
		},
	];
	return $commands;
}, 10, 2 );
```

#### `fnfe_callback_actions`

`array $actions` of id => `[ label, status (optional) ]`. Actions of inline buttons. With a `status` (`new`, `read`, `done`, `spam`, `trash`) the submission changes to it; without one `fnfe_bot_callback` fires.

```php
add_filter( 'fnfe_callback_actions', function ( $actions ) {
	$actions['assign'] = [ 'label' => 'I take it' ];
	return $actions;
} );
```

### Mini app

#### `fnfe_miniapp_allowed`

`bool $allowed, string $platform, array $user` (`uid`, `name`, `username`, `wp_user`, `perms`). Allowing somebody who is not an admin of the bot grants view access only.

```php
add_filter( 'fnfe_miniapp_allowed', function ( $allowed, $platform, $user ) {
	return $allowed && ! get_option( 'my_lockdown' );
}, 10, 3 );
```

#### `fnfe_miniapp_config`

`array $config, string $platform`. Public configuration of the page. Do not put secrets into it.

```php
add_filter( 'fnfe_miniapp_config', function ( $config, $platform ) {
	$config['site']['name'] = 'My team';
	return $config;
}, 10, 2 );
```

### For tests

#### `fnfe_now`

`int $timestamp`. The clock of the plugin. Meant for tests.

```php
add_filter( 'fnfe_now', function ( $timestamp ) {
	return defined( 'MY_TEST_TIME' ) ? (int) MY_TEST_TIME : $timestamp;
} );
```

#### `fnfe_table_prefix`

`string $prefix`, default `fnfe_`. Prefix of the tables after the one of WordPress. Meant for tests: changing it on a live site hides all data.

```php
add_filter( 'fnfe_table_prefix', function ( $prefix ) {
	return defined( 'MY_TEST_RUN' ) ? 'fnfe_test_' : $prefix;
} );
```

## Write a channel

A channel is one class. Its settings screen, receivers, per-form options, test button, log, retries and alerts come from the description it returns; no JavaScript is needed. Extend `Channels\AbstractChannel`.

`class-desk-channel.php`:

```php
<?php
use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

final class DeskChannel extends AbstractChannel {

	public function id(): string {
		return 'desk';
	}

	public function label(): string {
		return 'Desk';
	}

	public function description(): string {
		return 'Tickets in our help desk.';
	}

	public function group(): string {
		return 'chat'; // messenger | sms | email | automation | chat | push
	}

	public function format(): string {
		return 'markdown';
	}

	public function capabilities(): array {
		return array_merge( parent::capabilities(), [
			'receiver_kind' => 'text',
			'subject'       => true,
			'max_length'    => 5000,
		] );
	}

	// Keys are relative to channels.desk.
	protected function own_sections(): array {
		return [
			[
				'id'     => 'desk_connection',
				'title'  => 'Connection',
				'fields' => [
					$this->field( 'url', 'url', 'Address' ),
					$this->field( 'token', 'secret', 'Token' ),
				],
			],
		];
	}

	public function problems(): array {
		return $this->setting( 'url' ) === '' || $this->secret( 'token' ) === '' ? [ 'Enter the address and the token.' ] : [];
	}

	public function normalize_address( string $raw, array $context = [] ): string {
		$raw = strtoupper( trim( $raw ) );
		return preg_match( '/^[A-Z0-9-]{2,20}$/', $raw ) ? $raw : ''; // '' = not valid
	}

	public function send( Message $message ): SendResult {
		$response = Http::post_json(
			$this->setting( 'url' ) . '/tickets',
			[
				'queue' => $message->recipient->address,
				'title' => $message->subject,
				'text'  => $message->body,
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'token' ) ]
		);
		return $this->from_http( $response );
	}
}
```

Plugin file:

```php
add_action( 'fnfe_loaded', function () {
	require_once __DIR__ . '/class-desk-channel.php';
	fnfe_register_channel( 'desk', 'DeskChannel' );
} );
```

| Capability | Values | Meaning |
|---|---|---|
| `receivers` | `list`, `none` | Receivers are picked, or there is one implicit target |
| `receiver_kind` | `chat`, `phone`, `email`, `endpoint`, `text`, `secret` | Input and validation. `secret`: the address is a credential and goes to the encrypted store; read it with `$this->resolve_address()` |
| `submitter` | bool | Can write to the person who sent the form |
| `buttons` | list of `url`, `callback`, `web_app`, `copy` | Supported button types |
| `attachments` | bool | Uploads files from `$message->attachments` |
| `links` | bool | Can show `$message->links` |
| `subject` | bool | Has a title line |
| `pattern` | bool | Understands pattern text (`$message->pattern`) |
| `max_length` | int | Limit of one message, 0 = none |
| `discover` | bool | Offers receivers through the action `receiver_options` |

Rules for `send()`: never throw, never wait longer than two seconds, return `permanent = true` when a retry cannot help (wrong address, revoked token). `from_http()` maps status codes for you: 408, 425, 429 and 5xx are temporary, `Retry-After` is honoured.

`actions()` may return more buttons: action id => `callable( array $params ): array` with an ActionResult `[ ok, message, level, rows, columns, data, html ]`. Reserved id `receiver_options`: gets `search`, `type`, `page` and returns rows `[ address, label, group, hint, meta ]` for the picker.

`form_schema()` may return fields that appear on the channel tab of every form; their values arrive in `$message->options`.

## Write an SMS gateway

Extend `Channels\Sms\Providers\AbstractSmsProvider`. It checks the settings and countries, chooses between normal text and pattern, hides secrets in errors and refuses redirects.

```php
<?php
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

final class My_Sms_Gateway extends AbstractSmsProvider {

	private const BASE = 'https://api.my-gateway.example';

	public function id(): string {
		return 'mygate';
	}

	public function label(): string {
		return 'My gateway';
	}

	public function region(): string {
		return 'international'; // iran | international | passthrough | any
	}

	public function supports_pattern(): bool {
		return true;
	}

	public function countries(): array {
		return []; // ISO codes, [] = everywhere
	}

	// Keys are relative to channels.sms.providers.mygate.
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field(),
		];
	}

	protected function needs(): array {
		return [ 'api_key' ];
	}

	// $to is always E.164, for example +989120000000.
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r = $this->post_json(
			self::BASE . '/messages',
			[
				'from' => $this->option( 'sender' ),
				'to'   => $to,
				'text' => $text,
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'api_key' ) ]
		);
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '', $id, $this->text_at( $r['json'], 'error.message' ) );
	}

	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$r  = $this->post_json(
			self::BASE . '/templates/' . rawurlencode( $code ),
			[
				'to'     => $to,
				'values' => $this->named( $vars ),
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'api_key' ) ]
		);
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '', $id, $this->text_at( $r['json'], 'error.message' ) );
	}
}
```

```php
add_action( 'fnfe_loaded', function () {
	require_once __DIR__ . '/class-my-sms-gateway.php';
	fnfe_register_sms_provider( 'mygate', 'My_Sms_Gateway' );
} );
```

Helpers: `option( $key )`, `secret( $key )`, `http( $method, $url, $args )`, `post_json()`, `post_form()`, `dig( $json, 'a.b.0' )`, `text_at( $json, $path )`, `first_text( $json, $paths )`, `named( $vars )`, `listed( $vars )`, `one_line( $text )`, `is_unicode( $text )`, `outcome( $response, $ok, $id, $error, $permanent )`, `balance( $response, $value, $unit, $error )`, `unconfirmed_note()`. Implement `status(): ?string` to show a balance.

An unexpected answer with status 2xx is treated as final, because the message may already be on its way and a retry would bill it again.

## Write a WhatsApp gateway

Extend `Channels\WhatsApp\Providers\AbstractWhatsAppProvider`. The text is already in the format of WhatsApp (`*bold*`).

```php
<?php
use AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers\AbstractWhatsAppProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

final class My_WhatsApp_Gateway extends AbstractWhatsAppProvider {

	public function id(): string {
		return 'mywa';
	}

	public function label(): string {
		return 'My WhatsApp gateway';
	}

	public function fields(): array {
		return [ $this->field( 'token', 'secret', 'Token' ) ];
	}

	protected function needs(): array {
		return [ 'token' ];
	}

	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r  = $this->post_json(
			'https://wa.my-gateway.example/messages',
			[
				'to'   => Phone::digits( $to ),
				'body' => $text,
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'token' ) ]
		);
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '', $id, $this->text_at( $r['json'], 'error' ) );
	}
}
```

```php
add_action( 'fnfe_loaded', function () {
	require_once __DIR__ . '/class-my-whatsapp-gateway.php';
	fnfe_register_whatsapp_provider( 'mywa', 'My_WhatsApp_Gateway' );
} );
```

Approved templates: return true from `supports_template()` and implement `send_template( $to, $code, $message )`; variables are in `$message->pattern['vars']`, the language in `$message->pattern['lang']`. Files: return true from `supports_media()` and implement `send_file( $to, $attachment )`; `link( $file )`, `base64( $file )` and `readable( $file )` help.

## Add template tags and filters

Register the resolver with `fnfe_template_tags` and, to show it in the palette, `fnfe_template_tags_catalog`. A resolver gets the part after the colon and the context:

```php
add_filter( 'fnfe_template_tags', function ( $tags ) {
	// {post_meta:price} reads a meta value of the page the form was sent from.
	$tags['post_meta'] = function ( $arg, $context ) {
		$page = (int) ( $context->submission->meta['queried_id'] ?? 0 );
		$allowed = [ 'price', 'sku' ];
		return $page && in_array( $arg, $allowed, true ) ? (string) get_post_meta( $page, $arg, true ) : '';
	};
	return $tags;
} );
```

Return plain text. It is escaped for the format of the channel and never parsed again. Built-in filters: `upper`, `lower`, `title`, `trim`, `truncate:N`, `default:TEXT`, `digits:en`, `digits:fa`, `nl2space`, `first`, `last`, `join:SEP`, `count`, `urlencode`, `date:FORMAT`, `mask`, `phone`, `number`, `strip`.

Render a template yourself:

```php
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;

$submission = SubmissionRepository::find( 12 );
if ( $submission ) {
	$text = Engine::render( '<b>{form_name}</b> {field:email}', Context::for_submission( $submission, 'en' ), 'text' );
}
```

## Add condition operators

Rules know `is`, `is_not`, `contains`, `not_contains`, `starts`, `ends`, `empty`, `not_empty`, `gt`, `lt`, `in`, `not_in` and `matches`. Comparison ignores case and treats Persian and Arabic digits as Latin ones. Add operators with `fnfe_condition_ops`; they are accepted when a rule is saved through the REST API or an import. Conditions are about a field id, about meta (`meta:page_url`) or about a tag (`tag:lang`).

## Write a form source

A source connects another form builder. It tells which forms a document holds, and it hands every submission to `Capture::handle()`.

```php
<?php
use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Contracts\FormSourceInterface;
use AmirhpCom\FormsNotifier\Model\Submission;

final class My_Form_Source implements FormSourceInterface {

	public function id(): string {
		return 'mybuilder';
	}

	public function label(): string {
		return 'My form builder';
	}

	public function is_available(): bool {
		return defined( 'MY_BUILDER_VERSION' );
	}

	public function register(): void {
		add_action( 'my_builder_submitted', [ $this, 'submitted' ], 10, 2 );
	}

	// Optional: texts that occur in the stored data of documents with such a form; they make scans fast.
	public function markers(): array {
		return [ '"widgetType":"my-form"' ];
	}

	public function find_forms( array $elements, int $post_id ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ( $element['widgetType'] ?? '' ) === 'my-form' ) {
				$out[] = [
					'element_id' => (string) $element['id'],
					'name'       => (string) ( $element['settings']['title'] ?? '' ),
					'fields'     => [
						[
							'id'    => 'email',
							'type'  => 'email',
							'label' => 'Email',
						],
					],
					'editor'     => [],
				];
			}
			$out = array_merge( $out, $this->find_forms( $element['elements'] ?? [], $post_id ) );
		}
		return $out;
	}

	public function submitted( $form, $values ): void {
		$draft             = new Submission();
		$draft->source     = $this->id();
		$draft->post_id    = (int) $form['post_id'];
		$draft->element_id = (string) $form['id'];
		$draft->form_key   = $draft->post_id . ':' . $draft->element_id;
		$draft->form_name  = (string) $form['title'];
		foreach ( $values as $id => $value ) {
			$value                = sanitize_textarea_field( (string) $value );
			$draft->fields[ $id ] = [
				'id'       => (string) $id,
				'type'     => 'text',
				'label'    => (string) $id,
				'value'    => $value,
				'values'   => $value === '' ? [] : [ $value ],
				'raw'      => $value,
				'is_file'  => false,
				'redacted' => false,
				'hidden'   => false,
			];
		}
		$draft->meta['via']      = 'auto';
		$draft->meta['page_url'] = (string) wp_get_referer();

		// The second argument identifies the event: a second call with it in the same request does nothing.
		Capture::handle( $draft, 'mybuilder:' . $draft->form_key );
	}
}
```

Copy files before the builder deletes them with `AmirhpCom\FormsNotifier\Attachments\Storage::keep( Attachment $file )`.

## Settings schema

A section is `[ id, tab, title, desc, priority, collapsed, fields ]`. A field:

| Key | Meaning |
|---|---|
| `key` | Absolute dot path. In channels and gateways it is relative |
| `type` | `text`, `textarea`, `number`, `url`, `email`, `tel`, `time`, `toggle`, `select`, `radio`, `multiselect`, `checkboxes`, `secret`, `code`, `template`, `receivers`, `buttons`, `rules`, `repeater`, `keyvalue`, `tags`; without a value: `notice`, `status`, `action`, `guide`, `heading` |
| `label`, `desc`, `placeholder`, `default` | `desc` may hold limited HTML |
| `options` | value => label, or a list of `[ value, label, desc, group ]` |
| `options_source` | `channels`, `ready_channels`, `languages`, `sms_providers`, `whatsapp_providers`, `countries`, `forms`, `roles` |
| `width` | `full`, `half`, `third` |
| `localized` | One value per language (text, textarea, template) |
| `show_if` | List of `[ key, op, value ]`; ops `is`, `is_not`, `in`, `not_in`, `truthy`, `falsy` |
| `attrs` | `min`, `max`, `step`, `rows`, `dir`, `inputmode`, `maxlength` |
| `fields`, `item_label` | Repeater: sub-fields and the one used as the title of a row |
| `action` | `[ channel, id, label, send, confirm ]` |
| `steps` | Guide: list of `[ title, body, code, lang, copy ]` |

The schema decides what can be saved: unknown keys are rejected. Values of type `secret` are encrypted, never returned by the REST API, never exported, and can be replaced by a constant (see [Constants](#constants)).

## REST API

Namespace `fnfe/v1`. Panel routes use the WordPress cookie and the header `X-WP-Nonce` (or an application password). Capabilities: **M** `fnfe_manage`, **F** `fnfe_manage_forms`, **S** `fnfe_view_submissions`, **L** `fnfe_view_logs`; by default all four belong to `manage_options`.

Lists answer `{ items, total, page, per_page }` (`per_page` at most 100). Errors: `{ code: "fnfe_...", message, data: { status, fields } }`. An ActionResult is `{ ok, message, level, rows, columns, data, html }`.

| Route | Cap. | Parameters | Answer |
|---|---|---|---|
| `GET /bootstrap` | any | | Start data of the panel |
| `POST /bootstrap/ui` | any | `theme`, `dismissed` | Saves the display choices of the user |
| `GET /help` | any | | `{ items: [ { id, title, group, body, steps } ] }` |
| `GET /schema` | M | `tab` | Tabs and sections |
| `GET /settings` | M | `tab` | `{ values, secrets: { path: { set, masked, constant } }, defaults }` |
| `POST /settings` | M | `values`, `secrets` (text = set, null = clear) | `{ ok, values, secrets, errors }`; valid keys are saved even if others fail |
| `GET /channels` | any | | Channel list |
| `GET /channels/{id}` | M | | Channel, sections, form fields |
| `POST /channels/{id}/test` | M | `to`, `values`, `secrets` | ActionResult |
| `POST /channels/{id}/action/{action}` | M | `params`, `values`, `secrets` | ActionResult |
| `GET /providers/{channel}` | M | | Gateways |
| `POST /providers/{channel}/{provider}/{action}` | M | action `test` (`to`, `text`, `pattern`) or `status`; `values`, `secrets` | ActionResult |
| `GET /forms` | F | `search`, `status`, `source`, `page`, `per_page`, `orderby`, `order` | Forms and `last_scan` |
| `POST /forms/scan` | F | | `{ found, added, updated, missing, done, documents }` |
| `GET /forms/key/{key}` | F | `key` = `{post id}:{element id}` | Form by its Elementor ids; scans the document when it is unknown |
| `GET /forms/{id}` | F | | Form, fields, config, editor values, effective channels |
| `PUT /forms/{id}` | F | `config` | `{ ok, config, errors }` |
| `DELETE /forms/{id}` | F | `with_submissions` | Only forms that no longer exist |
| `POST /forms/{id}/test` | F | `channels`, `lang` | Sends a sample submission |
| `GET /submissions` | S | `form_id`, `status`, `search`, `from`, `to`, `order`, `page`, `per_page` | Submissions and `counts` per status. Without a status, spam and trash are left out |
| `GET /submissions/{id}` | S | | Submission and its messages |
| `PATCH /submissions/{id}` | S | `status` | `{ ok, submission }` |
| `DELETE /submissions/{id}` | M | | `{ ok }` |
| `POST /submissions/{id}/resend` | L | `channels` | `{ ok, queued }` |
| `POST /submissions/bulk` | S (delete: M) | `action` (read, done, spam, trash, delete), `ids` | `{ ok, affected }` |
| `GET /submissions/export` | S | filters as above | CSV |
| `GET /submissions/import` | M | `form_id` | What Elementor holds and how much was imported |
| `POST /submissions/import` | M | `form_id`, `cursor` | One batch; repeat with the returned `cursor` until `done` |
| `GET /file/{id}` | signed link or S | `t`, `e` | The file, always as a download |
| `POST /template/preview` | F or M | `template`, `subject`, `channel`, `purpose`, `form_id`, `submission_id`, `lang`, `buttons` | `{ text, parts, length, limit, plain, subject, buttons, warnings }` |
| `GET /template/tags` | F or M | `form_id`, `purpose` | Tag palette |
| `POST /strings/sync` | M | | Registers all texts with WPML or Polylang: `{ ok, provider, registered }` |
| `GET /messages` | L | `status`, `channel`, `purpose`, `form_id`, `submission_id`, `search`, `from`, `to`, `order`, `page`, `per_page` | Message log |
| `GET /messages/{id}` | L | | One message with body and answer |
| `POST /messages/{id}/retry` | L | | Sends it now |
| `POST /messages/bulk` | L | `action` (retry, cancel, delete), `ids` | `{ ok, affected }` |
| `GET /messages/export` | L | filters as above | CSV |
| `POST /queue/run` | L | | `{ processed, sent, failed, remaining }` |
| `GET /stats` | any | `range` (today, 7d, 30d, all) | Numbers, health, queue |
| `POST /alerts/test` | M | `values`, `secrets` | ActionResult |
| `POST /digest/send` | M | `values`, `secrets` | ActionResult |
| `GET /health/bots` | Site Health | | Async test of the bots |
| `POST /bot/{platform}/hook/{secret}` | secret path and header | update of the messenger | 200 when the secret is valid, 403 otherwise |
| `GET /bots/{platform}/status` | M | | Connection state |
| `POST /bots/{platform}/connect` | M | `mode` (auto, webhook, polling, off), `values`, `secrets` | Connection state |
| `POST /bots/{platform}/poll` | M | | `{ ok, updates, chats }` |
| `GET /bots/{platform}/chats` | M or F | `type`, `status`, `search`, `with_topics`, `page`, `per_page` | Known chats |
| `PATCH /bots/{platform}/chats/{id}` | M | `title`, `topic_name` | `{ ok, chat }` |
| `DELETE /bots/{platform}/chats/{id}` | M | | `{ ok }` |
| `POST /bots/{platform}/link` | M | `user_id` | Link that makes a person an admin of the bot (15 minutes, once) |
| `GET /bots/{platform}/admins` | M | | Admins |
| `DELETE /bots/{platform}/admins/{uid}` | M | | `{ ok }` |
| `GET /tools/export` | M | `include` (settings, forms) | JSON without secrets |
| `POST /tools/import` | M | `data`, `mode` (merge, replace) | `{ ok, imported, warnings }` |
| `GET /tools/system` | M | | System information |
| `GET /logs` | M | `level`, `context`, `search`, `page`, `per_page` | System log |
| `DELETE /logs` | M | | Empties the system log |
| `GET /app/setup` | M | | Addresses and checklist of the mini app |
| `POST /app/revoke` | M | | Ends every mini app session |
| `POST /app/auth` | launch data | `platform`, `init_data` | `{ token, expires, user, perms, site }` |
| `GET /app/digest` | app token | `range` | Overview |
| `GET /app/forms` | app token | `search`, `page` | Forms |
| `GET /app/forms/{id}/submissions` | app token | `status`, `search`, `page` | Submissions |
| `GET /app/submissions/{id}` | app token | | Submission and messages |
| `POST /app/submissions/{id}/status` | app token, act | `status` | `{ ok, submission }` |
| `GET /app/messages` | app token | `status`, `page` | Messages |
| `POST /app/messages/{id}/retry` | app token, act | | `{ ok, message }` |

`any` means any of the four capabilities. The app token travels in the header `X-FNFE-Token`. `values` and `secrets` on action routes are unsaved panel values that apply to this request only.

```bash
curl -u 'admin:APPLICATION PASSWORD' 'https://example.com/wp-json/fnfe/v1/submissions?status=new&per_page=5'
```

## JavaScript API

The panel is plain JavaScript without a build step. `window.fnfe` offers:

| Member | Purpose |
|---|---|
| `h( tag, props, ...children )`, `frag( ...children )`, `mount( container, node )` | Build DOM. There is no way to insert raw HTML |
| `safeHtml( html )` | Limited HTML as a fragment; everything else becomes text |
| `api.get/post/put/patch/del( path, data )`, `api.download( path, params, filename )` | REST calls, rejected with `{ code, message, status, fields }` |
| `store( initial )`, `bus.on/off/emit` | State and events |
| `router.add/start/go/current/guard` | Hash router |
| `toast( message, type, opts )` | Short notice |
| `path.get/set/del`, `uid( prefix )`, `debounce( fn, ms )` | Helpers |
| `fmt.date/bytes/number/relative` | Localised output, Jalali when the site uses it |
| `fields.register( type, renderer )`, `fields.render()`, `fields.form()` | Field types. A renderer is `( field, value, onChange, ctx ) => HTMLElement` |
| `screens.register( pattern, { title, cap, render } )` | Screens. `render( container, params, shell )` may return a cleanup function |
| `ui`, `icon( name, size )` | Components and icons of the panel |
| `boot` | Answer of `GET /bootstrap` |

Hooks (`wp.hooks`):

| Hook | Kind | Parameters |
|---|---|---|
| `fnfe.fieldRenderers` | filter | map of field type => renderer |
| `fnfe.channelScreen.sections` | filter | sections, channel id |
| `fnfe.routes` | filter | navigation entries `[ { path, label, icon, cap } ]` |
| `fnfe.screen.rendered` | action | pattern, container |

Load a script on the panel and add a screen:

```php
add_action( 'admin_enqueue_scripts', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( strpos( $page, 'fnfe' ) === 0 ) {
		wp_enqueue_script( 'my-fnfe', plugins_url( 'panel.js', __FILE__ ), [ 'fnfe-fields', 'fnfe-components' ], '1.0', [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}
}, 5 );
```

```js
( function () {
	'use strict';
	const { h, mount, api, screens } = window.fnfe;

	wp.hooks.addFilter( 'fnfe.routes', 'my/report', ( routes ) =>
		routes.concat( { path: 'my-report', label: 'Report', icon: 'calendar', cap: 'logs' } )
	);

	screens.register( 'my-report', {
		title: 'Report',
		cap: 'logs',
		render( container ) {
			api.get( 'stats', { range: '7d' } ).then( ( stats ) =>
				mount( container, h( 'pre', null, JSON.stringify( stats, null, 2 ) ) )
			);
		},
	} );

	// A field type for the settings schema.
	wp.hooks.addFilter( 'fnfe.fieldRenderers', 'my/stars', ( renderers ) =>
		Object.assign( {}, renderers, {
			stars: ( field, value, onChange, ctx ) =>
				h( 'input', { type: 'range', min: 0, max: 5, value: value || 0, id: ctx.id, onInput: ( e ) => onChange( Number( e.target.value ) ) } ),
		} )
	);
} )();
```

A new field type needs a sanitiser the server knows: give the field a `sanitize` value (`text`, `int`, `json` and so on).

## WP-CLI

| Command | Purpose |
|---|---|
| `wp fnfe status [--format=]` | Channels, queue and health |
| `wp fnfe forms scan`, `wp fnfe forms list [--format=]` | Find and list forms |
| `wp fnfe test <channel> [--to=] [--text=]` | Test message |
| `wp fnfe send --form=<id or key> [--field=id=value ...] [--channels=a,b]` | Test submission |
| `wp fnfe queue run [--limit=]`, `queue list [--status=]`, `queue retry <id>`, `queue retry --all-failed` | Queue |
| `wp fnfe log list [--status=] [--channel=] [--limit=]`, `wp fnfe log purge --days=` | Message log |
| `wp fnfe bot info <platform>`, `bot webhook set <platform>`, `bot webhook delete <platform>`, `bot poll <platform>` | Bots |
| `wp fnfe digest [--send]` | Show or send the digest |
| `wp fnfe strings sync` | Register texts with WPML or Polylang |
| `wp fnfe export [--file=]`, `wp fnfe import <file> [--mode=merge]` (or `replace`) | Move settings; secrets are never exported |
| `wp fnfe cleanup` | Apply the retention settings now |

```bash
wp fnfe send --form=45:a1b2c3d --field=name=Sara --field=email=sara@example.com --channels=telegram
wp fnfe queue retry --all-failed
```

A real cron job makes the queue independent of visitors:

```
* * * * * cd /var/www/html && wp fnfe queue run --quiet
```

## Constants

Every secret can be set in `wp-config.php`. The name is `FNFE_` plus the setting path in capitals, with dots and hyphens as underscores. Such a field is locked in the panel, which also shows the name.

| Constant | Setting |
|---|---|
| `FNFE_CHANNELS_TELEGRAM_TOKEN` | Telegram bot token |
| `FNFE_CHANNELS_BALE_TOKEN` | Bale bot token |
| `FNFE_CHANNELS_SMS_PROVIDERS_KAVENEGAR_API_KEY` | API key of an SMS gateway, here Kavenegar |
| `FNFE_CHANNELS_WHATSAPP_PROVIDERS_META_TOKEN` | Token of the WhatsApp Cloud API |
| `FNFE_CHANNELS_PUSHOVER_APP_TOKEN` | Pushover application token |

```php
define( 'FNFE_CHANNELS_TELEGRAM_TOKEN', '123456789:ABC-DEF' );
```

Set by you, in `wp-config.php`:

| Constant | |
|---|---|
| `FNFE_FILES_DIR` | Absolute folder for stored files, best outside the public folder: `define( 'FNFE_FILES_DIR', '/home/site/private/fnfe-files' );`. Every site of a network but the main one gets the subfolder `sites/<id>`. Files stored before are not moved and keep working. Uninstall with "Delete all data" removes only the files the plugin created there (stored copies, `index.php`, `.htaccess`, `probe.txt`) and the empty year and month folders. |

Defined by the plugin: `FNFE_VERSION`, `FNFE_DB_VERSION`, `FNFE_FILE`, `FNFE_DIR`, `FNFE_URL`, `FNFE_BASENAME`, `FNFE_MIN_PHP`, `FNFE_MIN_WP`.

Respected constants of WordPress: `DISABLE_WP_CRON`, `SCRIPT_DEBUG`, `WP_DEBUG_LOG` (only with "Detailed system log").

## Database

Six tables, each with the prefix of WordPress in front. Dates are UTC. JSON is stored as text. Use the repositories in `AmirhpCom\FormsNotifier\Repository` instead of SQL: names and columns may change with a new `FNFE_DB_VERSION`.

| Table | Columns |
|---|---|
| `fnfe_forms` | id, form_key (unique), post_id, element_id, source, name, post_title, post_type, status (active, missing), fields (JSON), config (JSON), editor (JSON), submissions_count, last_submission_gmt, last_seen_gmt, created_gmt, updated_gmt |
| `fnfe_submissions` | id, uuid (unique), form_id, form_key, form_name, source, status, lang, fields (JSON), meta (JSON), search_text, content_hash, user_id, user_ip, files_count, elementor_submission_id, delivery_status, created_gmt, updated_gmt |
| `fnfe_files` | id, submission_id, field_id, name, path, url, mime, ext, size, is_stored, token, created_gmt |
| `fnfe_messages` | id, submission_id, form_id, channel, provider, purpose, recipient, recipient_label, lang, status (queued, sending, sent, retry, failed, skipped, cancelled), attempts, max_attempts, next_attempt_gmt, locked_until_gmt, lock_token, subject, body, payload (JSON), response, error, remote_id, http_code, parent_id, created_gmt, updated_gmt, sent_gmt |
| `fnfe_chats` | id, platform, chat_id, thread_id, type, title, username, topic_name, is_forum, status (active, left, kicked, migrated, closed), bot_role, user_id, lang, meta (JSON), first_seen_gmt, last_seen_gmt; unique: platform, chat_id, thread_id |
| `fnfe_log` | id, level, context, message, data (JSON), created_gmt |

## Options, user meta, cron

| Name | Kind | Content |
|---|---|---|
| `fnfe_settings` | option | Settings that differ from the defaults |
| `fnfe_secrets` | option, not autoloaded | Path => encrypted value (libsodium, key from the salts of the site) |
| `fnfe_state` | option, not autoloaded | Bot state, cursors, last runs |
| `fnfe_db_version`, `fnfe_version` | options | Versions |
| `fnfe_lock_{name}` | options | Locks |
| `fnfe_ui`, `fnfe_bot_ids` | user meta | Display choices, linked messenger accounts |
| `fnfe_queue_tick` | cron, every minute | Queue; an Action Scheduler action when that library is present |
| `fnfe_queue_run` | single event | Queue run on request |
| `fnfe_bot_poll` | cron, every minute | Only while a bot uses regular checks |
| `fnfe_scan_forms` | cron, twice a day | Form scan |
| `fnfe_digest_tick` | cron, hourly | Digest when it is due |
| `fnfe_cleanup` | cron, daily | Retention |

Stored files are in `fnfe-files` inside the uploads folder, or in the folder of `FNFE_FILES_DIR` / `fnfe_files_dir`, under `<year>/<month>/<32 hex characters>.<ext>`, with `index.php` in every level, an `.htaccess` and `probe.txt` (the Site Health check requests it once a day to learn whether the web server hands out the folder; transient `fnfe_files_exposed`, forgotten when the settings are saved). Nothing is deleted when the plugin is removed unless "Delete all data when the plugin is deleted" is on.
