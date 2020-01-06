<?php
/*
Plugin Name:  Gravity Forms PayFast Add-On
Plugin URI:   http://www.gravityforms.com
Description:  Integrates Gravity Forms with PayFast, a South African payment gateway.
Version:      1.5.0
Author:       PayFast
Author URI:   http://www.payfast.co.za
Text Domain:  gravityformspayfast
Domain Path:  /languages
*/

include 'payfast_common.inc';
define( 'GF_PAYFAST_VERSION', PF_MODULE_VER );

add_action( 'gform_loaded', array( 'GF_PayFast_Bootstrap', 'load' ), 5 );

class GF_PayFast_Bootstrap
{
	public static function load()
    {
		if ( !method_exists( 'GFForms', 'include_payment_addon_framework' ) )
        {
			return;
		}

		require_once( 'class-gf-payfast.php' );

		GFAddOn::register( 'GFPayFast' );
	}
}

function gf_payfast()
{
	return GFPayFast::get_instance();
}