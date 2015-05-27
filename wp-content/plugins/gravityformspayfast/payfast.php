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
Copyright 2009-2015 PayFast

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/


define( 'GF_PAYFAST_VERSION', '1.1' );

add_action( 'gform_loaded', array( 'GF_PayPal_Bootstrap', 'load' ), 5 );

class GF_PayPal_Bootstrap
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