<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once 'classes/PfGfUtilities.php';
require_once 'classes/PfGfForm.php';

use Payfast\PayfastCommon\Aggregator\Request\PaymentRequest;

add_action('wp', array('GFPayFast', 'maybe_thankyou_page'), 5);
GFForms::include_payment_addon_framework();

class GFPayFast extends GFPaymentAddOn
{
    protected const DURATION_LITERAL = '+ 2 days';
    protected const DATE_LITERAL     = 'y-m-d H:i:s';
    public const    H6_TAG           = '<h6>';
    public const    H6_TAG_END       = '</h6>';
    private static $_instance                 = null;
    protected      $_version                  = '2.9.1';
    protected      $_min_gravityforms_version = '1.9.3';
    protected      $_slug                     = 'gravityformspayfast';
    protected      $_path                     = 'gravityformspayfast/payfast.php';
    protected      $_full_path                = __FILE__;
    protected      $_url                      = 'http://www.gravityforms.com';
    protected      $_title                    = 'Gravity Forms Payfast Add-On';
    protected      $_short_title              = 'Payfast';
    protected      $_supports_callbacks       = true;
    protected      $_capabilities             = array('gravityforms_payfast', 'gravityforms_payfast_uninstall');
    // Members plugin integration
    protected $_capabilities_settings_page = 'gravityforms_payfast';
    // Permissions
    protected $_capabilities_form_settings = 'gravityforms_payfast';
    protected $_capabilities_uninstall     = 'gravityforms_payfast_uninstall';
    protected $_enable_rg_autoupgrade      = false;
    // Automatic upgrade enabled
    private $productionUrl     = 'https://www.payfast.co.za/eng/process/';
    private $sandboxUrl        = 'https://sandbox.payfast.co.za/eng/process/';
    private $_pf_gf_module_ver = '1.6.0';

    public static function get_instance()
    {
        if (self::$_instance == null) {
            self::$_instance = new GFPayFast();
        }

        return self::$_instance;
    }

    public static function maybe_thankyou_page()
    {
        $instance = self::get_instance();
        if (!$instance->is_gravityforms_supported()) {
            return;
        }
        if ($str = rgget('gf_payfast_return')) {
            $str = base64_decode($str);
            parse_str($str, $query);
            if (wp_hash('ids=' . $query['ids']) == $query['hash']) {
                list($form_id, $lead_id) = explode('|', $query['ids']);
                $form = GFAPI::get_form($form_id);
                $lead = GFAPI::get_entry($lead_id);
                if (!class_exists('GFFormDisplay')) {
                    require_once GFCommon::get_base_path() . '/form_display.php';
                }
                $confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);
                if (is_array($confirmation) && isset($confirmation['redirect'])) {
                    header("Location: {$confirmation['redirect']}");
                    exit;
                }
                GFFormDisplay::$submission[$form_id] = array(
                    'is_confirmation'      => true,
                    'confirmation_message' => $confirmation,
                    'form'                 => $form,
                    'lead'                 => $lead
                );
            }
        }
    }

    public static function get_config_by_entry($entry)
    {
        $payfast = GFPayFast::get_instance();
        $feed    = $payfast->get_payment_feed($entry);
        if (empty($feed)) {
            return false;
        }

        return $feed['addon_slug'] == $payfast->_slug ? $feed : false;
    }

    //----- SETTINGS PAGES ----------//

    public static function get_config($form_id)
    {
        $payfast = GFPayFast::get_instance();
        $feed    = $payfast->get_feeds($form_id);
        //Ignore ITN messages from forms that are no longer configured with the Payfast add-on
        if (!$feed) {
            return false;
        }

        return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    public function init_frontend()
    {
        parent::init_frontend();
        add_filter('gform_disable_post_creation', array($this, 'delay_post'), 10, 3);
        add_filter('gform_disable_notification', array($this, 'delay_notification'), 10, 4);
    }

    public function plugin_settings_fields()
    {
        $payfastForm = new PfGfForm();

        return $payfastForm->getPayfastConfigurationInstructions();
    }

    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();
        if (!rgar($settings, 'gf_payfast_configured')) {
            return sprintf(
                __('To get started, configure your %sPayfast Settings%s!', 'gravityformspayfast'),
                '<a href="' . admin_url('admin.php?page=gf_settings&subview=' . $this->_slug) . '">',
                '</a>'
            );
        } else {
            return parent::feed_list_no_item_message();
        }
    }

    public function feed_settings_fields()
    {
        $utilities        = new PfGfUtilities();
        $default_settings = parent::feed_settings_fields();
        //--add Payfast fields
        $payfastFields    = new PfGfForm();
        $fields           = $payfastFields->getFields();
        $default_settings = parent::add_field_after('feedName', $fields, $default_settings);
        $default_settings = $this->remove_field('recurringTimes', $default_settings);
        $default_settings = $this->remove_field('billingCycle', $default_settings);

        // Remove trial period
        $default_settings = $this->remove_field('trial', $default_settings);

        //--add donation to transaction type drop down
        $transaction_type = parent::get_field('transactionType', $default_settings);
        $choices          = $transaction_type['choices'];
        $add_donation     = false;
        foreach ($choices as $choice) {
            //add donation option if it does not already exist
            if ($choice['value'] == 'donation') {
                $add_donation = false;
            }
        }
        if ($add_donation) {
            //add donation transaction type
            $choices[] = array('label' => __('Donations', 'gravityformspayfast'), 'value' => 'donation');
        }
        $transaction_type['choices'] = $choices;
        $default_settings            = $this->replace_field('transactionType', $transaction_type, $default_settings);
        //-------------------------------------------------------------------------------------------------

        //--add Page Style, Cancel URL
        $fields = $payfastFields->getCancelUrl();

        //Add post fields if form has a post
        $form = $this->get_current_form();
        if (GFCommon::has_post_field($form['fields'])) {
            $post_settings = $payfastFields->setPostSettings();
            if ($this->get_setting('transactionType') == 'subscription') {
                $post_settings['choices'][] = array(
                    'label'    => __('Change post status when subscription is canceled.', 'gravityformspayfast'),
                    'name'     => 'change_post_status',
                    'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
                );
            }
            $fields[] = $post_settings;
        }
        //Adding custom settings for backwards compatibility with hook 'gform_payfast_add_option_group'
        $fields[]         = array(
            'name'  => 'custom_options',
            'label' => '',
            'type'  => 'custom',
        );
        $default_settings = $this->add_field_after('billingInformation', $fields, $default_settings);
        //-----------------------------------------------------------------------------------------

        //--get billing info section and add customer first/last name
        $billing_info     = parent::get_field('billingInformation', $default_settings);
        $billing_fields   = $billing_info['field_map'];
        $add_first_name   = true;
        $add_last_name    = true;
        $billing_info     = $utilities->getBillingInfo($billing_fields, $add_first_name, $add_last_name, $billing_info);
        $default_settings = parent::replace_field('billingInformation', $billing_info, $default_settings);
        //----------------------------------------------------------------------------------------------------

        // hide default display of setup fee, not used by Payfast
        $default_settings = parent::remove_field('setupFee', $default_settings);

        //--Add Try to bill again after failed attempt.
        $freq             = $payfastFields->setFrequency();
        $initial          = $payfastFields->setInitialAmnt();
        $default_settings = parent::add_field_after('recurringAmount', $initial, $default_settings);

        $default_settings = parent::add_field_after('initialAmount', $freq, $default_settings);

        $cycles           = $payfastFields->setCycles();
        $default_settings = parent::add_field_after('frequency', $cycles, $default_settings);

        //-----------------------------------------------------------------------------------------------------
        return apply_filters('gform_payfast_feed_settings_fields', $default_settings, $form);
    }

    public function recurring_amount_choices()
    {
        $form                = $this->get_current_form();
        $recurring_choices   = $this->get_payment_choices($form);
        $recurring_choices[] = array('label' => esc_html__('Form Total', 'gravityforms'), 'value' => 'form_total');

        return $recurring_choices;
    }

    public function supported_billing_intervals()
    {
        $payfastForm = new PfGfForm();

        return $payfastForm->getBillingCycles();
    }

    public function field_map_title()
    {
        return __('Payfast Field', 'gravityformspayfast');
    }

    public function settings_trial_period($field, $echo = true)
    {
        // Use the parent billing cycle function to make the drop-down for the number and type
        return parent::settings_billing_cycle($field);
    }

    public function set_trial_onchange($field)
    {
        //return the javascript for the onchange event
        return "
        if(jQuery(this).prop('checked')){
            jQuery('#{$field['name']}_product').show('slow');
            jQuery('#gaddon-setting-row-trialPeriod').show('slow');
            if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
                jQuery('#{$field['name']}_amount').show('slow');
            }
            else{
                jQuery('#{$field['name']}_amount').hide();
            }
        }
        else {
            jQuery('#{$field['name']}_product').hide('slow');
            jQuery('#{$field['name']}_amount').hide();
            jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
        }";
    }

    public function settings_options($field, $echo = true): string
    {
        $payfastForm = new PfGfForm();
        $checkboxes  = $payfastForm->getOptionsSettings();
        $html        = $this->settings_checkbox($checkboxes, false);
        //--------------------------------------------------------
        //For backwards compatibility.
        ob_start();
        do_action('gform_payfast_action_fields', $this->get_current_feed(), $this->get_current_form());
        $html .= ob_get_clean();
        //--------------------------------------------------------
        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_custom($field, $echo = true)
    {
        ob_start();
        ?>
        <div id='gf_payfast_custom_settings'>
            <?php
            do_action('gform_payfast_add_option_group', $this->get_current_feed(), $this->get_current_form());
            ?>
        </div>

        <script type='text/javascript'>
          jQuery(document).ready(function (){
            jQuery('#gf_payfast_custom_settings label.left_header').css('margin-left', '-200px')
          })
        </script>

        <?php
        $html = ob_get_clean();
        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_notifications($field, $echo = true)
    {
        $checkboxes = array(
            'name'    => 'delay_notification',
            'type'    => 'checkboxes',
            'onclick' => 'ToggleNotifications();',
            'choices' => array(
                array(
                    'label' => __('Send notifications only when payment is received.', 'gravityformspayfast'),
                    'name'  => 'delayNotification',
                ),
            )
        );

        $html                      = $this->settings_checkbox($checkboxes, false);
        $html                      .= $this->settings_hidden(
            array('name' => 'selectedNotifications', 'id' => 'selectedNotifications'),
            false
        );
        $form                      = $this->get_current_form();
        $has_delayed_notifications = $this->get_setting('delayNotification');
        ob_start();
        ?>
        <ul id="gf_payfast_notification_container" style="padding-left:20px; margin-top:10px; <?php
        echo $has_delayed_notifications ? '' : 'display:none;' ?>">
            <?php
            if (!empty($form) && is_array($form['notifications'])) {
                $selected_notifications = $this->get_setting('selectedNotifications');
                if (!is_array($selected_notifications)) {
                    $selected_notifications = array();
                }
                $notifications = GFCommon::get_notifications('form_submission', $form);
                foreach ($notifications as $notification) {
                    ?>
                    <li class="gf_payfast_notification">
                        <input type="checkbox" class="notification_checkbox" value="<?php
                        echo $notification['id'] ?>" onclick="SaveNotifications();" <?php
                        checked(true, in_array($notification['id'], $selected_notifications)) ?> />
                        <label class="inline" for="gf_payfast_selected_notifications"><?php
                            echo $notification['name']; ?></label>
                    </li>
                    <?php
                }
            }
            ?>
        </ul>
        <script type='text/javascript'>
          function SaveNotifications(){
            var notifications = []
            jQuery('.notification_checkbox').each(function (){
              if(jQuery(this).is(':checked')){
                notifications.push(jQuery(this).val())
              }
            })
            jQuery('#selectedNotifications').val(jQuery.toJSON(notifications))
          }

          function ToggleNotifications(){
            var container = jQuery('#gf_payfast_notification_container')
            var isChecked = jQuery('#delaynotification').is(':checked')
            if(isChecked){
              container.slideDown()
              jQuery('.gf_payfast_notification input').prop('checked', true)
            } else {
              container.slideUp()
              jQuery('.gf_payfast_notification input').prop('checked', false)
            }
            SaveNotifications()
          }
        </script>
        <?php
        $html .= ob_get_clean();
        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function checkbox_input_change_post_status($choice, $attributes, $value, $tooltip)
    {
        $markup         = $this->checkbox_input($choice, $attributes, $value, $tooltip);
        $dropdown_field = array(
            'name'     => 'update_post_action',
            'choices'  => array(
                array('label' => ''),
                array('label' => __('Mark Post as Draft', 'gravityformspayfast'), 'value' => 'draft'),
                array('label' => __('Delete Post', 'gravityformspayfast'), 'value' => 'delete'),
            ),
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        );

        return $markup . '&nbsp;&nbsp;' . $this->settings_select($dropdown_field, false);
    }

    //------ SENDING TO PAYFAST -----------//

    public function option_choices()
    {
        return false;
    }

    public function save_feed_settings($feed_id, $form_id, $settings)
    {
        //--------------------------------------------------------
        //For backwards compatibility
        $feed = $this->get_feed($feed_id);
        //Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];
        if (isset($settings['recurringAmount'])) {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }
        $feed['meta'] = $settings;
        $feed         = apply_filters('gform_payfast_save_config', $feed);

        //call hook to validate custom settings/meta added using gform_payfast_action_fields or gform_payfast_add_option_group action hooks
        $is_validation_error = apply_filters('gform_payfast_config_validation', false, $feed);
        if ($is_validation_error) {
            //fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    public function redirect_url($feed, $submission_data, $form, $entry)
    {
        $utilities = new PfGfUtilities();
        //Don't process redirect url if request is a Payfast return
        if (!rgempty('gf_payfast_return', $_GET)) {
            return false;
        }

        //updating lead's payment_status to Pending
        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Pending');

        //Getting Url (Production or Sandbox)
        $url = $feed['meta']['mode'] == 'production' ? $this->productionUrl : $this->sandboxUrl;


        //Set return mode to 2 (Payfast will post info back to page). rm=1 seems to create lots of problems with the redirect back to the site. Defaulting it to 2.
        $return_mode = '2';

        $return_url = $this->return_url($form['id'], $entry['id']) . "&rm={$return_mode}";

        //Cancel URL
        $cancel_url = $feed['meta']['cancelUrl'];

        //URL that will listen to notifications from Payfast
        $itn_url = get_bloginfo('url') . '/?page=gf_payfast_itn';

        $merchant_id  = $feed['meta']['payfastMerchantId'];
        $merchant_key = $feed['meta']['payfastMerchantKey'];
        $passPhrase   = $feed['meta']['passphrase'];

        $pfNotifications = rgars($feed, 'meta/selectedNotifications');

        $varArray = array(
            'merchant_id'  => $merchant_id,
            'merchant_key' => $merchant_key,
            'return_url'   => $return_url
        );

        $varArray = $utilities->setCancelUrl($cancel_url, $varArray);

        $varArray['notify_url'] = $itn_url;

        $varArray = $utilities->setCustomerEmail($form, $feed, $entry, $varArray);

        $varArray['m_payment_id'] = $entry['id'];

        if ($feed['meta']['transactionType'] === 'subscription') {
            $initialAmountKey = $feed['meta']['initialAmount'];

            if ($initialAmountKey !== 'form_total' && !empty($entry[$initialAmountKey . '.2'])) {
                // Case: Subscription with a specific initial amount field
                $varArray['amount'] = str_replace(",", "", substr($entry[$initialAmountKey . '.2'], 1));
            } elseif ($initialAmountKey === 'form_total' && !empty($entry[$initialAmountKey])) {
                // Case: Subscription with total form amount
                $varArray['amount'] = substr(
                    $entry[$initialAmountKey],
                    strpos($entry[$initialAmountKey], '|') + 1
                );
            } else {
                // Fallback case: Default to the order total
                $varArray['amount'] = GFCommon::get_order_total($form, $entry);
            }
        } else {
            // Non-subscription case: Default to the order total
            $varArray['amount'] = GFCommon::get_order_total($form, $entry);
        }


        $varArray['item_name'] = $form['title'];

        $varArray['custom_int1'] = $feed['meta']['mode'] == 'production' ? 0 : 1;

        $varArray['custom_int2'] = $form['id'];

        $varArray['custom_str1'] = 'PF_GRAVITYFORMS_' . $this->_version . '_' . $this->_pf_gf_module_ver;

        $varArray['custom_str2'] = $pfNotifications[0] ?? '';

        $varArray['custom_str3'] = $pfNotifications[1] ?? '';

        if (rgars($feed, 'meta/delayPost')) {
            $varArray['custom_str4'] = 'delayPost';
        }

        // Include variables if subscription
        list($varArray, $entry) = $utilities->includeVariablesIfSubscription($feed['meta'], $varArray, $entry, $form);

        // Create output string
        $pfOutput = http_build_query($varArray, '', '&');

        // Append passphrase if provided, else trim the trailing ampersand
        if (!empty($passPhrase)) {
            $pfOutput .= '&passphrase=' . urlencode($passPhrase);
        }

        // Generate the signature
        $sig = md5($pfOutput);


        $secureString = '?';
        $secureString = $utilities->setSecureStr($varArray, $secureString);
        $secureString = substr($secureString, 0, -1);
        $query_string = apply_filters(
            "gform_payfast_query_{$form['id']}",
            apply_filters("gform_payfast_query", $secureString, $form, $entry),
            $form,
            $entry
        );

        if (!$query_string) {
            $this->log_debug(
                __METHOD__ . '(): NOT sending to Payfast: The price is either zero or the gform_payfast_query filter was used to remove the querystring that is sent to Payfast.'
            );

            return '';
        }
        $url .= $query_string;

        //add the bn code (build notation code)
        $url .= '&signature=' . $sig . '&user_agent=Gravity Forms 1.9';

        $this->log_debug(__METHOD__ . "(): Sending to Payfast: {$url}");

        return $url;
    }

    //customer email function

    public function get_product_query_string($submission_data, $entry_id)
    {
        if (empty($submission_data)) {
            return false;
        }
        $utilities      = new PfGfUtilities();
        $query_string   = '';
        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $discounts      = rgar($submission_data, 'discounts');
        $product_index  = 1;
        $shipping       = '';
        $discount_amt   = 0;
        $cmd            = '_cart';
        $extra_qs       = '&upload=1';
        //work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name = urlencode($item['name']);
                $quantity     = $item['quantity'];
                $unit_price   = $item['unit_price'];
                $options      = rgar($item, 'options');
                $is_shipping  = rgar($item, 'is_shipping');
                if ($is_shipping) {
                    //populate shipping info
                    $shipping .= !empty($unit_price) ? "&shipping_1={$unit_price}" : '';
                } else {
                    //add product info to querystring
                    $query_string .= "&item_name_{$product_index}={$product_name}&amount_{$product_index}={$unit_price}&quantity_{$product_index}={$quantity}";
                }
                //add options
                $query_string = $utilities->getQueryString($options, $product_index, $query_string);
                $product_index++;
            }
        }
        //look for discounts
        $query_string = $utilities->lookForDiscounts($discounts, $discount_amt, $query_string);
        $query_string .= "{$shipping}&cmd={$cmd}{$extra_qs}";
        //save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    public function get_donation_query_string($submission_data, $entry_id)
    {
        $utilities = new PfGfUtilities();
        if (empty($submission_data)) {
            return false;
        }
        $query_string   = '';
        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $purpose        = '';
        $cmd            = '_donations';
        //work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name    = $item['name'];
                $quantity        = $item['quantity'];
                $quantity_label  = $quantity > 1 ? $quantity . ' ' : '';
                $options         = rgar($item, 'options');
                $is_shipping     = rgar($item, 'is_shipping');
                $product_options = '';
                if (!$is_shipping) {
                    //add options
                    $product_options = $utilities->getProductOptions($options, $product_options);
                    $purpose         .= $quantity_label . $product_name . $product_options . ', ';
                }
            }
        }
        if (!empty($purpose)) {
            $purpose = substr($purpose, 0, strlen($purpose) - 2);
        }
        $purpose = urlencode($purpose);
        //truncating to maximum length allowed by Payfast
        if (strlen($purpose) > 127) {
            $purpose = substr($purpose, 0, 124) . '...';
        }
        $query_string = "&amount={$payment_amount}&item_name={$purpose}&cmd={$cmd}";
        //save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    public function customer_query_string($feed, $lead)
    {
        $fields = '';
        foreach ($this->get_customer_fields() as $field) {
            $field_id = $feed['meta'][$field['meta_name']];
            $value    = rgar($lead, $field_id);
            if ($field['name'] == 'country') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_country_code(
                    $value
                ) : GFCommon::get_country_code($value);
            } elseif ($field['name'] == 'state') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_us_state_code(
                    $value
                ) : GFCommon::get_us_state_code($value);
            }
            if (!empty($value)) {
                $fields .= "&{$field['name']}=" . urlencode($value);
            }
        }

        return $fields;
    }

    public function return_url($form_id, $lead_id)
    {
        $pageURL     = GFCommon::is_ssl() ? 'https://' : 'http://';
        $server_port = apply_filters('gform_payfast_return_url_port', $_SERVER['SERVER_PORT']);
        if ($server_port != '80') {
            $pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
        } else {
            $pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }
        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= '&hash=' . wp_hash($ids_query);

        return add_query_arg('gf_payfast_return', base64_encode($ids_query), $pageURL);
    }


    public function delay_post($is_disabled, $form, $entry)
    {
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);
        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        return !rgempty('delayPost', $feed['meta']);
    }

    //------- PROCESSING PAYFAST ITN (Callback) -----------//

    public function delay_notification($is_disabled, $notification, $form, $entry)
    {
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);
        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }
        $selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar(
            $feed['meta'],
            'selectedNotifications'
        ) : array();

        return isset($feed['meta']['delayNotification']) && in_array(
            $notification['id'],
            $selected_notifications
        ) ? true : $is_disabled;
    }

    public function get_payment_feed($entry, $form = false)
    {
        $feed = parent::get_payment_feed($entry, $form);
        if (empty($feed) && !empty($entry['id'])) {
            //looking for feed created by legacy versions
            $feed = $this->get_payfast_feed_by_entry($entry['id']);
        }

        return apply_filters('gform_payfast_get_payment_feed', $feed, $entry, $form);
    }

    public function get_payfast_feed_by_entry($entry_id)
    {
        $feed_id = gform_get_meta($entry_id, 'payfast_feed_id');
        $feed    = $this->get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    public function process_itn()
    {
        $payfastRequest = new PaymentRequest($this->get_plugin_setting('gf_payfast_debug') === '1');

        $feed = $this->get_feeds($_POST['custom_int2']);

        // handles products and donation
        self::log_debug("ITN request received. Starting to process...");
        $pfError       = false;
        $pfErrMsg      = '';
        $pfData        = $payfastRequest->pfGetData();
        $pfParamString = '';

        $entry = GFAPI::get_entry($pfData['m_payment_id']);
        //Ignore orphan ITN messages (ones without an entry)
        if (!$entry) {
            self::log_error("Entry could not be found. Aborting.");

            return;
        }

        self::log_debug("Entry has been found." . print_r($entry, true));

        $payfastRequest->pflog('Payfast ITN call received');
        self::log_debug('Payfast ITN call received');


        // Notify Payfast that information has been received
        header('HTTP/1.0 200 OK');
        flush();

        // Get data sent by Payfast
        $payfastRequest->pflog('Get posted data');
        // Posted variables from ITN
        $payfastRequest->pflog('Payfast Data: ' . print_r($pfData, true));
        self::log_debug('Get posted data');
        if ($pfData === false) {
            $pfError  = true;
            $pfErrMsg = $payfastRequest::PF_ERR_BAD_ACCESS;
        }

        // Verify security signature
        if (!$pfError) {
            $payfastRequest->pflog('Verify security signature');

            $passPhrase   = $feed[0]['meta']['passphrase'];
            $pfPassPhrase = empty($passPhrase) ? null : $passPhrase;

            // If signature different, log for debugging
            $pf_valid_signature = $payfastRequest->pfValidSignature($pfData, $pfParamString, $pfPassPhrase);

            if (!$pf_valid_signature) {
                $pfError  = true;
                $pfErrMsg = $payfastRequest::PF_ERR_INVALID_SIGNATURE;
            }
        }
        // Get internal cart
        $this->getInternalCart($pfError, $entry, $payfastRequest);

        // Verify data received
        if (!$pfError) {
            $pfValid = $this->verifyDataReceived($payfastRequest, $pfData['custom_int1'], $pfParamString);
            if ($pfValid) {
                self::log_debug("ITN message successfully verified by Payfast");
                $payfastRequest->pflog("ITN message successfully verified by Payfast");
            } else {
                $pfError  = true;
                $pfErrMsg = $payfastRequest::PF_ERR_BAD_ACCESS;
            }
        }
        // Check status and update order
        if (!$pfError) {
            $entry = GFAPI::get_entry($pfData['m_payment_id']);
            $form  = GFFormsModel::get_form_meta($pfData['custom_int2']);

            $sendUserMail         = false;
            $sendAdminMail        = false;
            $notificationAdminId  = [];
            $notificationClientId = [];

            $this->paymentStatus(
                $pfData,
                $form,
                $entry,
                $sendAdminMail,
                $notificationAdminId,
                $sendUserMail,
                $notificationClientId,
            );
        }

        if ($pfError) {
            $payfastRequest->pflog('Error occured: ' . $pfErrMsg);
        }
    }

    public function get_entry($custom_field): bool
    {
        // Valid ITN requests must have a custom field
        if (empty($custom_field)) {
            $this->log_error(
                __METHOD__ . '(): ITN request does not have a custom field, so it was not created by Gravity Forms. Aborting.'
            );

            return false;
        }
        //Getting entry associated with this ITN message (entry id is sent in the 'custom' field)
        list($entry_id, $hash) = explode('|', $custom_field);
        $hash_matches = wp_hash($entry_id) == $hash;
        //allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters('gform_payfast_hash_matches', $hash_matches, $entry_id, $hash, $custom_field);
        //Validates that Entry Id wasn't tampered with
        if (!rgpost('test_itn') && !$hash_matches) {
            $this->log_error(
                __METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: {$custom_field}. Aborting."
            );

            return false;
        }
        $this->log_debug(__METHOD__ . "(): ITN message has a valid custom field: {$custom_field}");
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry)) {
            $this->log_error(__METHOD__ . '(): ' . $entry->get_error_message());

            $entry = false;
        }

        return $entry;
    }

    public function modify_post($post_id, $action): bool
    {
        $result = false;
        if (!$post_id) {
            return false;
        }
        switch ($action) {
            case 'draft':
                $post              = get_post($post_id);
                $post->post_status = 'draft';
                $result            = wp_update_post($post);
                $this->log_debug(__METHOD__ . "(): Set post (#{$post_id}) status to \"draft\".");
                break;
            case 'delete':
                $result = wp_delete_post($post_id);
                $this->log_debug(__METHOD__ . "(): Deleted post (#{$post_id}).");
                break;
            default:
                break;
        }

        return $result;
    }

    public function is_callback_valid(): bool
    {
        if (rgget('page') != 'gf_payfast_itn') {
            return false;
        }
        $this->process_itn();

        return true;
    }

    //------- AJAX FUNCTIONS ------------------//

    public function init_ajax()
    {
        parent::init_ajax();
        add_action('wp_ajax_gf_dismiss_payfast_menu', array($this, 'ajax_dismiss_menu'));
    }

    //------- ADMIN FUNCTIONS/HOOKS -----------//
    public function init_admin()
    {
        parent::init_admin();
        //add actions to allow the payment status to be modified
        add_action('gform_payment_status', array($this, 'admin_edit_payment_status'), 3, 3);
        if (version_compare(GFCommon::$version, '1.8.17.4', '<')) {
            //using legacy hook
            add_action('gform_entry_info', array($this, 'admin_edit_payment_status_details'), 4, 2);
        } else {
            add_action('gform_payment_date', array($this, 'admin_edit_payment_date'), 3, 3);
            add_action('gform_payment_transaction_id', array($this, 'admin_edit_payment_transaction_id'), 3, 3);
            add_action('gform_payment_amount', array($this, 'admin_edit_payment_amount'), 3, 3);
        }
        add_action('gform_after_update_entry', array($this, 'admin_update_payment'), 4, 2);
        add_filter('gform_addon_navigation', array($this, 'maybe_create_menu'));
        add_filter('gform_notification_events', array($this, 'notification_events_dropdown'), 10, 2);
    }

    public function maybe_create_menu($menus)
    {
        $current_user         = wp_get_current_user();
        $dismiss_payfast_menu = get_metadata('user', $current_user->ID, 'dismiss_payfast_menu', true);
        if ($dismiss_payfast_menu != '1') {
            $menus[] = array(
                'name'       => $this->_slug,
                'label'      => $this->get_short_title(),
                'callback'   => array($this, 'temporary_plugin_page'),
                'permission' => $this->_capabilities_form_settings
            );
        }

        return $menus;
    }

    public function notification_events_dropdown($notification_events)
    {
        $payment_events = array(
            'complete_payment' => __('Payment Complete', 'gravityforms')
        );

        return array_merge($notification_events, $payment_events);
    }

    public function ajax_dismiss_menu()
    {
        $current_user = wp_get_current_user();
        update_metadata('user', $current_user->ID, 'dismiss_payfast_menu', '1');
    }

    public function temporary_plugin_page()
    {
        ?>
        <script type="text/javascript">
          function dismissMenu(){
            jQuery('#gf_spinner').show()
            jQuery.post(ajaxurl, {
                action: 'gf_dismiss_payfast_menu'
              },
              function (response){
                document.location.href = '?page=gf_edit_forms'
                jQuery('#gf_spinner').hide()
              }
            )
          }
        </script>

        <div class="wrap about-wrap">
            <h1><?php
                _e('Payfast Add-On v' . $this->_pf_gf_module_ver, 'gravityformspayfast') ?></h1>
            <div class="about-text"><?php
                _e(
                    'Thank you for updating! The new version of the Gravity Forms Payfast Add-On makes changes to how you manage your Payfast integration.',
                    'gravityformspayfast'
                ) ?></div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php
                            _e('Manage Payfast Contextually', 'gravityformspayfast') ?></h3>
                        <p><?php
                            _e(
                                'Payfast Feeds are now accessed via the Payfast sub-menu within the Form Settings for the Form you would like to integrate Payfast with.',
                                'gravityformspayfast'
                            ) ?></p>
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_payfast_menu" value="1" onclick="dismissMenu();"> <label><?php
                        _e('I understand, dismiss this message!', 'gravityformspayfast') ?></label>
                    <img id="gf_spinner" src="<?php
                    echo GFCommon::get_base_url() . '/images/spinner.gif' ?>" alt="<?php
                    _e('Please wait...', 'gravityformspayfast') ?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
    }

    public function admin_edit_payment_status($payment_status, $form, $lead)
    {
        //allow the payment status to be edited when for payfast, not set to Approved/Paid, and not a subscription
        if (
            !$this->is_payment_gateway($lead['id']) || strtolower(
                rgpost('save')
            ) <> 'edit' || $payment_status == 'Approved' || $payment_status == 'Paid' || rgar(
                $lead,
                'transaction_type'
            ) == 2
        ) {
            return $payment_status;
        }
        //create drop down for payment status
        $payment_string = gform_tooltip('payfast_edit_payment_status', '', true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';

        return $payment_string . '</select>';
    }

    public function admin_edit_payment_date($payment_date, $form, $lead)
    {
        //allow the payment date to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) <> 'edit') {
            return $payment_date;
        }
        $payment_date = $lead['payment_date'];
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_LITERAL);
        }

        return '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';
    }

    public function admin_edit_payment_transaction_id($transaction_id, $form, $lead)
    {
        //allow the transaction ID to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) <> 'edit') {
            return $transaction_id;
        }

        return '<input type="text" id="payfast_transaction_id" name="payfast_transaction_id" value="' . $transaction_id . '">';
    }

    public function admin_edit_payment_amount($payment_amount, $form, $lead)
    {
        //allow the payment amount to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) <> 'edit') {
            return $payment_amount;
        }
        if (empty($payment_amount)) {
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }

        return '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';
    }

    public function admin_edit_payment_status_details($form_id, $lead)
    {
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead['id']) || $form_action <> 'edit') {
            return;
        }
        //get data from entry to pre-populate fields
        $payment_amount = rgar($lead, 'payment_amount');
        if (empty($payment_amount)) {
            $form           = GFFormsModel::get_form_meta($form_id);
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }
        $transaction_id = rgar($lead, 'transaction_id');
        $payment_date   = rgar($lead, 'payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_LITERAL);
        }
        //display edit fields
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <caption>Edit Payfast payment status details</caption>
                <tr>
                    <th scope="col">Payment Information</th>
                </tr>

                <tr>
                    <td>Date:<?php
                        gform_tooltip('payfast_edit_payment_date') ?></td>
                    <td>
                        <input type="text" id="payment_date" name="payment_date" value="<?php
                        echo $payment_date ?>">
                    </td>
                </tr>
                <tr>
                    <td>Amount:<?php
                        gform_tooltip('payfast_edit_payment_amount') ?></td>
                    <td>
                        <input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="<?php
                        echo $payment_amount ?>">
                    </td>
                </tr>
                <tr>
                    <td>Transaction ID:<?php
                        gform_tooltip('payfast_edit_payment_transaction_id') ?></td>
                    <td>
                        <input type="text" id="payfast_transaction_id" name="payfast_transaction_id" value="<?php
                        echo $transaction_id ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function admin_update_payment($form, $lead_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');
        //update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead_id) || $form_action <> 'update') {
            return;
        }
        //get lead
        $lead = GFFormsModel::get_lead($lead_id);
        //check if current payment status is Pending
        if ($lead['payment_status'] != 'Pending') {
            return;
        }
        //get payment fields to update
        $payment_status = $_POST['payment_status'];
        //when updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status)) {
            $payment_status = $lead['payment_status'];
        }
        $payment_amount      = GFCommon::to_number(rgpost('payment_amount'));
        $payment_transaction = rgpost('payfast_transaction_id');
        $payment_date        = rgpost('payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_LITERAL);
        } else {
            //format date entered by user
            $payment_date = date(self::DATE_LITERAL, strtotime($payment_date));
        }
        global $current_user;
        $user_id   = 0;
        $user_name = 'System';
        if ($current_user && $user_data = get_userdata($current_user->ID)) {
            $user_id   = $current_user->ID;
            $user_name = $user_data->display_name;
        }
        $lead['payment_status'] = $payment_status;
        $lead['payment_amount'] = $payment_amount;
        $lead['payment_date']   = $payment_date;
        $lead['transaction_id'] = $payment_transaction;
        // if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
        if (($payment_status == 'Approved' || $payment_status == 'Paid') && !$lead['is_fulfilled']) {
            $action['id']             = $payment_transaction;
            $action['type']           = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount']         = $payment_amount;
            $action['entry_id']       = $lead['id'];
            $this->complete_payment($lead, $action);
            $this->fulfill_order($lead, $payment_transaction, $payment_amount);
        }
        //update lead, add a note
        GFAPI::update_entry($lead);
        GFFormsModel::add_note(
            $lead['id'],
            $user_id,
            $user_name,
            sprintf(
                __(
                    'Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s',
                    'gravityformspayfast'
                ),
                $lead['payment_status'],
                GFCommon::to_money($lead['payment_amount'], $lead['currency']),
                $payment_transaction,
                $lead['payment_date']
            )
        );
    }

    public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
    {
        if (!$feed) {
            $feed = $this->get_payment_feed($entry);
        }
        $form = GFFormsModel::get_form_meta($entry['form_id']);
        if (rgars($feed, 'meta/delayPost')) {
            $this->log_debug(__METHOD__ . '(): Creating post.');
            $entry['post_id'] = GFFormsModel::create_post($form, $entry);
            $this->log_debug(__METHOD__ . '(): Post created.');
        }
        if (rgars($feed, 'meta/delayNotification')) {
            //sending delayed notifications
            $notifications = rgars($feed, 'meta/selectedNotifications');
            GFCommon::send_notifications($notifications, $form, $entry, true, 'form_submission');
        }
        do_action('gform_payfast_fulfillment', $entry, $feed, $transaction_id, $amount);
        if (has_filter('gform_payfast_fulfillment')) {
            $this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_payfast_fulfillment.');
        }
    }

    public function payfast_fulfillment($entry, $payfast_config, $transaction_id, $amount)
    {
        //no need to do anything for payfast when it runs this function, ignore
        return false;
    }

    public function upgrade($previous_version)
    {
        $previous_is_pre_addon_framework = version_compare($previous_version, '1.0', '<');
        if ($previous_is_pre_addon_framework) {
            //copy plugin settings
            $this->copy_settings();
            //copy existing feeds to new table
            $this->copy_feeds();
            //copy existing payfast transactions to new table
            $this->copy_transactions();
            //updating payment_gateway entry meta to 'gravityformspayfast' from 'payfast'
            $this->update_payment_gateway();
            //updating entry status from 'Approved' to 'Paid'
            $this->update_lead();
        }
    }
    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    //Change data when upgrading from legacy payfast

    public function update_feed_id($old_feed_id, $new_feed_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payfast_feed_id' AND meta_value=%s",
            $new_feed_id,
            $old_feed_id
        );
        $wpdb->query($sql);
    }

    public function add_legacy_meta($new_meta, $old_feed)
    {
        $known_meta_keys = array(
            'email',
            'mode',
            'type',
            'style',
            'continue_text',
            'cancel_url',
            'disable_note',
            'disable_shipping',
            'recurring_amount_field',
            'recurring_times',
            'recurring_retry',
            'billing_cycle_number',
            'billing_cycle_type',
            'trial_period_enabled',
            'trial_amount',
            'trial_period_number',
            'trial_period_type',
            'delay_post',
            'update_post_action',
            'delay_notifications',
            'selected_notifications',
            'payfast_conditional_enabled',
            'payfast_conditional_field_id',
            'payfast_conditional_operator',
            'payfast_conditional_value',
            'customer_fields',
        );
        foreach ($old_feed['meta'] as $key => $value) {
            if (!in_array($key, $known_meta_keys)) {
                $new_meta[$key] = $value;
            }
        }

        return $new_meta;
    }

    public function update_payment_gateway()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='payfast'",
            $this->_slug
        );
        $wpdb->query($sql);
    }

    public function update_lead()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead
             SET payment_status='Paid', payment_method='Payfast'
             WHERE payment_status='Approved'
                    AND ID IN (
                        SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway' AND meta_value=%s
                    )",
            $this->_slug
        );
        $wpdb->query($sql);
    }

    public function copy_settings()
    {
        //copy plugin settings
        $old_settings = get_option('gf_payfast_configured');
        $new_settings = array('gf_payfast_configured' => $old_settings);
        $this->update_plugin_settings($new_settings);
    }

    public function copy_feeds()
    {
        $utilities = new PfGfUtilities();
        //get feeds
        $old_feeds = $this->get_old_feeds();
        if ($old_feeds) {
            $counter = 1;
            foreach ($old_feeds as $old_feed) {
                $feed_name       = 'Feed ' . $counter;
                $form_id         = $old_feed['form_id'];
                $is_active       = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];
                $new_meta        = array(
                    'feedName'                     => $feed_name,
                    'payfastMerchantId'            => rgar($old_feed['meta'], 'payfastMerchantId'),
                    'payfastMerchantKey'           => rgar($old_feed['meta'], 'payfastMerchantKey'),
                    'passphrase'                   => rgar($old_feed['meta'], 'passphrase'),
                    'mode'                         => rgar($old_feed['meta'], 'mode'),
                    'transactionType'              => rgar($old_feed['meta'], 'type'),
                    'type'                         => rgar($old_feed['meta'], 'type'),
                    //For backwards compatibility of the delayed payment feature
                    'pageStyle'                    => rgar($old_feed['meta'], 'style'),
                    'continueText'                 => rgar($old_feed['meta'], 'continue_text'),
                    'cancelUrl'                    => rgar($old_feed['meta'], 'cancel_url'),
                    'disableNote'                  => rgar($old_feed['meta'], 'disable_note'),
                    'disableShipping'              => rgar($old_feed['meta'], 'disable_shipping'),
                    'recurringAmount'              => rgar(
                        $old_feed['meta'],
                        'recurring_amount_field'
                    ) == 'all' ? 'form_total' : rgar(
                        $old_feed['meta'],
                        'recurring_amount_field'
                    ),
                    'recurring_amount_field'       => rgar($old_feed['meta'], 'recurring_amount_field'),
                    //For backwards compatibility of the delayed payment feature
                    'initialAmount'                => rgar($old_feed['meta'], 'initialAmount'),
                    'recurringTimes'               => rgar($old_feed['meta'], 'recurring_times'),
                    'recurringRetry'               => rgar($old_feed['meta'], 'recurring_retry'),
                    'paymentAmount'                => 'form_total',
                    'billingCycle_length'          => rgar($old_feed['meta'], 'billing_cycle_number'),
                    'billingCycle_unit'            => $utilities->convertInterval(
                        rgar($old_feed['meta'], 'billing_cycle_type'),
                        'text'
                    ),
                    'trial_enabled'                => rgar($old_feed['meta'], 'trial_period_enabled'),
                    'trial_product'                => 'enter_amount',
                    'trial_amount'                 => rgar($old_feed['meta'], 'trial_amount'),
                    'trialPeriod_length'           => rgar($old_feed['meta'], 'trial_period_number'),
                    'trialPeriod_unit'             => $utilities->convertInterval(
                        rgar($old_feed['meta'], 'trial_period_type'),
                        'text'
                    ),
                    'delayPost'                    => rgar($old_feed['meta'], 'delay_post'),
                    'change_post_status'           => rgar($old_feed['meta'], 'update_post_action') ? '1' : '0',
                    'update_post_action'           => rgar($old_feed['meta'], 'update_post_action'),
                    'delayNotification'            => rgar($old_feed['meta'], 'delay_notifications'),
                    'selectedNotifications'        => rgar($old_feed['meta'], 'selected_notifications'),
                    'billingInformation_firstName' => rgar($customer_fields, 'first_name'),
                    'billingInformation_lastName'  => rgar($customer_fields, 'last_name'),
                    'billingInformation_email'     => rgar($customer_fields, 'email'),
                    'billingInformation_address'   => rgar($customer_fields, 'address1'),
                    'billingInformation_address2'  => rgar($customer_fields, 'address2'),
                    'billingInformation_city'      => rgar($customer_fields, 'city'),
                    'billingInformation_state'     => rgar($customer_fields, 'state'),
                    'billingInformation_zip'       => rgar($customer_fields, 'zip'),
                    'billingInformation_country'   => rgar($customer_fields, 'country'),
                );
                $new_meta        = $this->add_legacy_meta($new_meta, $old_feed);
                //add conditional logic
                $conditional_enabled = rgar($old_feed['meta'], 'payfast_conditional_enabled');
                if ($conditional_enabled) {
                    $new_meta['feed_condition_conditional_logic']        = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = array(
                        'conditionalLogic' =>
                            array(
                                'actionType' => 'show',
                                'logicType'  => 'all',
                                'rules'      => array(
                                    array(
                                        'fieldId'  => rgar($old_feed['meta'], 'payfast_conditional_field_id'),
                                        'operator' => rgar($old_feed['meta'], 'payfast_conditional_operator'),
                                        'value'    => rgar($old_feed['meta'], 'payfast_conditional_value')
                                    ),
                                )
                            )
                    );
                } else {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }
                $new_feed_id = $this->insert_feed($form_id, $is_active, $new_meta);
                $this->update_feed_id($old_feed['id'], $new_feed_id);
                $counter++;
            }
        }
    }

    public function copy_transactions()
    {
        //copy transactions from the payfast transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        $this->log_debug(__METHOD__ . '(): Copying old Payfast transactions into new table structure.');
        $new_table_name = $this->get_new_transaction_table_name();
        $sql            = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
                    SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";
        $wpdb->query($sql);
        $this->log_debug(__METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added.");
    }

    public function get_old_transaction_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'rg_payfast_transaction';
    }

    public function get_new_transaction_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'gf_addon_payment_transaction';
    }

    public function get_old_feeds()
    {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'rg_payfast';
        $form_table_name = GFFormsModel::get_form_table_name();
        $sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                    FROM {$table_name} s
                    INNER JOIN {$form_table_name} f ON s.form_id = f.id";
        $this->log_debug(__METHOD__ . "(): getting old feeds: {$sql}");
        $results = $wpdb->get_results($sql, ARRAY_A);
        $this->log_debug(__METHOD__ . "(): error?: {$wpdb->last_error}");
        $count = sizeof($results);
        $this->log_debug(__METHOD__ . "(): count: {$count}");
        for ($i = 0; $i < $count; $i++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }

    /**
     * @param mixed $pfData
     * @param $form
     * @param $entry
     * @param bool $sendAdminMail
     * @param  $notificationAdminId
     * @param bool $sendUserMail
     * @param  $notificationClientId
     *
     * @return void
     */
    public function paymentStatus(
        mixed $pfData,
        $form,
        $entry,
        bool $sendAdminMail,
        $notificationAdminId,
        bool $sendUserMail,
        $notificationClientId,
    ): void {
        $payfastRequest = new PaymentRequest($this->get_plugin_setting('gf_payfast_debug') === '1');

        if (!empty($pfData['custom_str2'])) {
            $sendAdminMail       = true;
            $notificationAdminId = array($pfData['custom_str2']);
        }

        if (!empty($pfData['custom_str3'])) {
            $sendUserMail         = true;
            $notificationClientId = array($pfData['custom_str3']);
        }

        switch ($pfData['payment_status']) {
            case 'COMPLETE':
                $payfastRequest->pflog('- Complete');

                // If delayed post, create it now
                if (($pfData['custom_str4'] ?? null) === 'delayPost') {
                    $entry['post_id'] = GFFormsModel::create_post($form, $entry);
                }

                $paymentId     = $pfData['m_payment_id'];
                $transactionId = $pfData['pf_payment_id'];
                $amountGross   = $pfData['amount_gross'];
                $paymentDate   = gmdate(self::DATE_LITERAL);
                $currentDate   = gmdate('Y-m-d') . self::DURATION_LITERAL;
                $customDate    = $pfData['custom_str4'] ?? null;
                $hasToken      = !empty($pfData['token']);

                // Handle transaction completion
                $this->handleTransactionCompletion(
                    $hasToken,
                    $customDate,
                    $currentDate,
                    $paymentId,
                    $form,
                    $entry,
                    $transactionId,
                    $amountGross,
                    $paymentDate
                );

                // Handle single entry updates
                if (!$hasToken) {
                    $payfastRequest->pflog('- Handle single entry updates');
                    GFAPI::update_entry_property($paymentId, 'transaction_type', '1');
                    $this->insertTransaction($paymentId, 'complete_payment', $transactionId, $amountGross);
                }

                // Handle subscription creation or renewal
                if ($hasToken) {
                    $currentDateTime = strtotime($currentDate);
                    $customDateTime  = strtotime($customDate);
                    $adjustedDate    = strtotime($customDate . self::DURATION_LITERAL);

                    if ($customDateTime <= $currentDateTime) {
                        // Create subscription if the custom date is less than or equal to the current date
                        GFAPI::update_entry_property($paymentId, 'transaction_type', '2');
                        $this->insertTransaction($paymentId, 'create_subscription', $transactionId, $amountGross);
                    } elseif ($currentDateTime > $adjustedDate) {
                        // Complete payment if the current date is past the adjusted custom date
                        GFAPI::update_entry_property($paymentId, 'transaction_type', '1');
                        $this->insertTransaction($paymentId, 'complete_payment', $transactionId, $amountGross);
                    }


                    // Log subscription payment
                    $action = [
                        'amount'          => $amountGross,
                        'subscription_id' => $paymentId,
                        'note'            => sprintf(
                            esc_html__('Subscription has been paid. Amount: R%s. Subscription Id: %s', 'gravityforms'),
                            $amountGross,
                            $paymentId
                        ),
                    ];

                    $payfastRequest->pflog('- Log subscription payment');
                    $this->insertTransaction($paymentId, 'payment', $transactionId, $action['amount']);
                    $payfastRequest->pflog('- Log subscription payment');
                    GFFormsModel::add_note($paymentId, 0, 'Payfast', $action['note'], 'success');
                }
                $this->sendNotifications(
                    $sendAdminMail,
                    $notificationAdminId,
                    $form,
                    $entry,
                    $sendUserMail,
                    $notificationClientId
                );

                // Perform any custom actions
                do_action('gform_payfast_payment_complete', $pfData);

                break;

            case 'CANCELLED':
                $payfastRequest->pflog('Subscription Cancelled with entry ID: ' . $pfData['m_payment_id']);

                $note = sprintf(
                    esc_html__('Subscription Cancelled. Entry Id: %s', 'gravityforms'),
                    $pfData['m_payment_id']
                );
                GFAPI::update_entry_property($pfData['m_payment_id'], 'payment_status', 'Cancelled');
                GFFormsModel::add_note($pfData['m_payment_id'], 0, 'Payfast', $note, 'Cancelled');

                // Perform any custom actions
                do_action('gform_payfast_payment_cancelled', $pfData);

                break;

            case 'processed':
            case 'pending':
                // wait for complete
                break;
            case 'denied':
            case 'failed':
                //wait for complete
                break;
            default:
                break;
        }
    }

    /**
     * @param bool $sendAdminMail
     * @param Request $payfastRequest
     * @param array|null $notificationAdminId
     * @param $form
     * @param $entry
     * @param bool $sendUserMail
     * @param array|null $notificationClientId
     *
     * @return void
     */
    public function sendNotifications(
        bool $sendAdminMail,
        ?array $notificationAdminId,
        $form,
        $entry,
        bool $sendUserMail,
        ?array $notificationClientId
    ): void {
        //Send admin notifications
        $payfastRequest = new PaymentRequest($this->get_plugin_setting('gf_payfast_debug') === '1');

        if ($sendAdminMail) {
            $payfastRequest->pflog('sendadminmail');
            GFCommon::send_notifications($notificationAdminId, $form, $entry, true, 'form_submission');
        }

        // Send user notifications
        if ($sendUserMail) {
            $payfastRequest->pflog('sendusermail');
            GFCommon::send_notifications($notificationClientId, $form, $entry, true, 'form_submission');
        }
    }

    /**
     * @param bool $hasToken
     * @param mixed $customDate
     * @param string $currentDate
     * @param mixed $paymentId
     * @param $form
     * @param $entry
     * @param mixed $transactionId
     * @param mixed $amountGross
     * @param string $paymentDate
     *
     * @return void
     */
    public function handleTransactionCompletion(
        bool $hasToken,
        mixed $customDate,
        string $currentDate,
        mixed $paymentId,
        $form,
        $entry,
        mixed $transactionId,
        mixed $amountGross,
        string $paymentDate
    ): void {
        if (!$hasToken || strtotime($customDate) <= strtotime($currentDate)) {
            GFAPI::update_entry_property($paymentId, 'payment_status', 'Approved');
            GFAPI::send_notifications($form, $entry, 'complete_payment');
            GFAPI::update_entry_property($paymentId, 'transaction_id', $transactionId);
            GFAPI::update_entry_property($paymentId, 'payment_amount', $amountGross);
            GFAPI::update_entry_property($paymentId, 'is_fulfilled', '1');
            GFAPI::update_entry_property($paymentId, 'payment_method', 'Payfast');
            GFAPI::update_entry_property($paymentId, 'payment_date', $paymentDate);
        }
    }

    /**
     * @param PaymentRequest $payfastRequest
     * @param $custom_int
     * @param string $pfParamString
     *
     * @return bool
     */
    public function verifyDataReceived(PaymentRequest $payfastRequest, $custom_int, string $pfParamString): bool
    {
        $payfastRequest->pflog('Verify data received');
        self::log_debug('Verify data received');

        $pfHost = 'www.payfast.co.za';

        if ($custom_int == 1) {
            $pfHost = 'sandbox.payfast.co.za';
        }

        $moduleInfo = [
            "pfSoftwareName"       => 'GravityForms',
            "pfSoftwareVer"        => $this->_version,
            "pfSoftwareModuleName" => 'Payfast-GravityForms',
            "pfModuleVer"          => $this->_pf_gf_module_ver,
        ];

        return $payfastRequest->pfValidData($moduleInfo, $pfHost, $pfParamString);
    }

    private function __clone()
    {
        /* do nothing */
    }

    //This function kept static for backwards compatibility

    private function get_pending_reason($code)
    {
        if (strtolower($code) === "address") {
            return __(
                'The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences is set to allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.',
                'gravityformspayfast'
            );
        }

        return empty($code) ? __(
            'Reason has not been specified. For more information, contact Payfast Customer Service.',
            'gravityformspayfast'
        ) : $code;
    }
    //This function kept static for backwards compatibility
    //This needs to be here until all add-ons are on the framework, otherwise they look for this function

    private function is_valid_initial_payment_amount($entry_id, $amount_paid)
    {
        //get amount initially sent to payfast
        $amount_sent = gform_get_meta($entry_id, 'payment_amount');
        if (empty($amount_sent)) {
            return true;
        }
        $epsilon    = 0.00001;
        $is_equal   = abs(floatval($amount_paid) - floatval($amount_sent)) < $epsilon;
        $is_greater = floatval($amount_paid) > floatval($amount_sent);
        //initial payment is valid if it is equal to or greater than product/subscription amount
        if ($is_equal || $is_greater) {
            return true;
        }

        return false;
    }
    //------------------------------------------------------

    /**
     * @param bool $pfError
     * @param $entry
     * @param PaymentRequest $payfastRequest
     *
     * @return void
     */
    public function getInternalCart(bool $pfError, $entry, PaymentRequest $payfastRequest): void
    {
        if (!$pfError) {
            $order_info = $entry;
            $payfastRequest->pflog("Purchase:\n" . print_r($order_info, true));
            self::log_debug("Purchase:\n" . print_r($order_info, true));
        }
    }


    /**
     * Insert a transaction into Gravity Forms Payment Add-On.
     *
     * @param string $paymentId The payment ID (entry ID in Gravity Forms).
     * @param string $transactionType The type of transaction (e.g., 'complete_payment', 'create_subscription').
     * @param string $transactionId The transaction ID from PayFast.
     * @param float $amount The amount for the transaction.
     */
    public function insertTransaction(string $paymentId, string $transactionType, string $transactionId, float $amount)
    {
        GFPaymentAddOn::insert_transaction($paymentId, $transactionType, $transactionId, $amount);
    }
}
