<?php

/*
Plugin Name:  Gravity Forms Payfast Add-On
Plugin URI:   https://github.com/Payfast/mod-gravityforms
Description:  Integrates Gravity Forms with Payfast, a South African payment gateway.
Version:      1.5.4
Author:       Payfast (Pty) Ltd
Author URI:   https://payfast.io
Text Domain:  gravityformspayfast
Domain Path:  /languages
*/

add_action('gform_loaded', array('GF_PayFast_Bootstrap', 'load'), 5);

class GF_PayFast_Bootstrap
{
    public static function load()
    {
        if ( ! method_exists('GFForms', 'include_payment_addon_framework')) {
            return;
        }

        require_once('class-gf-payfast.php');

        GFAddOn::register('GFPayFast');
    }
}

function gf_payfast()
{
    return GFPayFast::get_instance();
}
