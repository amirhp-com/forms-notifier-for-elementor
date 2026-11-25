<?php
/**
 * Help topics of templates and webhooks.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Reference texts generated from the code they describe. */
final class HelpTopics {

	/**
	 * Filter fnfe_help_topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function add( $topics ) {
		if ( ! is_array( $topics ) ) {
			return $topics;
		}
		$topics['template-tags'] = [
			'title' => __( 'Templates and tags', 'forms-notifier-for-elementor' ),
			'group' => __( 'Templates & tags', 'forms-notifier-for-elementor' ),
			'body'  => self::tags(),
			'steps' => self::template_steps(),
		];
		$topics['webhooks']      = [
			'title' => __( 'Webhook payload and signature', 'forms-notifier-for-elementor' ),
			'group' => __( 'Webhooks', 'forms-notifier-for-elementor' ),
			'body'  => '<strong>' . esc_html__( 'Retries', 'forms-notifier-for-elementor' ) . '</strong><br>'
				. esc_html__( 'Answer with any 2xx status. A timeout, 408, 425, 429 or 5xx is tried again later; any other answer is final. Every attempt carries the same X-FNFE-Delivery, so you can drop repeated requests.', 'forms-notifier-for-elementor' ),
			'steps' => self::webhook_steps(),
		];
		return $topics;
	}

	/** Tag reference. */
	private static function tags(): string {
		$groups = [
			'fields'     => __( 'Fields', 'forms-notifier-for-elementor' ),
			'submission' => __( 'Submission', 'forms-notifier-for-elementor' ),
			'form'       => _x( 'Form', 'noun', 'forms-notifier-for-elementor' ),
			'links'      => __( 'Links', 'forms-notifier-for-elementor' ),
			'page'       => __( 'Page', 'forms-notifier-for-elementor' ),
			'user'       => __( 'Visitor', 'forms-notifier-for-elementor' ),
			'site'       => __( 'Site', 'forms-notifier-for-elementor' ),
			'date'       => __( 'Date and time', 'forms-notifier-for-elementor' ),
			'system'     => __( 'Alerts and digest', 'forms-notifier-for-elementor' ),
		];
		$lines  = [];
		foreach ( Tags::catalog( 0, '' ) as $entry ) {
			$group = isset( $groups[ $entry['group'] ] ) ? $entry['group'] : 'fields';

			$lines[ $group ][] = '<code>' . esc_html( $entry['tag'] ) . '</code> ' . esc_html( $entry['label'] . ( $entry['desc'] === '' ? '' : '. ' . $entry['desc'] ) );
		}
		$html = '';
		foreach ( $groups as $group => $label ) {
			if ( ! empty( $lines[ $group ] ) ) {
				$html .= '<strong>' . esc_html( $label ) . '</strong><br>' . implode( '<br>', $lines[ $group ] ) . '<br><br>';
			}
		}
		$filters = [];
		foreach ( Filters::catalog() as $filter => $desc ) {
			$filters[] = '<code>|' . esc_html( $filter ) . '</code> ' . esc_html( $desc );
		}
		return $html . '<strong>' . esc_html__( 'Filters', 'forms-notifier-for-elementor' ) . '</strong><br>' . implode( '<br>', $filters );
	}

	/** Template examples. */
	private static function template_steps(): array {
		return [
			[
				'title' => __( 'Put values into the text', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'A tag is replaced by its value. Filters after | change the value. Whatever a visitor types is shown as text and is never read as a tag or as formatting. Write \{ for a real brace.', 'forms-notifier-for-elementor' ),
				'code'  => "{field:name|upper}\n{field:message|truncate:200}\n{field:phone|default:\"no phone\"}",
				'lang'  => 'text',
				'copy'  => true,
			],
			[
				'title' => __( 'Show a part only sometimes', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Conditions: == != contains !contains > < in matches. Join two with "and" or "or". An exclamation mark in front means "is empty".', 'forms-notifier-for-elementor' ),
				'code'  => "{if field:plan == \"vip\"}\nVIP request\n{elseif field:plan in \"gold, silver\"}\nPremium request\n{else}\nNormal request\n{/if}\n{if files_count > 0}Files: {files}{/if}",
				'lang'  => 'text',
				'copy'  => true,
			],
			[
				'title' => __( 'Format the text', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Write formatting once; every channel gets its own version.', 'forms-notifier-for-elementor' ),
				'code'  => "<b>{form_name}</b>\n<i>{datetime}</i>\n<a href=\"{page_url}\">{page_title}</a>\n<code>{field:code}</code>\n<blockquote>{field:message}</blockquote>\n<spoiler>{field:phone}</spoiler>",
				'lang'  => 'html',
				'copy'  => true,
			],
		];
	}

	/** Payload and verification snippets. */
	private static function webhook_steps(): array {
		$json = <<<'JSON'
{
  "event": "submission.created",
  "delivery": "8f14e45fceea167a5a36dedd4bea2543",
  "sent_at": "2026-01-01T10:00:00Z",
  "site": { "name": "Example", "url": "https://example.com" },
  "form": { "id": 12, "key": "45:a1b2c3d", "name": "Contact", "source": "pro" },
  "submission": {
    "id": 981, "uuid": "8f14e45fceea167a5a36dedd4bea2543", "status": "new", "lang": "en",
    "created_at": "2026-01-01T09:59:58Z", "page_url": "https://example.com/contact/", "page_title": "Contact",
    "user_id": 0, "user_ip": "203.0.113.5", "admin_url": "https://example.com/wp-admin/admin.php?page=fnfe#/submissions/981"
  },
  "fields": { "name": "Sara", "email": "sara@example.com", "topics": [ "sales", "support" ] },
  "fields_meta": [ { "id": "name", "label": "Name", "type": "text", "value": "Sara" } ],
  "files": [ { "field": "cv", "name": "cv.pdf", "size": 48211, "mime": "application/pdf", "url": "https://example.com/file" } ],
  "text": "plain text of the message"
}
JSON;
		$php  = <<<'CODE'
$body      = file_get_contents( 'php://input' );
$timestamp = $_SERVER['HTTP_X_FNFE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_FNFE_SIGNATURE'] ?? '';
$expected  = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, 'YOUR SECRET' );
$fresh     = abs( time() - (int) $timestamp ) < 300;
if ( ! $fresh || ! hash_equals( $expected, $signature ) ) {
    http_response_code( 401 );
    exit;
}
CODE;
		$node = <<<'CODE'
const crypto = require('crypto');
// rawBody: the request body exactly as received, before JSON parsing
function verify(rawBody, headers, secret) {
  const t = headers['x-fnfe-timestamp'];
  const expected = 'v1=' + crypto.createHmac('sha256', secret).update(t + '.' + rawBody).digest('hex');
  const given = headers['x-fnfe-signature'] || '';
  return Math.abs(Date.now() / 1000 - Number(t)) < 300
    && given.length === expected.length
    && crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected));
}
CODE;
		$n8n  = <<<'CODE'
// Code node after a Webhook node with "Raw Body" switched on
const crypto = require('crypto');
const item = $input.first();
const raw = Buffer.from(item.binary.data.data, 'base64').toString('utf8');
const h = item.json.headers;
const expected = 'v1=' + crypto.createHmac('sha256', 'YOUR SECRET').update(h['x-fnfe-timestamp'] + '.' + raw).digest('hex');
if (h['x-fnfe-signature'] !== expected) throw new Error('Wrong signature');
return [{ json: JSON.parse(raw) }];
CODE;
		return [
			[
				'title' => __( 'Standard JSON', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Zapier and Pabbly get the same data as flat keys: form_name, field_name, file_1_url and so on.', 'forms-notifier-for-elementor' ),
				'code'  => $json,
				'lang'  => 'json',
				'copy'  => true,
			],
			[
				'title' => __( 'Check the signature in PHP', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Set a signing secret for the endpoint. The signature covers the time and the raw body.', 'forms-notifier-for-elementor' ),
				'code'  => $php,
				'lang'  => 'php',
				'copy'  => true,
			],
			[
				'title' => __( 'Check the signature in Node.js', 'forms-notifier-for-elementor' ),
				'body'  => '',
				'code'  => $node,
				'lang'  => 'js',
				'copy'  => true,
			],
			[
				'title' => __( 'Check the signature in n8n', 'forms-notifier-for-elementor' ),
				'body'  => '',
				'code'  => $n8n,
				'lang'  => 'js',
				'copy'  => true,
			],
		];
	}
}
