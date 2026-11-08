<?php
/**
 * Unit test bootstrap: Composer autoloader + Brain Monkey (no WordPress loaded).
 */

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
