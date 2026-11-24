<?php
/**
 * Test case base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

/** Base class of every test. Public methods starting with "test" run in file order. */
abstract class TestCase {

	use Assert;

	/** True: the class also runs without WordPress. */
	const PURE = false;

	/** @var array[] hooks added through filter() */
	private $hooks = [];

	/** Before each test method. */
	public function setUp(): void {}

	/** After each test method. */
	public function tearDown(): void {}

	/** Before the first test method of the class. */
	public static function setUpClass(): void {}

	/** After the last test method of the class. */
	public static function tearDownClass(): void {}

	/** Add a filter or action that is removed after the test. */
	protected function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->hooks[] = [ $hook, $callback, $priority ];
	}

	/** Freeze the plugin clock (filter fnfe_now). */
	protected function freeze( int $timestamp ): void {
		$this->filter(
			'fnfe_now',
			static function () use ( $timestamp ) {
				return $timestamp;
			},
			PHP_INT_MAX
		);
	}

	/** Remove hooks of the test; called by the runner. */
	public function cleanUp(): void {
		foreach ( array_reverse( $this->hooks ) as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = [];
	}
}
