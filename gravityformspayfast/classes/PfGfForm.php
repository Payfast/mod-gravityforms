<?php

GFForms::include_payment_addon_framework();

class PfGfForm extends GFPaymentAddOn
{
    /**
     * Retrieve Payfast form fields.
     *
     * @return array
     */
    public static function getFields(): array
    {
        return array(
            array(
                'name'     => 'payfastMerchantId',
                'label'    => __('Payfast Merchant ID ', 'gravityformspayfast'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => GFPayFast::H6_TAG . __(
                        'Payfast Merchant ID',
                        'gravityformspayfast'
                    ) . GFPayFast::H6_TAG_END . __(
                                  'Enter your Payfast Merchant ID.',
                                  'gravityformspayfast'
                              )
            ),
            array(
                'name'     => 'payfastMerchantKey',
                'label'    => __('Payfast Merchant Key ', 'gravityformspayfast'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => GFPayFast::H6_TAG . __(
                        'Payfast Merchant Key',
                        'gravityformspayfast'
                    ) . GFPayFast::H6_TAG_END . __(
                                  'Enter your Payfast Merchant Key.',
                                  'gravityformspayfast'
                              )
            ),
            array(
                'name'     => 'passphrase',
                'label'    => __('Payfast Passphrase ', 'gravityformspayfast'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => GFPayFast::H6_TAG . __(
                        'Payfast Passphrase',
                        'gravityformspayfast'
                    ) . GFPayFast::H6_TAG_END . __(
                                  'Only enter a passphrase if it is set on your Payfast account.',
                                  'gravityformspayfast'
                              )
            ),
            array(
                'name'          => 'mode',
                'label'         => __('Mode', 'gravityformspayfast'),
                'type'          => 'radio',
                'choices'       => array(
                    array(
                        'id'    => 'gf_payfast_mode_production',
                        'label' => __('Production', 'gravityformspayfast'),
                        'value' => 'production'
                    ),
                    array(
                        'id'    => 'gf_payfast_mode_test',
                        'label' => __('Test', 'gravityformspayfast'),
                        'value' => 'test'
                    ),
                ),
                'horizontal'    => true,
                'default_value' => 'production',
                'tooltip'       => GFPayFast::H6_TAG . __('Mode', 'gravityformspayfast') . GFPayFast::H6_TAG_END . __(
                        'Select Production to enable live transactions. Select Test for testing with the Payfast Sandbox.',
                        'gravityformspayfast'
                    )
            ),
        );
    }

    /**
     * @return array
     */
    public function setCycles(): array
    {
        return array(
            'name'       => 'cycles',
            'label'      => __('Cycles (set to 0 for infinite)', 'gravityformspayfast'),
            'type'       => 'text',
            'horizontal' => true,
            'required'   => true,
            'tooltip'    => GFPayFast::H6_TAG . __('Cycles', 'gravityformspayfast') . GFPayFast::H6_TAG_END . __(
                    'Cycles',
                    'gravityformspayfast'
                )
        );
    }

    /**
     * @return array
     */
    public function setPostSettings(): array
    {
        return array(
            'name'    => 'post_checkboxes',
            'label'   => __('Posts', 'gravityformspayfast'),
            'type'    => 'checkbox',
            'tooltip' => GFPayFast::H6_TAG . __('Posts', 'gravityformspayfast') . GFPayFast::H6_TAG_END . __(
                    'Enable this option if you would like to only create the post after payment has been received.',
                    'gravityformspayfast'
                ),
            'choices' => array(
                array(
                    'label' => __('Create post only when payment is received.', 'gravityformspayfast'),
                    'name'  => 'delayPost'
                ),
            ),
        );
    }


    /**
     * @return array
     */
    public function setFrequency(): array
    {
        return array(
            'name'     => 'frequency',
            'label'    => __('Frequency', 'gravityformspayfast'),
            'type'     => 'select',
            //    'horizontal' => true,
            'required' => true,
            'choices'  => array(
                array('label' => __('Monthly', 'gravityformspayfast'), 'name' => 'monthly', 'value' => '3'),
                array('label' => __('Quarterly', 'gravityformspayfast'), 'name' => 'quarterly', 'value' => '4'),
                array('label' => __('Biannual', 'gravityformspayfast'), 'name' => 'biannual', 'value' => '5'),
                array('label' => __('Annual', 'gravityformspayfast'), 'name' => 'annual', 'value' => '6')
            ),
            'tooltip'  => GFPayFast::H6_TAG . __('Frequency', 'gravityformspayfast') . GFPayFast::H6_TAG_END . __(
                    'Frequency.',
                    'gravityformspayfast'
                )
        );
    }

    /**
     * @return array
     */
    public function setInitialAmnt(): array
    {
        return array(
            'name'     => 'initialAmount',
            'label'    => esc_html__('Initial Amount', 'gravityformspayfast'),
            'type'     => 'select',
            'choices'  => $this->recurring_amount_choices(),
            'required' => true,
            'tooltip'  => GFPayFast::H6_TAG . esc_html__(
                    'Initial Amount',
                    'gravityformspayfast'
                ) . GFPayFast::H6_TAG_END . esc_html__(
                              "Select which field determines the initial payment amount, or select 'Form Total' to use the total of all pricing fields as the recurring amount.",
                              'gravityformspayfast'
                          ),
        );
    }

    public function recurring_amount_choices()
    {
        $form                = $this->get_current_form();
        $recurring_choices   = $this->get_payment_choices($form);
        $recurring_choices[] = array(
            'label' => esc_html__('Form Total', 'gravityformspayfast'),
            'value' => 'form_total'
        );

        return $recurring_choices;
    }

    /**
     * Get the configuration instructions and settings for Payfast.
     *
     * @return array
     */
    public function getPayfastConfigurationInstructions(): array
    {
        $description = '
            <p style="text-align: left;">' .
                       sprintf(
                       // translators: %1$s and %2$s are the opening and closing <a> tags for the Payfast link.
                           __(
                               'You will need a Payfast account in order to use the Payfast Add-On. Navigate to %1$sPayfast%2$s to register.',
                               'gravityformspayfast'
                           ),
                           '<a href="https://payfast.io" target="_blank">',
                           '</a>'
                       ) .
                       '</p>
            <ul>
                <li>' . __(
                           'The Payfast settings are configured per form. Navigate to \'Forms\' -> select \'Settings\' for the form, and select the \'Payfast\' tab.',
                           'gravityformspayfast'
                       ) . '</li>' .
                       '<li>' . __(
                           'From there, click \'Add New\' to configure Payfast feed settings for the currently selected form.',
                           'gravityformspayfast'
                       ) . '</li>' .
                       '</ul>
            <p style="text-align: left;">' .
                       __(
                           'Enable \'Debug\' below to log the server-to-server communication between Payfast and your website, for each transaction. The log file for debugging can be found at /wp-content/plugins/gravityformspayfast/payfast.log. If activated, be sure to protect it by adding an .htaccess file in the same directory. If not, the file will be readable by anyone. ',
                           'gravityformspayfast'
                       ) .
                       '</p>';

        return array(
            array(
                'title'       => esc_html__('How to configure Payfast', 'gravityformspayfast'),
                'description' => $description,
                'fields'      => array(
                    array(
                        'name'    => 'gf_payfast_debug',
                        'label'   => esc_html__('Payfast Debug', 'gravityformspayfast'),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => __('Enable Debug', 'gravityformspayfast'),
                                'name'  => 'gf_payfast_debug'
                            )
                        )
                    ),
                    array(
                        'type'     => 'save',
                        'messages' => array(
                            'success' => __('Settings have been updated.', 'gravityformspayfast')
                        ),
                    ),
                ),
            ),
        );
    }

    public function getCancelUrl(): array
    {
        return array(
            array(
                'name'     => 'cancelUrl',
                'label'    => __('Cancel URL', 'gravityformspayfast'),
                'type'     => 'text',
                'class'    => 'medium',
                'required' => false,
                'tooltip'  => GFPayFast::H6_TAG . __('Cancel URL', 'gravityformspayfast') . GFPayFast::H6_TAG_END . __(
                        'Enter the URL the user should be sent to should they cancel before completing their payment. It currently defaults to the Payfast website.',
                        'gravityformspayfast'
                    )
            ),
            array(
                'name'    => 'notifications',
                'label'   => __('Notifications', 'gravityformspayfast'),
                'type'    => 'notifications',
                'tooltip' => GFPayFast::H6_TAG . __(
                        'Notifications',
                        'gravityformspayfast'
                    ) . GFPayFast::H6_TAG_END . __(
                                 "Enable this option if you would like to only send out this form's notifications after payment has been received. Leaving this option disabled will send notifications immediately after the form is submitted.",
                                 'gravityformspayfast'
                             )
            ),
        );
    }


    public function getBillingCycles(): array
    {
        return array(
            'monthly'   => array('label' => esc_html__('month(s)', 'gravityformspayfast'), 'min' => 1, 'max' => 24),
            'quarterly' => array('label' => esc_html__('day(s)', 'gravityformspayfast'), 'min' => 1, 'max' => 20),
            'biannual'  => array('label' => esc_html__('week(s)', 'gravityformspayfast'), 'min' => 1, 'max' => 10),
            'annual'    => array('label' => esc_html__('year(s)', 'gravityformspayfast'), 'min' => 1, 'max' => 5)
        );
    }

    public function getOptionsSettings(): array
    {
        return array(
            'name'    => 'options_checkboxes',
            'type'    => 'checkboxes',
            'choices' => array(
                array(
                    'label' => __('Do not prompt buyer to include a shipping address.', 'gravityformspayfast'),
                    'name'  => 'disableShipping'
                ),
                array(
                    'label' => __('Do not prompt buyer to include a note with payment.', 'gravityformspayfast'),
                    'name'  => 'disableNote'
                ),
            )
        );
    }

}
