<?php
/*
Plugin Name: Gravity Forms PayFast Add-On
Plugin URI: http://www.payfast.co.za/s/std/gravity_forms
Description: Integrates Gravity Forms with PayFast, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.0.1
Author: Ron Darby
Author URI: http://www.payfast.co.za

------------------------------------------------------------------------
Copyright 2009 rocketgenius
last updated: October 20, 2010

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

add_action('parse_request', array("GFPayFast", "process_itn"));
add_action('wp',  array('GFPayFast', 'maybe_thankyou_page'), 5);

add_action('init',  array('GFPayFast', 'init'));
add_filter("gform_currencies", array("GFPayFast","currencyZar"));
register_activation_hook( __FILE__, array("GFPayFast", "add_permissions"));

class GFPayFast {

    private static $path = "gravityformspayfast/payfast.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravityformspayfast";
    private static $version = "1.0.1";
    private static $min_gravityforms_version = "1.6.4";
    private static $production_url = "https://www.payfast.co.za/eng/process/";
    private static $sandbox_url = "https://sandbox.payfast.co.za/eng/process/";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                    "post_tags", "post_custom_field", "post_content", "post_excerpt");

    //Plugin starting point. Will load appropriate files
    public static function init(){
        //supports logging
        add_filter("gform_logging_supported", array("GFPayFast", "set_logging_supported"));

        if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain('gravityformspayfast', FALSE, '/gravityformspayfast/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFPayFast', 'plugin_row') );

        }

        if(!self::is_gravityforms_supported())
           return;

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformspayfast', FALSE, '/gravityformspayfast/languages' );

            //automatic upgrade hooks
            add_filter("transient_update_plugins", array('GFPayFast', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFPayFast', 'check_update'));
            add_action('install_plugins_pre_plugin-information', array('GFPayFast', 'display_changelog'));
            add_action('gform_after_check_update', array("GFPayFast", 'flush_version_info'));

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFPayFast", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFPayFast', 'create_menu'));

            //add actions to allow the payment status to be modified
            add_action('gform_payment_status', array('GFPayFast','admin_edit_payment_status'), 3, 3);
            add_action('gform_entry_info', array('GFPayFast','admin_edit_payment_status_details'), 4, 2);
            add_action('gform_after_update_entry', array('GFPayFast','admin_update_payment'), 4, 2);


            if(self::is_payfast_page()){

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFPayFast', 'tooltips'));

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(self::get_base_path() . "/data.php");

                //loading upgrade lib
                if(!class_exists("RGPayFastUpgrade"))
                    require_once("plugin-upgrade.php");



                //runs the setup when version changes
                self::setup();

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(self::get_base_path() . "/data.php");

                add_action('wp_ajax_gf_payfast_update_feed_active', array('GFPayFast', 'update_feed_active'));
                add_action('wp_ajax_gf_select_payfast_form', array('GFPayFast', 'select_payfast_form'));
                add_action('wp_ajax_gf_payfast_confirm_settings', array('GFPayFast', 'confirm_settings'));
                add_action('wp_ajax_gf_payfast_load_notifications', array('GFPayFast', 'load_notifications'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("PayFast", array("GFPayFast", "settings_page"), self::get_base_url() . "/images/payfast_wordpress_icon_32.png");
            }
        }
        else{
            //loading data class
            require_once(self::get_base_path() . "/data.php");

            //handling post submission.
            add_filter("gform_confirmation", array("GFPayFast", "send_to_payfast"), 1000, 4);

            //setting some entry metas
            //add_action("gform_after_submission", array("GFPayFast", "set_entry_meta"), 5, 2);

            add_filter("gform_disable_post_creation", array("GFPayFast", "delay_post"), 10, 3);
            add_filter("gform_disable_user_notification", array("GFPayFast", "delay_autoresponder"), 10, 3);
            add_filter("gform_disable_admin_notification", array("GFPayFast", "delay_admin_notification"), 10, 3);
            add_filter("gform_disable_notification", array("GFPayFast", "delay_notification"), 10, 4);

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFPayFast', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFPayFast', 'premium_update') );
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('gf_payfast_update_feed_active','gf_payfast_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPayFastData::get_feed($id);
        GFPayFastData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //-------------- Automatic upgrade ---------------------------------------


    //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }

    public static function flush_version_info(){
        if(!class_exists("RGPayFastUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayFastUpgrade::set_version_info(false);
    }

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s", "gravityformspayfast"), "<a href='http://www.gravityforms.com'>", "</a>");
            RGPayFastUpgrade::display_plugin_message($message, true);
        }
        else{
            $version_info = RGPayFastUpgrade::get_version_info(self::$slug, self::get_key(), self::$version, true );
            
        }
    }

    //Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        //loading upgrade lib
        if(!class_exists("RGPayFastUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayFastUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
        if(!class_exists("RGPayFastUpgrade"))
            require_once("plugin-upgrade.php");

        return RGPayFastUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------

    //Creates PayFast left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_payfast");
        if(!empty($permission))
            $menus[] = array("name" => "gf_payfast", "label" => __("PayFast", "gravityformspayfast"), "callback" =>  array("GFPayFast", "payfast_page"), "permission" => $permission);

        return $menus;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_payfast_version") != self::$version)
            GFPayFastData::update_table();

        update_option("gf_payfast_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $payfast_tooltips = array(
            "payfast_merchantId" => "<h6>" . __("PayFast Email Address", "gravityformspayfast") . "</h6>" . __("Enter the PayFast email address where payment should be received.", "gravityformspayfast"),
            "payfast_merchantKey" => "<h6>" . __("PayFast Email Address", "gravityformspayfast") . "</h6>" . __("Enter the PayFast email address where payment should be received.", "gravityformspayfast"),
            "payfast_sandbox" => "<h6>" . __("Sandbox", "gravityformspayfast") . "</h6>" . __("Select Live to receive live payments. Select Test for testing purposes when using the PayFast development sandbox.", "gravityformspayfast"),
            "payfast_debug" => "<h6>" . __("Debug", "gravityformspayfast") . "</h6>" . __("Switch debugging on or off.", "gravityformspayfast"),
            "payfast_transaction_type" => "<h6>" . __("Transaction Type", "gravityformspayfast") . "</h6>" . __("Select which PayFast transaction type should be used. Products and Services, Donations or Subscription.", "gravityformspayfast"),
            "payfast_gravity_form" => "<h6>" . __("Gravity Form", "gravityformspayfast") . "</h6>" . __("Select which Gravity Forms you would like to integrate with PayFast.", "gravityformspayfast"),
            "payfast_customer" => "<h6>" . __("Customer", "gravityformspayfast") . "</h6>" . __("Map your Form Fields to the available PayFast customer information fields.", "gravityformspayfast"),
            "payfast_page_style" => "<h6>" . __("Page Style", "gravityformspayfast") . "</h6>" . __("This option allows you to select which PayFast page style should be used if you have setup a custom payment page style with PayFast.", "gravityformspayfast"),
            "payfast_continue_button_label" => "<h6>" . __("Continue Button Label", "gravityformspayfast") . "</h6>" . __("Enter the text that should appear on the continue button once payment has been completed via PayFast.", "gravityformspayfast"),
            "payfast_cancel_url" => "<h6>" . __("Cancel URL", "gravityformspayfast") . "</h6>" . __("Enter the URL the user should be sent to should they cancel before completing their PayFast payment.", "gravityformspayfast"),
            "payfast_options" => "<h6>" . __("Options", "gravityformspayfast") . "</h6>" . __("Turn on or off the available PayFast checkout options.", "gravityformspayfast"),
            "payfast_conditional" => "<h6>" . __("PayFast Condition", "gravityformspayfast") . "</h6>" . __("When the PayFast condition is enabled, form submissions will only be sent to PayFast when the condition is met. When disabled all form submissions will be sent to PayFast.", "gravityformspayfast"),
            "payfast_edit_payment_amount" => "<h6>" . __("Amount", "gravityformspayfast") . "</h6>" . __("Enter the amount the user paid for this transaction.", "gravityformspayfast"),
            "payfast_edit_payment_date" => "<h6>" . __("Date", "gravityformspayfast") . "</h6>" . __("Enter the date of this transaction.", "gravityformspayfast"),
            "payfast_edit_payment_transaction_id" => "<h6>" . __("Transaction ID", "gravityformspayfast") . "</h6>" . __("The transacation id is returned from PayFast and uniquely identifies this payment.", "gravityformspayfast"),
            "payfast_edit_payment_status" => "<h6>" . __("Status", "gravityformspayfast") . "</h6>" . __("Set the payment status. This status can only be altered if not currently set to Approved and not a subscription.", "gravityformspayfast")
        );
        return array_merge($tooltips, $payfast_tooltips);
    }

    public static function delay_post($is_disabled, $form, $lead){
        //loading data class
        require_once(self::get_base_path() . "/data.php");

        $config = GFPayFastData::get_feed_by_form($form["id"]);
        if(!$config)
            return $is_disabled;

        $config = $config[0];
        if(!self::has_payfast_condition($form, $config))
            return $is_disabled;

        return $config["meta"]["delay_post"] == true;
    }

    //Kept for backwards compatibility
    public static function delay_admin_notification($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_notification"]) ? $config["meta"]["delay_notification"] == true : $is_disabled;
    }

    //Kept for backwards compatibility
    public static function delay_autoresponder($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_autoresponder"]) ? $config["meta"]["delay_autoresponder"] == true : $is_disabled;
    }

    public static function delay_notification($is_disabled, $notification, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        $selected_notifications = is_array(rgar($config["meta"], "selected_notifications")) ? rgar($config["meta"], "selected_notifications") : array();

        return isset($config["meta"]["delay_notifications"]) && in_array($notification["id"], $selected_notifications) ? true : $is_disabled;
    }

    private static function get_selected_notifications($config, $form){
        $selected_notifications = is_array(rgar($config['meta'], 'selected_notifications')) ? rgar($config['meta'], 'selected_notifications') : array();

        if(empty($selected_notifications)){
            //populating selected notifications so that their delayed notification settings get carried over
            //to the new structure when upgrading to the new PayFast Add-On
            if(!rgempty("delay_autoresponder", $config['meta'])){
                $user_notification = self::get_notification_by_type($form, "user");
                if($user_notification)
                    $selected_notifications[] = $user_notification["id"];
            }

            if(!rgempty("delay_notification", $config['meta'])){
                $admin_notification = self::get_notification_by_type($form, "admin");
                if($admin_notification)
                    $selected_notifications[] = $admin_notification["id"];
            }
        }

        return $selected_notifications;
    }

    private static function get_notification_by_type($form, $notification_type){
        if(!is_array($form["notifications"]))
            return false;

        foreach($form["notifications"] as $notification){
            if($notification["type"] == $notification_type)
                return $notification;
        }

        return false;

    }

    public static function payfast_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the payfast feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("PayFast Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformspayfast"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_payfast_list");

            $id = absint($_POST["action_argument"]);
            GFPayFastData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformspayfast") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_payfast_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPayFastData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformspayfast") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("PayFast Transactions", "gravityformspayfast") ?>" src="<?php echo self::get_base_url()?>/images/payfast_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php
            _e("PayFast Forms", "gravityformspayfast");

            if(get_option("gf_payfast_settings")){
                ?>
                <a class="button add-new-h2" href="admin.php?page=gf_payfast&view=stats"><?php _e("View Statistics", "gravityformspayfast") ?></a>
                <a class="button add-new-h2" href="admin.php?page=gf_payfast&view=edit&id=0"><?php _e("Add New", "gravityformspayfast") ?></a>
                <?php
            }
            ?>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_payfast_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformspayfast") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformspayfast") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformspayfast") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformspayfast") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformspayfast") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspayfast") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspayfast") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspayfast") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspayfast") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspayfast") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php


                        $settings = GFPayFastData::get_feeds();
                        if(!get_option("gf_payfast_settings")){
                            ?>
                            <tr>
                                <td colspan="3" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sPayFast Settings%s.", "gravityformspayfast"), '<a href="admin.php?page=gf_settings&addon=PayFast">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravityformspayfast") : __("Inactive", "gravityformspayfast");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravityformspayfast") : __("Inactive", "gravityformspayfast");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_payfast&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravityformspayfast") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravityformspayfast")?>" href="admin.php?page=gf_payfast&view=edit&id=<?php echo $setting["id"] ?>" ><?php _e("Edit", "gravityformspayfast") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Stats", "gravityformspayfast")?>" href="admin.php?page=gf_payfast&view=stats&id=<?php echo $setting["id"] ?>"><?php _e("Stats", "gravityformspayfast") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Entries", "gravityformspayfast")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>"><?php _e("Entries", "gravityformspayfast") ?></a>
                                            |
                                            </span>
                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravityformspayfast") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformspayfast") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspayfast") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravityformspayfast")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
                                            switch($setting["meta"]["type"]){
                                                case "product" :
                                                    _e("Product and Services", "gravityformspayfast");
                                                break;
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any PayFast feeds configured. Let's go %screate one%s!", "gravityformspayfast"), '<a href="admin.php?page=gf_payfast&view=edit&id=0">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformspayfast") ?>').attr('alt', '<?php _e("Inactive", "gravityformspayfast") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformspayfast") ?>').attr('alt', '<?php _e("Active", "gravityformspayfast") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_payfast_update_feed_active" );
                mysack.setVar( "gf_payfast_update_feed_active", "<?php echo wp_create_nonce("gf_payfast_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformspayfast" ) ?>' )};
                mysack.runAJAX();

                return true;
            }


        </script>
        <?php
    }

    public static function load_notifications(){
        $form_id = $_POST["form_id"];
        $form = RGFormsModel::get_form_meta($form_id);
        $notifications = array();
        if(is_array(rgar($form, "notifications"))){
            foreach($form["notifications"] as $notification){
                $notifications[] = array("name" => $notification["name"], "id" => $notification["id"]);
            }
        }
        die(json_encode($notifications));
    }

    

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_payfast_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms PayFast Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformspayfast")?></div>
            <?php
            return;
        }
        elseif(isset($_POST["gf_payfast_settings"]))
        {
            check_admin_referer("update", "gf_payfast_update");
            $settings = array(  "merchantId" => $_POST["gf_payfast_merchantId"],
                                "merchantKey" => $_POST["gf_payfast_merchantKey"]
                            );
            update_option("gf_payfast_settings", $settings);
            $settings = get_option("gf_payfast_settings");
            $updated = true;
        }
        else
        {
            $settings = get_option("gf_payfast_settings");
        }
        if(isset($updated) && $updated)
        {
           ?>
           <div class="updated fade" style="padding:6px;">
            <p><?php _e("Your PayFast Settings have been updated.", "gravityformspayfast"); ?></p>
           </div>
           <?php 
        }
        ?>

       <form action="" method="post">
            <?php wp_nonce_field("update", "gf_payfast_update") ?>

            <table class="form-table">
                <tr>
                    <td colspan="2">
                        <h3><?php _e("PayFast Settings", "gravityformspayfast") ?></h3>                       
                    </td>
                </tr>
           

                <tr>
                    <th scope="row"><label for="gf_payfast_merchantId"><?php _e("Merchant ID", "gravityformspayfast"); ?></label> </th>
                    <td width="80%">
                        <input class="size-1" id="gf_payfast_merchantId" name="gf_payfast_merchantId" value="<?php echo esc_attr($settings["merchantId"]) ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_payfast_merchantKey"><?php _e("Merchant Key", "gravityformspayfast"); ?></label> </th>
                    <td width="80%">
                        <input type="text" class="size-1" id="gf_payfast_merchantKey" name="gf_payfast_merchantKey" value="<?php echo esc_attr($settings["merchantKey"]) ?>" />
                    </td>
                </tr>

                <tr>
                    <td colspan="2" ><input type="submit" name="gf_payfast_settings" class="button-primary" value="<?php _e("Save Settings", "gravityformspayfast") ?>" /></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_payfast_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_payfast_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall PayFast Add-On", "gravityformspayfast") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL PayFast Feeds.", "gravityformspayfast") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall PayFast Add-On", "gravityformspayfast") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL PayFast Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformspayfast") . '\');"/>';
                    echo apply_filters("gform_payfast_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . __("Select a product", "gravityformspayfast") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

    private static function stats_page(){
        ?>
        <style>
          .payfast_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
        .payfast_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
        .payfast_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
        .payfast_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
        .payfast_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
        .payfast_summary_title {}
        #payfast_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
        #payfast_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

        .payfast_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
        .payfast_tooltip_sales {line-height:130%;}
        .payfast_tooltip_revenue {line-height:130%;}
            .payfast_tooltip_revenue .payfast_tooltip_heading {}
            .payfast_tooltip_revenue .payfast_tooltip_value {}
            .payfast_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("PayFast", "gravityformspayfast") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/payfast_wordpress_icon_32.png"/>
            <h2><?php _e("PayFast Stats", "gravityformspayfast") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_payfast&view=stats&id=<?php echo $_GET["id"] ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_payfast&view=stats&id=<?php echo $_GET["id"] ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_payfast&view=stats&id=<?php echo $_GET["id"] ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
                $config = GFPayFastData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
                    <div class="payfast_message_container"><?php _e("No payments have been made yet.", "gravityformspayfast") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
                    <div class="payfast_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var payfast_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#payfast_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, payfast_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#payfast_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }

                        function showTooltip(x, y, contents) {
                            jQuery('<div id="payfast_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }


                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }
                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravityformspayfast") ?>" + number.substring(number.length-2);
                        }

                        function getCurrentCurrency(){
                            <?php
                            if(!class_exists("RGCurrency"))
                                require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

                            $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                            ?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
                }
                $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                $transaction_totals = GFPayFastData::get_transaction_totals($config["form_id"]);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = __("Total Orders", "gravityformspayfast");
                    break;
                }

                $total_revenue = empty($transaction_totals["payment"]["revenue"]) ? 0 : $transaction_totals["payment"]["revenue"];
                ?>
                <div class="payfast_summary_container">
                    <div class="payfast_summary_item">
                        <div class="payfast_summary_title"><?php _e("Total Revenue", "gravityformspayfast")?></div>
                        <div class="payfast_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="payfast_summary_item">
                        <div class="payfast_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="payfast_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="payfast_summary_item">
                        <div class="payfast_summary_title"><?php echo $sales_label?></div>
                        <div class="payfast_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="payfast_summary_item">
                        <div class="payfast_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="payfast_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
                    <div class="payfast_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravityformspayfast") ?></div>
                    <?php
                }
                ?>
            </form>
        </div>
        <?php
    }
    private function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

        $tz_offset = self::get_mysql_tz_offset();

        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_payfast_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";

        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";
                
                $sales_line = "<div class='payfast_tooltip_sales'><span class='payfast_tooltip_heading'>" . __("Orders", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->new_sales . "</span></div>";
                

                $tooltips .= "\"<div class='payfast_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='payfast_tooltip_revenue'><span class='payfast_tooltip_heading'>" . __("Revenue", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = __("Orders Today", "gravityformspayfast");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravityformspayfast"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_payfast_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
            $sales_week = 0;
            $revenue_week = 0;
            $tooltips = "";
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='payfast_tooltip_subscription'><span class='payfast_tooltip_heading'>" . __("New Subscriptions", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->new_sales . "</span></div><div class='payfast_tooltip_subscription'><span class='payfast_tooltip_heading'>" . __("Renewals", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='payfast_tooltip_sales'><span class='payfast_tooltip_heading'>" . __("Orders", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='payfast_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravityformspayfast") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='payfast_tooltip_revenue'><span class='payfast_tooltip_heading'>" . __("Revenue", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Week", "gravityformspayfast");
                break;
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravityformspayfast"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;
            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_payfast_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

            $sales_month = 0;
            $revenue_month = 0;
            $tooltips = "";
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='payfast_tooltip_subscription'><span class='payfast_tooltip_heading'>" . __("New Subscriptions", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->new_sales . "</span></div><div class='payfast_tooltip_subscription'><span class='payfast_tooltip_heading'>" . __("Renewals", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='payfast_tooltip_sales'><span class='payfast_tooltip_heading'>" . __("Orders", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='payfast_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='payfast_tooltip_revenue'><span class='payfast_tooltip_heading'>" . __("Revenue", "gravityformspayfast") . ": </span><span class='payfast_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Month", "gravityformspayfast");
                break;

            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravityformspayfast"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . __("Jan", "gravityformspayfast") ."','" . __("Feb", "gravityformspayfast") ."','" . __("Mar", "gravityformspayfast") ."','" . __("Apr", "gravityformspayfast") ."','" . __("May", "gravityformspayfast") ."','" . __("Jun", "gravityformspayfast") ."','" . __("Jul", "gravityformspayfast") ."','" . __("Aug", "gravityformspayfast") ."','" . __("Sep", "gravityformspayfast") ."','" . __("Oct", "gravityformspayfast") ."','" . __("Nov", "gravityformspayfast") ."','" . __("Dec", "gravityformspayfast") ."']";
    }

    // Edit Page
    private static function edit_page(){
        ?>
        <style>
            #payfast_submit_container{clear:both;}
            .payfast_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .payfast_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

            .payfast_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .payfast_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_payfast_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
        </style>
        <script type="text/javascript">
            var form = Array();
            function ToggleNotifications(){

                var container = jQuery("#gf_payfast_notification_container");
                var isChecked = jQuery("#gf_payfast_delay_notifications").is(":checked");

                if(isChecked){
                    container.slideDown();
                    var isLoaded = jQuery(".gf_payfast_notification").length > 0
                    if(!isLoaded){
                        container.html("<li><img src='<?php echo self::get_base_url() ?>/images/loading.gif' title='<?php _e("Please wait...", "gravityformspayfast"); ?>'></li>");
                        jQuery.post(ajaxurl, {
                            action: "gf_payfast_load_notifications",
                            form_id: form["id"],
                            },
                            function(response){

                                var notifications = jQuery.parseJSON(response);
                                if(!notifications){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("Notifications could not be loaded. Please try again later or contact support", "gravityformspayfast") ?></div></li>");
                                }
                                else if(notifications.length == 0){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("The form selected does not have any notifications.", "gravityformspayfast") ?></div></li>");
                                }
                                else{
                                    var str = "";
                                    for(var i=0; i<notifications.length; i++){
                                        str += "<li class='gf_payfast_notification'>"
                                            +       "<input type='checkbox' value='" + notifications[i]["id"] + "' name='gf_payfast_selected_notifications[]' id='gf_payfast_selected_notifications' checked='checked' /> "
                                            +       "<label class='inline' for='gf_payfast_selected_notifications'>" + notifications[i]["name"] + "</label>";
                                            +  "</li>";
                                    }
                                    container.html(str);
                                }
                            }
                        );
                    }
                    jQuery(".gf_payfast_notification input").prop("checked", true);
                }
                else{
                    container.slideUp();
                    jQuery(".gf_payfast_notification input").prop("checked", false);
                }
            }
        </script>
        <div class="wrap">
            <img alt="<?php _e("PayFast", "gravityformspayfast") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/payfast_wordpress_icon_32.png"/>
            <h2><?php _e("PayFast Transaction Settings", "gravityformspayfast") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["payfast_setting_id"]) ? $_POST["payfast_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPayFastData::get_feed($id);
        $is_validation_error = false;
        
        $config["form_id"] = rgpost("gf_payfast_submit") ? absint(rgpost("gf_payfast_form")) : $config["form_id"];

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();

        //updating meta information
        if(rgpost("gf_payfast_submit")){
            
            $config["meta"]["merchantId"] = trim(rgpost("gf_payfast_merchantId"));
            $config["meta"]["merchantKey"] = trim(rgpost("gf_payfast_merchantKey"));
            $config["meta"]["sandbox"] = rgpost("gf_payfast_sandbox");
            $config["meta"]["debug"] = rgpost("gf_payfast_debug");
            $config["meta"]["type"] = rgpost("gf_payfast_type");
            $config["meta"]["style"] = rgpost("gf_payfast_page_style");
            $config["meta"]["continue_text"] = rgpost("gf_payfast_continue_text");
            $config["meta"]["cancel_url"] = rgpost("gf_payfast_cancel_url");
            $config["meta"]["disable_note"] = rgpost("gf_payfast_disable_note");
            $config["meta"]["disable_shipping"] = rgpost('gf_payfast_disable_shipping');
            $config["meta"]["delay_post"] = rgpost('gf_payfast_delay_post');
            $config["meta"]["update_post_action"] = rgpost('gf_payfast_update_action');

            if(isset($form["notifications"])){
                //new notification settings
                $config["meta"]["delay_notifications"] = rgpost('gf_payfast_delay_notifications');
                $config["meta"]["selected_notifications"] = $config["meta"]["delay_notifications"] ? rgpost('gf_payfast_selected_notifications') : array();

                if(isset($config["meta"]["delay_autoresponder"]))
                    unset($config["meta"]["delay_autoresponder"]);
                if(isset($config["meta"]["delay_notification"]))
                    unset($config["meta"]["delay_notification"]);
            }
            else{
                //legacy notification settings (for backwards compatibility)
                $config["meta"]["delay_autoresponder"] = rgpost('gf_payfast_delay_autoresponder');
                $config["meta"]["delay_notification"] = rgpost('gf_payfast_delay_notification');

                if(isset($config["meta"]["delay_notifications"]))
                    unset($config["meta"]["delay_notifications"]);
                if(isset($config["meta"]["selected_notifications"]))
                    unset($config["meta"]["selected_notifications"]);
            }

            // payfast conditional
            $config["meta"]["payfast_conditional_enabled"] = rgpost('gf_payfast_conditional_enabled');
            $config["meta"]["payfast_conditional_field_id"] = rgpost('gf_payfast_conditional_field_id');
            $config["meta"]["payfast_conditional_operator"] = rgpost('gf_payfast_conditional_operator');
            $config["meta"]["payfast_conditional_value"] = rgpost('gf_payfast_conditional_value');


            //-----------------

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){
                $config["meta"]["customer_fields"][$field["name"]] = $_POST["payfast_customer_field_{$field["name"]}"];
            }

            $config = apply_filters('gform_payfast_save_config', $config);

            $is_validation_error = apply_filters("gform_payfast_config_validation", false, $config);

            if(!$is_validation_error){
                $id = GFPayFastData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformspayfast"), "<a href='?page=gf_payfast'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }

        }
     
        
        if(count($config['meta'])>0)
        {
            $merchantId = $config['meta']['merchantId'];
            $merchantKey = $config['meta']['merchantKey'];
        }
        else
        {
            $settings = get_option('gf_payfast_settings');
            $merchantId = $settings['merchantId'];
            $merchantKey = $settings['merchantKey'];
        }
        //print_r($config);
        ?>
        <form method="post" action="">
            <input type="hidden" name="payfast_setting_id" value="<?php echo $id ?>" />

            <div class="margin_vertical_10 <?php echo $is_validation_error ? "payfast_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
                    <span><?php _e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
            </div> <!-- / validation message -->

            <div class="margin_vertical_10">
                <label class="left_header" for="gf_payfast_merchantId"><?php _e("PayFast Merchant ID", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_merchantId") ?></label>
                <input type="text" name="gf_payfast_merchantId" id="gf_payfast_merchantId" value="<?php echo $merchantId; ?>" class="width-1"/>
               
            </div>
             <div class="margin_vertical_10">
                <label class="left_header" for="gf_payfast_merchantKey"><?php _e("PayFast Merchant Key", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_merchantKey") ?></label>
                <input type="text" name="gf_payfast_merchantKey" id="gf_payfast_merchantKey" value="<?php echo $merchantKey; ?>" class="width-1"/>
               
            </div>
            <div class="margin_vertical_10">
                <label class="left_header"><?php _e("Sandbox", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_sandbox") ?></label>

                <input type="radio" name="gf_payfast_sandbox" id="gf_payfast_sandbox_live" value="live" <?php echo rgar($config['meta'], 'sandbox') != "test" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_payfast_sandbox_live"><?php _e("Live", "gravityformspayfast"); ?></label>
                &nbsp;&nbsp;&nbsp;
                <input type="radio" name="gf_payfast_sandbox" id="gf_payfast_sandbox_test" value="test" <?php echo rgar($config['meta'], 'sandbox') == "test" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_payfast_sandbox_test"><?php _e("Test", "gravityformspayfast"); ?></label>
            </div>
            <div class="margin_vertical_10">
                <label class="left_header"><?php _e("Debug", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_debug") ?></label>

                <input type="radio" name="gf_payfast_debug" id="gf_payfast_debug_no" value="no" <?php echo rgar($config['meta'], 'debug') != "yes" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_payfast_debug_no"><?php _e("No", "gravityformspayfast"); ?></label>
                &nbsp;&nbsp;&nbsp;
                <input type="radio" name="gf_payfast_debug" id="gf_payfast_debug_yes" value="yes" <?php echo rgar($config['meta'], 'debug') == "yes" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_payfast_debug_yes"><?php _e("Yes", "gravityformspayfast"); ?></label>
            </div>
          
            <div id="payfast_form_container" valign="top" class="margin_vertical_10" >
                <input type="hidden" value="product" name="gf_payfast_type">
                <label for="gf_payfast_form" class="left_header"><?php _e("Gravity Form", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_gravity_form") ?></label>

                <select id="gf_payfast_form" name="gf_payfast_form" onchange="SelectForm(jQuery('#gf_payfast_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                    <option value=""><?php _e("Select a form", "gravityformspayfast"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFPayFastData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>

                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
                    }
                    ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFPayFast::get_base_url() ?>/images/loading.gif" id="payfast_wait" style="display: none;"/>

                <div id="gf_payfast_invalid_product_form" class="gf_payfast_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspayfast") ?>
                </div>
                <div id="gf_payfast_invalid_donation_form" class="gf_payfast_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspayfast") ?>
                </div>
            </div>
            <div id="payfast_field_group" valign="top" <?php echo count($config["meta"])==0 || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

              

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Customer", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_customer") ?></label>

                    <div id="payfast_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                    </div>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_payfast_page_style"><?php _e("Page Style", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_page_style") ?></label>
                    <input type="text" name="gf_payfast_page_style" id="gf_payfast_page_style" class="width-1" value="<?php echo rgars($config, "meta/style") ?>"/>
                </div>
                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_payfast_continue_text"><?php _e("Continue Button Label", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_continue_button_label") ?></label>
                    <input type="text" name="gf_payfast_continue_text" id="gf_payfast_continue_text" class="width-1" value="<?php echo rgars($config, "meta/continue_text") ?>"/>
                </div>
                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_payfast_cancel_url"><?php _e("Cancel URL", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_cancel_url") ?></label>
                    <input type="text" name="gf_payfast_cancel_url" id="gf_payfast_cancel_url" class="width-1" value="<?php echo rgars($config, "meta/cancel_url") ?>"/>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Options", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_options") ?></label>

                    <ul style="overflow:hidden;">
                        <li>
                            <input type="checkbox" name="gf_payfast_disable_shipping" id="gf_payfast_disable_shipping" value="1" <?php echo rgar($config['meta'], 'disable_shipping') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_payfast_disable_shipping"><?php _e("Do not prompt buyer to include a shipping address.", "gravityformspayfast"); ?></label>
                        </li>
                        <li>
                            <input type="checkbox" name="gf_payfast_disable_note" id="gf_payfast_disable_note" value="1" <?php echo rgar($config['meta'], 'disable_note') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_payfast_disable_note"><?php _e("Do not prompt buyer to include a note with payment.", "gravityformspayfast"); ?></label>
                        </li>

                        <li id="payfast_delay_notification" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_payfast_delay_notification" id="gf_payfast_delay_notification" value="1" <?php echo rgar($config["meta"], 'delay_notification') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_payfast_delay_notification"><?php _e("Send admin notification only when payment is received.", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_delay_admin_notification") ?></label>
                        </li>
                        <li id="payfast_delay_autoresponder" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_payfast_delay_autoresponder" id="gf_payfast_delay_autoresponder" value="1" <?php echo rgar($config["meta"], 'delay_autoresponder') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_payfast_delay_autoresponder"><?php _e("Send user notification only when payment is received.", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_delay_user_notification") ?></label>
                        </li>

                        <?php
                        $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                        ?>
                        <li id="payfast_post_action" <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_payfast_delay_post" id="gf_payfast_delay_post" value="1" <?php echo rgar($config["meta"],"delay_post") ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_payfast_delay_post"><?php _e("Create post only when payment is received.", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_delay_post") ?></label>
                        </li>

                        <li id="payfast_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_payfast_update_post" id="gf_payfast_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_payfast_update_action').val(action);" />
                            <label class="inline" for="gf_payfast_update_post"><?php _e("Update Post when subscription is cancelled.", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_update_post") ?></label>
                            <select id="gf_payfast_update_action" name="gf_payfast_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_payfast_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravityformspayfast") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravityformspayfast") ?></option>
                            </select>
                        </li>

                        <?php do_action("gform_payfast_action_fields", $config, $form) ?>
                    </ul>
                </div>

                <div class="margin_vertical_10" id="gf_payfast_notifications" <?php echo !isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                    <label class="left_header"><?php _e("Notifications", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_notifications") ?></label>
                    <?php
                    $has_delayed_notifications = rgar($config['meta'], 'delay_notifications') || rgar($config['meta'], 'delay_notification') || rgar($config['meta'], 'delay_autoresponder');
                    ?>
                    <div style="overflow:hidden;">
                        <input type="checkbox" name="gf_payfast_delay_notifications" id="gf_payfast_delay_notifications" value="1" onclick="ToggleNotifications();" <?php checked("1", $has_delayed_notifications)?> />
                        <label class="inline" for="gf_payfast_delay_notifications"><?php _e("Send notifications only when payment is received.", "gravityformspayfast"); ?></label>

                        <ul id="gf_payfast_notification_container" style="padding-left:20px; <?php echo $has_delayed_notifications ? "" : "display:none;"?>">
                        <?php
                        if(!empty($form) && is_array($form["notifications"])){
                            $selected_notifications = self::get_selected_notifications($config, $form);

                            foreach($form["notifications"] as $notification){
                                ?>
                                <li class="gf_payfast_notification">
                                    <input type="checkbox" name="gf_payfast_selected_notifications[]" id="gf_payfast_selected_notifications" value="<?php echo $notification["id"]?>" <?php checked(true, in_array($notification["id"], $selected_notifications))?> />
                                    <label class="inline" for="gf_payfast_selected_notifications"><?php echo $notification["name"]; ?></label>
                                </li>
                                <?php
                            }
                        }
                        ?>
                        </ul>
                    </div>
                </div>

                <?php do_action("gform_payfast_add_option_group", $config, $form); ?>

                <div id="gf_payfast_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_payfast_conditional_optin" class="left_header"><?php _e("PayFast Condition", "gravityformspayfast"); ?> <?php gform_tooltip("payfast_conditional") ?></label>

                    <div id="gf_payfast_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_payfast_conditional_enabled" name="gf_payfast_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_payfast_conditional_container').fadeIn('fast');} else{ jQuery('#gf_payfast_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'payfast_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_payfast_conditional_enable"><?php _e("Enable", "gravityformspayfast"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_payfast_conditional_container" <?php echo !rgar($config['meta'], 'payfast_conditional_enabled') ? "style='display:none'" : ""?>>

                                        <div id="gf_payfast_conditional_fields" style="display:none">
                                            <?php _e("Send to PayFast if ", "gravityformspayfast") ?>
                                            <select id="gf_payfast_conditional_field_id" name="gf_payfast_conditional_field_id" class="optin_select" onchange='jQuery("#gf_payfast_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'>
                                            </select>
                                            <select id="gf_payfast_conditional_operator" name="gf_payfast_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformspayfast") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformspayfast") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformspayfast") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformspayfast") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformspayfast") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformspayfast") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'payfast_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformspayfast") ?></option>
                                            </select>
                                            <div id="gf_payfast_conditional_value_container" name="gf_payfast_conditional_value_container" style="display:inline;"></div>
                                        </div>

                                        <div id="gf_payfast_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- / payfast conditional -->

                <div id="payfast_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_payfast_submit" value="<?php echo empty($id) ? __("  Save  ", "gravityformspayfast") : __("Update", "gravityformspayfast"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravityformspayfast"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_payfast'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function(){
                
                
            });


            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#payfast_field_group").slideUp();
                    return;
                }

                jQuery("#payfast_wait").show();
                jQuery("#payfast_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_payfast_form" );
                mysack.setVar( "gf_select_payfast_form", "<?php echo wp_create_nonce("gf_select_payfast_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.onError = function() {jQuery("#payfast_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformspayfast") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options){

                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_payfast_type").val();

                jQuery(".gf_payfast_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_payfast_invalid_product_form").show();
                    jQuery("#payfast_wait").hide();
                    return;
                }
                else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
                    jQuery("#gf_payfast_invalid_donation_form").show();
                    jQuery("#payfast_wait").hide();
                    return;
                }

                jQuery(".payfast_field_container").hide();
                jQuery("#payfast_customer_fields").html(customer_fields);
                jQuery("#gf_payfast_recurring_amount").html(recurring_amount_options);

                //displaying delayed post creation setting if current form has a post field
                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(post_fields.length > 0){
                    jQuery("#payfast_post_action").show();
                }
                else{
                    jQuery("#gf_payfast_delay_post").attr("checked", false);
                    jQuery("#payfast_post_action").hide();
                }

                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#payfast_post_update_action").show();
                }
                else{
                    jQuery("#gf_payfast_update_post").attr("checked", false);
                    jQuery("#payfast_post_update_action").hide();
                }

                SetPeriodNumber('#gf_payfast_billing_cycle_number', jQuery("#gf_payfast_billing_cycle_type").val());
                SetPeriodNumber('#gf_payfast_trial_period_number', jQuery("#gf_payfast_trial_period_type").val());

                //Calling callback functions
                jQuery(document).trigger('payfastFormSelected', [form]);

                jQuery("#gf_payfast_conditional_enabled").attr('checked', false);
                SetPayFastCondition("","");

                if(form["notifications"]){
                    jQuery("#gf_payfast_notifications").show();
                    jQuery("#payfast_delay_autoresponder, #payfast_delay_notification").hide();
                }
                else{
                    jQuery("#payfast_delay_autoresponder, #payfast_delay_notification").show();
                    jQuery("#gf_payfast_notifications").hide();
                }

                jQuery("#payfast_field_container_" + type).show();
                jQuery("#payfast_field_group").slideDown();
                jQuery("#payfast_wait").hide();
            }

            function SetPeriodNumber(element, type){
                var prev = jQuery(element).val();

                var min = 1;
                var max = 0;
                switch(type){
                    case "D" :
                        max = 100;
                    break;
                    case "W" :
                        max = 52;
                    break;
                    case "M" :
                        max = 12;
                    break;
                    case "Y" :
                        max = 5;
                    break;
                }
                var str="";
                for(var i=min; i<=max; i++){
                    var selected = prev == i ? "selected='selected'" : "";
                    str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
                }
                jQuery(element).html(str);
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;

                return -1;
            }

        </script>

        <script type="text/javascript">

            <?php
            if(!empty($config["form_id"])){
                ?>

                // initilize form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["payfast_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["payfast_conditional_value"])?>";
                    SetPayFastCondition(selectedField, selectedValue);
                });

                <?php
            }
            ?>

            function SetPayFastCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_payfast_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_payfast_conditional_field_id").val();
                var checked = jQuery("#gf_payfast_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_payfast_conditional_message").hide();
                    jQuery("#gf_payfast_conditional_fields").show();
                    jQuery("#gf_payfast_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_payfast_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_payfast_conditional_message").show();
                    jQuery("#gf_payfast_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_payfast_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
                    str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_payfast_conditional_value", "name"=> "gf_payfast_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
                }
                else if(field.choices){
                    str += '<select id="gf_payfast_conditional_value" name="gf_payfast_conditional_value" class="optin_select">'


                    for(var i=0; i<field.choices.length; i++){
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if(isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if(!isAnySelected && selectedValue){
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                }
                else
                {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_payfast_conditional_value' name='gf_payfast_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                        "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }

        </script>

        <?php

    }

    public static function select_payfast_form(){

        check_ajax_referer("gf_select_payfast_form", "gf_select_payfast_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "");

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_payfast");
        $wp_roles->add_cap("administrator", "gravityforms_payfast_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_payfast", "gravityforms_payfast_uninstall"));
    }

    public static function get_active_config($form){

        require_once(self::get_base_path() . "/data.php");

        $configs = GFPayFastData::get_feed_by_form($form["id"], true);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_payfast_condition($form, $config))
                return $config;
        }

        return false;
    }

    public static function send_to_payfast($confirmation, $form, $entry, $ajax){

        // ignore requests that are not the current form's submissions
        if(RGForms::post("gform_submit") != $form["id"])
        {
            return $confirmation;
        }

        $config = self::get_active_config($form);

        if(!$config)
        {
            self::log_debug("NOT sending to PayFast: No PayFast setup was located for form_id = {$form['id']}.");
            return $confirmation;
        }

        // updating entry meta with current feed id
        gform_update_meta($entry["id"], "payfast_feed_id", $config["id"]);

        // updating entry meta with current payment gateway
        gform_update_meta($entry["id"], "payment_gateway", "payfast");

        //updating lead's payment_status to Processing
        RGFormsModel::update_lead_property($entry["id"], "payment_status", 'Processing');

        //Getting Url (Production or Sandbox)
        $url = $config["meta"]["sandbox"] == "live" ? self::$production_url : self::$sandbox_url;

        $invoice_id = apply_filters("gform_payfast_invoice", "", $form, $entry);

        $invoice = empty($invoice_id) ? "" : $invoice_id;       

        //Customer fields
        

        $itn_url = get_bloginfo("url") . "/?page=gf_payfast_itn";
       
        $return_url = self::return_url($form["id"], $entry["id"]);
        
        $cancel_url = !empty($config["meta"]["cancel_url"]) ? $config["meta"]["cancel_url"] : home_url();

        $merchant = self::get_merchant($config);
        $varArray = array(
            'merchant_id'=>$merchant['id'],
            'merchant_key'=>$merchant['key'],
            'return_url'=> $return_url,
            'cancel_url'=> $cancel_url,
            'notify_url'=> $itn_url
        );
        
        $custom_field = $entry["id"] . "|" . wp_hash($entry["id"]);

        
        $query_string = "";

        $customer_fields = self::customer_query_string($config, $entry);
        $productInfo = self::get_product_query_string($form, $entry);
           
        if(count($productInfo)==0)
        {
            self::log_debug("NOT sending to PayFast: The price is either zero or the gform_payfast_query filter was used to remove the querystring that is sent to PayFast.");
            return $confirmation;
        }

        $varArray = $varArray
                    +$customer_fields
                    +array('m_payment_id'=>$invoice)
                    +$productInfo
                    +array('custom_str1'=>$custom_field);

        
        $secureString = '?';
        foreach($varArray as $k=>$v)
        {           
            if(!empty($v))
                $secureString .= $k.'='.urlencode(trim($v)).'&';
        }
       
        $secureString = substr( $secureString, 0, -1 );
        
        $query_string = apply_filters("gform_payfast_query_{$form['id']}", apply_filters("gform_payfast_query", $secureString, $form, $entry), $form, $entry);

        $secureSig = md5($query_string);
        $secureString .= '&signature='.$secureSig;

        $url .= $query_string;

        //$url = apply_filters("gform_payfast_request_{$form['id']}", apply_filters("gform_payfast_request", $url, $form, $entry), $form, $entry);

        self::log_debug("Sending to PayFast: {$url}");

        if(headers_sent() || $ajax){
            $confirmation = "<script>function gformRedirect(){document.location.href='$url';}";
            if(!$ajax)
                $confirmation .="gformRedirect();";
            $confirmation .="</script>";
        }
        else{
            $confirmation = array("redirect" => $url);
        }

        return $confirmation;
    }

    public static function has_payfast_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["payfast_conditional_operator"]) ? $config["payfast_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["payfast_conditional_field_id"]);

        if(empty($field) || !$config["payfast_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["payfast_conditional_value"], $operator);
        $go_to_payfast = $is_value_match && $is_visible;

        return  $go_to_payfast;
    }

    public static function get_config($form_id){
        if(!class_exists("GFPayFastData"))
            require_once(self::get_base_path() . "/data.php");

        //Getting payfast settings associated with this transaction
        $config = GFPayFastData::get_feed_by_form($form_id);

        //Ignore ITN messages from forms that are no longer configured with the PayFast add-on
        if(!$config)
            return false;

        return $config[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    public static function get_config_by_entry($entry) {

        if(!class_exists("GFPayFastData"))
            require_once(self::get_base_path() . "/data.php");

        $feed_id = gform_get_meta($entry["id"], "payfast_feed_id");
        $feed = GFPayFastData::get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    public static function maybe_thankyou_page(){

        if(!self::is_gravityforms_supported())
            return;

        if($str = RGForms::get("gf_payfast_return"))
        {
            $str = base64_decode($str);

            parse_str($str, $query);
            if(wp_hash("ids=" . $query["ids"]) == $query["hash"]){
                list($form_id, $lead_id) = explode("|", $query["ids"]);

                $form = RGFormsModel::get_form_meta($form_id);
                $lead = RGFormsModel::get_lead($lead_id);

                if(!class_exists("GFFormDisplay"))
                    require_once(GFCommon::get_base_path() . "/form_display.php");

                $confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);

                if(is_array($confirmation) && isset($confirmation["redirect"])){
                    header("Location: {$confirmation["redirect"]}");
                    exit;
                }

                GFFormDisplay::$submission[$form_id] = array("is_confirmation" => true, "confirmation_message" => $confirmation, "form" => $form, "lead" => $lead);
            }
        }
    }

    function currencyZar($currency)
    {
        
        $currency['ZAR'] = array(
            "name" => __("South African Rand", "gravityforms"), 
            "symbol_left" => 'R', 
            "symbol_right" => "", 
            "symbol_padding" =>  "", 
            "thousand_separator" => ',', 
            "decimal_separator" => '.',
             "decimals" => 2
            );
        return $currency;
    }
    public static function process_itn($wp){
        global $current_user;
        $user_id = 0;
        $user_name = "PayFast ITN";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        if(!self::is_gravityforms_supported())
           return;

        //Ignore requests that are not ITN
        if(RGForms::get("page") != "gf_payfast_itn")
            return;

        self::log_debug("ITN request received. Starting to process...");
      
        $pfError = false;
        $pfErrMsg = '';
        $pfDone = false;
        $pfData = array();      
        $pfParamString = '';   

        //// Notify PayFast that information has been received
        if( !$pfError && !$pfDone )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }

        //Valid ITN requests must have a custom field
        $custom = RGForms::post("custom_str1");
        if(empty($custom)){
            self::log_error("ITN request does not have a custom field, so it was not created by Gravity Forms. Aborting.");
            return;
        }
        //Getting entry associated with this ITN message (entry id is sent in the "custom" field)
        list($entry_id, $hash) = explode("|", $custom);
        $hash_matches = wp_hash($entry_id) == $hash;
        //Validates that Entry Id wasn't tampered with
        if(!$hash_matches){
            self::log_error("Entry Id verification failed. Hash does not match. Custom field: {$custom}. Aborting.");
            return;
        }

        self::log_debug("ITN message has a valid custom field: {$custom}");

        //$entry_id = RGForms::post("custom");
        $entry = RGFormsModel::get_lead($entry_id);

        //Ignore orphan ITN messages (ones without an entry)
        if(!$entry){
            self::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
            return;
        }
        self::log_debug("Entry has been found." . print_r($entry, true));

        // config ID is stored in entry via send_to_payfast() function
        $config = self::get_config_by_entry($entry);

        //Ignore ITN messages from forms that are no longer configured with the PayFast add-on
        if(!$config){
            self::log_error("Form no longer is configured with PayFast Addon. Form ID: {$entry["form_id"]}. Aborting.");
            return;
        }
        self::log_debug("Form {$entry["form_id"]} is properly configured.");  

        define( 'PF_DEBUG', ($config['meta']['debug']=='yes'?true:false));
        include( self::get_base_path().'/payfast_common.inc');

        pflog( 'PayFast ITN call received' );
        self::log_debug( 'PayFast ITN call received' );
        //// Get data sent by PayFast
        if( !$pfError && !$pfDone )
        {
            pflog( 'Get posted data' );
            // Posted variables from ITN
            $pfData = pfGetData();
            $transaction_id = $pfData['pf_payment_id'];
            pflog( 'PayFast Data: '. print_r( $pfData, true ) );
            self::log_debug( 'Get posted data') ;

            if( $pfData === false )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Verify security signature
        if( !$pfError && !$pfDone )
        {
            pflog( 'Verify security signature' );

            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
            }
        }

        //// Verify source IP (If not in debug mode)
        if( !$pfError && !$pfDone )
        {
            pflog( 'Verify source IP' );

            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
            }
        }
        
        //// Get internal cart
        if( !$pfError && !$pfDone )
        {           
            $order_info = $entry+$config;

            pflog( "Purchase:\n". print_r( $order_info, true )  );
            self::log_debug( "Purchase:\n". print_r( $order_info, true )  );
        }

        //// Verify data received
        if( !$pfError )
        {
            pflog( 'Verify data received' );
            self::log_debug( 'Verify data received' );
            $pfHost = ($config['meta']['sandbox']=='sandbox' ? 'sandbox' : 'www').'.payfast.co.za';
            $pfValid = pfValidData( $pfHost, $pfParamString );

            if( $pfValid )
            {
                self::log_debug("ITN message successfully verified by PayFast");
            }
            else
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Check data against internal order
        if( !$pfError && !$pfDone )
        {
            pflog( 'Check data against internal order' );
            self::log_debug( 'Check data against internal order' );
            
            $form = RGFormsModel::get_form_meta($entry["form_id"]);
            $products = GFCommon::get_product_fields($form, $entry, true);    

            $product_amount = 0;
            
            $product_amount = GFCommon::get_order_total($form, $entry); 
            // Check order amount
            if( !pfAmountsEqual( $pfData['amount_gross'],$product_amount ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_AMOUNT_MISMATCH;
                RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
            }          
            
        }
                
        //// Check status and update order
        if( !$pfError && !$pfDone )
        {
            pflog( 'Check status and update order' );
            self::log_debug( 'Check status and update order' );    
            

            switch( $pfData['payment_status'] )
            {
                case 'COMPLETE':
                    pflog( '- Complete' );
                   

                    self::log_debug("Payment status: {$pfData['payment_status']} - Transaction ID: {$transaction_id} - Amount: {$product_amount}");
                    self::log_debug("Processing a completed payment");                    
                    
                    self::log_debug("Entry is not already approved. Proceeding...");
                    $entry["payment_status"] = "Approved";
                    $entry["payment_amount"] = $product_amount;
                    $entry["payment_date"] = gmdate("y-m-d H:i:s");
                    $entry["transaction_id"] = $transaction_id;
                    $entry["transaction_type"] = 1; //payment

                    if(!$entry["is_fulfilled"]){
                        self::log_debug("Payment has been made. Fulfilling order.");
                        self::fulfill_order($entry, $transaction_id, $product_amount);
                        self::log_debug("Order has been fulfilled");
                        $entry["is_fulfilled"] = true;
                    }

                    self::log_debug("Updating entry.");
                    RGFormsModel::update_lead($entry);
                    self::log_debug("Adding note.");
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been approved. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $transaction_id));
                    
                    
                    
                    self::log_debug("Inserting transaction.");
                    GFPayFastData::insert_transaction($entry["id"], "payment", null, $transaction_id, $parent_transaction_id, $product_amount);                                
                     
                    self::log_debug("Before gform_post_payment_status.");
                    do_action("gform_post_payment_status", $config, $entry, $pfData['payment_status'],  $transaction_id, null, $product_amount, null, null);
                break;

                case 'FAILED':
                    pflog( '- Failed' );
                    self::log_debug( '- Failed' );
                    return;
                    break;

                case 'PENDING':
                    pflog( '- Pending' );
                    self::log_debug( '- Pending' );
                    return;
                    break;

                default:                    

                break;
            }
        }
        else
        {
            pflog( "Errors:\n". print_r( $pfErrMsg, true ) );
            self::log_debug( "Errors:\n". print_r( $pfErrMsg, true ) );
            return;
        }

        self::log_debug("ITN processing complete."); 
    }   

    public static function fulfill_order(&$entry, $transaction_id, $amount){

        $config = self::get_config_by_entry($entry);
        if(!$config){
            self::log_error("Order can't be fulfilled because feed wasn't found for form: {$entry["form_id"]}");
            return;
        }

        $form = RGFormsModel::get_form_meta($entry["form_id"]);
        if($config["meta"]["delay_post"]){
            self::log_debug("Creating post.");
            RGFormsModel::create_post($form, $entry);
        }

        if(isset($config["meta"]["delay_notifications"])){
            //sending delayed notifications
            GFCommon::send_notifications($config["meta"]["selected_notifications"], $form, $entry, true, "form_submission");

        }
        else{

            //sending notifications using the legacy structure
            if($config["meta"]["delay_notification"]){
               self::log_debug("Sending admin notification.");
               GFCommon::send_admin_notification($form, $entry);
            }

            if($config["meta"]["delay_autoresponder"]){
               self::log_debug("Sending user notification.");
               GFCommon::send_user_notification($form, $entry);
            }
        }

        self::log_debug("Before gform_payfast_fulfillment.");
        do_action("gform_payfast_fulfillment", $entry, $config, $transaction_id, $amount);
    }    

    private static function customer_query_string($config, $lead){
        $fields = array();
        foreach(self::get_customer_fields() as $field){
            $field_id = $config["meta"]["customer_fields"][$field["name"]];
            $value = rgar($lead,$field_id);

            if($field["name"] == "country")
                $value = GFCommon::get_country_code($value);
            else if($field["name"] == "state")
                $value = GFCommon::get_us_state_code($value);

            if(!empty($value))
            {
                switch($field['name'])
                {
                    case 'first_name':
                        $fields['name_first']=$value;
                    break;
                    case 'last_name':
                        $fields['name_last']=$value;
                    break;
                    case 'email':
                        $fields['email_address']=$value;
                    break;
                }
            }
                
        }
        return $fields;
    }  

    private static function get_product_query_string($form, $entry){
      
        $products = GFCommon::get_product_fields($form, $entry, true);       
        $total = 0;
        $discount = 0;

        foreach($products["products"] as $product){           
            $price = GFCommon::to_number($product["price"]);        
           
            if($price > 0)
            {                
                $total += $price * $product['quantity'];
            
            }
            else{
                $discount += abs($price) * $product['quantity'];
            }

        }
        $total = !empty($products["shipping"]["price"]) ? $products["shipping"]["price"]+$total : $total;
        $total = $discount > 0 ? $total - $discount : $total;        
        
        $item_name = $form['title'];
        $item_description = $form['description'];
        
        $fields = array(
            'amount'            =>$total,
            'item_name'         =>$item_name,
            'item_description'  =>$item_description,
            );

        return $total > 0 && $total > $discount ? $fields : array();
    }

    private static function get_donation_query_string($form, $entry){
        $fields = "";

        //getting all donation fields
        $donations = GFCommon::get_fields_by_type($form, array("donation"));
        $total = 0;
        $purpose = "";
        foreach($donations as $donation){
            $value = RGFormsModel::get_lead_field_value($entry, $donation);
            list($name, $price) = explode("|", $value);
            if(empty($price)){
                $price = $name;
                $name = $donation["label"];
            }
            $purpose .= $name . ", ";
            $price = GFCommon::to_number($price);
            $total += $price;
        }

        //using product fields for donation if there aren't any legacy donation fields in the form
        if($total == 0){
            //getting all product fields
            $products = GFCommon::get_product_fields($form, $entry, true);
            foreach($products["products"] as $product){
                $options = "";
                if(is_array($product["options"]) && !empty($product["options"])){
                    $options = " (";
                    foreach($product["options"] as $option){
                        $options .= $option["option_name"] . ", ";
                    }
                    $options = substr($options, 0, strlen($options)-2) . ")";
                }
                $quantity = GFCommon::to_number($product["quantity"]);
                $quantity_label = $quantity > 1 ? $quantity . " " : "";
                $purpose .= $quantity_label . $product["name"] . $options . ", ";
            }

            $total = GFCommon::get_order_total($form, $entry);
        }

        if(!empty($purpose))
            $purpose = substr($purpose, 0, strlen($purpose)-2);

        $purpose = urlencode($purpose);

        //truncating to maximum length allowed by PayFast
        if(strlen($purpose) > 127)
            $purpose = substr($purpose, 0, 124) . "...";

        $fields = "&amount={$total}&item_name={$purpose}&cmd=_donations";

        return $total > 0 ? $fields : false;
    }    

    private static function get_subscription_option_info($product){
        $option_price = 0;
        $option_labels = array();
        if(is_array($product["options"])){
            foreach($product["options"] as $option){
                $option_price += $option["price"];
                $option_labels[] = $option["option_label"];
            }
        }
        $label = empty($option_labels) ? $product["name"] : $product["name"] . " - " . implode(", " , $option_labels);
        if(strlen($label) > 127)
            $label = $product["name"] . " - " . __("with options", "gravityformspayfast");

        return array("price" => $option_price, "label" => $label);
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFPayFast::has_access("gravityforms_payfast_uninstall"))
            die(__("You don't have adequate permission to uninstall the PayFast Add-On.", "gravityformspayfast"));

        //droping all tables
        GFPayFastData::drop_tables();

        //removing options
        delete_option("gf_payfast_settings");
        delete_option("gf_payfast_version");

        //Deactivating plugin
        $plugin = "gravityformspayfast/payfast.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='payfast_col_heading'>" . __("PayFast Fields", "gravityformspayfast") . "</td><td class='payfast_col_heading'>" . __("Form Fields", "gravityformspayfast") . "</td></tr>";
        $customer_fields = self::get_customer_fields();
        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='payfast_field_cell'>" . $field["label"]  . "</td><td class='payfast_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(array("name" => "first_name" , "label" => "First Name"), array("name" => "last_name" , "label" =>"Last Name"),
        array("name" => "email" , "label" =>"Email"), array("name" => "address1" , "label" =>"Address"), array("name" => "address2" , "label" =>"Address 2"),
        array("name" => "city" , "label" =>"City"), array("name" => "state" , "label" =>"State"), array("name" => "zip" , "label" =>"Zip"),
        array("name" => "country" , "label" =>"Country"));
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "payfast_customer_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field){
        $str = "<option value=''>" . __("Select a field", "gravityformspayfast") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        $selected = $selected_field == 'all' ? "selected='selected'" : "";
        $str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformspayfast") ."</option>";

        return $str;
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function return_url($form_id, $lead_id) {
        $pageURL = GFCommon::is_ssl() ? "https://" : "http://";

        if ($_SERVER["SERVER_PORT"] != "80")
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        else
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];

        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= "&hash=" . wp_hash($ids_query);

        return add_query_arg("gf_payfast_return", base64_encode($ids_query), $pageURL);
    }

    private static function get_merchant($config)
    {
        $sandbox = $config['meta']['sandbox'] == 'test' ? true : false;
        $merchant = array();
        if($sandbox)
        {
            $merchant['id'] = '10000100';
            $merchant['key'] = '46f0cd694581a';
        }
        elseif(isset($config['meta']['merchantId']))
        {
            $merchant['id'] = $config['meta']['merchantId'];
            $merchant['key'] = $config['meta']['merchantKey'];
        }
        else
        {
            $settings = get_option('gf_payfast_settings');
            $merchant['id'] = $settings['merchantId'];
            $merchant['key'] = $settings['merchantKey'];
        }
        return $merchant;
    }
   
    private static function is_payfast_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_payfast"));
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    public static function admin_edit_payment_status($payment_status, $form_id, $lead)
    {
        //allow the payment status to be edited when for payfast, not set to Approved, and not a subscription
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        require_once(self::get_base_path() . "/data.php");
        //get the transaction type out of the feed configuration, do not allow status to be changed when subscription
        $payfast_feed_id = gform_get_meta($lead["id"], "payfast_feed_id");
        $feed_config = GFPayFastData::get_feed($payfast_feed_id);
        $transaction_type = rgars($feed_config, "meta/type");
        if ($payment_gateway <> "payfast" || strtolower(rgpost("save")) <> "edit" || $payment_status == "Approved" || $transaction_type == "subscription")
            return $payment_status;

        //create drop down for payment status
        $payment_string = gform_tooltip("payfast_edit_payment_status","",true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Approved">Approved</option>';
        $payment_string .= '</select>';
        return $payment_string;
    }
    public static function admin_edit_payment_status_details($form_id, $lead)
    {
        //check meta to see if this entry is payfast
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        $form_action = strtolower(rgpost("save"));
        if ($payment_gateway <> "payfast" || $form_action <> "edit")
            return;

        //get data from entry to pre-populate fields
        $payment_amount = rgar($lead, "payment_amount");
        if (empty($payment_amount))
        {
            $form = RGFormsModel::get_form_meta($form_id);
            $payment_amount = GFCommon::get_order_total($form,$lead);
        }
        $transaction_id = rgar($lead, "transaction_id");
        $payment_date = rgar($lead, "payment_date");
        if (empty($payment_date))
        {
            $payment_date = gmdate("y-m-d H:i:s");
        }

        //display edit fields
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <tr>
                    <td colspan="2"><strong>Payment Information</strong></td>
                </tr>

                <tr>
                    <td>Date:<?php gform_tooltip("payfast_edit_payment_date") ?></td>
                    <td><input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date?>"></td>
                </tr>
                <tr>
                    <td>Amount:<?php gform_tooltip("payfast_edit_payment_amount") ?></td>
                    <td><input type="text" id="payment_amount" name="payment_amount" value="<?php echo $payment_amount?>"></td>
                </tr>
                <tr>
                    <td nowrap>Transaction ID:<?php gform_tooltip("payfast_edit_payment_transaction_id") ?></td>
                    <td><input type="text" id="payfast_transaction_id" name="payfast_transaction_id" value="<?php echo $transaction_id?>"></td>
                </tr>
            </table>
        </div>
        <?php
    }

    public static function admin_update_payment($form, $lead_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');
        //update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        //check meta to see if this entry is payfast
        $payment_gateway = gform_get_meta($lead_id, "payment_gateway");
        $form_action = strtolower(rgpost("save"));
        if ($payment_gateway <> "payfast" || $form_action <> "update")
            return;
        //get lead
        $lead = RGFormsModel::get_lead($lead_id);
        //get payment fields to update
        $payment_status = rgpost("payment_status");
        //when updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status))
        {
            $payment_status = $lead["payment_status"];
        }

        $payment_amount = rgpost("payment_amount");
        $payment_transaction = rgpost("payfast_transaction_id");
        $payment_date = rgpost("payment_date");
        if (empty($payment_date))
        {
            $payment_date = gmdate("y-m-d H:i:s");
        }
        else
        {
            //format date entered by user
            $payment_date = date("Y-m-d H:i:s", strtotime($payment_date));
        }

        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $lead["payment_status"] = $payment_status;
        $lead["payment_amount"] = $payment_amount;
        $lead["payment_date"] =   $payment_date;
        $lead["transaction_id"] = $payment_transaction;

        // if payment status does not equal approved or the lead has already been fulfilled, do not continue with fulfillment
        if($payment_status == 'Approved' && !$lead["is_fulfilled"])
        {
            //call fulfill order, mark lead as fulfilled
            self::fulfill_order($lead, $payment_transaction, $payment_amount);
            $lead["is_fulfilled"] = true;
        }
        //update lead, add a note
        RGFormsModel::update_lead($lead);
        RGFormsModel::add_note($lead["id"], $user_id, $user_name, sprintf(__("Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s", "gravityforms"), $lead["payment_status"], GFCommon::to_money($lead["payment_amount"], $lead["currency"]), $payment_transaction, $lead["payment_date"]));
    }

    function set_logging_supported($plugins)
    {
        $plugins[self::$slug] = "PayFast Payment Add-on";
        return $plugins;
    }

    private static function log_error($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    private static function log_debug($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgars")){
function rgars($array, $name){
    $names = explode("/", $name);
    $val = $array;
    foreach($names as $current_name){
        $val = rgar($val, $current_name);
    }
    return $val;
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}
