<?php
/**
 * Starts the output buffer on `template_redirect` and runs the rewrite and the server cookie guard on the finished
 * page. Started as late as possible, so it sits inside page-cache and optimiser buffers: they receive and store the
 * blocked markup, which is the same for every visitor.
 *
 * Only runs on front-end HTML pages and only when at least one rule is active.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Consent\ConsentReader;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class OutputBuffer {

	/**
	 * Rules and mode captured when the buffer starts.
	 *
	 * @var array|null
	 */
	private static $ctx = null;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), PHP_INT_MAX );
	}

	/**
	 * Start buffering when this request is a front-end page and rules exist.
	 *
	 * @return bool True when the buffer was started.
	 */
	public static function start() {
		if ( ! self::should_run() ) {
			return false;
		}
		$active = ScriptRules::active();
		if ( ! $active['rules'] && ! $active['cookies'] ) {
			return false;
		}
		$blocking  = Settings::group( 'blocking' );
		self::$ctx = array(
			'rules'      => $active['rules'],
			'cookies'    => $active['cookies'],
			'mode'       => $blocking['mode'],
			'exclusions' => (array) $blocking['exclusions'],
		);
		return ob_start( array( __CLASS__, 'callback' ) );
	}

	/**
	 * Is this request one the blocker may touch?
	 *
	 * @return bool
	 */
	public static function should_run() {
		if ( ! Renderer::should_print_head() ) {
			return false; // Admin, REST, AJAX, cron, feeds, embeds, robots, builders and previews, or the old mu-plugin is active.
		}
		if ( is_preview() || is_trackback() || is_favicon() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with a constant, never output.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		return (bool) apply_filters( 'dscc_blocker_enabled', true );
	}

	/**
	 * Output buffer callback. Never throws; on any problem the page goes out unchanged and the reason is recorded.
	 *
	 * @param string $buffer Page.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
	 * @return string|false
	 */
	public static function callback( $buffer, $phase ) {
		if ( null === self::$ctx ) {
			return false;
		}
		// Only a whole document in one piece: chunks cut by an earlier flush could split a tag.
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_START ) || ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			if ( '' !== $buffer ) {
				self::fail_open( 'partial_flush' );
			}
			return false;
		}
		try {
			if ( ! self::is_html_document( $buffer ) ) {
				return false;
			}
			$start   = microtime( true );
			$signals = array();
			$result  = self::transform( $buffer, self::$ctx, $signals );
			self::measure( ( microtime( true ) - $start ) * 1000, strlen( $buffer ) );
			foreach ( $signals as $reason ) {
				self::fail_open( $reason );
			}
			return $result;
		} catch ( \Throwable $e ) {
			self::fail_open( 'exception' );
			return false;
		}
	}

	/**
	 * Rewrite (block mode) or only report (observe mode), and guard server cookies (block mode). The cookie guard runs
	 * even when the page itself could not be rewritten.
	 *
	 * @param string   $html    Page.
	 * @param array    $ctx     Context: rules, cookies, mode, exclusions.
	 * @param string[] $signals Filled with fail-open reasons (pcre, unparsed_tag).
	 * @return string
	 */
	public static function transform( $html, array $ctx, array &$signals = array() ) {
		$block  = 'block' === $ctx['mode'];
		$result = HtmlRewriter::process(
			$html,
			$ctx['rules'],
			array(
				'rewrite'     => $block,
				'exclusions'  => $ctx['exclusions'],
				'own_url'     => defined( 'DSCC_URL' ) ? DSCC_URL : '',
				'skip'        => array( __CLASS__, 'integration_veto' ),
				'placeholder' => array( Placeholder::class, 'html' ),
			)
		);
		if ( $block ) {
			ServerCookieGuard::apply( $ctx['cookies'], ConsentReader::effective() );
		}
		if ( $result['error'] ) {
			$signals[] = 'pcre';
			return $html;
		}
		if ( $result['unparsed'] > 0 ) {
			$signals[] = 'unparsed_tag';
		}
		if ( ! $block ) {
			ObservedMatches::record( $result['matches'] );
		}
		return $result['html'];
	}

	/**
	 * Remember why a page went out unchanged.
	 *
	 * @param string $reason Reason code.
	 */
	private static function fail_open( $reason ) {
		try {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trimmed to a path and stored as text.
			FailOpenLog::record( $reason, sanitize_text_field( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );
		} catch ( \Throwable $e ) {
			return; // Reporting must never break the page either.
		}
	}

	/**
	 * Lets integrations keep a tag out of the blocker.
	 *
	 * @param string $kind  script|iframe|img.
	 * @param array  $attrs Attributes keyed by lower-case name.
	 * @param string $src   URL.
	 * @return bool
	 */
	public static function integration_veto( $kind, array $attrs, $src ) {
		return (bool) apply_filters( 'dscc_blocker_skip_tag', false, $kind, $attrs, $src );
	}

	/**
	 * Is the buffer a full HTML page (not JSON, XML or a fragment)?
	 *
	 * @param string $buffer Output.
	 * @return bool
	 */
	private static function is_html_document( $buffer ) {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) ) {
				return false !== stripos( $header, 'html' );
			}
		}
		$head = substr( $buffer, 0, 8192 );
		return false !== stripos( $head, '<html' ) || false !== stripos( $head, '<!doctype html' );
	}

	/**
	 * Hand a timing to the monitor.
	 *
	 * @param float $ms    Milliseconds.
	 * @param int   $bytes Page size.
	 */
	private static function measure( $ms, $bytes ) {
		$limit = (int) apply_filters( 'dscc_blocker_slow_ms', PerfMonitor::THRESHOLD_MS );
		if ( $ms > $limit ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trimmed to a path and stored as text.
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			PerfMonitor::record( $ms, $bytes, sanitize_text_field( $path ) );
		}
	}

	/**
	 * Forget the captured context (tests).
	 */
	public static function reset() {
		self::$ctx = null;
	}
}
