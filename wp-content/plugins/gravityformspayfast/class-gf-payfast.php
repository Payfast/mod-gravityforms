<?php

add_action( 'wp', array( 'GFPayFast', 'maybe_thankyou_page' ), 5 );
GFForms::include_payment_addon_framework();
class GFPayFast extends GFPaymentAddOn
{
    protected $_version = GF_PAYFAST_VERSION;
    protected $_min_gravityforms_version = '1.9.3';
    protected $_slug = 'gravityformspayfast';
    protected $_path = 'gravityformspayfast/payfast.php';
    protected $_full_path = __FILE__;
    protected $_url = 'http://www.gravityforms.com';
    protected $_title = 'Gravity Forms PayFast Add-On';
    protected $_short_title = 'PayFast';
    protected $_supports_callbacks = true;
    private $production_url = 'https://www.payfast.co.za/eng/process/';
    private $sandbox_url = 'https://sandbox.payfast.co.za/eng/process/';
    // Members plugin integration
    protected $_capabilities = array( 'gravityforms_payfast', 'gravityforms_payfast_uninstall' );
    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_payfast';
    protected $_capabilities_form_settings = 'gravityforms_payfast';
    protected $_capabilities_uninstall = 'gravityforms_payfast_uninstall';
    // Automatic upgrade enabled
    protected $_enable_rg_autoupgrade = false;
    private static $_instance = null;
    public static function get_instance()
    {
        if ( self::$_instance == null )
        {
            self::$_instance = new GFPayFast();
        }
        return self::$_instance;
    }
    private function __clone()
    {
        /* do nothing */
    }
    public function init_frontend()
    {
        parent::init_frontend();
        add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
        add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
    }
    //----- SETTINGS PAGES ----------//
    public function plugin_settings_fields()
    {
        $description = '
			<p style="text-align: left;">' .
            __( 'You will need a PayFast account in order to use the PayFast Add-On.', 'gravityformspayfast' ) .
            '</p>
			<ul>
				<li>' . sprintf( __( 'Go to the %sPayFast Website%s in order to register an account.', 'gravityformspayfast' ), '<a href="https://www.payfast.page" target="_blank">', '</a>' ) . '</li>' .
            '<li>' . __( 'Check \'I understand\' and click on \'Update Settings\' in order to proceed.', 'gravityformspayfast' ) . '</li>' .
            '</ul>
				<br/>';
        return array(
            array(
                'title'       => '',
                'description' => $description,
                'fields'      => array(
                    array(
                        'name'    => 'gf_payfast_configured',
                        'label'   => __( 'I understand', 'gravityformspayfast' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'label' => __( '', 'gravityformspayfast' ), 'name' => 'gf_payfast_configured' ) )
                    ),
                    array(
                        'type' => 'save',
                        'messages' => array(
                            'success' => __( 'Settings have been updated.', 'gravityformspayfast' )
                        ),
                    ),
                ),
            ),
        );
    }
    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();
        if ( !rgar( $settings, 'gf_payfast_configured' ) )
        {
            return sprintf( __( 'To get started, configure your %sPayFast Settings%s!', 'gravityformspayfast' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
        }
        else
        {
            return parent::feed_list_no_item_message();
        }
    }
    public function feed_settings_fields()
    {
        $default_settings = parent::feed_settings_fields();
        //--add PayFast fields
        $fields = array(
            array(
                'name'     => 'payfastMerchantId',
                'label'    => __( 'PayFast Merchant ID ', 'gravityformspayfast' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'PayFast Merchant ID', 'gravityformspayfast' ) . '</h6>' . __( 'Enter your PayFast Merchant ID.', 'gravityformspayfast' )
            ),
            array(
                'name'     => 'payfastMerchantKey',
                'label'    => __( 'PayFast Merchant Key ', 'gravityformspayfast' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'PayFast Merchant Key', 'gravityformspayfast' ) . '</h6>' . __( 'Enter your PayFast Merchant Key.', 'gravityformspayfast' )
            ),
            array(
                'name'     => 'passphrase',
                'label'    => __( 'PayFast Passphrase ', 'gravityformspayfast' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'PayFast Passphrase', 'gravityformspayfast' ) . '</h6>' . __( 'Only enter a passphrase if it is set on your PayFast account.', 'gravityformspayfast' )
            ),
            array(
                'name'          => 'mode',
                'label'         => __( 'Mode', 'gravityformspayfast' ),
                'type'          => 'radio',
                'choices'       => array(
                    array( 'id' => 'gf_payfast_mode_production', 'label' => __( 'Production', 'gravityformspayfast' ), 'value' => 'production' ),
                    array( 'id' => 'gf_payfast_mode_test', 'label' => __( 'Test', 'gravityformspayfast' ), 'value' => 'test' ),
                ),
                'horizontal'    => true,
                'default_value' => 'production',
                'tooltip'       => '<h6>' . __( 'Mode', 'gravityformspayfast' ) . '</h6>' . __( 'Select Production to enable live transactions. Select Test for testing with the PayFast Sandbox.', 'gravityformspayfast' )
            ),
        );
        $default_settings = parent::add_field_after( 'feedName', $fields, $default_settings );
        //--------------------------------------------------------------------------------------
//        $message = array(
//            'name'                => 'message',
//            'label'               => __( 'PayFast does not currently support subscription billing', 'gravityformsstripe' ),
//            'style'               => 'width:40px;text-align:center;',
//            'type'                => 'checkbox',
//        );
//        $default_settings   = $this->add_field_after( 'trial', $message, $default_settings );
        $default_settings = $this->remove_field( 'recurringTimes', $default_settings );
        $default_settings = $this->remove_field( 'billingCycle', $default_settings );
//        $default_settings = $this->remove_field( 'recurringAmount', $default_settings );
//        $default_settings = $this->remove_field( 'setupFee', $default_settings );

        // Remove trial period
        $default_settings = $this->remove_field( 'trial', $default_settings );

        //--add donation to transaction type drop down
        $transaction_type = parent::get_field( 'transactionType', $default_settings );
        $choices          = $transaction_type['choices'];
        $add_donation     = false;
        foreach ( $choices as $choice )
        {
            //add donation option if it does not already exist
            if ( $choice['value'] == 'donation' )
            {
                $add_donation = false;
            }
        }
        if ( $add_donation )
        {
            //add donation transaction type
            $choices[] = array( 'label' => __( 'Donations', 'gravityformspayfast' ), 'value' => 'donation' );
        }
        $transaction_type['choices'] = $choices;
        $default_settings            = $this->replace_field( 'transactionType', $transaction_type, $default_settings );
        //-------------------------------------------------------------------------------------------------

        //--add Page Style, Cancel URL
        $fields = array(
            array(
                'name'     => 'cancelUrl',
                'label'    => __( 'Cancel URL', 'gravityformspayfast' ),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => '<h6>' . __( 'Cancel URL', 'gravityformspayfast' ) . '</h6>' . __( 'Enter the URL the user should be sent to should they cancel before completing their payment. It currently defaults to the PayFast website.', 'gravityformspayfast' )
            ),
            array(
                'name'    => 'notifications',
                'label'   => __( 'Notifications', 'gravityformspayfast' ),
                'type'    => 'notifications',
                'tooltip' => '<h6>' . __( 'Notifications', 'gravityformspayfast' ) . '</h6>' . __( "Enable this option if you would like to only send out this form's notifications after payment has been received. Leaving this option disabled will send notifications immediately after the form is submitted.", 'gravityformspayfast' )
            ),
        );

        //Add post fields if form has a post
        $form = $this->get_current_form();
        if ( GFCommon::has_post_field( $form['fields'] ) )
        {
            $post_settings = array(
                'name'    => 'post_checkboxes',
                'label'   => __( 'Posts', 'gravityformspayfast' ),
                'type'    => 'checkbox',
                'tooltip' => '<h6>' . __( 'Posts', 'gravityformspayfast' ) . '</h6>' . __( 'Enable this option if you would like to only create the post after payment has been received.', 'gravityformspayfast' ),
                'choices' => array(
                    array( 'label' => __( 'Create post only when payment is received.', 'gravityformspayfast' ), 'name' => 'delayPost' ),
                ),
            );
            if ( $this->get_setting( 'transactionType' ) == 'subscription' )
            {
                $post_settings['choices'][] = array(
                    'label'    => __( 'Change post status when subscription is canceled.', 'gravityformspayfast' ),
                    'name'     => 'change_post_status',
                    'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
                );
            }
            $fields[] = $post_settings;
        }
        //Adding custom settings for backwards compatibility with hook 'gform_payfast_add_option_group'
        $fields[] = array(
            'name'  => 'custom_options',
            'label' => '',
            'type'  => 'custom',
        );
        $default_settings = $this->add_field_after( 'billingInformation', $fields, $default_settings );
        //-----------------------------------------------------------------------------------------

        //--get billing info section and add customer first/last name
        $billing_info   = parent::get_field( 'billingInformation', $default_settings );
        $billing_fields = $billing_info['field_map'];
        $add_first_name = true;
        $add_last_name  = true;
        foreach ( $billing_fields as $mapping )
        {
            //add first/last name if it does not already exist in billing fields
            if ( $mapping['name'] == 'firstName' )
            {
                $add_first_name = false;
            }
            elseif ( $mapping['name'] == 'lastName' )
            {
                $add_last_name = false;
            }
        }
        if ( $add_last_name )
        {
            //add last name
            array_unshift( $billing_info['field_map'], array( 'name' => 'lastName', 'label' => __( 'Last Name', 'gravityformspayfast' ), 'required' => false ) );
        }
        if ( $add_first_name )
        {
            array_unshift( $billing_info['field_map'], array( 'name' => 'firstName', 'label' => __( 'First Name', 'gravityformspayfast' ), 'required' => false ) );
        }
        $default_settings = parent::replace_field( 'billingInformation', $billing_info, $default_settings );
        //----------------------------------------------------------------------------------------------------

        // hide default display of setup fee, not used by PayFast
        $default_settings = parent::remove_field( 'setupFee', $default_settings );
        //--add trial period
//            $freq     = array(
//                'name'    => 'frequency',
//                'label'   => __( 'Frequency', 'gravityformspayfast' ),
//                'type'    => 'frequency',
//                'hidden'  => ! $this->get_setting( 'frequency' ),
//                'tooltip' => '<h6>' . __( 'Trial Period', 'gravityformspayfast' ) . '</h6>' . __( 'Select the trial period length.', 'gravityformspayfast' )
//            );
//            $default_settings = parent::add_field_after( 'trial', $trial_period, $default_settings );
        //-----------------------------------------------------------------------------------------
        //--Add Try to bill again after failed attempt.
        $freq  = array(
            'name'       => 'frequency',
            'label'      => __( 'Frequency', 'gravityformspayfast' ),
            'type'       => 'select',
            //    'horizontal' => true,
            'required' => true,
            'choices'    => array( array( 'label' => __( 'Monthly', 'gravityformspayfast' ), 'name' => 'monthly', 'value' => '3' ),
                array( 'label' => __( 'Quarterly', 'gravityformspayfast' ), 'name' => 'quarterly', 'value' => '4' ),
                array( 'label' => __( 'Biannual', 'gravityformspayfast' ), 'name' => 'biannual', 'value' => '5' ),
                array( 'label' => __( 'Annual', 'gravityformspayfast' ), 'name' => 'annual', 'value' => '6' )
            ),
            'tooltip'    => '<h6>' . __( 'Frequency', 'gravityformspayfast' ) . '</h6>' . __( 'Frequency.', 'gravityformspayfast' )
        );
        $initial = array(
            'name' => 'initialAmount',
            'label' => esc_html__( 'Initial Amount', 'gravityforms' ),
            'type' => 'select',
            'choices' => $this->recurring_amount_choices(),
            'required' => true,
            'tooltip' => '<h6>' . esc_html__( 'Initial Amount', 'gravityforms' ) . '</h6>' . esc_html__( "Select which field determines the initial payment amount, or select 'Form Total' to use the total of all pricing fields as the recurring amount.", 'gravityforms' ),
        );
        $default_settings = parent::add_field_after( 'recurringAmount', $initial, $default_settings );
        
        $default_settings = parent::add_field_after( 'initialAmount', $freq, $default_settings );
        

        $cycles  = array(
            'name'       => 'cycles',
            'label'      => __( 'Cycles (set to 0 for infinite)', 'gravityformspayfast' ),
            'type'       => 'text',
            'horizontal' => true,
            'required' => true,
            'tooltip'    => '<h6>' . __( 'Cycles', 'gravityformspayfast' ) . '</h6>' . __( 'Cycles', 'gravityformspayfast' )
        );
        $default_settings = parent::add_field_after( 'frequency', $cycles, $default_settings );


        //-----------------------------------------------------------------------------------------------------
        return apply_filters( 'gform_payfast_feed_settings_fields', $default_settings, $form );
    }

	public function recurring_amount_choices() {
		$form                = $this->get_current_form();
		$recurring_choices   = $this->get_payment_choices( $form );
		$recurring_choices[] = array( 'label' => esc_html__( 'Form Total', 'gravityforms' ), 'value' => 'form_total' );

		return $recurring_choices;
	}

    public function supported_billing_intervals()
    {
        $billing_cycles = array(
            'monthly' => array( 'label' => esc_html__( 'month(s)', 'gravityformspayfast' ), 'min' => 1, 'max' => 24 ),
            'quarterly'   => array( 'label' => esc_html__( 'day(s)', 'gravityformspayfast' ), 'min' => 1, 'max' => 20 ),
            'biannual'  => array( 'label' => esc_html__( 'week(s)', 'gravityformspayfast' ), 'min' => 1, 'max' => 10 ),
            'annual'  => array( 'label' => esc_html__( 'year(s)', 'gravityformspayfast' ), 'min' => 1, 'max' => 5 )
        );

        return $billing_cycles;
    }

    public function field_map_title()
    {
        return __( 'PayFast Field', 'gravityformspayfast' );
    }
    public function settings_trial_period( $field, $echo = true )
    {
        //use the parent billing cycle function to make the drop down for the number and type
        $html = parent::settings_billing_cycle( $field );
        return $html;
    }
    public function set_trial_onchange( $field )
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
    public function settings_options( $field, $echo = true )
    {
        $checkboxes = array(
            'name'    => 'options_checkboxes',
            'type'    => 'checkboxes',
            'choices' => array(
                array( 'label' => __( 'Do not prompt buyer to include a shipping address.', 'gravityformspayfast' ), 'name' => 'disableShipping' ),
                array( 'label' => __( 'Do not prompt buyer to include a note with payment.', 'gravityformspayfast' ), 'name' => 'disableNote' ),
            )
        );
        $html = $this->settings_checkbox( $checkboxes, false );
        //--------------------------------------------------------
        //For backwards compatibility.
        ob_start();
        do_action( 'gform_payfast_action_fields', $this->get_current_feed(), $this->get_current_form() );
        $html .= ob_get_clean();
        //--------------------------------------------------------
        if ( $echo )
        {
            echo $html;
        }
        return $html;
    }
    public function settings_custom( $field, $echo = true )
    {
        ob_start();
        ?>
        <div id='gf_payfast_custom_settings'>
            <?php
            do_action( 'gform_payfast_add_option_group', $this->get_current_feed(), $this->get_current_form() );
            ?>
        </div>

        <script type='text/javascript'>
            jQuery(document).ready(function () {
                jQuery('#gf_payfast_custom_settings label.left_header').css('margin-left', '-200px');
            });
        </script>

        <?php
        $html = ob_get_clean();
        if ( $echo ) {
            echo $html;
        }
        return $html;
    }
    public function settings_notifications( $field, $echo = true )
    {
        $checkboxes = array(
            'name'    => 'delay_notification',
            'type'    => 'checkboxes',
            'onclick' => 'ToggleNotifications();',
            'choices' => array(
                array(
                    'label' => __( 'Send notifications only when payment is received.', 'gravityformspayfast' ),
                    'name'  => 'delayNotification',
                ),
            )
        );

        $html = $this->settings_checkbox( $checkboxes, false );
        $html .= $this->settings_hidden( array( 'name' => 'selectedNotifications', 'id' => 'selectedNotifications' ), false );
        $form = $this->get_current_form();
        $has_delayed_notifications = $this->get_setting( 'delayNotification' );
        ob_start();
        ?>
        <ul id="gf_payfast_notification_container" style="padding-left:20px; margin-top:10px; <?php echo $has_delayed_notifications ? '' : 'display:none;' ?>">
            <?php
            if ( ! empty( $form ) && is_array( $form['notifications'] ) )
            {
                $selected_notifications = $this->get_setting( 'selectedNotifications' );
                if ( ! is_array( $selected_notifications ) )
                {
                    $selected_notifications = array();
                }
                //$selected_notifications = empty($selected_notifications) ? array() : json_decode($selected_notifications);
                $notifications = GFCommon::get_notifications( 'form_submission', $form );
                foreach ( $notifications as $notification )
                {
                    ?>
                    <li class="gf_payfast_notification">
                        <input type="checkbox" class="notification_checkbox" value="<?php echo $notification['id'] ?>" onclick="SaveNotifications();" <?php checked( true, in_array( $notification['id'], $selected_notifications ) ) ?> />
                        <label class="inline" for="gf_payfast_selected_notifications"><?php echo $notification['name']; ?></label>
                    </li>
                    <?php
                }
            }
            ?>
        </ul>
        <script type='text/javascript'>
            function SaveNotifications()
            {
                var notifications = [];
                jQuery('.notification_checkbox').each(function ()
                {
                    if (jQuery(this).is(':checked'))
                    {
                        notifications.push(jQuery(this).val());
                    }
                });
                jQuery('#selectedNotifications').val(jQuery.toJSON(notifications));
            }
            function ToggleNotifications() {
                var container = jQuery('#gf_payfast_notification_container');
                var isChecked = jQuery('#delaynotification').is(':checked');
                if (isChecked)
                {
                    container.slideDown();
                    jQuery('.gf_payfast_notification input').prop('checked', true);
                }
                else
                {
                    container.slideUp();
                    jQuery('.gf_payfast_notification input').prop('checked', false);
                }
                SaveNotifications();
            }
        </script>
        <?php
        $html .= ob_get_clean();
        if ( $echo )
        {
            echo $html;
        }
        return $html;
    }

    public function checkbox_input_change_post_status( $choice, $attributes, $value, $tooltip )
    {
        $markup = $this->checkbox_input( $choice, $attributes, $value, $tooltip );
        $dropdown_field = array(
            'name'     => 'update_post_action',
            'choices'  => array(
                array( 'label' => '' ),
                array( 'label' => __( 'Mark Post as Draft', 'gravityformspayfast' ), 'value' => 'draft' ),
                array( 'label' => __( 'Delete Post', 'gravityformspayfast' ), 'value' => 'delete' ),
            ),
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        );
        $markup .= '&nbsp;&nbsp;' . $this->settings_select( $dropdown_field, false );
        return $markup;
    }

    public function option_choices()
    {
        return false;
        $option_choices = array(
            array( 'label' => __( 'Do not prompt buyer to include a shipping address.', 'gravityformspayfast' ), 'name' => 'disableShipping', 'value' => '' ),
            array( 'label' => __( 'Do not prompt buyer to include a note with payment.', 'gravityformspayfast' ), 'name' => 'disableNote', 'value' => '' ),
        );
        return $option_choices;
    }

    public function save_feed_settings( $feed_id, $form_id, $settings )
    {
        //--------------------------------------------------------
        //For backwards compatibility
        $feed = $this->get_feed( $feed_id );
        //Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];
        if ( isset( $settings['recurringAmount'] ) )
        {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }
        $feed['meta'] = $settings;
        $feed = apply_filters( 'gform_payfast_save_config', $feed );

        //call hook to validate custom settings/meta added using gform_payfast_action_fields or gform_payfast_add_option_group action hooks
        $is_validation_error = apply_filters( 'gform_payfast_config_validation', false, $feed );
        if ( $is_validation_error )
        {
            //fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings( $feed_id, $form_id, $settings );
    }

    //------ SENDING TO PAYFAST -----------//
    public function redirect_url( $feed, $submission_data, $form, $entry )
    {
        require_once( 'payfast_common.inc' );

        //Don't process redirect url if request is a Payfast return
        if (!rgempty('gf_payfast_return', $_GET)) {
            return false;
        }

        //updating lead's payment_status to Processing
        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Processing');

        //Getting Url (Production or Sandbox)
        $url = $feed['meta']['mode'] == 'production' ? $this->production_url : $this->sandbox_url;

        $invoice_id = apply_filters('gform_payfast_invoice', '', $form, $entry);
        $invoice = empty($invoice_id) ? '' : "&invoice={$invoice_id}";

        //Current Currency
        $currency = GFCommon::get_currency();

        //Customer fields
        $customer_fields = $this->customer_query_string($feed, $entry);

        //Continue link text
        $continue_text = !empty($feed['meta']['continueText']) ? '&cbt=' . urlencode($feed['meta']['continueText']) : '&cbt=' . __('Click here to continue', 'gravityformspayfast');

        //Set return mode to 2 (Payfast will post info back to page). rm=1 seems to create lots of problems with the redirect back to the site. Defaulting it to 2.
        $return_mode = '2';

        $return_url = $this->return_url($form['id'], $entry['id']) . "&rm={$return_mode}";

        //Cancel URL
        $cancel_url = $feed['meta']['cancelUrl'];

        //Don't display note section
        $disable_note = !empty($feed['meta']['disableNote']) ? '&no_note=1' : '';

        //Don't display shipping section
        $disable_shipping = !empty($feed['meta']['disableShipping']) ? '&no_shipping=1' : '';

        //URL that will listen to notifications from PayFast
        $itn_url = get_bloginfo('url') . '/?page=gf_payfast_itn';
        
        if ( $feed['meta']['mode'] == 'test' && empty( $feed['meta']['payfastMerchantId'] ) && empty( $feed['meta']['payfastMerchantKey'] ) )
        {
            $merchant_id = '10004002';
            $merchant_key = 'q1cd2rdny4a53';
            $passPhrase = 'payfast';
        }
        else
        {
            $merchant_id = $feed['meta']['payfastMerchantId'];
            $merchant_key = $feed['meta']['payfastMerchantKey'];
            $passPhrase = $feed['meta']['passphrase'];
        }

        $custom_field = $entry['id'] . '|' . wp_hash($entry['id']);
        $pfNotifications = rgars( $feed, 'meta/selectedNotifications' );

        $varArray = array(
            'merchant_id' => $merchant_id,
            'merchant_key' => $merchant_key,
            'return_url' => $return_url
        );

        if ( !empty( $cancel_url ) )
        {
            $varArray['cancel_url'] = $cancel_url;
        }

        $varArray['notify_url'] = $itn_url;
        $varArray['email_address'] = $this->customer_email($feed, $entry);
        $varArray['m_payment_id'] = $entry['id'];

        if ( $feed['meta']['transactionType'] == 'subscription' )
        {
            if ( $feed['meta']['initialAmount'] == 'form_total' )
            {
                $varArray['amount'] = GFCommon::get_order_total( $form, $entry ) / 2;
            }
            else
            {
                $varArray['amount'] = substr( $entry['' . $feed['meta']['initialAmount'] . '.2'], 1 );
            }
        }
        else
        {
            $varArray['amount'] = GFCommon::get_order_total( $form, $entry );
        }        
        
        $varArray['item_name'] = $form['title'];

        if ( $feed['meta']['mode'] != 'production' )
        {
            $varArray['custom_int1'] = '1';
        }

        $varArray['custom_int2'] = $form['id'];

        $varArray['custom_str1'] = 'PF_GRAVITYFORMS_2.3_'.constant( 'PF_MODULE_VER' );;

        if ( !is_null( $pfNotifications[0] ) )
        {
            $varArray['custom_str2'] = $pfNotifications[0];
        }

        if ( !is_null( $pfNotifications[1] ) )
        {
            $varArray['custom_str3'] = $pfNotifications[1];
        }

        if ( rgars( $feed, 'meta/delayPost' ) )
        {
            $varArray['custom_str4'] = 'delayPost';
        }

        // Include variables if subscription
        if ( $feed['meta']['transactionType'] == 'subscription' )
        {
            $varArray['custom_str4'] = gmdate( 'Y-m-d' );
            $varArray['subscription_type'] = 1;
            $varArray['billing_date'] = gmdate( 'Y-m-d' );

            if ( $feed['meta']['recurring_amount_field'] == 'form_total' )
            {
                $varArray['recurring_amount'] = GFCommon::get_order_total( $form, $entry ) / 2;
            }
            else
            {
                $varArray['recurring_amount'] = substr( $entry['' . $feed['meta']['recurring_amount_field'] . '.2'], 1 );
            }

            $varArray['frequency'] = rgar($feed['meta'], 'frequency');
            $varArray['cycles'] = rgar($feed['meta'], 'cycles');
        }


        $pfOutput = '';
        // Create output string
        foreach ( $varArray as $key => $val )
            $pfOutput .= $key . '=' . urlencode( trim( $val ) ) . '&';

        if ( empty( $passPhrase ) )
        {
            $pfOutput = substr($pfOutput, 0, -1);
        }
        else
        {
            $pfOutput = $pfOutput . "passphrase=" . urlencode($passPhrase);
        }

        $sig = md5( $pfOutput );

        //    $url .= "?notify_url={$itn_url}&charset=UTF-8&currency_code={$currency}&business={$business_email}&custom={$custom_field}{$invoice}{$customer_fields}{$page_style}{$continue_text}{$cancel_url}{$disable_note}{$disable_shipping}{$return_url}";
        $query_string = '';
        switch ( $feed['meta']['transactionType'] )
        {
            case 'product' :
                //build query string using $submission_data
                $query_string = $this->get_product_query_string( $submission_data, $entry['id'] );
                break;
            case 'donation' :
                $query_string = $this->get_donation_query_string( $submission_data, $entry['id'] );
                break;
//                case 'subscription' :
//                    $query_string = $this->get_subscription_query_string( $feed, $submission_data, $entry['id'] );
//                    break;
        }
        $secureString = '?';
        foreach( $varArray as $k => $v )
        {
            if( !is_null ( $v ) )
                $secureString .= $k.'='.urlencode( trim( $v ) ).'&';
        }
        $secureString = substr( $secureString, 0, -1 );
        $query_string = apply_filters( "gform_payfast_query_{$form['id']}", apply_filters( "gform_payfast_query", $secureString, $form, $entry ), $form, $entry);

        $secureSig = md5( $query_string );
        $secureString .= '&signature='.$secureSig;
        //$url .= $query_string;

        //// $query_string = apply_filters( "gform_payfast_query_{$form['id']}", apply_filters( 'gform_payfast_query', $query_string, $form, $entry, $feed ), $form, $entry, $feed );

        if ( ! $query_string )
        {
            $this->log_debug( __METHOD__ . '(): NOT sending to PayFast: The price is either zero or the gform_payfast_query filter was used to remove the querystring that is sent to PayFast.' );
            return '';
        }
        $url .= $query_string;
        //    $url = apply_filters( "gform_payfast_request_{$form['id']}", apply_filters( 'gform_payfast_request', $url, $form, $entry, $feed ), $form, $entry, $feed );

        //add the bn code (build notation code)
        $url .= '&signature=' . $sig . '&user_agent=Gravity Forms 1.9';

        $this->log_debug( __METHOD__ . "(): Sending to PayFast: {$url}" );

        return $url;
    }

    public function get_product_query_string( $submission_data, $entry_id )
    {
        if ( empty( $submission_data ) )
        {
            return false;
        }
        $query_string   = '';
        $payment_amount = rgar( $submission_data, 'payment_amount' );
        $setup_fee      = rgar( $submission_data, 'setup_fee' );
        $trial_amount   = rgar( $submission_data, 'trial' );
        $line_items     = rgar( $submission_data, 'line_items' );
        $discounts      = rgar( $submission_data, 'discounts' );
        $product_index = 1;
        $shipping      = '';
        $discount_amt  = 0;
        $cmd           = '_cart';
        $extra_qs      = '&upload=1';
        //work on products
        if ( is_array( $line_items ) )
        {
            foreach ( $line_items as $item )
            {
                $product_name = urlencode( $item['name'] );
                $quantity     = $item['quantity'];
                $unit_price   = $item['unit_price'];
                $options      = rgar( $item, 'options' );
                $product_id   = $item['id'];
                $is_shipping  = rgar( $item, 'is_shipping' );
                if ( $is_shipping )
                {
                    //populate shipping info
                    $shipping .= ! empty( $unit_price ) ? "&shipping_1={$unit_price}" : '';
                }
                else
                {
                    //add product info to querystring
                    $query_string .= "&item_name_{$product_index}={$product_name}&amount_{$product_index}={$unit_price}&quantity_{$product_index}={$quantity}";
                }
                //add options
                if ( !empty( $options ) )
                {
                    if ( is_array( $options ) )
                    {
                        $option_index = 1;
                        foreach ( $options as $option )
                        {
                            $option_label = urlencode( $option['field_label'] );
                            $option_name  = urlencode( $option['option_name'] );
                            $query_string .= "&on{$option_index}_{$product_index}={$option_label}&os{$option_index}_{$product_index}={$option_name}";
                            $option_index ++;
                        }
                    }
                }
                $product_index ++;
            }
        }
        //look for discounts
        if ( is_array( $discounts ) )
        {
            foreach ( $discounts as $discount )
            {
                $discount_full = abs( $discount['unit_price'] ) * $discount['quantity'];
                $discount_amt += $discount_full;
            }
            if ( $discount_amt > 0 )
            {
                $query_string .= "&discount_amount_cart={$discount_amt}";
            }
        }
        $query_string .= "{$shipping}&cmd={$cmd}{$extra_qs}";
        //save payment amount to lead meta
        gform_update_meta( $entry_id, 'payment_amount', $payment_amount );
        return $payment_amount > 0 ? $query_string : false;
    }
    public function get_donation_query_string( $submission_data, $entry_id )
    {
        if ( empty( $submission_data ) )
        {
            return false;
        }
        $query_string   = '';
        $payment_amount = rgar( $submission_data, 'payment_amount' );
        $line_items     = rgar( $submission_data, 'line_items' );
        $purpose        = '';
        $cmd            = '_donations';
        //work on products
        if ( is_array( $line_items ) )
        {
            foreach ( $line_items as $item )
            {
                $product_name    = $item['name'];
                $quantity        = $item['quantity'];
                $quantity_label  = $quantity > 1 ? $quantity . ' ' : '';
                $options         = rgar( $item, 'options' );
                $is_shipping     = rgar( $item, 'is_shipping' );
                $product_options = '';
                if ( ! $is_shipping )
                {
                    //add options
                    if ( ! empty( $options ) )
                    {
                        if ( is_array( $options ) )
                        {
                            $product_options = ' (';
                            foreach ( $options as $option )
                            {
                                $product_options .= $option['option_name'] . ', ';
                            }
                            $product_options = substr( $product_options, 0, strlen( $product_options ) - 2 ) . ')';
                        }
                    }
                    $purpose .= $quantity_label . $product_name . $product_options . ', ';
                }
            }
        }
        if ( !empty( $purpose ) )
        {
            $purpose = substr( $purpose, 0, strlen( $purpose ) - 2 );
        }
        $purpose = urlencode( $purpose );
        //truncating to maximum length allowed by PayFast
        if ( strlen( $purpose ) > 127 )
        {
            $purpose = substr( $purpose, 0, 124 ) . '...';
        }
        $query_string = "&amount={$payment_amount}&item_name={$purpose}&cmd={$cmd}";
        //save payment amount to lead meta
        gform_update_meta( $entry_id, 'payment_amount', $payment_amount );
        return $payment_amount > 0 ? $query_string : false;
    }

    //customer email function
    public function customer_email ( $feed, $lead )
    {
        $cutomer_email = '';
        foreach ( $this->get_customer_fields() as $field )
        {
            $field_id = $feed['meta'][ $field['meta_name'] ];
            $value = rgar( $lead, $field_id );
            if ( !empty( $value ) && $field['name'] == 'email')
            {
                $cutomer_email = $value;
            }
        }

        return $cutomer_email;
    }

    public function customer_query_string( $feed, $lead )
    {
        $fields = '';
        foreach ( $this->get_customer_fields() as $field )
        {
            $field_id = $feed['meta'][ $field['meta_name'] ];
            $value    = rgar( $lead, $field_id );
            if ( $field['name'] == 'country' )
            {
                $value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $value ) : GFCommon::get_country_code( $value );
            }
            elseif ( $field['name'] == 'state' )
            {
                $value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_us_state_code( $value ) : GFCommon::get_us_state_code( $value );
            }
            if ( !empty( $value ) )
            {
                $fields .= "&{$field['name']}=" . urlencode( $value );
            }
        }
        return $fields;
    }

    public function return_url( $form_id, $lead_id )
    {
        $pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';
        $server_port = apply_filters( 'gform_payfast_return_url_port', $_SERVER['SERVER_PORT'] );
        if ( $server_port != '80' )
        {
            $pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
        }
        else
        {
            $pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
        }
        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= '&hash=' . wp_hash( $ids_query );
        return add_query_arg( 'gf_payfast_return', base64_encode( $ids_query ), $pageURL );
    }

    public static function maybe_thankyou_page()
    {
        $instance = self::get_instance();
        if ( ! $instance->is_gravityforms_supported() )
        {
            return;
        }
        if ( $str = rgget( 'gf_payfast_return' ) )
        {
            $str = base64_decode( $str );
            parse_str( $str, $query );
            if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] )
            {
                list( $form_id, $lead_id ) = explode( '|', $query['ids'] );
                $form = GFAPI::get_form( $form_id );
                $lead = GFAPI::get_entry( $lead_id );
                if ( ! class_exists( 'GFFormDisplay' ) )
                {
                    require_once( GFCommon::get_base_path() . '/form_display.php' );
                }
                $confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );
                if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) )
                {
                    header( "Location: {$confirmation['redirect']}" );
                    exit;
                }
                GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead );
            }
        }
    }
    public function get_customer_fields()
    {
        return array(
            array( 'name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName' ),
            array( 'name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName' ),
            array( 'name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email' ),
            array( 'name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address' ),
            array( 'name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2' ),
            array( 'name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city' ),
            array( 'name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state' ),
            array( 'name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip' ),
            array( 'name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country' ),
        );
    }
    public function convert_interval( $interval, $to_type )
    {
        //convert single character into long text for new feed settings or convert long text into single character for sending to payfast
        //$to_type: text (change character to long text), OR char (change long text to character)
        if ( empty( $interval ) )
        {
            return '';
        }

        $new_interval = '';
        if ( $to_type == 'text' )
        {
            //convert single char to text
            switch ( strtoupper( $interval ) )
            {
                case 'D' :
                    $new_interval = 'day';
                    break;
                case 'W' :
                    $new_interval = 'week';
                    break;
                case 'M' :
                    $new_interval = 'month';
                    break;
                case 'Y' :
                    $new_interval = 'year';
                    break;
                default :
                    $new_interval = $interval;
                    break;
            }
        }
        else
        {
            //convert text to single char
            switch ( strtolower( $interval ) )
            {
                case 'day' :
                    $new_interval = 'D';
                    break;
                case 'week' :
                    $new_interval = 'W';
                    break;
                case 'month' :
                    $new_interval = 'M';
                    break;
                case 'year' :
                    $new_interval = 'Y';
                    break;
                default :
                    $new_interval = $interval;
                    break;
            }
        }
        return $new_interval;
    }
    public function delay_post( $is_disabled, $form, $entry )
    {
        $feed = $this->get_payment_feed( $entry );
        $submission_data = $this->get_submission_data( $feed, $form, $entry );
        if ( ! $feed || empty( $submission_data['payment_amount'] ) )
        {
            return $is_disabled;
        }
        return ! rgempty( 'delayPost', $feed['meta'] );
    }

    public function delay_notification( $is_disabled, $notification, $form, $entry )
    {
        $feed = $this->get_payment_feed( $entry );
        $submission_data = $this->get_submission_data( $feed, $form, $entry );
        if ( !$feed || empty( $submission_data['payment_amount'] ) )
        {
            return $is_disabled;
        }
        $selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();
        return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
    }

    //------- PROCESSING PAYFAST ITN (Callback) -----------//
    public function get_payment_feed( $entry, $form = false )
    {
        $feed = parent::get_payment_feed( $entry, $form );
        if ( empty( $feed ) && ! empty($entry['id']) )
        {
            //looking for feed created by legacy versions
            $feed = $this->get_payfast_feed_by_entry( $entry['id'] );
        }
        $feed = apply_filters( 'gform_payfast_get_payment_feed', $feed, $entry, $form );
        return $feed;
    }

    public function get_payfast_feed_by_entry( $entry_id )
    {
        $feed_id = gform_get_meta( $entry_id, 'payfast_feed_id' );
        $feed = $this->get_feed( $feed_id );
        return !empty( $feed ) ? $feed : false;
    }

    public function process_itn(/*$entry, $status, $transaction_type, $transaction_id, $amount*/)
    {
        global $current_user;
        $user_id = 0;
        $user_name = "PayFast ITN";
        if( $current_user && $user_data = get_userdata( $current_user->ID ) )
        {
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        require_once( 'payfast_common.inc' );

        $status = $pfData['payment_status'];
        $transaction_id = $pfData['pf_payment_id'];
        $amount = $pfData['amount_gross'];
        $entry['id'] = $pfData['m_payment_id'];
        //    $this->log_debug( __METHOD__ . "(): Payment status: {$status} - Transaction Type: {$transaction_type} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Subscriber ID: {$subscriber_id} - Amount: {$amount} - Pending reason: {$pending_reason} - Reason: {$reason}" );
        $action = array();

        $feed = $this->get_feeds( $_POST['custom_int2'] );

        //handles products and donation
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
        $entry = GFAPI::get_entry( $pfData['m_payment_id'] );
        //Ignore orphan ITN messages (ones without an entry)
        if( !$entry )
        {
            self::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
            return;
        }

        self::log_debug( "Entry has been found." . print_r( $entry, true ) );

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

            $passPhrase = $feed[0]['meta']['passphrase'];
            $pfPassPhrase = empty( $passPhrase ) ? null : $passPhrase;

            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString, $pfPassPhrase ) )
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

            $pfHost = 'www.payfast.co.za';

            if ( $pfData['custom_int1'] == 1 )
            {
                $pfHost = 'sandbox.payfast.co.za';
            }

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
        //// Check status and update order
        if( !$pfError && !$pfDone )
        {
            pflog('Check status and update order');
            $sendAdminMail = false;
            $sendUserMail = false;
            $entry = GFAPI::get_entry( $pfData['m_payment_id'] );
            $form = GFFormsModel::get_form_meta( $pfData['custom_int2'] );
            $notifications = GFCommon::get_notifications( 'form_submission', $form );

            if ( !empty( $pfData['custom_str1'] ) )
            {
                $sendAdminMail = true;
                $notificationAdminId = array( $pfData['custom_str1'] );
            }

            if ( !empty( $pfData['custom_str2'] ) )
            {
                $sendUserMail = true;
                $notificationClientId = array( $pfData['custom_str2'] );
            }

            //    self::log_debug('Check status and update order');
            switch ($pfData['payment_status'])
            {
                case 'COMPLETE' :
                    pflog( '- Complete' );

                    //If delayed post set it now
                    if ( $pfData['custom_str3'] == 'delayPost' )
                    {
                        $entry['post_id'] = GFFormsModel::create_post( $form, $entry );
                    }
                    
                    //creates transaction
                    if ( empty( $pfData['token'] ) || strtotime( $pfData['custom_str4'] ) <= strtotime( gmdate( 'Y-m-d' ). '+ 2 days' ) )
                    {
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'payment_status', 'Paid');
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'transaction_id', $pfData['pf_payment_id']);
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'payment_amount', $pfData['amount_gross']);
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'is_fulfilled', '1');
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'payment_method', 'PayFast');
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'payment_date', gmdate('y-m-d H:i:s'));
                    }

                    // Update single entry
                    if ( empty( $pfData['token'] ) )
                    {
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'transaction_type', '1');
                        GFPaymentAddOn::insert_transaction($pfData['m_payment_id'], 'complete_payment', $pfData['pf_payment_id'], $pfData['amount_gross']);
                    }

                    if ( !empty( $pfData['token'] ) && strtotime( $pfData['custom_str4'] ) <= strtotime( gmdate( 'Y-m-d' ). '+ 2 days' ) )
                    {
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'transaction_type', '2');
                        GFPaymentAddOn::insert_transaction($pfData['m_payment_id'], 'create_subscription', $pfData['pf_payment_id'], $pfData['amount_gross']);
                    }
                    if ( !empty( $pfData['token'] ) && strtotime( gmdate( 'Y-m-d' ) ) > strtotime( $pfData['custom_str4'] . '+ 2 days' ) )
                    {
                        GFAPI::update_entry_property($pfData['m_payment_id'], 'transaction_type', '1');
                        GFPaymentAddOn::insert_transaction($pfData['m_payment_id'], 'complete_payment', $pfData['pf_payment_id'], $pfData['amount_gross']);
                    }


                    if ( !empty($pfData['token'] ) )
                    {
                        //GFPaymentAddOn::insert_transaction( $pfData['m_subscription_id'], 'add_subscription_payment', $pfData['pf_payment_id'], $pfData['amount_gross'] );
                        $action = array( 'amount' => $pfData['amount_gross'], 'subscription_id' => $pfData['m_payment_id'] );
                        //GFPaymentAddOn::add_subscription_payment( $entry, $action );
                        GFPaymentAddOn::insert_transaction( $pfData['m_payment_id'], 'payment'/*$action['transaction_type']*/, $pfData['pf_payment_id']/*$transaction_id*/, $pfData['amount_gross']/*$action['amount']*/ );
                        $action['note']   = sprintf( esc_html__( 'Subscription has been paid. Amount: R%s. Subscription Id: %s', 'gravityforms' ), $pfData['amount_gross'], $pfData['m_payment_id'] );
                        GFFormsModel::add_note( $pfData['m_payment_id']/*$entry_id*/, 0, 'PayFast', $action['note'], 'success' );
                    }

                    if ( $sendAdminMail )
                    {
                        pflog('sendadminmail');
                        GFCommon::send_notifications( $notificationAdminId, $form, $entry, true, 'form_submission' );
                    }
                    if ( $sendUserMail )
                    {
                        pflog('sendusermail');
                        GFCommon::send_notifications( $notificationClientId, $form, $entry, true, 'form_submission' );
                    }

                    break;

                case 'CANCELLED':
                    pflog( 'Subscription Cancelled with entry ID: ' . $pfData['m_payment_id'] );

                    $note = sprintf( esc_html__( 'Subscription Cancelled. Entry Id: %s', 'gravityforms' ), $pfData['m_payment_id'] );
                    GFAPI::update_entry_property( $pfData['m_payment_id'], 'payment_status', 'Cancelled' );
                    GFFormsModel::add_note( $pfData['m_payment_id'], 0, 'PayFast', $note, 'Cancelled' );

                    break;

                case 'processed' :
                case 'pending' :
                    // wait for complete
                    break;
                case 'denied' :
                case 'failed' :
                    //wait for complete
                    break;
            }
        }

        if ( $pfError )
        {
            pflog( 'Error occured: ' . $pfErrMsg );
        }
    }
    public function get_entry( $custom_field )
    {
        //Valid ITN requests must have a custom field
        if ( empty( $custom_field ) )
        {
            $this->log_error( __METHOD__ . '(): ITN request does not have a custom field, so it was not created by Gravity Forms. Aborting.' );
            return false;
        }
        //Getting entry associated with this ITN message (entry id is sent in the 'custom' field)
        list( $entry_id, $hash ) = explode( '|', $custom_field );
        $hash_matches = wp_hash( $entry_id ) == $hash;
        //allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters( 'gform_payfast_hash_matches', $hash_matches, $entry_id, $hash, $custom_field );
        //Validates that Entry Id wasn't tampered with
        if ( ! rgpost( 'test_itn' ) && ! $hash_matches )
        {
            $this->log_error( __METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: {$custom_field}. Aborting." );
            return false;
        }
        $this->log_debug( __METHOD__ . "(): ITN message has a valid custom field: {$custom_field}" );
        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) )
        {
            $this->log_error( __METHOD__ . '(): ' . $entry->get_error_message() );
            return false;
        }
        return $entry;
    }
    public function modify_post( $post_id, $action )
    {
        $result = false;
        if ( ! $post_id )
        {
            return $result;
        }
        switch ( $action )
        {
            case 'draft':
                $post = get_post( $post_id );
                $post->post_status = 'draft';
                $result = wp_update_post( $post );
                $this->log_debug( __METHOD__ . "(): Set post (#{$post_id}) status to \"draft\"." );
                break;
            case 'delete':
                $result = wp_delete_post( $post_id );
                $this->log_debug( __METHOD__ . "(): Deleted post (#{$post_id})." );
                break;
        }
        return $result;
    }
    public function is_callback_valid()
    {
        if ( rgget( 'page' ) != 'gf_payfast_itn' )
        {
            return false;
        }
        $this->process_itn();
        return true;
    }
    private function get_pending_reason( $code )
    {
        switch ( strtolower( $code ) )
        {
            case 'address':
                return __( 'The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences is set to allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.', 'gravityformspayfast' );
            default:
                return empty( $code ) ? __( 'Reason has not been specified. For more information, contact PayFast Customer Service.', 'gravityformspayfast' ) : $code;
        }
    }
    //------- AJAX FUNCTIONS ------------------//
    public function init_ajax()
    {
        parent::init_ajax();
        add_action( 'wp_ajax_gf_dismiss_payfast_menu', array( $this, 'ajax_dismiss_menu' ) );
    }
    //------- ADMIN FUNCTIONS/HOOKS -----------//
    public function init_admin()
    {
        parent::init_admin();
        //add actions to allow the payment status to be modified
        add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );
        if ( version_compare( GFCommon::$version, '1.8.17.4', '<' ) )
        {
            //using legacy hook
            add_action( 'gform_entry_info', array( $this, 'admin_edit_payment_status_details' ), 4, 2 );
        }
        else
        {
            add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );
            add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 3, 3 );
            add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 3, 3 );
        }
        add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 4, 2 );
        add_filter( 'gform_addon_navigation', array( $this, 'maybe_create_menu' ) );
    }
    public function maybe_create_menu( $menus )
    {
        $current_user = wp_get_current_user();
        $dismiss_payfast_menu = get_metadata( 'user', $current_user->ID, 'dismiss_payfast_menu', true );
        if ( $dismiss_payfast_menu != '1' )
        {
            $menus[] = array( 'name' => $this->_slug, 'label' => $this->get_short_title(), 'callback' => array( $this, 'temporary_plugin_page' ), 'permission' => $this->_capabilities_form_settings );
        }
        return $menus;
    }
    public function ajax_dismiss_menu()
    {
        $current_user = wp_get_current_user();
        update_metadata( 'user', $current_user->ID, 'dismiss_payfast_menu', '1' );
    }
    public function temporary_plugin_page()
    {
        $current_user = wp_get_current_user();
        ?>
        <script type="text/javascript">
            function dismissMenu(){
                jQuery('#gf_spinner').show();
                jQuery.post(ajaxurl, {
                        action : "gf_dismiss_payfast_menu"
                    },
                    function (response) {
                        document.location.href='?page=gf_edit_forms';
                        jQuery('#gf_spinner').hide();
                    }
                );
            }
        </script>

        <div class="wrap about-wrap">
            <h1><?php _e( 'PayFast Add-On v1.1', 'gravityformspayfast' ) ?></h1>
            <div class="about-text"><?php _e( 'Thank you for updating! The new version of the Gravity Forms PayFast Add-On makes changes to how you manage your PayFast integration.', 'gravityformspayfast' ) ?></div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php _e( 'Manage PayFast Contextually', 'gravityformspayfast' ) ?></h3>
                        <p><?php _e( 'PayFast Feeds are now accessed via the PayFast sub-menu within the Form Settings for the Form you would like to integrate PayFast with.', 'gravityformspayfast' ) ?></p>
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_payfast_menu" value="1" onclick="dismissMenu();"> <label><?php _e( 'I understand, dismiss this message!', 'gravityformspayfast' ) ?></label>
                    <img id="gf_spinner" src="<?php echo GFCommon::get_base_url() . '/images/spinner.gif'?>" alt="<?php _e( 'Please wait...', 'gravityformspayfast' ) ?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
    }
    public function admin_edit_payment_status( $payment_status, $form, $lead )
    {
        //allow the payment status to be edited when for payfast, not set to Approved/Paid, and not a subscription
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) <> 'edit' || $payment_status == 'Approved' || $payment_status == 'Paid' || rgar( $lead, 'transaction_type' ) == 2 )
        {
            return $payment_status;
        }
        //create drop down for payment status
        $payment_string = gform_tooltip( 'payfast_edit_payment_status', '', true );
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';
        return $payment_string;
    }
    public function admin_edit_payment_date( $payment_date, $form, $lead )
    {
        //allow the payment date to be edited
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) <> 'edit' )
        {
            return $payment_date;
        }
        $payment_date = $lead['payment_date'];
        if ( empty( $payment_date ) )
        {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        }
        $input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';
        return $input;
    }
    public function admin_edit_payment_transaction_id( $transaction_id, $form, $lead )
    {
        //allow the transaction ID to be edited
        if ( !$this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) <> 'edit' )
        {
            return $transaction_id;
        }
        $input = '<input type="text" id="payfast_transaction_id" name="payfast_transaction_id" value="' . $transaction_id . '">';
        return $input;
    }
    public function admin_edit_payment_amount( $payment_amount, $form, $lead )
    {
        //allow the payment amount to be edited
        if ( ! $this->is_payment_gateway( $lead['id'] ) || strtolower( rgpost( 'save' ) ) <> 'edit' )
        {
            return $payment_amount;
        }
        if ( empty( $payment_amount ) )
        {
            $payment_amount = GFCommon::get_order_total( $form, $lead );
        }
        $input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';
        return $input;
    }
    public function admin_edit_payment_status_details( $form_id, $lead )
    {
        $form_action = strtolower( rgpost( 'save' ) );
        if ( ! $this->is_payment_gateway( $lead['id'] ) || $form_action <> 'edit' )
        {
            return;
        }
        //get data from entry to pre-populate fields
        $payment_amount = rgar( $lead, 'payment_amount' );
        if ( empty( $payment_amount ) )
        {
            $form           = GFFormsModel::get_form_meta( $form_id );
            $payment_amount = GFCommon::get_order_total( $form, $lead );
        }
        $transaction_id = rgar( $lead, 'transaction_id' );
        $payment_date   = rgar( $lead, 'payment_date' );
        if ( empty( $payment_date ) )
        {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        }
        //display edit fields
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <tr>
                    <td colspan="2"><strong>Payment Information</strong></td>
                </tr>

                <tr>
                    <td>Date:<?php gform_tooltip( 'payfast_edit_payment_date' ) ?></td>
                    <td>
                        <input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date ?>">
                    </td>
                </tr>
                <tr>
                    <td>Amount:<?php gform_tooltip( 'payfast_edit_payment_amount' ) ?></td>
                    <td>
                        <input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="<?php echo $payment_amount ?>">
                    </td>
                </tr>
                <tr>
                    <td nowrap>Transaction ID:<?php gform_tooltip( 'payfast_edit_payment_transaction_id' ) ?></td>
                    <td>
                        <input type="text" id="payfast_transaction_id" name="payfast_transaction_id" value="<?php echo $transaction_id ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    public function admin_update_payment( $form, $lead_id )
    {
        check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );
        //update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        $form_action = strtolower( rgpost( 'save' ) );
        if ( ! $this->is_payment_gateway( $lead_id ) || $form_action <> 'update' )
        {
            return;
        }
        //get lead
        $lead = GFFormsModel::get_lead( $lead_id );
        //check if current payment status is processing
        if( $lead['payment_status'] != 'Processing')
        {
            return;
        }
        //get payment fields to update
        $payment_status = $_POST['payment_status'];
        //when updating, payment status may not be editable, if no value in post, set to lead payment status
        if ( empty( $payment_status ) )
        {
            $payment_status = $lead['payment_status'];
        }
        $payment_amount = GFCommon::to_number( rgpost( 'payment_amount' ) );
        $payment_transaction = rgpost( 'payfast_transaction_id' );
        $payment_date = rgpost( 'payment_date' );
        if ( empty( $payment_date ) )
        {
            $payment_date = gmdate( 'y-m-d H:i:s' );
        }
        else
        {
            //format date entered by user
            $payment_date = date( 'Y-m-d H:i:s', strtotime( $payment_date ) );
        }
        global $current_user;
        $user_id   = 0;
        $user_name = 'System';
        if ( $current_user && $user_data = get_userdata( $current_user->ID ) )
        {
            $user_id   = $current_user->ID;
            $user_name = $user_data->display_name;
        }
        $lead['payment_status'] = $payment_status;
        $lead['payment_amount'] = $payment_amount;
        $lead['payment_date'] = $payment_date;
        $lead['transaction_id'] = $payment_transaction;
        // if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
        if ( ( $payment_status == 'Approved' || $payment_status == 'Paid' ) && ! $lead['is_fulfilled'] )
        {
            $action['id'] = $payment_transaction;
            $action['type'] = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount'] = $payment_amount;
            $action['entry_id'] = $lead['id'];
            $this->complete_payment( $lead, $action );
            $this->fulfill_order( $lead, $payment_transaction, $payment_amount );
        }
        //update lead, add a note
        GFAPI::update_entry( $lead );
        GFFormsModel::add_note( $lead['id'], $user_id, $user_name, sprintf( __( 'Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s', 'gravityformspayfast' ), $lead['payment_status'], GFCommon::to_money( $lead['payment_amount'], $lead['currency'] ), $payment_transaction, $lead['payment_date'] ) );
    }
    public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null )
    {
        if ( !$feed )
        {
            $feed = $this->get_payment_feed( $entry );
        }
        $form = GFFormsModel::get_form_meta( $entry['form_id'] );
        if ( rgars( $feed, 'meta/delayPost' ) )
        {
            $this->log_debug( __METHOD__ . '(): Creating post.' );
            $entry['post_id'] = GFFormsModel::create_post( $form, $entry );
            $this->log_debug( __METHOD__ . '(): Post created.' );
        }
        if ( rgars( $feed, 'meta/delayNotification' ) )
        {
            //sending delayed notifications
            $notifications = rgars( $feed, 'meta/selectedNotifications' );
            GFCommon::send_notifications( $notifications, $form, $entry, true, 'form_submission' );
        }
        do_action( 'gform_payfast_fulfillment', $entry, $feed, $transaction_id, $amount );
        if ( has_filter( 'gform_payfast_fulfillment' ) )
        {
            $this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_payfast_fulfillment.' );
        }
    }
    private function is_valid_initial_payment_amount( $entry_id, $amount_paid )
    {
        //get amount initially sent to paypfast
        $amount_sent = gform_get_meta( $entry_id, 'payment_amount' );
        if ( empty( $amount_sent ) )
        {
            return true;
        }
        $epsilon = 0.00001;
        $is_equal = abs( floatval( $amount_paid ) - floatval( $amount_sent ) ) < $epsilon;
        $is_greater = floatval( $amount_paid ) > floatval( $amount_sent );
        //initial payment is valid if it is equal to or greater than product/subscription amount
        if ( $is_equal || $is_greater )
        {
            return true;
        }
        return false;
    }
    public function payfast_fulfillment( $entry, $payfast_config, $transaction_id, $amount )
    {
        //no need to do anything for payfast when it runs this function, ignore
        return false;
    }
    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    //Change data when upgrading from legacy payfast
    public function upgrade( $previous_version )
    {
        $previous_is_pre_addon_framework = version_compare( $previous_version, '1.0', '<' );
        if ( $previous_is_pre_addon_framework )
        {
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
    public function update_feed_id( $old_feed_id, $new_feed_id )
    {
        global $wpdb;
        $sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payfast_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id );
        $wpdb->query( $sql );
    }
    public function add_legacy_meta( $new_meta, $old_feed )
    {
        $known_meta_keys = array(
            'email', 'mode', 'type', 'style', 'continue_text', 'cancel_url', 'disable_note', 'disable_shipping', 'recurring_amount_field', 'recurring_times',
            'recurring_retry', 'billing_cycle_number', 'billing_cycle_type', 'trial_period_enabled', 'trial_amount', 'trial_period_number', 'trial_period_type', 'delay_post',
            'update_post_action', 'delay_notifications', 'selected_notifications', 'payfast_conditional_enabled', 'payfast_conditional_field_id',
            'payfast_conditional_operator', 'payfast_conditional_value', 'customer_fields',
        );
        foreach ( $old_feed['meta'] as $key => $value )
        {
            if ( !in_array( $key, $known_meta_keys ) )
            {
                $new_meta[ $key ] = $value;
            }
        }
        return $new_meta;
    }
    public function update_payment_gateway()
    {
        global $wpdb;
        $sql = $wpdb->prepare( "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='payfast'", $this->_slug );
        $wpdb->query( $sql );
    }
    public function update_lead()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead
             SET payment_status='Paid', payment_method='PayFast'
             WHERE payment_status='Approved'
                    AND ID IN (
                        SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway' AND meta_value=%s
                    )",
            $this->_slug);
        $wpdb->query( $sql );
    }
    public function copy_settings()
    {
        //copy plugin settings
        $old_settings = get_option( 'gf_payfast_configured' );
        $new_settings = array( 'gf_payfast_configured' => $old_settings );
        $this->update_plugin_settings( $new_settings );
    }
    public function copy_feeds()
    {
        //get feeds
        $old_feeds = $this->get_old_feeds();
        if ( $old_feeds )
        {
            $counter = 1;
            foreach ( $old_feeds as $old_feed )
            {
                $feed_name = 'Feed ' . $counter;
                $form_id = $old_feed['form_id'];
                $is_active = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];
                $new_meta = array(
                    'feedName' => $feed_name,
                    'payfastMerchantId' => rgar( $old_feed['meta'], 'payfastMerchantId' ),
                    'payfastMerchantKey' => rgar( $old_feed['meta'], 'payfastMerchantKey' ),
                    'passphrase' => rgar( $old_feed['meta'], 'passphrase' ),
                    'mode' => rgar( $old_feed['meta'], 'mode' ),
                    'transactionType' => rgar( $old_feed['meta'], 'type' ),
                    'type' => rgar( $old_feed['meta'], 'type' ), //For backwards compatibility of the delayed payment feature
                    'pageStyle' => rgar( $old_feed['meta'], 'style' ),
                    'continueText' => rgar( $old_feed['meta'], 'continue_text' ),
                    'cancelUrl' => rgar( $old_feed['meta'], 'cancel_url' ),
                    'disableNote' => rgar( $old_feed['meta'], 'disable_note' ),
                    'disableShipping' => rgar( $old_feed['meta'], 'disable_shipping' ),
                    'recurringAmount' => rgar( $old_feed['meta'], 'recurring_amount_field' ) == 'all' ? 'form_total' : rgar( $old_feed['meta'], 'recurring_amount_field' ),
                    'recurring_amount_field' => rgar( $old_feed['meta'], 'recurring_amount_field' ), //For backwards compatibility of the delayed payment feature
                    'initialAmount' => rgar( $old_feed['meta'], 'initialAmount' ), 
                    'recurringTimes' => rgar( $old_feed['meta'], 'recurring_times' ),
                    'recurringRetry' => rgar( $old_feed['meta'], 'recurring_retry' ),
                    'paymentAmount' => 'form_total',
                    'billingCycle_length' => rgar( $old_feed['meta'], 'billing_cycle_number' ),
                    'billingCycle_unit' => $this->convert_interval( rgar( $old_feed['meta'], 'billing_cycle_type' ), 'text' ),
                    'trial_enabled' => rgar( $old_feed['meta'], 'trial_period_enabled' ),
                    'trial_product' => 'enter_amount',
                    'trial_amount' => rgar( $old_feed['meta'], 'trial_amount' ),
                    'trialPeriod_length' => rgar( $old_feed['meta'], 'trial_period_number' ),
                    'trialPeriod_unit' => $this->convert_interval( rgar( $old_feed['meta'], 'trial_period_type' ), 'text' ),
                    'delayPost' => rgar( $old_feed['meta'], 'delay_post' ),
                    'change_post_status' => rgar( $old_feed['meta'], 'update_post_action' ) ? '1' : '0',
                    'update_post_action' => rgar( $old_feed['meta'], 'update_post_action' ),
                    'delayNotification' => rgar( $old_feed['meta'], 'delay_notifications' ),
                    'selectedNotifications' => rgar( $old_feed['meta'], 'selected_notifications' ),
                    'billingInformation_firstName' => rgar( $customer_fields, 'first_name' ),
                    'billingInformation_lastName' => rgar( $customer_fields, 'last_name' ),
                    'billingInformation_email' => rgar( $customer_fields, 'email' ),
                    'billingInformation_address' => rgar( $customer_fields, 'address1' ),
                    'billingInformation_address2' => rgar( $customer_fields, 'address2' ),
                    'billingInformation_city' => rgar( $customer_fields, 'city' ),
                    'billingInformation_state' => rgar( $customer_fields, 'state' ),
                    'billingInformation_zip' => rgar( $customer_fields, 'zip' ),
                    'billingInformation_country' => rgar( $customer_fields, 'country' ),
                );
                $new_meta = $this->add_legacy_meta( $new_meta, $old_feed );
                //add conditional logic
                $conditional_enabled = rgar( $old_feed['meta'], 'payfast_conditional_enabled' );
                if ( $conditional_enabled )
                {
                    $new_meta['feed_condition_conditional_logic']        = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = array(
                        'conditionalLogic' =>
                            array(
                                'actionType' => 'show',
                                'logicType'  => 'all',
                                'rules'      => array(
                                    array(
                                        'fieldId' => rgar( $old_feed['meta'], 'payfast_conditional_field_id' ),
                                        'operator' => rgar( $old_feed['meta'], 'payfast_conditional_operator' ),
                                        'value' => rgar( $old_feed['meta'], 'payfast_conditional_value' )
                                    ),
                                )
                            )
                    );
                }
                else
                {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }
                $new_feed_id = $this->insert_feed( $form_id, $is_active, $new_meta );
                $this->update_feed_id( $old_feed['id'], $new_feed_id );
                $counter ++;
            }
        }
    }
    public function copy_transactions()
    {
        //copy transactions from the payfast transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        $this->log_debug( __METHOD__ . '(): Copying old PayFast transactions into new table structure.' );
        $new_table_name = $this->get_new_transaction_table_name();
        $sql    =   "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
                    SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";
        $wpdb->query( $sql );
        $this->log_debug( __METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added." );
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
        $table_name = $wpdb->prefix . 'rg_payfast';
        $form_table_name = GFFormsModel::get_form_table_name();
        $sql     = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                    FROM {$table_name} s
                    INNER JOIN {$form_table_name} f ON s.form_id = f.id";
        $this->log_debug( __METHOD__ . "(): getting old feeds: {$sql}" );
        $results = $wpdb->get_results( $sql, ARRAY_A );
        $this->log_debug( __METHOD__ . "(): error?: {$wpdb->last_error}" );
        $count = sizeof( $results );
        $this->log_debug( __METHOD__ . "(): count: {$count}" );
        for ( $i = 0; $i < $count; $i ++ )
        {
            $results[ $i ]['meta'] = maybe_unserialize( $results[ $i ]['meta'] );
        }
        return $results;
    }
    //This function kept static for backwards compatibility
    public static function get_config_by_entry( $entry )
    {
        $payfast = GFPayFast::get_instance();
        $feed = $payfast->get_payment_feed( $entry );
        if ( empty( $feed ) )
        {
            return false;
        }
        return $feed['addon_slug'] == $payfast->_slug ? $feed : false;
    }
    //This function kept static for backwards compatibility
    //This needs to be here until all add-ons are on the framework, otherwise they look for this function
    public static function get_config( $form_id )
    {
        $payfast = GFPayFast::get_instance();
        $feed   = $payfast->get_feeds( $form_id );
        //Ignore ITN messages from forms that are no longer configured with the PayFast add-on
        if ( ! $feed )
        {
            return false;
        }
        return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
    }
    //------------------------------------------------------
}