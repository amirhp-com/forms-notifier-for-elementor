<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\Conditions;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Template\Tags;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class EngineTest extends TestCase {

	private Submission $s;
	private Context $c;

	public function setUp(): void {
		$this->s              = Fixtures::submission( [ 'id' => 981, 'uuid' => str_repeat( 'ab', 16 ), 'form_id' => 12, 'status' => 'read' ] );
		$this->s->meta       += [
			'referrer'   => 'https://example.org/',
			'user_login' => 'sara',
			'user_email' => 'user@example.com',
			'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
		];
		$this->s->files[]     = \AmirhpCom\FormsNotifier\Model\Attachment::from_array( [ 'field_id' => 'cv', 'name' => 'cv.pdf', 'url' => 'https://example.com/cv.pdf', 'size' => 10 ] );
		$this->s->fields['cv'] = Fixtures::field( 'cv', 'cv.pdf', 'upload' );
		$this->c              = Context::for_submission( $this->s, 'en', 'x' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'UTC' );
	}

	public function tearDown(): void {
		Settings::clear_overlay();
	}

	private function r( string $template, string $format = 'text' ): string {
		return Engine::render( $template, $this->c, $format );
	}

	public function test_every_plain_tag(): void {
		$expected = [
			'{field:email}'          => Fixtures::EMAIL,
			'{field_raw:topics}'     => 'sales, support',
			'{label:email}'          => 'Email',
			'{form_name}'            => 'Contact',
			'{form_id}'              => '12',
			'{form_key}'             => '45:a1b2c3d',
			'{form_url}'             => Urls::form_admin( 12 ),
			'{submission_id}'        => '981',
			'{submission_uuid}'      => str_repeat( 'ab', 16 ),
			'{submission_url}'       => Urls::submission_admin( 981 ),
			'{admin_submission_url}' => Urls::submission_admin( 981 ),
			'{miniapp_submission_url}' => '',
			'{lang}'                 => 'en',
			'{files_count}'          => '1',
			'{page_url}'             => 'https://example.com/contact/',
			'{page_title}'           => 'Contact us',
			'{referrer}'             => 'https://example.org/',
			'{utm:source}'           => 'newsletter',
			'{utm:utm_source}'       => 'newsletter',
			'{utm:nope}'             => '',
			'{user_id}'              => '',
			'{user_login}'           => 'sara',
			'{user_name}'            => 'sara',
			'{user_email}'           => 'user@example.com',
			'{user_ip}'              => '203.0.113.5',
			'{user_agent}'           => $this->s->meta['user_agent'],
			'{browser}'              => 'Safari 18',
			'{device}'               => 'iPhone',
			'{site_name}'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'             => home_url( '/' ),
			'{admin_url}'            => admin_url(),
			'{admin_email}'          => get_option( 'admin_email' ),
			'{date}'                 => 'January 1, 2026',
			'{time}'                 => '10:00',
			'{datetime}'             => 'January 1, 2026 10:00',
			'{date:Y-m-d H:i}'       => '2026-01-01 10:00',
			'{jdate:Y/m/d}'          => '1404/10/11',
			'{gdate:Y-m-d}'          => '2026-01-01',
			'{timestamp}'            => (string) strtotime( '2026-01-01 10:00:00 UTC' ),
			'{name}'                 => 'سارا 🙂',
			'{email}'                => Fixtures::EMAIL,
			'{phone}'                => Fixtures::PHONE,
			'{alert.channel}'        => '',
			'{digest.sent}'          => '',
			'{unknown}'              => '',
		];
		I18n::with_language( 'en', function () use ( $expected ) {
			foreach ( $expected as $template => $value ) {
				$this->assertSame( $value, $this->r( $template ), $template );
			}
			$this->assertSame( 'Read', $this->r( '{status}' ) );
		} );
	}

	public function test_every_catalogued_tag_is_known(): void {
		foreach ( Tags::catalog( 0, '' ) as $entry ) {
			$this->assertKeys( [ 'tag', 'label', 'group', 'desc', 'purposes' ], $entry );
			preg_match( '/^\{([a-z0-9_.]+)/', $entry['tag'], $m );
			$this->assertTrue( Tags::known( $m[1] ), $entry['tag'] );
			$this->assertTrue( in_array( $entry['group'], [ 'form', 'fields', 'page', 'user', 'site', 'date', 'submission', 'links', 'system' ], true ) );
			$this->assertSame( [], Engine::validate( $entry['tag'] ), $entry['tag'] );
		}
	}

	public function test_markup_tags(): void {
		$this->assertSame( '<a href="https://example.com/contact/">Contact us</a>', $this->r( '{page_link}', 'telegram_html' ) );
		$this->assertSame( 'Contact us (https://example.com/contact/)', $this->r( '{page_link}' ) );
		$this->assertSame( 'cv.pdf', $this->r( '{files}' ) );
		$lines = explode( "\n", $this->r( '{all_fields}', 'telegram_html' ) );
		$this->assertSame( '<b>Name</b>: سارا 🙂', $lines[0] );
		$this->assertContains( '<b>Topics</b>: sales, support', $lines );
		$this->assertContains( "<b>Message</b>:\nFirst line\nSecond &lt;b&gt;line&lt;/b&gt; &amp; {site_url}", implode( "\n", $lines ) );
		$this->assertNotContains( 'Empty', implode( "\n", $lines ) );
		$email = $this->r( '{fields_table}', 'email_html' );
		$this->assertSame( 1, substr_count( $email, '<table' ) );
		$this->assertContains( '&lt;b&gt;line&lt;/b&gt;', $email );
		$this->assertContains( '<b>Name</b>: ', $this->r( '{fields_table}', 'telegram_html' ) );
		$this->assertSame( 'Contact us', $this->r( '{page_link|truncate:50|lower}' ) === 'contact us (https://example.com/contact/)' ? 'Contact us' : 'x' );
	}

	public function test_all_fields_options(): void {
		$this->assertSame( "Name: سارا 🙂\nEmail: sara@example.com", $this->r( '{all_fields:only=name+email}' ) );
		$this->assertNotContains( 'Email', $this->r( '{all_fields:exclude=email+name}' ) );
		$this->assertContains( 'Empty:', $this->r( '{all_fields:empty=1}' ) );
		$this->assertSame( "سارا 🙂\nsara@example.com", $this->r( '{all_fields:only=name+email,labels=0}' ) );
		$this->assertSame( 'Name = سارا 🙂', $this->r( '{all_fields:only=name,sep=" = "}' ) );
		$this->assertSame( 'Email: sara@example.com; Message: First line Second <b>line</b> & {site_url}', $this->r( '{all_fields:only=email+message,format=inline}' ) );
		$this->assertSame( 2, count( explode( "\n", $this->r( '{all_fields:max=2}' ) ) ) );
		$this->c->form = Fixtures::config( [ 'fields' => [ 'exclude' => [ 'email' ], 'labels' => [ 'name' => [ '_' => 'Full name', 'fa' => 'نام' ] ] ] ] );
		$this->assertNotContains( 'Email', $this->r( '{all_fields}' ) );
		$this->assertContains( 'Full name: ', $this->r( '{all_fields}' ) );
		$this->c->lang = 'fa';
		$this->assertContains( 'نام: ', $this->r( '{all_fields}' ) );
		$this->s->fields['phone']['hidden'] = true;
		$this->assertNotContains( Fixtures::PHONE, $this->r( '{all_fields}' ) );
	}

	public function test_redacted_and_file_values_never_leak(): void {
		$this->s->fields['pin']        = array_merge( Fixtures::field( 'pin', '••••••', 'password' ), [ 'redacted' => true, 'raw' => 'secret', 'values' => [ 'secret' ] ] );
		$this->s->fields['cv']['raw']  = '/var/www/uploads/cv.pdf';
		$this->assertNotContains( 'secret', $this->r( '{field:pin}{field_raw:pin}{field:pin|first}{field:pin|join:,}{all_fields}' ) );
		$this->assertSame( 'cv.pdf', $this->r( '{field_raw:cv}' ) );
	}

	public function test_file_links_follow_the_policy(): void {
		$seen = [];
		$this->filter( 'fnfe_channels', static function ( array $map ) {
			$map['x'] = new \AmirhpCom\FormsNotifier\Tests\FakeChannel( 'x' );
			return $map;
		} );
		\AmirhpCom\FormsNotifier\Core\Registry::reset();
		$this->s->files            = [ Fixtures::file( 'cv.pdf' ) ];
		$this->s->files[0]->url    = 'https://example.com/cv.pdf';
		$links = \AmirhpCom\FormsNotifier\Attachments\Policy::apply( [ 'mode' => 'link', 'types' => 'all', 'max_mb' => 20, 'max_files' => 10, 'link_ttl_days' => 0 ], $this->s, \AmirhpCom\FormsNotifier\Core\Registry::channel( 'x' ) )['links'];
		foreach ( [ 'off' => 'cv.pdf', 'send' => 'cv.pdf' ] as $mode => $expected ) {
			$this->c->policy = [ 'mode' => $mode, 'types' => 'all', 'max_mb' => 20, 'max_files' => 10, 'link_ttl_days' => 0 ];
			$this->assertSame( $expected, $this->r( '{files}' ), $mode );
		}
		if ( $links ) {
			$this->c->policy['mode'] = 'link';
			$this->assertSame( 'cv.pdf (' . $links[0]['url'] . ')', $this->r( '{files}' ) );
			$this->assertContains( '<b>Cv</b>: <a href="', $this->r( '{all_fields}', 'telegram_html' ) );
			$this->c->purpose = 'submitter';
			$this->assertSame( 'cv.pdf', $this->r( '{files}' ) );
		}
		unset( $seen );
		\AmirhpCom\FormsNotifier\Core\Registry::reset();
	}

	public function test_every_filter(): void {
		$this->s->fields += [
			'low'  => Fixtures::field( 'low', 'hello world' ),
			'pad'  => Fixtures::field( 'pad', '  x  ' ),
			'num'  => Fixtures::field( 'num', '1234567.5' ),
			'fan'  => Fixtures::field( 'fan', '۱۲۳۴۵' ),
			'day'  => Fixtures::field( 'day', '2026-03-21' ),
			'jday' => Fixtures::field( 'jday', '۱۴۰۵/۰۱/۰۱' ),
			'tags' => Fixtures::field( 'tags', '<i>x</i> y' ),
			'card' => Fixtures::field( 'card', '6037991234' ),
			'tel'  => Fixtures::field( 'tel', '+989121234567' ),
		];
		$cases = [
			'{field:low|upper}'                  => 'HELLO WORLD',
			'{field:low|upper|lower}'            => 'hello world',
			'{field:low|title}'                  => 'Hello World',
			'[{field:pad|trim}]'                 => '[x]',
			'{field:low|truncate:6}'             => 'hello…',
			'{field:empty|default:none}'         => 'none',
			'{field:empty|default:"a, b"|upper}' => 'A, B',
			'{field:fan|digits:en}'              => '12345',
			'{field:card|digits:fa}'             => '۶۰۳۷۹۹۱۲۳۴',
			'{field:message|nl2space}'           => 'First line Second <b>line</b> & {site_url}',
			'{field:topics|first}'               => 'sales',
			'{field:topics|last|upper}'          => 'SUPPORT',
			'{field:topics|join: / }'            => 'sales / support',
			'{field:topics|count}'               => '2',
			'{field:empty|count}'                => '0',
			'{field:low|urlencode}'              => 'hello%20world',
			'{field:day|date:d.m.Y}'             => '21.03.2026',
			'{field:jday|date:Y-m-d}'            => '2026-03-21',
			'{field:low|date:Y}'                 => 'hello world',
			'{field:card|mask}'                  => '60••••••34',
			'{field:tel|phone}'                  => '+989121234567',
			'{field:low|phone}'                  => 'hello world',
			'{field:num|number}'                 => '1,234,567.5',
			'{field:fan|number}'                 => '12,345',
			'{field:low|number}'                 => 'hello world',
			'{field:tags|strip}'                 => 'x y',
			'{field:low|nope}'                   => 'hello world',
		];
		foreach ( $cases as $template => $expected ) {
			$this->assertSame( $expected, $this->r( $template ), $template );
		}
		// The result of a filter is still a value.
		$this->assertSame( '&lt;I&gt;X&lt;/I&gt; Y', $this->r( '{field:tags|upper}', 'telegram_html' ) );
	}

	public function test_conditionals(): void {
		$this->s->fields['plan'] = Fixtures::field( 'plan', 'VIP' );
		$this->s->fields['age']  = Fixtures::field( 'age', '۱۸' );
		$true = [
			'field:plan', '!field:empty', '!field:missing', 'field:plan == "vip"', "field:plan == 'vip'", 'field:plan == vip', 'field:plan != "x"', 'field:plan contains "I"', 'field:plan !contains "z"', 'field:age > 17', 'field:age < 18.5',
			'field:plan in "gold, vip"', 'field:topics == "support"', 'field:topics in "support"', 'field:plan matches "^[A-Z]{3}$"', 'lang == "en"', 'files_count > 0', 'utm:source == "newsletter"', 'form_name contains "cont"', 'field:plan and field:age',
			'field:empty or field:plan', 'field:empty and field:plan or field:age', 'field:plan or field:empty and field:missing', '!field:plan == "x"', 'name', 'field:plan &amp;&amp;' === '' ? '' : 'field:age &gt; 1',
		];
		foreach ( $true as $condition ) {
			$this->assertSame( 'yes', $this->r( "{if $condition}yes{else}no{/if}" ), $condition );
		}
		$false = [ 'field:empty', '!field:plan', 'field:plan == "x"', 'field:plan != "vip"', 'field:plan contains ""', 'field:age > 18', 'field:plan > 1', 'field:plan in ""', 'field:plan matches "(["', 'field:plan and field:empty', 'lang == "fa"', 'nope', 'field:topics != "sales"', '!field:plan == "vip"', 'field:plan ==' ];
		foreach ( $false as $condition ) {
			$this->assertSame( 'no', $this->r( "{if $condition}yes{else}no{/if}" ), $condition );
		}
		$this->assertSame( 'b', $this->r( '{if field:empty}a{elseif field:plan}b{else}c{/if}' ) );
		$this->assertSame( 'ab', $this->r( '{if field:plan}a{if field:age}b{/if}{/if}' ) );
		$this->assertSame( "x\ny", $this->r( "x\n{if field:empty}\nhidden\n{/if}\ny" ) );
		$this->assertSame( "x\nshown\ny", $this->r( "x\n{if field:plan}\nshown\n{/if}\ny" ) );
	}

	public function test_condition_operators_and_meta(): void {
		$test = function ( string $field, string $op, string $value = '' ): bool {
			return Conditions::test( compact( 'field', 'op', 'value' ), $this->c );
		};
		$this->assertTrue( $test( 'email', 'starts', 'SARA' ) );
		$this->assertTrue( $test( 'email', 'ends', '.com' ) );
		$this->assertFalse( $test( 'email', 'ends', '' ) );
		$this->assertTrue( $test( 'empty', 'empty' ) );
		$this->assertTrue( $test( 'email', 'not_empty' ) );
		$this->assertTrue( $test( 'topics', 'not_in', 'a,b' ) );
		$this->assertTrue( $test( 'topics', 'is_not', 'x' ) );
		$this->assertTrue( $test( 'meta:lang', 'is', 'en' ) );
		$this->assertTrue( $test( 'meta:utm.source', 'is', 'newsletter' ) );
		$this->assertTrue( $test( 'meta:page_url', 'contains', 'contact' ) );
		$this->assertTrue( $test( 'meta:nope.deep', 'empty' ) );
		$this->assertTrue( $test( 'tag:form_name', 'is', 'contact' ) );
		$this->assertTrue( $test( 'tag:utm:source', 'is', 'newsletter' ) );
		$this->assertFalse( $test( 'email', 'unknown_op', 'x' ) );
		$this->assertFalse( $test( 'email', 'matches', '~' ) );
		$this->assertTrue( $test( 'email', 'matches', 'a~?' ) );
		$this->filter( 'fnfe_condition_ops', static function ( array $ops ) {
			$ops['long']   = static function ( string $actual, string $expected ): bool { return strlen( $actual ) > (int) $expected; };
			$ops['broken'] = static function () { throw new \RuntimeException( 'x' ); };
			return $ops;
		} );
		$this->assertTrue( $test( 'email', 'long', '3' ) );
		$this->assertFalse( $test( 'email', 'broken' ) );
		$this->assertTrue( Conditions::test_all( [], 'all', $this->c ) );
		$this->assertTrue( Conditions::test_all( [ [ 'field' => 'x', 'op' => 'is', 'value' => '1' ], [ 'field' => 'email', 'op' => 'not_empty' ] ], 'any', $this->c ) );
		$this->assertFalse( Conditions::test_all( [ [ 'field' => 'x', 'op' => 'is', 'value' => '1' ], [ 'field' => 'email', 'op' => 'not_empty' ] ], 'all', $this->c ) );
	}

	public function test_inline_rendering(): void {
		$this->assertSame( 'New: Contact First line Second <b>line</b> & {site_url}', Engine::render_inline( '<b>New</b>: {form_name} {field:message}', $this->c ) );
		$this->assertSame( 'https://x.example/?q=' . rawurlencode( 'sales, support' ) . '&copy=1', Engine::render_inline( 'https://x.example/?q={field:topics}&copy=1', $this->c, true ) );
		$this->assertSame( Urls::submission_admin( 981 ), Engine::render_inline( '{admin_submission_url}', $this->c, true ) );
		$this->assertSame( 'https://x.example/?u=' . rawurlencode( 'https://example.com/contact/' ), Engine::render_inline( 'https://x.example/?u={page_url}', $this->c, true ) );
		$this->s->fields['name']['value'] = 'javascript:alert(1)';
		$this->assertSame( 'javascript%3Aalert%281%29', Engine::render_inline( '{field:name}', $this->c, true ) );
		$this->assertSame( 'sales, support', Engine::render_inline( '{field:topics}', $this->c ) );
		$this->assertSame( 'a b', Engine::render_inline( "a\n\n b", $this->c ) );
		$this->assertSame( 'Hi', Engine::render_inline( [ '_' => 'x', 'en' => 'Hi' ], $this->c ) );
	}

	public function test_dates_follow_calendar_and_language(): void {
		$this->c->lang = 'fa';
		I18n::with_language( 'fa', function () {
			$this->assertSame( '۱۱ دی ۱۴۰۴', $this->r( '{date}' ) );
			$this->assertSame( '۱۱ دی ۱۴۰۴ ۱۰:۰۰', $this->r( '{datetime}' ) );
			$this->assertSame( '1404-10-11', $this->r( '{date:Y-m-d}' ) );
			$this->assertSame( '2026-01-01', $this->r( '{gdate:Y-m-d}' ) );
			Settings::overlay( [ 'general.calendar' => 'gregorian' ] );
			$this->assertSame( '2026-01-01', $this->r( '{date:Y-m-d}' ) );
			$this->assertSame( '1404', $this->r( '{jdate:Y}' ) );
		} );
		$this->c->lang = 'en';
		Settings::overlay( [ 'general.calendar' => 'jalali' ] );
		I18n::with_language( 'en', function () {
			$this->assertSame( 'Dey 11, 1404', $this->r( '{date}' ) );
		} );
		update_option( 'timezone_string', 'Asia/Tehran' );
		$this->assertSame( '13:30', $this->r( '{gdate:H:i}' ) );
		$this->freeze( strtotime( '2026-11-25 00:00:00 UTC' ) );
		$this->assertSame( '2026-11-25', Engine::render( '{gdate:Y-m-d}', Context::bare( [], 'en' ) ) );
	}

	public function test_submitter_mapping(): void {
		$this->c->form = Fixtures::config( [ 'fields' => [ 'email' => 'message', 'name' => 'topics', 'phone' => 'nope' ] ] );
		$this->assertSame( 'sales, support', $this->r( '{name}' ) );
		$this->assertSame( '', $this->r( '{phone}' ) );
		$this->c->form = null;
		$this->s->fields['name']['redacted'] = true;
		$this->assertSame( '', $this->r( '{name}' ) );
	}

	public function test_miniapp_link(): void {
		Settings::overlay( [ 'miniapp.enabled' => true ] );
		State::set( 'bot.telegram.me', [ 'username' => 'example_bot' ] );
		$this->assertSame( 'https://t.me/example_bot?startapp=sub_981', $this->r( '{miniapp_submission_url}' ) );
		State::set( 'bot.bale.me', [ 'username' => 'bale_bot' ] );
		$this->c->channel = 'bale';
		$this->assertSame( 'https://ble.ir/bale_bot?startapp=sub_981', $this->r( '{miniapp_submission_url}' ) );
		State::delete( 'bot' );
	}

	public function test_extension_points(): void {
		$this->filter( 'fnfe_template_tags', static function ( array $tags ) {
			$tags['hello']  = static function ( string $arg, Context $ctx ): string { return "<b>$arg</b> {form_name} " . $ctx->submission->form_name; };
			$tags['broken'] = static function () { throw new \RuntimeException( 'x' ); };
			return $tags;
		} );
		$this->filter( 'fnfe_template_filters', static function ( array $filters ) {
			$filters['dots'] = static function ( string $value, string $arg ): string { return $value . str_repeat( '.', (int) $arg ); };
			$filters['bad']  = static function () { throw new \RuntimeException( 'x' ); };
			return $filters;
		} );
		$this->filter( 'fnfe_template', static function ( string $template, string $channel, string $purpose ): string { return $template . "[$channel/$purpose]"; }, 10, 3 );
		$this->assertSame( '&lt;b&gt;x&lt;/b&gt; {form_name} Contact[x/admin]', $this->r( '{hello:x}', 'telegram_html' ) );
		$this->assertSame( 'a..' . '[x/admin]', $this->r( '{field:nope|default:a|dots:2}{broken}' ) );
		$this->assertSame( 'Contact[x/admin]', $this->r( '{form_name|bad}' ) );
		$this->filter( 'fnfe_template_context', static function ( Context $c ): Context { $c->extra['form_name'] = 'Other'; return $c; } );
		$this->assertSame( 'Other[x/admin]', $this->r( '{form_name}' ) );
		$this->filter( 'fnfe_template_tags_catalog', static function ( array $catalog ) { $catalog[] = [ 'tag' => '{hello}' ]; return $catalog; } );
		$this->assertContains( '{hello}', array_column( Tags::catalog(), 'tag' ) );
	}

	public function test_render_never_throws(): void {
		$this->filter( 'fnfe_template', static function () { throw new \RuntimeException( 'x' ); } );
		$this->assertSame( '', $this->r( 'x' ) );
		$this->assertSame( '', Engine::render( [ 'en' => [] ], Context::bare(), 'nope' ) );
	}

	public function test_default_templates_for_every_format_and_purpose(): void {
		$extra = [ 'alert.channel' => 'Telegram', 'alert.error' => 'boom', 'digest.period' => 'today', 'digest.submissions' => '3', 'digest.sent' => '2', 'digest.failed' => '0' ];
		foreach ( [ 'text', 'telegram_html', 'telegram_md', 'bale_md', 'whatsapp', 'slack', 'markdown', 'email_html', 'json' ] as $format ) {
			foreach ( [ 'admin', 'submitter', 'alert', 'digest', 'test' ] as $purpose ) {
				$template = DefaultTemplates::for_format( $format, $purpose );
				$this->assertTrue( $template !== '' );
				$this->assertSame( [], Engine::validate( $template ), "$format $purpose" );
				$this->c->extra = $extra;
				$this->assertTrue( $this->r( $template, $format ) !== '', "$format $purpose" );
				$this->assertTrue( Engine::render_inline( DefaultTemplates::subject( $purpose ), $this->c ) !== '' );
			}
		}
		I18n::with_language( 'en', function () {
			$this->c->extra = [];
			$this->assertSame(
				"New submission: Contact\n\nName: سارا 🙂\nEmail: sara@example.com\nPhone: +989120000000\nTopics: sales, support\nMessage:\nFirst line\nSecond <b>line</b> & {site_url}\nCv: cv.pdf\n\nFiles: cv.pdf\nPage: Contact us (https://example.com/contact/)\nDate: January 1, 2026 10:00",
				$this->r( DefaultTemplates::for_format( 'telegram_html', 'admin' ) )
			);
			$this->assertSame( "Contact: new submission #981\nName: سارا 🙂\nEmail: sara@example.com\nPhone: +989120000000", $this->r( DefaultTemplates::for_format( 'text', 'admin' ) ) );
			$this->assertSame( 'Thank you سارا 🙂. We received your message.', $this->r( DefaultTemplates::for_format( 'text', 'submitter' ) ) );
		} );
		$this->s->form_name = str_repeat( 'ف', 300 );
		$this->s->fields['name']['value'] = str_repeat( 'م', 1000 );
		$this->assertTrue( Text::utf16_length( $this->r( DefaultTemplates::for_format( 'text', 'admin' ) ) ) <= 201 );
		$this->filter( 'fnfe_default_template', static function () { return 'mine'; } );
		$this->assertSame( 'mine', DefaultTemplates::for_format( 'text', 'admin' ) );
	}

	public function test_sample_submission(): void {
		$generic = Engine::sample_submission( 0, 'en' );
		$this->assertTrue( count( $generic->fields ) >= 4 );
		$id     = FormRepository::upsert( '9:abcdef1', [ 'name' => 'Job', 'source' => 'pro', 'fields' => array_merge( Fixtures::form_fields(), [ [ 'id' => 'cv', 'type' => 'upload', 'label' => 'CV' ], [ 'id' => 'future', 'type' => 'hologram', 'label' => '' ] ] ) ] );
		$sample = Engine::sample_submission( $id, 'en' );
		$this->assertSame( [ 'name', 'email', 'topics', 'cv', 'future' ], array_keys( $sample->fields ) );
		$this->assertSame( 'Job', $sample->form_name );
		$this->assertCount( 1, $sample->files );
		$this->assertSame( [ 'sales', 'support' ], $sample->fields['topics']['values'] );
		$this->assertSame( 'future', $sample->fields['future']['label'] );
		$tags = array_column( Tags::catalog( $id ), 'label', 'tag' );
		$this->assertSame( 'CV', $tags['{field:cv}'] );
	}
}
