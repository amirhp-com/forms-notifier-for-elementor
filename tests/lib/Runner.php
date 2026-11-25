<?php
/**
 * Test runner.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

/** Finds and runs every Test.php file below tests/cases. */
final class Runner {

	/** @var string */
	private $filter;

	/** @var bool */
	private $verbose;

	/** @var bool */
	private $pure;

	/** @var int */
	private $tests = 0;

	/** @var array[] */
	private $failures = [];

	public function __construct( string $filter, bool $verbose, bool $pure ) {
		$this->filter  = $filter;
		$this->verbose = $verbose;
		$this->pure    = $pure;
	}

	/** Run everything; returns the exit code. */
	public function run(): int {
		// Deprecations, notices and warnings raised by the plugin fail the running test.
		set_error_handler(
			static function ( $level, $message, $file, $line ) {
				if ( ! ( error_reporting() & $level ) || strpos( $file, 'forms-notifier-for-elementor' ) === false ) {
					return false;
				}
				throw new \ErrorException( $message, 0, $level, $file, $line );
			}
		);

		foreach ( $this->files() as $file ) {
			$this->run_file( $file );
		}
		restore_error_handler();

		echo "\n";
		foreach ( $this->failures as $failure ) {
			echo 'FAIL ' . $failure['name'] . "\n     " . $failure['message'] . "\n     " . $failure['where'] . "\n";
		}
		echo sprintf( "%d tests, %d assertions, %d failures\n", $this->tests, TestCase::$assertions, count( $this->failures ) );
		return $this->failures ? 1 : 0;
	}

	/** Test files in a stable order. */
	private function files(): array {
		$out      = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/cases', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( substr( $file->getFilename(), -8 ) === 'Test.php' ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	private function run_file( string $file ): void {
		if ( $this->pure && ! preg_match( '/const\s+PURE\s*=\s*true/', file_get_contents( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}
		$before = get_declared_classes();
		require_once $file;
		$class_name = '';
		foreach ( array_diff( get_declared_classes(), $before ) as $name ) {
			$reflection = new \ReflectionClass( $name );
			if ( $reflection->isSubclassOf( TestCase::class ) && ! $reflection->isAbstract() && realpath( $reflection->getFileName() ) === realpath( $file ) ) {
				$class_name = $name;
			}
		}
		if ( $class_name === '' ) {
			$this->failures[] = [
				'name'    => basename( $file ),
				'message' => 'no test class found',
				'where'   => $file,
			];
			return;
		}

		$reflection = new \ReflectionClass( $class_name );
		$label      = substr( $file, strlen( dirname( __DIR__ ) . '/cases/' ), -4 );
		$methods    = [];
		foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( strncmp( $method->getName(), 'test', 4 ) === 0 && $method->class === $class_name && ! $method->isStatic() ) {
				$name = $label . '::' . $method->getName();
				if ( $this->filter === '' || stripos( $name, $this->filter ) !== false ) {
					$methods[ $method->getName() ] = $name;
				}
			}
		}
		if ( ! $methods ) {
			return;
		}

		$failed = count( $this->failures );
		$hooks  = self::hooks();
		if ( ! $this->pure ) {
			Db::reset();
		}
		if ( $this->guard( $label . '::setUpClass', [ $class_name, 'setUpClass' ] ) ) {
			foreach ( $methods as $method => $name ) {
				$this->run_method( new $class_name(), $method, $name );
			}
			$this->guard( $label . '::tearDownClass', [ $class_name, 'tearDownClass' ] );
		}
		self::restore_hooks( $hooks );

		$bad = count( $this->failures ) - $failed;
		echo sprintf( "%s %s (%d)\n", $bad ? 'FAIL' : 'ok  ', $label, count( $methods ) );
	}

	/** Copies of the plugin's own hooks, so a class cannot leak registrations into the next one. */
	private static function hooks(): array {
		$out = [];
		foreach ( $GLOBALS['wp_filter'] ?? [] as $name => $hook ) {
			if ( strncmp( $name, 'fnfe_', 5 ) === 0 ) {
				$out[ $name ] = clone $hook;
			}
		}
		return $out;
	}

	private static function restore_hooks( array $hooks ): void {
		foreach ( array_keys( $GLOBALS['wp_filter'] ?? [] ) as $name ) {
			if ( strncmp( $name, 'fnfe_', 5 ) === 0 ) {
				unset( $GLOBALS['wp_filter'][ $name ] );
			}
		}
		foreach ( $hooks as $name => $hook ) {
			$GLOBALS['wp_filter'][ $name ] = $hook;
		}
	}

	private function run_method( TestCase $test, string $method, string $name ): void {
		++$this->tests;
		$level = ob_get_level();
		$ok    = $this->guard(
			$name,
			static function () use ( $test, $method ) {
				HttpMock::reset();
				try {
					$test->setUp();
					$test->$method();
					HttpMock::verify();
				} finally {
					try {
						$test->tearDown();
					} finally {
						$test->cleanUp();
						HttpMock::reset();
					}
				}
			}
		);
		while ( ob_get_level() > $level ) {
			$status = ob_get_status();
			// A buffer that cannot be removed would keep this loop running forever.
			if ( empty( $status['flags'] ) || ! ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
				break;
			}
		}
		if ( $this->verbose ) {
			echo '  ' . ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
		}
	}

	private function guard( string $name, callable $callback ): bool {
		try {
			$callback();
			return true;
		} catch ( \Throwable $e ) {
			$this->failures[] = [
				'name'    => $name,
				'message' => ( $e instanceof AssertionFailed ? '' : get_class( $e ) . ': ' ) . $e->getMessage(),
				'where'   => self::where( $e ),
			];
			return false;
		}
	}

	/** First frame inside a test file, otherwise the origin. */
	private static function where( \Throwable $e ): string {
		$origin = $e->getFile() . ':' . $e->getLine();
		foreach ( array_merge( [ [ 'file' => $e->getFile(), 'line' => $e->getLine() ] ], $e->getTrace() ) as $frame ) {
			if ( isset( $frame['file'] ) && substr( $frame['file'], -8 ) === 'Test.php' ) {
				$test = $frame['file'] . ':' . $frame['line'];
				return $e instanceof AssertionFailed || $test === $origin ? $test : $test . ' (thrown in ' . $origin . ')';
			}
		}
		return $origin;
	}
}
