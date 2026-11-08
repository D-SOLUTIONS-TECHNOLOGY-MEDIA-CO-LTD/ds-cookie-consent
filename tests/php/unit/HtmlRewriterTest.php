<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Blocker\HtmlRewriter;
use DSolutions\CookieConsent\Catalog\PatternMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Table tests for the blocker markup (contract: scripts become type="text/plain" + data-dscc-*; iframes and pixels move
 * src to data-dscc-src). Rules are built from the bundled known-cookie database, so its real patterns are exercised.
 */
class HtmlRewriterTest extends TestCase {

	/**
	 * Rules for known-db services, shaped like Blocker\ScriptRules::active() output.
	 *
	 * @param string[] $slugs      Service slugs.
	 * @param string[] $categories Category override per slug.
	 * @return array[]
	 */
	private function rules( array $slugs, array $categories = array() ) {
		$db    = json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' ), true );
		$rules = array();
		foreach ( $db['services'] as $service ) {
			if ( ! in_array( $service['slug'], $slugs, true ) ) {
				continue;
			}
			$patterns = PatternMatcher::normalize_patterns( $service['script_patterns'] );
			$rules[]  = array(
				's' => $service['slug'],
				'c' => isset( $categories[ $service['slug'] ] ) ? $categories[ $service['slug'] ] : $service['category'],
				'h' => $patterns['hosts'],
				'r' => $patterns['src_regex'],
				'i' => $patterns['inline_regex'],
			);
		}
		return $rules;
	}

	private function run_rewrite( $html, array $slugs, array $opts = array(), array $categories = array() ) {
		return HtmlRewriter::process( $html, $this->rules( $slugs, $categories ), $opts );
	}

	public function test_ga4_gtag_loader_and_config_are_blocked_with_contract_markup() {
		$html   = '<head><script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>'
			. '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag(\'js\',new Date());gtag(\'config\',\'G-LITTLED000\');</script></head>';
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );

		$this->assertStringContainsString( '<script type="text/plain" data-dscc-category="analytics" data-dscc-service="google-analytics" data-no-optimize="1" async data-dscc-src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>', $result['html'] );
		$this->assertStringContainsString( '<script type="text/plain" data-dscc-category="analytics" data-dscc-service="google-analytics" data-no-optimize="1">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag(\'js\',new Date());gtag(\'config\',\'G-LITTLED000\');</script>', $result['html'] );
		$this->assertStringNotContainsString( ' src="https://www.googletagmanager', $result['html'] );
		$this->assertSame( array( 'script', 'inline' ), array_column( $result['matches'], 'kind' ) );
	}

	public function test_gtm_loader_is_only_blocked_when_a_rule_for_it_is_active() {
		$gtm = '<script>(function(w,d,s,l,i){w[l]=w[l]||[];var j=d.createElement(s);j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i;})(window,document,\'script\',\'dataLayer\',\'GTM-LITTLED\');</script>'
			. '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-LITTLED" height="0" width="0"></iframe></noscript>';

		// consent_mode (default): the service has no active rule, the page is left alone.
		$this->assertSame( $gtm, $this->run_rewrite( $gtm, array() )['html'] );

		// block mode: released by analytics OR marketing; the noscript iframe (no-JS visitors cannot consent) is removed.
		$blocked = $this->run_rewrite( $gtm, array( 'google-tag-manager' ), array(), array( 'google-tag-manager' => 'analytics marketing' ) );
		$this->assertStringContainsString( 'type="text/plain" data-dscc-category="analytics marketing" data-dscc-service="google-tag-manager"', $blocked['html'] );
		$this->assertStringContainsString( '<noscript></noscript>', $blocked['html'] );
		$this->assertStringNotContainsString( 'ns.html', $blocked['html'] );
		$this->assertCount( 2, $blocked['matches'] );
	}

	public function test_meta_pixel_inline_and_loader() {
		$html   = '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){};t=b.createElement(e);t.async=!0;t.src=v;}(window,document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');fbq(\'init\',\'000LittleD\');fbq(\'track\',\'PageView\');</script>'
			. '<script src="https://connect.facebook.net/vi_VN/fbevents.js" defer></script>'
			. '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=000LittleD&ev=PageView&noscript=1"/></noscript>';
		$result = $this->run_rewrite( $html, array( 'meta-pixel' ) );

		$this->assertSame( 2, substr_count( $result['html'], 'data-dscc-service="meta-pixel"' ) );
		$this->assertStringContainsString( ' data-dscc-src="https://connect.facebook.net/vi_VN/fbevents.js" defer></script>', $result['html'] );
		$this->assertStringContainsString( '<noscript></noscript>', $result['html'], 'the no-JS pixel goes too' );
	}

	/**
	 * @dataProvider vendor_snippets
	 */
	public function test_vendor_snippets_are_blocked( $slug, $category, $html ) {
		$result = $this->run_rewrite( $html, array( $slug ) );
		$this->assertStringContainsString( 'type="text/plain" data-dscc-category="' . $category . '" data-dscc-service="' . $slug . '"', $result['html'] );
		$this->assertSame( $slug, $result['matches'][0]['service'] );
	}

	public function vendor_snippets() {
		return array(
			'tiktok pixel'   => array( 'tiktok-pixel', 'marketing', '<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.load(\'LITTLED000\');ttq.page();}(window,document,\'ttq\');</script>' ),
			'tiktok loader'  => array( 'tiktok-pixel', 'marketing', '<script async src="https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=LITTLED000"></script>' ),
			'clarity'        => array( 'microsoft-clarity', 'analytics', '<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;})(window, document, "clarity", "script", "littled000");</script>' ),
			'yandex inline'  => array( 'yandex-metrica', 'analytics', '<script>(function(m,e,t,r,i,k,a){})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym(12345678, "init", {clickmap:true});</script>' ),
			'yandex loader'  => array( 'yandex-metrica', 'analytics', '<script src="https://mc.yandex.ru/metrika/tag.js"></script>' ),
			'hotjar'         => array( 'hotjar', 'analytics', '<script>(function(h,o,t,j,a,r){h.hj=h.hj||function(){};h._hjSettings={hjid:1234567,hjsv:6};})(window,document);</script>' ),
		);
	}

	public function test_youtube_iframe_gets_hidden_src_and_a_placeholder_before_it() {
		$html   = '<p>Video</p><iframe width="560" height="315" src="https://www.youtube.com/embed/LittleD000" title="v" allowfullscreen></iframe><p>after</p>';
		$result = $this->run_rewrite(
			$html,
			array( 'youtube' ),
			array(
				'placeholder' => static function ( $info ) {
					return '<div class="dscc-placeholder" data-w="' . $info['width'] . '" data-s="' . $info['service'] . '"></div>';
				},
			)
		);
		$this->assertSame(
			'<p>Video</p><div class="dscc-placeholder" data-w="560" data-s="youtube"></div><iframe data-dscc-category="marketing" data-dscc-service="youtube" hidden width="560" height="315" data-dscc-src="https://www.youtube.com/embed/LittleD000" title="v" allowfullscreen></iframe><p>after</p>',
			$result['html']
		);
		$this->assertSame( 'iframe', $result['matches'][0]['kind'] );
	}

	public function test_youtube_nocookie_and_maps_iframes() {
		$html   = '<iframe src="//www.youtube-nocookie.com/embed/x"></iframe><iframe src="https://www.google.com/maps/embed?pb=1"></iframe>';
		$result = $this->run_rewrite( $html, array( 'youtube', 'google-maps' ) );
		$this->assertSame( 2, substr_count( $result['html'], ' hidden ' ) );
		$this->assertStringContainsString( 'data-dscc-service="google-maps"', $result['html'] );
	}

	public function test_standalone_tracking_pixel_image_is_blocked_but_content_images_are_not() {
		$pixel   = '<img src="https://www.facebook.com/tr/?id=000LittleD&ev=PageView" width="1" height="1" alt="">';
		$content = '<img src="https://connect.facebook.net/vi_VN/fbevents.js.png" width="600" height="400">';
		$result  = $this->run_rewrite( $pixel . $content, array( 'meta-pixel' ) );
		$this->assertStringContainsString( '<img data-dscc-category="marketing" data-dscc-service="meta-pixel" data-dscc-src="https://www.facebook.com/tr/?id=000LittleD&ev=PageView" width="1" height="1" alt="">', $result['html'] );
		$this->assertStringEndsWith( $content, $result['html'] );
	}

	public function test_ignore_attribute_json_ld_templates_and_foreign_types_are_untouched() {
		$html = '<script data-dscc-ignore src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>'
			. '<script type="application/ld+json">{"@type":"Thing","url":"https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"}</script>'
			. '<script type="text/template">gtag(\'config\',\'G-LITTLED000\')</script>'
			. '<script type="text/plain" data-other>gtag(\'config\',\'G-LITTLED000\')</script>'
			. '<script type="litespeed/javascript" data-src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>'
			. '<!-- <script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script> -->'
			. '<style>.x{background:url(https://www.google-analytics.com/a.png)}</style>';
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );
		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['matches'] );
	}

	public function test_module_scripts_keep_their_original_type() {
		$html   = '<script type="module" src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>';
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );
		$this->assertSame( '<script type="text/plain" data-dscc-category="analytics" data-dscc-service="google-analytics" data-dscc-type="module" data-no-optimize="1" data-dscc-src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>', $result['html'] );
	}

	public function test_attribute_order_values_and_quoted_greater_than_are_preserved() {
		$html   = "<script id='ga' data-x=\"a>b\" crossorigin=anonymous src='https://www.google-analytics.com/analytics.js' integrity=\"sha384-abc\" data-y></script>";
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );
		$this->assertSame(
			"<script type=\"text/plain\" data-dscc-category=\"analytics\" data-dscc-service=\"google-analytics\" data-no-optimize=\"1\" id='ga' data-x=\"a>b\" crossorigin=anonymous data-dscc-src='https://www.google-analytics.com/analytics.js' integrity=\"sha384-abc\" data-y></script>",
			$result['html']
		);
	}

	public function test_inline_script_with_jquery_gets_the_optimizer_marker_but_keeps_its_code() {
		$code   = 'jQuery(function(){ fbq(\'init\',\'000LittleD\'); });';
		$result = $this->run_rewrite( '<script>' . $code . '</script>', array( 'meta-pixel' ) );
		$this->assertStringContainsString( '>' . HtmlRewriter::INLINE_MARKER . $code . '</script>', $result['html'] );

		$plain = $this->run_rewrite( '<script>fbq(\'init\',\'000LittleD\');</script>', array( 'meta-pixel' ) );
		$this->assertStringNotContainsString( HtmlRewriter::INLINE_MARKER, $plain['html'] );
	}

	public function test_exclusions_by_substring_and_regex() {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script><script src="https://www.google-analytics.com/analytics.js"></script>';
		$one  = $this->run_rewrite( $html, array( 'google-analytics' ), array( 'exclusions' => array( 'G-LITTLED000' ) ) );
		$this->assertSame( 1, substr_count( $one['html'], 'data-dscc-service' ) );
		$this->assertStringContainsString( '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>', $one['html'] );

		$two = $this->run_rewrite( $html, array( 'google-analytics' ), array( 'exclusions' => array( '/google-analytics\.com\/analytics/' ) ) );
		$this->assertStringContainsString( '<script src="https://www.google-analytics.com/analytics.js"></script>', $two['html'] );
		$this->assertSame( 1, substr_count( $two['html'], 'data-dscc-service' ) );

		$bad = $this->run_rewrite( $html, array( 'google-analytics' ), array( 'exclusions' => array( '/(a+)+$/', '  ' ) ) );
		$this->assertSame( 2, substr_count( $bad['html'], 'data-dscc-service' ), 'an unsafe regex entry is ignored, never applied' );
	}

	public function test_observe_mode_reports_matches_and_changes_nothing() {
		$html   = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script><iframe src="https://www.youtube.com/embed/x"></iframe>';
		$result = $this->run_rewrite( $html, array( 'google-analytics', 'youtube' ), array( 'rewrite' => false ) );
		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array( 'google-analytics', 'youtube' ), array_column( $result['matches'], 'service' ) );
		$this->assertStringContainsString( 'gtag/js', $result['matches'][0]['sample'], 'only the matched fragment is kept' );
	}

	public function test_integration_veto_and_own_scripts_are_skipped() {
		$html   = '<script id="google_gtagjs" src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script><script src="https://example.test/wp-content/plugins/ds/assets/x.js?u=https://www.google-analytics.com/analytics.js"></script>';
		$result = $this->run_rewrite(
			$html,
			array( 'google-analytics' ),
			array(
				'own_url' => 'https://example.test/wp-content/plugins/ds/',
				'skip'    => static function ( $kind, $attrs ) {
					return isset( $attrs['id'] ) && 'google_gtagjs' === $attrs['id']['value'];
				},
			)
		);
		$this->assertSame( $html, $result['html'] );
	}

	public function test_processing_twice_changes_nothing_more() {
		$html  = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script><iframe src="https://www.youtube.com/embed/x"></iframe>';
		$once  = $this->run_rewrite( $html, array( 'google-analytics', 'youtube' ) );
		$twice = $this->run_rewrite( $once['html'], array( 'google-analytics', 'youtube' ) );
		$this->assertSame( $once['html'], $twice['html'] );
	}

	public function test_output_is_the_same_whoever_asks() {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>';
		$this->assertSame( $this->run_rewrite( $html, array( 'google-analytics' ) ), $this->run_rewrite( $html, array( 'google-analytics' ) ) );
	}

	public function test_no_rules_returns_the_page_untouched() {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>';
		$this->assertSame( $html, HtmlRewriter::process( $html, array() )['html'] );
	}

	public function test_one_huge_inline_script_does_not_stop_the_blocker() {
		$html   = '<html><body><script>var state="' . str_repeat( 'a<b ', 600000 ) . '";</script><script src="https://www.google-analytics.com/analytics.js"></script></body></html>';
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );
		$this->assertFalse( $result['error'], 'a 2.4 MB script must not hit the PCRE backtrack limit' );
		$this->assertSame( 1, substr_count( $result['html'], 'data-dscc-service="google-analytics"' ) );
	}

	public function test_a_large_page_is_processed_quickly() {
		$block = '<div class="x"><p>text</p><script>var a=1;</script><img src="/a.png" width="10" height="10"></div>' . "\n";
		$html  = '<html><body>' . str_repeat( $block, 6000 ) . '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script></body></html>';
		$start = microtime( true );
		$out   = $this->run_rewrite( $html, array( 'google-analytics', 'meta-pixel', 'microsoft-clarity', 'youtube' ) );
		$ms    = ( microtime( true ) - $start ) * 1000;
		$this->assertFalse( $out['error'] );
		$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $out['html'] );
		$this->assertLessThan( 500, $ms, 'a 600 KB page must not take half a second' );
	}

	/**
	 * @dataProvider ga_loader_urls
	 */
	public function test_google_tag_loader_variants_are_blocked( $src ) {
		$result = $this->run_rewrite( '<script async src="' . $src . '"></script>', array( 'google-analytics', 'google-ads' ) );
		$this->assertStringContainsString( 'data-dscc-src="' . $src . '"', $result['html'] );
	}

	public function ga_loader_urls() {
		return array(
			'id after l'    => array( 'https://www.googletagmanager.com/gtag/js?l=dataLayer&id=G-LITTLED000' ),
			'GT id'         => array( 'https://www.googletagmanager.com/gtag/js?id=GT-LITTLED00' ),
			'UA id'         => array( 'https://www.googletagmanager.com/gtag/js?id=UA-0000-1' ),
			'AW id'         => array( 'https://www.googletagmanager.com/gtag/js?l=dataLayer&id=AW-000000' ),
		);
	}

	public function test_unquoted_src_with_equals_and_inline_config_variants() {
		$unquoted = $this->run_rewrite( '<script src=https://www.googletagmanager.com/gtag/js?id=G-LITTLED000></script>', array( 'google-analytics' ) );
		$this->assertStringContainsString( 'data-dscc-src=https://www.googletagmanager.com/gtag/js?id=G-LITTLED000', $unquoted['html'] );
		foreach ( array( 'G-LITTLED000', 'GT-LITTLED00', 'UA-0000-1' ) as $id ) {
			$inline = $this->run_rewrite( "<script>gtag('config', '$id');</script>", array( 'google-analytics' ) );
			$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $inline['html'], $id );
		}
	}

	public function test_lazy_iframes_with_data_src_are_blocked_and_the_lazy_attribute_is_parked() {
		$html   = '<iframe class="lazy" data-lazy-src="https://www.youtube.com/embed/LittleD000" src="about:blank"></iframe><iframe data-src="https://www.google.com/maps/embed?pb=1"></iframe>';
		$result = $this->run_rewrite( $html, array( 'youtube', 'google-maps' ) );
		$this->assertSame( 2, substr_count( $result['html'], ' hidden ' ) );
		$this->assertStringContainsString( 'data-dscc-src="https://www.youtube.com/embed/LittleD000" src="about:blank"', $result['html'] );
		$this->assertStringNotContainsString( 'data-lazy-src', $result['html'] );
		$this->assertStringNotContainsString( ' data-src=', $result['html'] );
	}

	public function test_noscript_keeps_content_that_is_not_a_tracker() {
		$html   = '<noscript><p>Enable JS</p><img src="/logo.png" width="1" height="1"><iframe src="https://example.test/ok"></iframe></noscript>';
		$result = $this->run_rewrite( $html, array( 'meta-pixel', 'youtube' ) );
		$this->assertSame( $html, $result['html'] );
	}

	public function test_textarea_content_and_look_alike_tags_are_not_rewritten() {
		$html   = '<textarea><script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script></textarea><script-foo src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script-foo>';
		$result = $this->run_rewrite( $html, array( 'google-analytics' ) );
		$this->assertSame( $html, $result['html'] );
	}

	public function test_a_tag_with_far_too_many_attributes_is_left_alone_and_counted() {
		$attrs  = str_repeat( ' data-a="1"', 1500 );
		$html   = '<iframe' . $attrs . ' src="https://www.youtube.com/embed/x"></iframe><script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>';
		$result = $this->run_rewrite( $html, array( 'youtube', 'google-analytics' ) );
		$this->assertSame( 1, $result['unparsed'] );
		$this->assertStringStartsWith( '<iframe' . $attrs, $result['html'] );
		$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $result['html'], 'the rest of the page is still processed' );
	}

	public function test_unclosed_comment_and_unclosed_script_do_not_break_the_scan() {
		$tracker = '<script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>';
		$this->assertStringContainsString( 'data-dscc-service', $this->run_rewrite( $tracker . '<!-- never closed ' . str_repeat( 'x', 2000000 ), array( 'google-analytics' ) )['html'] );
		$this->assertFalse( $this->run_rewrite( $tracker . '<script>var a=1;', array( 'google-analytics' ) )['error'] );
	}

	public function test_slug_and_category_are_escaped_in_attributes() {
		$rules  = array(
			array(
				's' => 'x"y',
				'c' => 'marketing',
				'h' => array( 'tracker.littled.test' ),
				'r' => array(),
				'i' => array(),
			),
		);
		$result = HtmlRewriter::process( '<script src="https://tracker.littled.test/a.js"></script>', $rules );
		$this->assertStringContainsString( 'data-dscc-service="x&quot;y"', $result['html'] );
	}
}
