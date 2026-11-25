<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class AddressTest extends TestCase {

	const PURE = true;

	public function test_table(): void {
		$cases = [
			'123456'                                 => '123456',
			' 123456 '                               => '123456',
			'-1001234567890'                         => '-1001234567890',
			'-1001234567890/45'                      => '-1001234567890/45',
			'-1001234567890_45'                      => '-1001234567890/45',
			'-1001234567890 / 45'                    => '-1001234567890/45',
			'-1001234567890/0'                       => '-1001234567890',
			'-۱۰۰۱۲۳۴۵۶۷۸۹۰/۴۵'                      => '-1001234567890/45',
			'-١٠٠١٢٣٤٥٦٧٨٩٠'                         => '-1001234567890',
			"\u{200E}-1001234567890"                 => '-1001234567890',
			'@my_channel'                            => '@my_channel',
			'@my_channel_45'                         => '@my_channel_45',
			'@my_channel/45'                         => '@my_channel/45',
			'https://t.me/c/1234567890/45'           => '-1001234567890/45',
			't.me/c/1234567890/45/678'               => '-1001234567890/45',
			'https://t.me/c/1234567890'              => '-1001234567890',
			'https://t.me/my_channel'                => '@my_channel',
			'https://t.me/my_channel/12'             => '@my_channel',
			'https://t.me/my_channel/45/12'          => '@my_channel/45',
			'ble.ir/my_channel'                      => '@my_channel',
			// Invalid.
			''                                       => '',
			'0'                                      => '',
			'abc'                                    => '',
			'@ab'                                    => '',
			'@1abc'                                  => '',
			'12/ab'                                  => '',
			'--12'                                   => '',
			'12 13'                                  => '',
			'https://t.me/+AbCdEfGh'                 => '',
			'https://evil.example/c/123/4'           => '',
			"123\n456"                               => '',
			'<b>12</b>'                              => '',
		];
		foreach ( $cases as $raw => $expected ) {
			$this->assertSame( $expected, BotClient::normalize_address( (string) $raw ), (string) $raw );
		}
		$this->assertSame( [ 'chat' => '-100', 'thread' => '5' ], BotClient::parse_address( '-100/5' ) );
		$this->assertNull( BotClient::parse_address( 'x' ) );
		$this->assertSame( '1', BotClient::format_address( '1', 0 ) );
		$this->assertSame( '1/2', BotClient::format_address( '1', 2 ) );
	}
}
