<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\DurationLabel;
use PHPUnit\Framework\TestCase;

class DurationLabelTest extends TestCase {

	/**
	 * @dataProvider cases
	 */
	public function test_labels( $seconds, $vi, $en ) {
		$this->assertSame( $vi, DurationLabel::label( $seconds, 'vi' ) );
		$this->assertSame( $en, DurationLabel::label( $seconds, 'en' ) );
	}

	public function cases() {
		return array(
			'session'  => array( null, 'Phiên', 'Session' ),
			'zero'     => array( 0, 'Phiên', 'Session' ),
			'seconds'  => array( 30, '1 phút', '1 minute' ),
			'minutes'  => array( 600, '10 phút', '10 minutes' ),
			'one hour' => array( 3600, '1 giờ', '1 hour' ),
			'day'      => array( 86400, '1 ngày', '1 day' ),
			'90 days'  => array( 7776000, '3 tháng', '3 months' ),
			'year'     => array( 31536000, '1 năm', '1 year' ),
			'2 years'  => array( 63072000, '2 năm', '2 years' ),
		);
	}
}
