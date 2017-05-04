<?php
/*
Plugin Name: Gravity Forms PayFast Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with PayFast, a South African payment gateway.
Version: 1.1
Author: PayFast
Author URI: http://www.payfast.co.za
Text Domain: gravityformspayfast
Domain Path: /languages

------------------------------------------------------------------------
Copyright (c) 2008 PayFast (Pty) Ltd
You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
*/


define( 'GF_PAYFAST_VERSION', '1.1' );

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