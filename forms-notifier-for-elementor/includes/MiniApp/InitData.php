<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

defined( 'ABSPATH' ) || exit;

/** Launch data validation: nothing is accepted yet. */
final class InitData {

	/**
	 * Validate launch data from the messenger client.
	 *
	 * @param string   $platform  telegram | bale.
	 * @param string   $init_data Raw query string exactly as received.
	 * @param int|null $max_age   Accepted age in seconds.
	 * @return array{ok:bool,error:string,user:array{id:string,first_name:string,last_name:string,username:string,language_code:string,photo_url:string},auth_date:int,start_param:string,chat_type:string}
	 */
	public static function validate( string $platform, string $init_data, ?int $max_age = null ): array {
		unset( $platform, $max_age );
		return [
			'ok'          => false,
			'error'       => $init_data === '' ? 'missing' : 'bad_hash',
			'user'        => [
				'id'            => '',
				'first_name'    => '',
				'last_name'     => '',
				'username'      => '',
				'language_code' => '',
				'photo_url'     => '',
			],
			'auth_date'   => 0,
			'start_param' => '',
			'chat_type'   => '',
		];
	}

	/**
	 * HMAC check only.
	 *
	 * @param string $init_data Raw query string.
	 * @param string $bot_token Bot token.
	 */
	public static function check_hash( string $init_data, string $bot_token ): bool {
		unset( $init_data, $bot_token );
		return false;
	}
}
