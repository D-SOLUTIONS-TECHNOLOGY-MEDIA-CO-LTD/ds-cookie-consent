<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Scanner\GtmParser;
use DSolutions\CookieConsent\Scanner\HtmlScanner;
use PHPUnit\Framework\TestCase;

/**
 * Platform detection in page HTML and in a Tag Manager container.
 */
class HtmlScannerTest extends TestCase {

	private function known() {
		return KnownDb::from_json( (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' ) );
	}

	private function detect( $html ) {
		$ex = HtmlScanner::extract( $html );
		return HtmlScanner::detect( $ex['resources'], $this->known(), 'example.test' );
	}

	public function test_ga4_gtag_loader_and_inline_config() {
		$html = '<html><head><script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>'
			. "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-LITTLED000');</script></head></html>";
		$this->assertArrayHasKey( 'google-analytics', $this->detect( $html )['services'] );
	}

	public function test_meta_pixel_inline_snippet_and_script_src() {
		$html = "<script>!function(f,b,e,v,n,t,s){}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','000LittleD');fbq('track','PageView');</script>";
		$this->assertArrayHasKey( 'meta-pixel', $this->detect( $html )['services'] );
		$this->assertArrayHasKey( 'meta-pixel', $this->detect( '<script src="//connect.facebook.net/en_US/fbevents.js"></script>' )['services'] );
	}

	public function test_clarity_loader() {
		$html = '<script>(function(c,l,a,r,i,t,y){t=l.createElement(r);t.src="https://www.clarity.ms/tag/"+i;})(window,document,"clarity","script","littled000");</script>';
		$this->assertArrayHasKey( 'microsoft-clarity', $this->detect( $html )['services'] );
	}

	public function test_blocked_markup_is_read_through_data_dscc_src() {
		$html = '<script type="text/plain" data-dscc-category="analytics" data-dscc-service="microsoft-clarity" data-dscc-src="https://www.clarity.ms/tag/littled000" data-no-optimize="1"></script>'
			. '<iframe hidden data-dscc-src="https://www.youtube.com/embed/LittleD000"></iframe>';
		$services = $this->detect( $html )['services'];
		$this->assertArrayHasKey( 'microsoft-clarity', $services );
		$this->assertArrayHasKey( 'youtube', $services );
	}

	public function test_tracking_pixel_image_and_noscript_image() {
		$html = '<img src="https://www.facebook.com/tr/?id=000LittleD&amp;ev=PageView" width="1" height="1"><noscript><img src="https://www.facebook.com/tr?id=000LittleD&ev=PageView&noscript=1"></noscript>';
		$ex   = HtmlScanner::extract( $html );
		$this->assertCount( 2, $ex['resources'] );
		$this->assertStringContainsString( 'id=000LittleD&ev=PageView', $ex['resources'][0]['src'], 'entities are decoded' );
	}

	public function test_gtm_container_ids_from_snippet_and_noscript_iframe() {
		$html = "<script>(function(w,d,s,l,i){j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;})(window,document,'script','dataLayer','GTM-LITTLED');</script>"
			. '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-LITTLED2" height="0"></iframe></noscript>'
			. '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-ABCD123"></script>';
		$this->assertSame( array( 'GTM-LITTLED', 'GTM-LITTLED2', 'GTM-ABCD123' ), HtmlScanner::extract( $html )['gtm_ids'] );
	}

	public function test_a_script_that_only_mentions_gtm_in_text_is_not_a_container_source() {
		$this->assertSame( array(), HtmlScanner::extract( "<script>var note='GTM-LITTLED is our container';</script>" )['gtm_ids'] );
	}

	public function test_first_party_scripts_are_not_reported_as_third_party_and_unknown_hosts_are() {
		$html = '<script src="https://example.test/wp-includes/js/a.js"></script><script src="https://cdn.example.test/b.js"></script><script src="https://cdn.littled.example/x.js"></script><script src="/relative.js"></script>';
		$det  = $this->detect( $html );
		$this->assertSame( array( 'cdn.littled.example' ), $det['unknown_hosts'] );
		$this->assertSame( array(), $det['services'] );
	}

	public function test_tags_inside_a_script_body_are_not_taken_for_tags() {
		$html = "<script>document.write('<script src=\"https://www.clarity.ms/tag/x\"><\\/script>');</script>";
		$this->assertCount( 1, HtmlScanner::extract( $html )['resources'] );
	}

	public function test_garbage_html_does_not_fail() {
		$found = HtmlScanner::extract( '<<<script src=>>> <iframe' );
		$this->assertSame( array(), array_filter( array_column( $found['resources'], 'src' ) ), 'nothing usable is reported' );
	}

	public function test_gtm_container_parsing_finds_tag_ids_and_vendor_urls() {
		$js  = '(function(){var a=["G-LITTLED000","AW-000LITTLED","UA-123456-1","DC-1234567","https:\/\/connect.facebook.net\/en_US\/fbevents.js","\/\/static.hotjar.com\/c\/hotjar-1.js","https:\/\/www.w3.org\/2000\/svg","XG-BAD","https:\/\/www.googletagmanager.com\/gtag\/js"];})()';
		$out = GtmParser::parse( $js );
		$this->assertSame( array( 'G-LITTLED000', 'AW-000LITTLED', 'UA-123456-1', 'DC-1234567' ), $out['tag_ids'] );
		$this->assertContains( 'https://connect.facebook.net/en_US/fbevents.js', $out['urls'] );
		$this->assertContains( 'https://static.hotjar.com/c/hotjar-1.js', $out['urls'] );
		$this->assertCount( 2, $out['urls'], 'w3.org and the Tag Manager host are skipped' );

		$services = array();
		$resources = array();
		foreach ( array_merge( array_map( array( GtmParser::class, 'tag_url' ), $out['tag_ids'] ), $out['urls'] ) as $url ) {
			$resources[] = array( 'kind' => 'script', 'src' => $url, 'inline' => '' );
		}
		$found = HtmlScanner::detect( $resources, $this->known(), '' );
		$this->assertArrayHasKey( 'google-analytics', $found['services'] );
		$this->assertArrayHasKey( 'google-ads', $found['services'] );
		$this->assertArrayHasKey( 'meta-pixel', $found['services'] );
		$this->assertArrayHasKey( 'hotjar', $found['services'] );
	}
}
