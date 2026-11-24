<?php
/**
 * Routing.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class ResolverTest extends TestCase {

	public static function setUpClass(): void {
		FakeChannel::install( 'fake', [ 'buttons' => [ 'url', 'callback', 'copy' ], 'subject' => true ] );
		FakeChannel::install( 'second' );
		FakeChannel::install( 'single', [ 'receivers' => 'none' ] );
		FakeChannel::install( 'phone', [ 'receiver_kind' => 'phone', 'submitter' => true, 'buttons' => [ 'url', 'callback' ] ] );
		FakeChannel::install( 'mailer', [ 'receiver_kind' => 'email', 'submitter' => true, 'subject' => true ] );
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
	}

	public function setUp(): void {
		FakeChannel::reset();
		Db::options( [ 'fnfe_settings' => null ] );
		$this->set( 'fake', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 'g1' ), Fixtures::receiver( 'g2', [ 'enabled' => false ] ) ] ] );
	}

	private function set( string $channel, array $values ): void {
		foreach ( $values as $key => $value ) {
			Settings::set( "channels.$channel.$key", $value );
		}
	}

	private function submission( array $editor = [], array $more = [] ): Submission {
		$s                 = Fixtures::submission( $more );
		$s->meta['editor'] = $editor;
		return $s;
	}

	/** "channel:address" of every delivery of a purpose. */
	private function plan( array $config = [], array $editor = [], array $more = [], array $only = [], string $purpose = 'admin' ): array {
		$out = [];
		foreach ( Resolver::plan( $this->submission( $editor, $more ), Fixtures::config( $config ), $only ) as $d ) {
			if ( $d->purpose === $purpose ) {
				$out[] = $d->channel . ':' . $d->recipient->address;
			}
		}
		return $out;
	}

	private function first( array $config = [], array $editor = [], array $more = [], string $purpose = 'admin' ): ?Delivery {
		foreach ( Resolver::plan( $this->submission( $editor, $more ), Fixtures::config( $config ) ) as $d ) {
			if ( $d->purpose === $purpose ) {
				return $d;
			}
		}
		return null;
	}

	public function test_form_active_truth_table() {
		foreach ( [ 'all', 'selected' ] as $mode ) {
			Settings::set( 'general.capture_mode', $mode );
			foreach ( [ 'inherit', 'on', 'off' ] as $form ) {
				foreach ( [ '', 'yes', 'no' ] as $editor ) {
					foreach ( [ 'auto', 'action' ] as $via ) {
						if ( $editor === 'no' || $form === 'off' ) {
							$expected = false;
						} elseif ( $form === 'on' || $editor === 'yes' || $mode === 'all' ) {
							$expected = true;
						} else {
							$expected = $via === 'action';
						}
						$this->assertSame(
							$expected,
							Resolver::form_active( Fixtures::config( [ 'enabled' => $form ] ), [ 'fnfe_enabled' => $editor, 'via' => $via ] ),
							"$mode/$form/$editor/$via"
						);
					}
				}
			}
		}
	}

	public function test_channel_on_off_truth_table() {
		foreach ( [ true, false ] as $global ) {
			$this->set( 'fake', [ 'enabled' => $global ] );
			foreach ( [ 'inherit', 'on', 'off' ] as $route ) {
				foreach ( [ [], [ 'fake' ], [ 'second' ] ] as $editor ) {
					$on = $route === 'on' || ( $route === 'inherit' && $global );
					if ( $editor && ! in_array( 'fake', $editor, true ) ) {
						$on = false;
					}
					$this->assertSame(
						$on ? [ 'fake:g1' ] : [],
						$this->plan( [ 'channels' => [ 'fake' => [ 'enabled' => $route ] ] ], [ 'fnfe_channels' => $editor ] ),
						json_encode( [ $global, $route, $editor ] )
					);
				}
			}
		}
	}

	public function test_receivers_mode_truth_table() {
		$expected = [
			'inherit' => [ '' => [ 'g1' ], 'append' => [ 'g1', 'e1' ], 'replace' => [ 'e1' ] ],
			'append'  => [ '' => [ 'g1', 'f1' ], 'append' => [ 'g1', 'f1', 'e1' ], 'replace' => [ 'e1' ] ],
			'replace' => [ '' => [ 'f1' ], 'append' => [ 'f1', 'e1' ], 'replace' => [ 'e1' ] ],
		];
		foreach ( $expected as $route => $editors ) {
			foreach ( $editors as $mode => $addresses ) {
				$config = [ 'channels' => [ 'fake' => [ 'receivers_mode' => $route, 'receivers' => [ Fixtures::receiver( 'f1' ), Fixtures::receiver( 'f2', [ 'enabled' => false ] ) ] ] ] ];
				$editor = $mode === '' ? [] : [ 'fnfe_receivers_fake' => "e1,\n", 'fnfe_receivers_mode' => $mode ];
				$this->assertSame(
					array_map( function ( $a ) {
						return 'fake:' . $a;
					}, $addresses ),
					$this->plan( $config, $editor ),
					"$route/$mode"
				);
			}
		}
	}

	public function test_receivers_are_deduplicated_by_normalised_address() {
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'G1', [ 'label' => 'First' ] ), Fixtures::receiver( ' g1 ' ), Fixtures::receiver( 'g2' ) ] ] );
		$this->assertSame( [ 'fake:g1', 'fake:g2' ], $this->plan() );
		$this->assertSame( 'First', $this->first()->recipient->name );
	}

	public function test_invalid_stored_address_is_reported_as_skipped() {
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'bad one' ), Fixtures::receiver( 'ok' ) ] ] );
		$out = Resolver::resolve( $this->submission(), Fixtures::config() );
		$this->assertCount( 1, $out['deliveries'] );
		$this->assertCount( 1, $out['skipped'] );
		$this->assertSame( 'bad one', $out['skipped'][0]['delivery']->recipient->address );
		$this->assertNotSame( '', $out['skipped'][0]['reason'] );
	}

	public function test_receiver_from_a_field() {
		$config = [ 'channels' => [ 'fake' => [ 'receivers_mode' => 'replace', 'receivers' => [ Fixtures::receiver( '{field:topics}' ), Fixtures::receiver( '[field id="email"]' ), Fixtures::receiver( '{field:empty}' ), Fixtures::receiver( '{field:nope}' ) ] ] ] ];
		$this->assertSame( [ 'fake:sales', 'fake:support', 'fake:sara@example.com' ], $this->plan( $config ) );
		$this->assertSame( [ 'fake:g1', 'fake:sara@example.com' ], $this->plan( [], [ 'fnfe_receivers_fake' => '[field id="email"]' ] ) );
	}

	public function test_a_visitor_cannot_name_a_secret_or_a_url() {
		$fields = [ 'x' => Fixtures::field( 'x', 'secret:r_abc' ), 'u' => Fixtures::field( 'u', 'https://evil.example.com/' ) ];
		$list   = [ Fixtures::receiver( '{field:x}' ), Fixtures::receiver( '{field:u}' ) ];
		$this->set( 'hook', [ 'enabled' => true, 'receivers' => $list ] );
		$this->assertSame( [], $this->plan( [], [], [ 'fields' => $fields ], [ 'hook' ] ) );
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( '{field:x}' ) ] ] );
		$this->assertSame( [], $this->plan( [], [], [ 'fields' => $fields ], [ 'fake' ] ) );
		$config = [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'phone' => [ 'enabled' => true, 'field' => 'x' ] ] ] ];
		$this->set( 'phone', [ 'enabled' => true ] );
		$this->assertSame( [], $this->plan( $config, [], [ 'fields' => $fields ], [], 'submitter' ) );
	}

	public function test_placeholder_receiver_is_kept() {
		$this->set( 'hook', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 'secret:r_1' ) ] ] );
		$this->assertSame( [ 'hook:secret:r_1' ], $this->plan( [], [], [], [ 'hook' ] ) );
	}

	public function test_channel_without_receivers_has_one_implicit_recipient() {
		$this->set( 'single', [ 'enabled' => true ] );
		$this->assertSame( [ 'single:default' ], $this->plan( [], [], [], [ 'single' ] ) );
		$this->assertCount( 1, Resolver::receivers( 'single', Fixtures::config() ) );
	}

	public function test_language_filter() {
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'fa-only', [ 'langs' => [ 'fa' ] ] ), Fixtures::receiver( 'both', [ 'langs' => [ 'fa', 'en' ] ] ), Fixtures::receiver( 'all' ) ] ] );
		$this->assertSame( [ 'fake:both', 'fake:all' ], $this->plan( [], [], [ 'lang' => 'en' ] ) );
		$this->assertSame( [ 'fake:fa-only', 'fake:both', 'fake:all' ], $this->plan( [], [], [ 'lang' => 'fa' ] ) );
	}

	public function test_render_language() {
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'a', [ 'lang' => 'de' ] ) ] ] );
		$this->assertSame( 'de', $this->first()->lang );
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'a' ) ] ] );
		$this->assertSame( I18n::default_lang(), $this->first( [], [], [ 'lang' => 'xx' ] )->lang );
		Settings::set( 'general.admin_lang', 'submission' );
		$this->assertSame( 'en', $this->first( [], [], [ 'lang' => 'en' ] )->lang );
		Settings::set( 'general.admin_lang', 'fr' );
		$this->assertSame( 'fr', $this->first( [], [], [ 'lang' => 'en' ] )->lang );
	}

	public function test_template_and_subject_truth_table() {
		foreach ( [ '', 'GLOBAL' ] as $global ) {
			foreach ( [ 'inherit', 'custom' ] as $mode ) {
				foreach ( [ '', 'FORM' ] as $form ) {
					foreach ( [ '', 'EDITOR' ] as $editor ) {
						$this->set( 'fake', [ 'template.admin' => [ '_' => $global ], 'subject.admin' => [ '_' => $global ] ] );
						$config   = [ 'channels' => [ 'fake' => [ 'template_mode' => $mode, 'template' => [ '_' => $form ], 'subject' => [ '_' => $form ] ] ] ];
						$delivery = $this->first( $config, [ 'fnfe_template' => $editor ] );
						$own      = $mode === 'custom' && $form !== '' ? $form : $global;
						$this->assertSame( $editor !== '' ? $editor : $own, $delivery->template, "$global/$mode/$form/$editor" );
						$this->assertSame( $own, $delivery->subject, "$global/$mode/$form/$editor" );
					}
				}
			}
		}
	}

	public function test_template_per_language_and_public_getter() {
		$this->set( 'fake', [ 'template.admin' => [ '_' => 'default', 'fr' => 'français' ] ] );
		$this->set( 'fake', [ 'receivers' => [ Fixtures::receiver( 'a', [ 'lang' => 'fr' ] ), Fixtures::receiver( 'b', [ 'lang' => 'de' ] ) ] ] );
		$plan = Resolver::plan( $this->submission(), Fixtures::config() );
		$this->assertSame( [ 'français', 'default' ], [ $plan[0]->template, $plan[1]->template ] );
		$this->assertSame( [ '_' => 'default', 'fr' => 'français' ], Resolver::template( 'fake', Fixtures::config() ) );
	}

	public function test_buttons_mode_truth_table() {
		$button = function ( string $id, int $row = 0 ): array {
			return [ 'id' => $id, 'text' => [ '_' => $id ], 'type' => 'url', 'value' => 'https://example.com/' . $id, 'row' => $row, 'style' => '' ];
		};
		$this->set( 'fake', [ 'buttons' => [ $button( 'b_g' ) ] ] );
		$expected = [ 'inherit' => [ 'b_g' ], 'append' => [ 'b_g', 'b_f' ], 'replace' => [ 'b_f' ], 'none' => [] ];
		foreach ( $expected as $mode => $ids ) {
			$config = [ 'channels' => [ 'fake' => [ 'buttons_mode' => $mode, 'buttons' => [ $button( 'b_f' ) ] ] ] ];
			$this->assertSame( $ids, array_column( $this->first( $config )->buttons, 'id' ), $mode );
			$editor = [ 'fnfe_buttons' => [ [ '_id' => 'abc', 'text' => 'Own', 'type' => 'url', 'value' => 'https://example.com/e' ], [ 'text' => '' ] ] ];
			$this->assertSame( [ 'abc' ], array_column( $this->first( $config, $editor )->buttons, 'id' ), "$mode with editor" );
		}
		$appended = Resolver::buttons( 'fake', Fixtures::config( [ 'channels' => [ 'fake' => [ 'buttons_mode' => 'append', 'buttons' => [ $button( 'b_f' ) ] ] ] ] ) );
		$this->assertSame( [ 0, 1 ], array_column( $appended, 'row' ) );
	}

	public function test_no_buttons_for_channels_without_buttons() {
		$this->set( 'second', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 's' ) ], 'buttons' => [ [ 'id' => 'b_1', 'text' => [ '_' => 'x' ], 'type' => 'url', 'value' => 'https://example.com', 'row' => 0, 'style' => '' ] ] ] );
		foreach ( Resolver::plan( $this->submission(), Fixtures::config(), [ 'second' ] ) as $d ) {
			$this->assertSame( [], $d->buttons );
		}
	}

	public function test_options_merge() {
		$this->assertSame( false, $this->first()->options['silent'] );
		$this->set( 'fake', [ 'silent' => true ] );
		$this->assertSame( true, $this->first()->options['silent'] );
		$this->assertSame( false, $this->first( [ 'channels' => [ 'fake' => [ 'options' => [ 'silent' => false ] ] ] ] )->options['silent'] );
	}

	public function test_attachment_policy_editor_override() {
		$this->assertSame( 'link', $this->first()->attachment_policy['mode'] );
		$this->assertSame( 'both', $this->first( [], [ 'fnfe_attach' => 'both' ] )->attachment_policy['mode'] );
		$this->assertSame( 'link', $this->first( [], [ 'fnfe_attach' => 'bogus' ] )->attachment_policy['mode'] );
	}

	public function test_only_channels() {
		$this->set( 'second', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 's' ) ] ] );
		$this->assertSame( [ 'fake:g1', 'second:s' ], $this->plan() );
		$this->assertSame( [ 'second:s' ], $this->plan( [], [], [], [ 'second' ] ) );
	}

	private function rule( string $effect, array $more = [] ): array {
		return array_merge(
			[ 'id' => 'q_' . $effect, 'name' => '', 'enabled' => true, 'match' => 'all', 'conditions' => [ [ 'field' => 'name', 'op' => 'not_empty', 'value' => '' ] ], 'effect' => $effect, 'channels' => [], 'receivers' => [], 'stop' => false ],
			$more
		);
	}

	public function test_rule_skip() {
		$this->set( 'phone', [ 'enabled' => true ] );
		$submitter = [ 'enabled' => true, 'channels' => [ 'phone' => [ 'enabled' => true ] ] ];
		$this->assertSame( [], Resolver::plan( $this->submission(), Fixtures::config( [ 'rules' => [ $this->rule( 'skip' ) ], 'submitter' => $submitter ] ) ) );
	}

	public function test_rule_only() {
		$this->set( 'second', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 's' ) ] ] );
		$this->assertSame( [ 'second:s' ], $this->plan( [ 'rules' => [ $this->rule( 'only', [ 'channels' => [ 'second' ] ] ) ] ] ) );
	}

	public function test_rule_add() {
		$this->set( 'second', [ 'enabled' => false, 'receivers' => [ Fixtures::receiver( 's' ) ] ] );
		$add = $this->rule( 'add', [ 'channels' => [ 'fake', 'second' ], 'receivers' => [ 'fake' => [ Fixtures::receiver( 'vip' ), Fixtures::receiver( 'off', [ 'enabled' => false ] ) ], 'second' => [ Fixtures::receiver( 'boss' ) ] ] ] );
		$this->assertSame( [ 'fake:g1', 'fake:vip', 'second:boss' ], $this->plan( [ 'rules' => [ $add ] ] ) );
		// Without receivers the channel gets its usual ones.
		$this->assertSame( [ 'fake:g1', 'second:s' ], $this->plan( [ 'rules' => [ $this->rule( 'add', [ 'channels' => [ 'second' ] ] ) ] ] ) );
		// Also for a channel that the editor did not choose, never beyond an explicit restriction.
		$this->assertSame( [ 'fake:g1', 'second:s' ], $this->plan( [ 'rules' => [ $this->rule( 'add', [ 'channels' => [ 'second' ] ] ) ] ], [ 'fnfe_channels' => [ 'fake' ] ] ) );
		$this->assertSame( [ 'fake:g1' ], $this->plan( [ 'rules' => [ $this->rule( 'add', [ 'channels' => [ 'second' ] ] ) ] ], [], [], [ 'fake' ] ) );
	}

	public function test_rule_add_keeps_the_rule_id_and_language_filter() {
		$add  = $this->rule( 'add', [ 'channels' => [ 'fake' ], 'receivers' => [ 'fake' => [ Fixtures::receiver( 'vip' ), Fixtures::receiver( 'fa', [ 'langs' => [ 'fa' ] ] ) ] ] ] );
		$plan = Resolver::plan( $this->submission(), Fixtures::config( [ 'rules' => [ $add ] ] ) );
		$this->assertSame( [ '', 'q_add' ], [ $plan[0]->rule_id, $plan[1]->rule_id ] );
		$this->assertCount( 2, $plan );
	}

	public function test_rule_replace() {
		$replace = $this->rule( 'replace', [ 'channels' => [ 'fake' ], 'receivers' => [ 'fake' => [ Fixtures::receiver( 'r' ) ] ] ] );
		$this->assertSame( [ 'fake:r' ], $this->plan( [ 'rules' => [ $replace ] ] ) );
		$this->set( 'single', [ 'enabled' => true ] );
		$this->assertSame( [ 'fake:g1', 'single:default' ], $this->plan( [ 'rules' => [ $this->rule( 'replace', [ 'channels' => [ 'single' ] ] ) ] ] ) );
	}

	public function test_rule_order_and_stop() {
		$a = $this->rule( 'add', [ 'channels' => [ 'fake' ], 'receivers' => [ 'fake' => [ Fixtures::receiver( 'a' ) ] ], 'id' => 'q_1' ] );
		$b = $this->rule( 'replace', [ 'channels' => [ 'fake' ], 'receivers' => [ 'fake' => [ Fixtures::receiver( 'b' ) ] ], 'id' => 'q_2' ] );
		$this->assertSame( [ 'fake:b' ], $this->plan( [ 'rules' => [ $a, $b ] ] ) );
		$this->assertSame( [ 'fake:b', 'fake:a' ], $this->plan( [ 'rules' => [ $b, $a ] ] ) );
		$a['stop'] = true;
		$this->assertSame( [ 'fake:g1', 'fake:a' ], $this->plan( [ 'rules' => [ $a, $b ] ] ) );
	}

	public function test_rule_match_and_disabled() {
		$yes  = [ 'field' => 'email', 'op' => 'is', 'value' => 'SARA@example.com' ];
		$no   = [ 'field' => 'email', 'op' => 'is', 'value' => 'other@example.com' ];
		$only = [ 'channels' => [ 'second' ] ];
		$this->assertSame( [], $this->plan( [ 'rules' => [ $this->rule( 'only', $only + [ 'conditions' => [ $yes, $no ], 'match' => 'any' ] ) ] ] ) );
		$this->assertSame( [], $this->plan( [ 'rules' => [ array_merge( $this->rule( 'only' ), $only, [ 'conditions' => [ $yes, $no ], 'match' => 'any' ] ) ] ] ) );
		$this->assertSame( [ 'fake:g1' ], $this->plan( [ 'rules' => [ array_merge( $this->rule( 'only' ), $only, [ 'conditions' => [ $yes, $no ], 'match' => 'all' ] ) ] ] ) );
		$this->assertSame( [ 'fake:g1' ], $this->plan( [ 'rules' => [ $this->rule( 'skip', [ 'enabled' => false ] ) ] ] ) );
	}

	public function test_submitter_reply() {
		$this->set( 'phone', [ 'enabled' => true, 'template.submitter' => [ '_' => 'GLOBAL' ], 'buttons' => [
			[ 'id' => 'b_1', 'text' => [ '_' => 'Site' ], 'type' => 'url', 'value' => 'https://example.com', 'row' => 0, 'style' => '' ],
			[ 'id' => 'b_2', 'text' => [ '_' => 'Done' ], 'type' => 'callback', 'value' => 'done', 'row' => 0, 'style' => '' ],
			[ 'id' => 'b_3', 'text' => [ '_' => 'Admin' ], 'type' => 'url', 'value' => '{admin_submission_url}', 'row' => 0, 'style' => '' ],
		] ] );
		$config = [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'phone' => [ 'enabled' => true ] ] ] ];
		$d      = $this->first( $config, [], [ 'lang' => 'de' ], 'submitter' );
		$this->assertSame( Fixtures::PHONE, $d->recipient->address );
		$this->assertSame( [ 'submitter', 'de', 'GLOBAL' ], [ $d->recipient->role, $d->lang, $d->template ] );
		$this->assertSame( [ 'b_1' ], array_column( $d->buttons, 'id' ) );
		$this->assertSame( 'off', $d->attachment_policy['mode'] );

		$config['submitter']['channels']['phone']['template'] = [ '_' => 'ENTRY' ];
		$this->assertSame( 'ENTRY', $this->first( $config, [], [], 'submitter' )->template );

		$config['submitter']['enabled'] = false;
		$this->assertNull( $this->first( $config, [], [], 'submitter' ) );
	}

	public function test_submitter_consent() {
		$this->set( 'phone', [ 'enabled' => true ] );
		$config = [ 'submitter' => [ 'enabled' => true, 'consent_field' => 'agree', 'channels' => [ 'phone' => [ 'enabled' => true ] ] ] ];
		$base   = Fixtures::submission()->fields;
		$this->assertSame( [], $this->plan( $config, [], [], [], 'submitter' ) );
		$this->assertSame( [], $this->plan( $config, [], [ 'fields' => $base + [ 'agree' => Fixtures::field( 'agree', '', 'acceptance' ) ] ], [], 'submitter' ) );
		$this->assertCount( 1, $this->plan( $config, [], [ 'fields' => $base + [ 'agree' => Fixtures::field( 'agree', 'Accepted', 'acceptance' ) ] ], [], 'submitter' ) );
	}

	public function test_submitter_invalid_address_channel_off_and_field_choice() {
		$config = [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'phone' => [ 'enabled' => true, 'field' => 'name' ], 'mailer' => [ 'enabled' => true ] ] ], 'fields' => [ 'email' => 'other' ] ];
		$this->assertSame( [], $this->plan( $config, [], [], [], 'submitter' ), 'channels are off' );

		$this->set( 'phone', [ 'enabled' => true ] );
		$this->set( 'mailer', [ 'enabled' => true ] );
		$fields = array_merge( Fixtures::submission()->fields, [ 'name' => Fixtures::field( 'name', 'bad number' ), 'other' => Fixtures::field( 'other', 'second@example.com', 'text' ) ] );
		$out    = Resolver::resolve( $this->submission( [], [ 'fields' => $fields ] ), Fixtures::config( $config ) );
		$this->assertSame( [ 'phone' ], [ $out['skipped'][0]['delivery']->channel ] );
		$this->assertSame( 'submitter', $out['skipped'][0]['delivery']->purpose );
		$this->assertContains( 'mailer:second@example.com', array_map( function ( Delivery $d ) {
			return $d->channel . ':' . $d->recipient->address;
		}, $out['deliveries'] ) );
	}

	public function test_submitter_never_uses_a_redacted_field() {
		$this->set( 'phone', [ 'enabled' => true ] );
		$field  = array_merge( Fixtures::field( 'phone', '••••••', 'tel' ), [ 'redacted' => true ] );
		$config = [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'phone' => [ 'enabled' => true, 'field' => 'phone' ] ] ] ];
		$this->assertSame( [], $this->plan( $config, [], [ 'fields' => [ 'phone' => $field ] ], [], 'submitter' ) );
	}

	public function test_linked_form_uses_the_other_config() {
		$a = FormRepository::upsert( '10:aaa', [ 'name' => 'A' ] );
		$b = FormRepository::upsert( '20:bbb', [ 'name' => 'B' ] );
		FormRepository::save_config( $a, [ 'channels' => [ 'fake' => [ 'receivers_mode' => 'replace', 'receivers' => [ Fixtures::receiver( 'linked' ) ] ] ] ] );
		FormRepository::save_config( $b, [ 'link' => $a ] );
		$plan = Resolver::plan( $this->submission(), FormRepository::config( $b ) );
		$this->assertSame( 'linked', $plan[0]->recipient->address );
	}

	public function test_filters() {
		$this->filter( 'fnfe_recipients', function ( $list, $channel ) {
			return $channel === 'fake' ? array_merge( $list, [ [ 'address' => 'filtered' ], 'junk' ] ) : $list;
		}, 10, 4 );
		$this->assertSame( [ 'fake:g1', 'fake:filtered' ], $this->plan() );
		$this->filter( 'fnfe_deliveries', function ( $plan ) {
			return [ $plan[1], 'junk' ];
		}, 10, 3 );
		$this->assertSame( [ 'fake:filtered' ], $this->plan() );
	}

	public function test_fallback_ignores_on_off_and_rules() {
		$this->set( 'second', [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( 's' ) ] ] );
		$config = Fixtures::config( [ 'channels' => [ 'second' => [ 'enabled' => 'off' ] ], 'rules' => [ $this->rule( 'only', [ 'channels' => [ 'fake' ] ] ) ] ] );
		$this->assertSame( 's', Resolver::fallback( 'second', $this->submission(), $config )[0]->recipient->address );
	}
}
