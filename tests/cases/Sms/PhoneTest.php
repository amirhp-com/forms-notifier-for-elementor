<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Phone\DialCodes;
use AmirhpCom\FormsNotifier\Phone\Phone;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PhoneTest extends TestCase {

	const PURE = true;

	public function tearDown(): void {
		Settings::clear_overlay();
	}

	/** raw, hint, expected */
	private function table(): array {
		return [
			// Iranian mobiles.
			[ '09120000000', 'IR', '+989120000000' ],
			[ '9120000000', 'IR', '+989120000000' ],
			[ '989120000000', 'IR', '+989120000000' ],
			[ '989120000000', '', '+989120000000' ],
			[ '989120000000', 'DE', '+989120000000' ],
			[ '0989120000000', 'IR', '+989120000000' ],
			[ '0989120000000', '', '+989120000000' ],
			[ '+989120000000', '', '+989120000000' ],
			[ '+989120000000', 'US', '+989120000000' ],
			[ '00989120000000', '', '+989120000000' ],
			[ '+98 0912 000 0000', '', '+989120000000' ],
			[ '0098 0912 000 0000', '', '+989120000000' ],
			[ '+98 (0) 912 000 00 00', '', '+989120000000' ],
			[ '(+98) 912-000-0000', '', '+989120000000' ],
			[ '۰۹۱۲۰۰۰۰۰۰۰', 'IR', '+989120000000' ],
			[ '٠٩١٢٠٠٠٠٠٠٠', 'IR', '+989120000000' ],
			[ '۰۹۱۲ ۰۰۰ ۰۰۰۰', 'IR', '+989120000000' ],
			[ '+۹۸۹۱۲۰۰۰۰۰۰۰', '', '+989120000000' ],
			[ '۰۰۹۸۹۱۲۰۰۰۰۰۰۰', '', '+989120000000' ],
			[ '0912-000-0000', 'IR', '+989120000000' ],
			[ '0912.000.0000', 'IR', '+989120000000' ],
			[ " 0912\t0000000\n", 'IR', '+989120000000' ],
			[ 'tel:+989120000000', '', '+989120000000' ],
			[ "\u{200E}+989120000000", '', '+989120000000' ],
			[ '＋989120000000', '', '+989120000000' ],
			[ '09120000000', '98', '+989120000000' ],
			[ '09120000000', '+98', '+989120000000' ],
			[ '09120000000', '0098', '+989120000000' ],
			[ '09120000000', '۹۸', '+989120000000' ],
			[ '09120000000', 'ir', '+989120000000' ],
			// Iranian landlines.
			[ '02100000000', 'IR', '+982100000000' ],
			[ '2100000000', 'IR', '+982100000000' ],
			[ '+98 21 0000 0000', '', '+982100000000' ],
			[ '021-00000000 ext 12', 'IR', '+982100000000' ],
			[ '021-00000000 داخلی ۱۲', 'IR', '+982100000000' ],
			// Iranian numbers of the wrong length.
			[ '0912000000', 'IR', null ],
			[ '091200000000', 'IR', null ],
			[ '+98912000000', '', null ],
			[ '+9891200000000', '', null ],
			[ '88000000', 'IR', null ],
			[ '+98 0 0', '', null ],
			// Other countries with a hint.
			[ '05320000000', 'TR', '+905320000000' ],
			[ '5320000000', 'TR', '+905320000000' ],
			[ '905320000000', 'TR', '+905320000000' ],
			[ '0501000000', 'AE', '+971501000000' ],
			[ '971501000000', 'AE', '+971501000000' ],
			[ '(555) 010-0000', 'US', '+15550100000' ],
			[ '1 555 010 0000', 'US', '+15550100000' ],
			[ '555 010 0000', 'CA', '+15550100000' ],
			[ '020 7946 0000', 'GB', '+442079460000' ],
			[ '0151 00000000', 'DE', '+4915100000000' ],
			[ '4915100000000', 'DE', '+4915100000000' ],
			[ '06 00 00 00 00', 'FR', '+33600000000' ],
			[ '06 0000 0000', 'IT', '+390600000000' ],
			[ '320 000 0000', 'IT', '+393200000000' ],
			[ '07 00 00 00 00', 'CI', '+2250700000000' ],
			[ '9100000000', 'IN', '+919100000000' ],
			[ '09100000000', 'IN', '+919100000000' ],
			[ '8 916 000 00 00', 'RU', '+79160000000' ],
			[ '8 701 000 00 00', 'KZ', '+77010000000' ],
			[ '55 99000 0000', 'BR', '+5555990000000' ],
			[ '5511990000000', 'BR', '+5511990000000' ],
			[ '0770 000 0000', 'IQ', '+9647700000000' ],
			[ '070 000 0000', 'AF', '+93700000000' ],
			[ '0412 000 000', 'AU', '+61412000000' ],
			[ '090 0000 0000', 'JP', '+819000000000' ],
			[ '8760000000', 'JM', '+18760000000' ],
			[ '5000000', 'JM', '+18765000000' ],
			[ '050-000-0000', 'SA', '+966500000000' ],
			// Other countries without a hint.
			[ '+90 532 000 00 00', '', '+905320000000' ],
			[ '0090 532 000 00 00', '', '+905320000000' ],
			[ '+44 (0)20 7946 0000', '', '+442079460000' ],
			[ '+1 (555) 010-0000', '', '+15550100000' ],
			[ '+1 555 010 0000 x12', '', '+15550100000' ],
			[ '+49 151 00000000', 'IR', '+4915100000000' ],
			// Without a country a national number means nothing.
			[ '05320000000', '', null ],
			[ '09120000000', 'Atlantis', null ],
			[ '09120000000', 'ZZ', null ],
			// Garbage.
			[ '', 'IR', null ],
			[ '+', 'IR', null ],
			[ '000', 'IR', null ],
			[ 'hello', 'IR', null ],
			[ '12', 'IR', null ],
			[ '+0123456789', '', null ],
			[ '+12345', '', null ],
			[ '+1234567890123456', '', null ],
			[ '09120000000, 09130000000', 'IR', null ],
			[ '<script>', '', null ],
		];
	}

	public function test_normalize(): void {
		$table = $this->table();
		$this->assertTrue( count( $table ) >= 60 );
		Settings::overlay( [ 'general.default_country' => '' ] );
		foreach ( $table as $row ) {
			$this->assertSame( $row[2], Phone::normalize( $row[0], $row[1] ), json_encode( $row, JSON_UNESCAPED_UNICODE ) );
		}
	}

	public function test_default_country(): void {
		Settings::overlay( [ 'general.default_country' => 'IR' ] );
		$this->assertSame( '+989120000000', Phone::normalize( '09120000000' ) );
		$this->assertSame( '+905320000000', Phone::normalize( '05320000000', 'TR' ) );
		Settings::overlay( [ 'general.default_country' => 'TR' ] );
		$this->assertSame( '+905320000000', Phone::normalize( '05320000000' ) );
		$this->assertSame( '+989120000000', Phone::normalize( '989120000000' ) );
	}

	public function test_normalize_is_idempotent(): void {
		Settings::overlay( [ 'general.default_country' => '' ] );
		foreach ( $this->table() as $row ) {
			if ( $row[2] !== null ) {
				$this->assertSame( $row[2], Phone::normalize( $row[2], 'US' ) );
			}
		}
	}

	public function test_country_of_every_dial_code(): void {
		$primary = [ '1' => 'US', '7' => 'RU', '39' => 'IT', '44' => 'GB', '47' => 'NO', '61' => 'AU', '212' => 'MA', '262' => 'RE', '358' => 'FI', '590' => 'GP', '599' => 'CW' ];
		$codes   = DialCodes::all();
		$this->assertTrue( count( $codes ) > 230 );
		foreach ( $codes as $iso2 => $dial ) {
			$this->assertSame( $primary[ $dial ] ?? $iso2, Phone::country( '+' . $dial . '5000000' ), $iso2 );
			$this->assertSame( $dial, Phone::dial_for( strtolower( $iso2 ) ) );
		}
		// Only these codes are shared.
		$shared = array_keys( array_filter( array_count_values( $codes ), static function ( $n ) {
			return $n > 1;
		} ) );
		sort( $shared );
		$expected = array_keys( $primary );
		sort( $expected );
		$this->assertSame( $expected, $shared );
	}

	public function test_country_rules(): void {
		foreach ( [
			'+989120000000' => 'IR',
			'+15550100000'  => 'US',
			'+18760000000'  => 'JM',
			'+16580000000'  => 'JM',
			'+18290000000'  => 'DO',
			'+79160000000'  => 'RU',
			'+77010000000'  => 'KZ',
			'+76000000000'  => 'KZ',
			'+447000000000' => 'GB',
			'+999000000'    => '',
			''              => '',
			'+'             => '',
		] as $number => $country ) {
			$this->assertSame( $country, Phone::country( (string) $number ), (string) $number );
		}
		$this->assertSame( '', Phone::dial_for( 'ZZ' ) );
	}

	public function test_dial_code_filter(): void {
		$this->filter( 'fnfe_dial_codes', static function ( $codes ) {
			return [ 'XA' => '+999', 'bad' => '1', 'XB' => 'x', 'XC' => [] ] + $codes;
		} );
		$codes = DialCodes::all();
		$this->assertSame( '999', $codes['XA'] );
		$this->assertFalse( isset( $codes['XB'] ) || isset( $codes['XC'] ) || isset( $codes['BAD'] ) );
		$this->assertSame( 'XA', Phone::country( '+9991234567' ) );
	}

	public function test_shapes(): void {
		$this->assertSame( '+989120000000', Phone::clean( '+98 (912) ۰۰۰-0000' ) );
		$this->assertSame( '09120000000', Phone::clean( '0912 000 00 00' ) );
		$this->assertTrue( Phone::is_iran( '+989120000000' ) );
		$this->assertFalse( Phone::is_iran( '+905320000000' ) );
		$this->assertSame( '09120000000', Phone::iran_local( '+989120000000' ) );
		$this->assertSame( '9120000000', Phone::iran_bare( '+989120000000' ) );
		$this->assertSame( '989120000000', Phone::digits( '+989120000000' ) );
		$this->assertSame( '+98912***0000', Phone::mask( '+989120000000' ) );
		$this->assertSame( '***', Phone::mask( '+123' ) );
	}
}
