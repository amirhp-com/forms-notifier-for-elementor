<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

defined( 'ABSPATH' ) || exit;

/** Counters: empty. */
final class Stats {

	/**
	 * Numbers for a range.
	 *
	 * @param string $range today | 7d | 30d | all.
	 * @return array{range:string,submissions:int,messages:array{sent:int,failed:int,queued:int},by_channel:array<string,array{sent:int,failed:int}>,by_form:array<int,array{form_id:int,name:string,submissions:int,failed:int}>,series:array<int,array{date:string,submissions:int,sent:int,failed:int}>}
	 */
	public static function summary( string $range = '7d' ): array {
		return [
			'range'       => $range,
			'submissions' => 0,
			'messages'    => [
				'sent'   => 0,
				'failed' => 0,
				'queued' => 0,
			],
			'by_channel'  => [],
			'by_form'     => [],
			'series'      => [],
		];
	}

	/**
	 * Health overview.
	 *
	 * @return array[] [ 'id' =>, 'label' =>, 'status' => 'good|warning|critical', 'message' => ]
	 */
	public static function health(): array {
		return [];
	}
}
