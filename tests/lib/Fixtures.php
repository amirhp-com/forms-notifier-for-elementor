<?php
/**
 * Fixtures: fake data only.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;

final class Fixtures {

	const TOKEN = '123456:TEST-TOKEN';
	const PHONE = '+989120000000';
	const CHAT  = '-1001234567890';
	const EMAIL = 'sara@example.com';

	/** Field array with every key. */
	public static function field( string $id, $value, string $type = 'text', string $label = '' ): array {
		$values = is_array( $value ) ? array_values( $value ) : ( $value === '' ? [] : [ (string) $value ] );
		return [
			'id'       => $id,
			'type'     => $type,
			'label'    => $label === '' ? ucfirst( $id ) : $label,
			'value'    => implode( ', ', $values ),
			'values'   => $values,
			'raw'      => $value,
			'is_file'  => $type === 'upload',
			'redacted' => false,
			'hidden'   => false,
		];
	}

	/** Submission with Persian text, emoji and several values. */
	public static function submission( array $overrides = [] ): Submission {
		return Submission::from_array(
			array_merge(
				[
					'form_key'    => '45:a1b2c3d',
					'post_id'     => 45,
					'element_id'  => 'a1b2c3d',
					'source'      => 'pro',
					'form_name'   => 'Contact',
					'lang'        => 'en',
					'created_gmt' => '2026-01-01 10:00:00',
					'fields'      => [
						'name'    => self::field( 'name', 'سارا 🙂' ),
						'email'   => self::field( 'email', self::EMAIL, 'email' ),
						'phone'   => self::field( 'phone', self::PHONE, 'tel' ),
						'topics'  => self::field( 'topics', [ 'sales', 'support' ], 'checkbox' ),
						'message' => self::field( 'message', "First line\nSecond <b>line</b> & {site_url}", 'textarea' ),
						'empty'   => self::field( 'empty', '' ),
					],
					'meta'        => [
						'page_url'   => 'https://example.com/contact/',
						'page_title' => 'Contact us',
						'user_id'    => 0,
						'user_ip'    => '203.0.113.5',
						'via'        => 'auto',
						'utm'        => [ 'source' => 'newsletter' ],
					],
				],
				$overrides
			)
		);
	}

	/** Attachment on disk inside the temporary uploads directory. */
	public static function file( string $name = 'cv.pdf', string $content = 'fake file', string $field_id = 'cv' ): Attachment {
		$path = Db::uploads() . '/' . uniqid( 'f', true ) . '-' . $name;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$types = [
			'pdf' => 'application/pdf',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'txt' => 'text/plain',
		];
		$ext   = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return Attachment::from_array(
			[
				'field_id' => $field_id,
				'name'     => $name,
				'path'     => $path,
				'mime'     => $types[ $ext ] ?? 'application/octet-stream',
				'size'     => strlen( $content ),
				'ext'      => $ext,
			]
		);
	}

	/** Receiver in the stored shape. */
	public static function receiver( string $address, array $overrides = [] ): array {
		return array_merge(
			[
				'id'      => 'r_' . substr( md5( $address ), 0, 8 ),
				'address' => $address,
				'label'   => 'Receiver ' . $address,
				'enabled' => true,
				'lang'    => '',
				'langs'   => [],
				'meta'    => [],
			],
			$overrides
		);
	}

	/** Form config. */
	public static function config( array $data = [], int $form_id = 1, string $form_key = '45:a1b2c3d' ): FormConfig {
		return new FormConfig( $form_id, $form_key, $data );
	}

	/** Message. */
	public static function message( string $channel = 'fake', string $address = 'desk', string $body = 'Hello' ): Message {
		$message        = new Message( $channel, new Recipient( $channel, $address, 'Desk' ) );
		$message->body  = $body;
		$message->plain = $body;
		return $message;
	}

	/** Bot update: private /start. */
	public static function update_start( int $update_id = 1, string $payload = '' ): array {
		return [
			'update_id' => $update_id,
			'message'   => [
				'message_id' => 10,
				'date'       => 1767261600,
				'from'       => [
					'id'            => 111111,
					'is_bot'        => false,
					'first_name'    => 'Sara',
					'username'      => 'sara_example',
					'language_code' => 'fa',
				],
				'chat'       => [
					'id'         => 111111,
					'type'       => 'private',
					'first_name' => 'Sara',
					'username'   => 'sara_example',
				],
				'text'       => trim( '/start ' . $payload ),
			],
		];
	}

	/** Bot update: message in a forum topic. */
	public static function update_topic( int $update_id = 2 ): array {
		return [
			'update_id' => $update_id,
			'message'   => [
				'message_id'        => 11,
				'message_thread_id' => 45,
				'is_topic_message'  => true,
				'date'              => 1767261600,
				'from'              => [
					'id'         => 111111,
					'is_bot'     => false,
					'first_name' => 'Sara',
				],
				'chat'              => [
					'id'       => (int) self::CHAT,
					'type'     => 'supergroup',
					'title'    => 'Sales',
					'is_forum' => true,
				],
				'reply_to_message'  => [
					'message_id'          => 45,
					'forum_topic_created' => [
						'name' => 'Leads',
					],
				],
				'text'              => '/id',
			],
		];
	}

	/** Native field definitions of a form, as found by a scan. */
	public static function form_fields(): array {
		return [
			[
				'id'    => 'name',
				'type'  => 'text',
				'label' => 'Name',
			],
			[
				'id'    => 'email',
				'type'  => 'email',
				'label' => 'Email',
			],
			[
				'id'      => 'topics',
				'type'    => 'checkbox',
				'label'   => 'Topics',
				'options' => [ 'sales', 'support' ],
			],
		];
	}
}
