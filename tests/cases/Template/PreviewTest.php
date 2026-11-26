<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\HelpTopics;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PreviewTest extends TestCase {

	const KEYS = [ 'text', 'parts', 'length', 'limit', 'plain', 'subject', 'buttons', 'warnings' ];

	/** @var int own administrator: users of the site are shared with other runs */
	private static $admin = 0;

	public static function setUpClass(): void {
		self::$admin = (int) wp_insert_user( [ 'user_login' => 'fnfe_preview_' . getmypid(), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
	}

	public static function tearDownClass(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( self::$admin );
	}

	public function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
	}

	private function preview( array $params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/fnfe/v1/template/preview' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_do_request( $request );
	}

	public function test_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->preview( [ 'template' => 'x' ] )->get_status() );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/fnfe/v1/template/tags' ) )->get_status() );
	}

	public function test_reading_a_submission_needs_its_own_capability(): void {
		$id = SubmissionRepository::insert( Fixtures::submission() );
		$this->filter( 'fnfe_capability', static function ( string $cap, string $fnfe ): string {
			return $fnfe === 'fnfe_view_submissions' ? 'do_not_allow' : $cap;
		}, 10, 2 );
		$this->assertSame( 403, $this->preview( [ 'template' => '{all_fields}', 'submission_id' => $id ] )->get_status() );
		$this->assertSame( 200, $this->preview( [ 'template' => '{all_fields}' ] )->get_status() );
	}

	public function test_preview_equals_the_sent_body_in_every_format(): void {
		$form_id = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact', 'source' => 'pro', 'fields' => Fixtures::form_fields() ] );
		$s       = Fixtures::submission( [ 'form_id' => $form_id ] );
		SubmissionRepository::insert( $s );
		$s = SubmissionRepository::find( $s->id );
		$template = "<b>{form_name}</b>\n{all_fields}\n{if field:email}<a href=\"mailto:{field:email}\">mail</a>{/if} {date:Y-m-d} <code>{field:name}</code>";
		foreach ( Registry::channels() as $id => $channel ) {
			$delivery           = new Delivery( $id, new Recipient( $id, 'x' ) );
			$delivery->template = $template;
			$delivery->lang     = 'en';
			$message            = Dispatcher::build( $delivery, $s, FormRepository::config( $form_id ) );
			$response           = $this->preview( [ 'template' => $template, 'channel' => $id, 'submission_id' => $s->id, 'lang' => 'en' ] );
			$this->assertSame( 200, $response->get_status(), $id );
			$data = $response->get_data();
			$this->assertKeys( self::KEYS, $data, $id );
			if ( $channel->format() !== 'json' ) {
				$this->assertSame( $message->body, $data['text'], $id );
			}
			$this->assertSame( $message->plain, $data['plain'], $id );
			$this->assertSame( Registry::formatter( $channel->format() )->length( $data['text'] ), $data['length'] );
		}
	}

	public function test_defaults_subject_buttons_and_warnings(): void {
		$data = $this->preview( [
			'channel' => 'slack',
			'lang'    => 'en',
			'buttons' => [
				[ 'id' => 'b_2', 'text' => [ '_' => 'Second' ], 'type' => 'url', 'value' => 'https://example.com/?f={field:name}', 'row' => 1 ],
				[ 'id' => 'b_1', 'text' => [ '_' => 'See #{submission_id}' ], 'type' => 'submission', 'value' => '', 'row' => 0 ],
				[ 'id' => 'b_3', 'text' => [ '_' => 'Read' ], 'type' => 'callback', 'value' => 'read', 'row' => 0 ],
			],
		] )->get_data();
		$this->assertTrue( $data['text'] !== '' );
		$this->assertTrue( $data['subject'] !== '' );
		$this->assertSame( [ [ 'text' => 'See #981', 'type' => 'url', 'value' => Urls::submission_admin( 981 ) ] ], $data['buttons'][0] );
		$this->assertMatches( '~^https://example.com/\?f=~', $data['buttons'][1][0]['value'] );
		$this->assertCount( 1, $data['warnings'] );

		$data = $this->preview( [ 'template' => "{nope}\n" . str_repeat( 'word ', 900 ), 'channel' => 'discord', 'buttons' => [] ] )->get_data();
		$this->assertSame( 2000, $data['limit'] );
		$this->assertCount( 2, $data['warnings'] );
		$this->assertTrue( count( $data['parts'] ) === 3 );

		$data = $this->preview( [ 'template' => '<script>x</script><b>y</b>', 'channel' => 'unknown' ] )->get_data();
		$this->assertSame( 'y', $data['text'] );
		foreach ( [ 'alert', 'digest', 'submitter', 'test' ] as $purpose ) {
			$this->assertTrue( $this->preview( [ 'purpose' => $purpose, 'channel' => 'email' ] )->get_data()['text'] !== '', $purpose );
		}
		$this->assertSame( 400, $this->preview( [ 'purpose' => 'zzz' ] )->get_status() );
		$this->assertSame( 404, $this->preview( [ 'submission_id' => 999999 ] )->get_status() );
	}

	public function test_tags_route(): void {
		$id      = FormRepository::upsert( '46:a1b2c3d', [ 'name' => 'Contact', 'fields' => Fixtures::form_fields() ] );
		$request = new \WP_REST_Request( 'GET', '/fnfe/v1/template/tags' );
		$request->set_query_params( [ 'form_id' => $id, 'purpose' => 'admin' ] );
		$tags = array_column( rest_do_request( $request )->get_data()['items'], 'tag' );
		$this->assertSame( '{field:name}', $tags[0] );
		$this->assertContains( '{all_fields}', $tags );
		$this->assertFalse( in_array( '{digest.sent}', $tags, true ) );
		$request->set_query_params( [ 'purpose' => 'digest' ] );
		$tags = array_column( rest_do_request( $request )->get_data()['items'], 'tag' );
		$this->assertContains( '{digest.sent}', $tags );
		$this->assertFalse( in_array( '{all_fields}', $tags, true ) );
	}

	public function test_help_topics(): void {
		$topics = apply_filters( 'fnfe_help_topics', [] );
		$this->assertArrayHasKey( 'template-tags', $topics );
		$this->assertArrayHasKey( 'webhooks', $topics );
		$this->assertContains( '{all_fields}', $topics['template-tags']['body'] );
		$this->assertSame( $topics['template-tags']['body'], wp_kses( $topics['template-tags']['body'], [ 'a' => [ 'href' => true ], 'code' => [], 'strong' => [], 'em' => [], 'br' => [] ] ) );
		$this->assertSame( 'not an array', HelpTopics::add( 'not an array' ) );
	}
}
