<?php
/**
 * Custom ports for outgoing requests.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress only lets safe requests go to the ports 80, 443 and 8080. For the time of one request this opens the port of
 * its URL, for that host only, when every address of the host is public, and makes cURL connect to exactly the addresses
 * that were checked: a name that answers differently a moment later (DNS rebinding) cannot lead to another port of the
 * private network. The checks of WordPress itself stay in place, also for redirects.
 */
final class PortGuard {

	/** Ports WordPress allows anyway. */
	private const STANDARD = [ 80, 443, 8080 ];

	/** Ports of other protocols, refused by browsers for the same reason. */
	private const FORBIDDEN = [ 1, 7, 9, 11, 13, 15, 17, 19, 20, 21, 22, 23, 25, 37, 42, 43, 53, 69, 77, 79, 87, 95, 101, 102, 103, 104, 109, 110, 111, 113, 115, 117, 119, 123, 135, 137, 139, 143, 161, 179, 389, 427, 465, 512, 513, 514, 515, 526, 530, 531, 532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993, 995, 1719, 1720, 1723, 2049, 3659, 4045, 4190, 5060, 5061, 6000, 6566, 6665, 6666, 6667, 6668, 6669, 6679, 6697, 10080 ];

	/** Ranges that are not on the public internet: network => prefix length. */
	private const PRIVATE_RANGES = [
		'0.0.0.0'      => 8,
		'10.0.0.0'     => 8,
		'100.64.0.0'   => 10,
		'127.0.0.0'    => 8,
		'169.254.0.0'  => 16,
		'172.16.0.0'   => 12,
		'192.0.0.0'    => 24,
		'192.0.2.0'    => 24,
		'192.88.99.0'  => 24,
		'192.168.0.0'  => 16,
		'198.18.0.0'   => 15,
		'198.51.100.0' => 24,
		'203.0.113.0'  => 24,
		'224.0.0.0'    => 3,
	];

	/** @var string */
	private $host;

	/** @var int */
	private $port;

	/** @var string[] checked addresses of the host */
	private $addresses = [];

	/** @var string why the port stays closed */
	private $reason = '';

	/** @var callable|null host => IPv4 addresses */
	private $resolver;

	/**
	 * Guard for one URL.
	 *
	 * @param string        $url      URL of the request.
	 * @param callable|null $resolver Host => IPv4 addresses, for tests. Default: the resolver of the system.
	 */
	public function __construct( string $url, ?callable $resolver = null ) {
		$parts          = wp_parse_url( $url );
		$parts          = is_array( $parts ) ? $parts : [];
		$this->host     = strtolower( (string) ( $parts['host'] ?? '' ) );
		$this->port     = (int) ( $parts['port'] ?? 0 );
		$this->resolver = $resolver;
	}

	/** The URL names a port WordPress does not allow. */
	public function is_needed(): bool {
		return $this->port > 0 && ! in_array( $this->port, self::STANDARD, true );
	}

	/** Attach to the request. */
	public function start(): void {
		add_filter( 'http_allowed_safe_ports', [ $this, 'allow' ], 10, 3 );
		add_action( 'http_api_curl', [ $this, 'pin' ], PHP_INT_MAX );
	}

	/** Detach. */
	public function stop(): void {
		remove_filter( 'http_allowed_safe_ports', [ $this, 'allow' ], 10 );
		remove_action( 'http_api_curl', [ $this, 'pin' ], PHP_INT_MAX );
	}

	/** Why the port was not opened, '' when it was or when nobody asked. */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Filter http_allowed_safe_ports. WordPress asks after it checked the host, for the URL and for every redirect.
	 *
	 * @param mixed  $ports Allowed ports.
	 * @param string $host  Host.
	 * @param string $url   URL.
	 * @return mixed
	 */
	public function allow( $ports, $host = '', $url = '' ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $ports ) || ! is_array( $parts ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== $this->host || (int) ( $parts['port'] ?? 0 ) !== $this->port ) {
			return $ports;
		}
		$this->reason = $this->check( (string) $url );
		if ( $this->reason === '' ) {
			$ports[] = $this->port;
		}
		return $ports;
	}

	/**
	 * Action http_api_curl: connect to the checked addresses only.
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 */
	public function pin( $handle ): void {
		if ( ! $this->addresses ) {
			return;
		}
		$version   = curl_version(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_version -- The handle of the WordPress HTTP API.
		$addresses = version_compare( (string) ( $version['version'] ?? '0' ), '7.59.0', '>=' ) ? implode( ',', $this->addresses ) : $this->addresses[0];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The WordPress HTTP API has no argument for this.
		curl_setopt( $handle, CURLOPT_RESOLVE, [ $this->host . ':' . $this->port . ':' . $addresses ] );
	}

	/**
	 * Address on the public internet.
	 *
	 * @param string $ip IPv4 address.
	 */
	public static function is_public_ip( string $ip ): bool {
		$long = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? ip2long( $ip ) : false;
		if ( $long === false ) {
			return false;
		}
		foreach ( self::PRIVATE_RANGES as $network => $bits ) {
			if ( ( $long >> ( 32 - $bits ) ) === ( ip2long( $network ) >> ( 32 - $bits ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check the target, '' when the port may be used.
	 *
	 * @param string $url URL.
	 */
	private function check( string $url ): string {
		$this->addresses = [];

		// One spelling only: what is checked here must be what cURL looks up.
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/D', $this->host ) ) {
			return __( 'The address is not a valid URL.', 'forms-notifier-for-elementor' );
		}
		if ( in_array( $this->port, self::FORBIDDEN, true ) ) {
			/* translators: %1$d: port number */
			return sprintf( __( 'Port %1$d is not used for web services.', 'forms-notifier-for-elementor' ), $this->port );
		}
		// Hosts the site owner allows in code. WordPress answers yes for the site itself, which is no reason to open its ports.
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress filter.
		if ( $this->host !== $home && apply_filters( 'http_request_host_is_external', false, $this->host, $url ) ) {
			return '';
		}

		$literal   = (bool) filter_var( $this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$addresses = $literal ? [ $this->host ] : $this->resolve();
		if ( ! $addresses ) {
			return __( 'The server name cannot be found.', 'forms-notifier-for-elementor' );
		}
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( $address ) ) {
				return __( 'The address leads to a private network. Allow addresses on private networks under Advanced if this is intended.', 'forms-notifier-for-elementor' );
			}
		}
		if ( ! $literal && ! self::can_pin( $url ) ) {
			/* translators: %1$d: port number */
			return sprintf( __( 'Port %1$d cannot be used safely on this server: it needs the cURL extension and a direct connection without a proxy. Use an address without a port number.', 'forms-notifier-for-elementor' ), $this->port );
		}
		$this->addresses = $literal ? [] : $addresses;
		return '';
	}

	/**
	 * IPv4 addresses of the host.
	 *
	 * @return string[]
	 */
	private function resolve(): array {
		$found = $this->resolver ? ( $this->resolver )( $this->host ) : gethostbynamel( $this->host );
		return is_array( $found ) ? array_values( array_filter( $found, 'is_string' ) ) : [];
	}

	/**
	 * The request goes through cURL straight to the server.
	 *
	 * @param string $url URL.
	 */
	private static function can_pin( string $url ): bool {
		if ( ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) || ! \WpOrg\Requests\Transport\Curl::test( [ 'ssl' => stripos( $url, 'https:' ) === 0 ] ) ) {
			return false;
		}
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return false;
		}
		// cURL itself follows these.
		foreach ( [ 'http_proxy', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY' ] as $name ) {
			if ( (string) getenv( $name ) !== '' ) {
				return false;
			}
		}
		return true;
	}
}
