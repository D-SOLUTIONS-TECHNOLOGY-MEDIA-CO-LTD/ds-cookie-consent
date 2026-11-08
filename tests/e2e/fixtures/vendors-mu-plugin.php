<?php
/**
 * Plugin Name: DSCC E2E vendor snippets
 * Description: Test only. Prints the usual third-party tracking snippets (test IDs only) and, on request, sets cookies
 *              from the server. Installed and removed by tests/e2e/fixtures/vendors.php; flags live in the option
 *              dscc_e2e_vendors (ga, ordered, meta, clarity, youtube, pixel, gtm, server_cookies, unknown_cookie,
 *              gtm_stub, scan_loopback).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is a flag switched on?
 *
 * @param string $flag Flag.
 * @return bool
 */
function dscc_e2e_flag( $flag ) {
	$flags = get_option( 'dscc_e2e_vendors', array() );
	return is_array( $flags ) && in_array( $flag, $flags, true );
}

// Cookies written by PHP, the way the Meta Pixel plugin writes _fbp.
add_action(
	'init',
	static function () {
		if ( dscc_e2e_flag( 'server_cookies' ) ) {
			setcookie( '_fbp', 'fb.1.1700000000.LittleD', time() + 7776000, '/' );
			setcookie( 'wp-wpml_current_language', 'vi', time() + 86400, '/' );
		}
		// A cookie nobody has classified: the scanner must file it as new.
		if ( dscc_e2e_flag( 'unknown_cookie' ) ) {
			setcookie( 'LittleD_unknown', 'LittleDSecretValue', time() + 86400, '/', '', false, true );
		}
	}
);

// The scanner's own HTTP requests: the Tag Manager container is answered locally (nothing leaves the machine) and
// requests to the site's published address (localhost:8888 / 8889) are sent to the web server's port 80 inside the
// container, because a wp-env container cannot reach its own published port.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( dscc_e2e_flag( 'gtm_stub' ) && 'www.googletagmanager.com' === $host && false !== strpos( $url, 'gtm.js?id=GTM-LITTLED' ) ) {
			return array(
				'headers'  => array( 'content-type' => 'application/javascript' ),
				'body'     => 'window.__gtm=1;var a=["G-LITTLED000","AW-000LITTLED","https:\/\/static.hotjar.com\/c\/hotjar-1.js","https:\/\/connect.facebook.net\/en_US\/fbevents.js"];',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( dscc_e2e_flag( 'scan_loopback' ) && 'localhost' === $host && in_array( (int) $port, array( 8888, 8889 ), true ) ) {
			$args['headers']         = array_merge( (array) $args['headers'], array( 'Host' => 'localhost:' . $port ) );
			return wp_remote_request( str_replace( 'localhost:' . $port, 'localhost', $url ), $args );
		}
		return $pre;
	},
	10,
	3
);

add_action(
	'wp_head',
	static function () {
		if ( dscc_e2e_flag( 'ga' ) ) {
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>' . "\n";
			echo "<script>window.__order=window.__order||[];window.__order.push('ga-config');window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-LITTLED000');</script>\n";
		}
		if ( dscc_e2e_flag( 'ordered' ) ) {
			echo '<script src="https://www.google-analytics.com/analytics.js"></script>' . "\n";
			echo "<script>window.__order=window.__order||[];window.__order.push('ga-create');ga('create','UA-LITTLED000','auto');</script>\n";
		}
		if ( dscc_e2e_flag( 'meta' ) ) {
			echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','000LittleD');fbq('track','PageView');</script>\n";
		}
		if ( dscc_e2e_flag( 'clarity' ) ) {
			echo "<script type=\"text/javascript\">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, \"clarity\", \"script\", \"littled000\");</script>\n";
		}
		if ( dscc_e2e_flag( 'gtm' ) ) {
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-LITTLED');</script>\n";
		}
	},
	20
);

add_action(
	'wp_footer',
	static function () {
		if ( dscc_e2e_flag( 'youtube' ) ) {
			echo '<iframe id="littled-video" width="560" height="315" src="https://www.youtube.com/embed/LittleD000" title="LittleD video" allowfullscreen></iframe>' . "\n";
		}
		if ( dscc_e2e_flag( 'pixel' ) ) {
			echo '<img id="littled-pixel" src="https://www.facebook.com/tr/?id=000LittleD&ev=PageView" width="1" height="1" alt="">' . "\n";
			echo '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=000LittleD&ev=PageView&noscript=1"/></noscript>' . "\n";
		}
		if ( dscc_e2e_flag( 'gtm' ) ) {
			echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-LITTLED" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		}
	},
	5
);
