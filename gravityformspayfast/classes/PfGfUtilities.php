<?php

class PfGfUtilities
{
    public function setCancelUrl($cancel_url, $varArray)
    {
        if (!empty($cancel_url)) {
            $varArray['cancel_url'] = $cancel_url;
        }

        return $varArray;
    }

    /**
     * @param $form
     * @param $feed
     * @param $entry
     * @param array $varArray
     *
     * @return array
     */
    public function setCustomerEmail($form, $feed, $entry, array $varArray): array
    {
        if ($form["pagination"] === null) {
            $varArray['email_address'] = $this->getCustomerEmail($feed, $entry);

            if (empty($varArray['email_address'])) {
                $varArray['email_address'] = $entry[GFCommon::get_email_fields($form)[0]->id];
            }
        }

        return $varArray;
    }

    public function getCustomerEmail($feed, $lead)
    {
        $customer_email = '';
        foreach ($this->getCustomerFields() as $field) {
            $field_id = $feed['meta'][$field['meta_name']];
            $value    = rgar($lead, $field_id);
            if (!empty($value) && $field['name'] == 'email') {
                $customer_email = $value;
            }
        }

        return $customer_email;
    }


    public function getCustomerFields()
    {
        return array(
            array('name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName'),
            array('name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName'),
            array('name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email'),
            array('name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address'),
            array('name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2'),
            array('name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city'),
            array('name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state'),
            array('name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip'),
            array('name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country'),
        );
    }

    /**
     * @param mixed $varArray
     * @param string $secureString
     *
     * @return string
     */
    public function setSecureStr(mixed $varArray, string $secureString): string
    {
        foreach ($varArray as $k => $v) {
            if (!is_null($v)) {
                $secureString .= $k . '=' . urlencode(trim($v)) . '&';
            }
        }

        return $secureString;
    }

    /**
     * @param $options
     * @param string $product_options
     *
     * @return string
     */
    public function getProductOptions($options, string $product_options): string
    {
        if (!empty($options) && is_array($options)) {
            $product_options = ' (';
            foreach ($options as $option) {
                $product_options .= $option['option_name'] . ', ';
            }
            $product_options = substr($product_options, 0, strlen($product_options) - 2) . ')';
        }

        return $product_options;
    }


    /**
     * @param $options
     * @param int $product_index
     * @param string $query_string
     *
     * @return string
     */
    public function getQueryString($options, int $product_index, string $query_string): string
    {
        if (!empty($options) && is_array($options)) {
            $option_index = 1;
            foreach ($options as $option) {
                $option_label = urlencode($option['field_label']);
                $option_name  = urlencode($option['option_name']);
                $query_string .= "&on{$option_index}_{$product_index}={$option_label}&os{$option_index}_{$product_index}={$option_name}";
                $option_index++;
            }
        }

        return $query_string;
    }

    /**
     * @param mixed $billing_fields
     * @param bool $add_first_name
     * @param bool $add_last_name
     * @param $billing_info
     *
     * @return mixed
     */
    public function getBillingInfo(mixed $billing_fields, bool $add_first_name, bool $add_last_name, $billing_info)
    {
        foreach ($billing_fields as $mapping) {
            //add first/last name if it does not already exist in billing fields
            if ($mapping['name'] == 'firstName') {
                $add_first_name = false;
            } elseif ($mapping['name'] == 'lastName') {
                $add_last_name = false;
            }
        }
        if ($add_last_name) {
            //add last name
            array_unshift(
                $billing_info['field_map'],
                array(
                    'name'     => 'lastName',
                    'label'    => __('Last Name', 'gravityformspayfast'),
                    'required' => false
                )
            );
        }
        if ($add_first_name) {
            array_unshift(
                $billing_info['field_map'],
                array(
                    'name'     => 'firstName',
                    'label'    => __('First Name', 'gravityformspayfast'),
                    'required' => false
                )
            );
        }

        return $billing_info;
    }

    /**
     * @param $discounts
     * @param float|int $discount_amt
     * @param string $query_string
     *
     * @return string
     */
    public function lookForDiscounts($discounts, float|int $discount_amt, string $query_string): string
    {
        if (is_array($discounts)) {
            foreach ($discounts as $discount) {
                $discount_full = abs($discount['unit_price']) * $discount['quantity'];
                $discount_amt  += $discount_full;
            }
            if ($discount_amt > 0) {
                $query_string .= "&discount_amount_cart={$discount_amt}";
            }
        }

        return $query_string;
    }

    /**
     * @param $meta
     * @param array $varArray
     * @param $entry
     * @param $form
     *
     * @return array
     */
    public function includeVariablesIfSubscription($meta, array $varArray, $entry, $form): array
    {
        if ($meta['transactionType'] === 'subscription') {
            $varArray = array_merge($varArray, [
                'custom_str4'       => gmdate('Y-m-d'),
                'subscription_type' => 1,
                'billing_date'      => gmdate('Y-m-d'),
                'frequency'         => rgar($meta, 'frequency'),
                'cycles'            => rgar($meta, 'cycles'),
            ]);

            $recurringAmountField = $meta['recurring_amount_field'] ?? null;

            if ($recurringAmountField !== 'form_total') {
                if (!empty($entry[$recurringAmountField . '.2'])) {
                    // Case: Specific recurring amount field with decimal value
                    $varArray['recurring_amount'] = str_replace(
                        ",",
                        "",
                        substr($entry[$recurringAmountField . '.2'], 1)
                    );
                } elseif (!empty($entry[$recurringAmountField])) {
                    // Case: Specific recurring amount field without decimal value
                    $varArray['recurring_amount'] = substr(
                        $entry[$recurringAmountField],
                        strpos($entry[$recurringAmountField], '|') + 1
                    );
                } else {
                    // Fallback to order total
                    $varArray['recurring_amount'] = GFCommon::get_order_total($form, $entry);
                }
            } else {
                // Case: Recurring amount is based on form total
                $varArray['recurring_amount'] = GFCommon::get_order_total($form, $entry);
            }
        }

        return array($varArray, $entry);
    }

    public function convertInterval($interval, $to_type)
    {
        //convert single character into long text for new feed settings or convert long text into single character for sending to payfast
        //$to_type: text (change character to long text), OR char (change long text to character)
        if (empty($interval)) {
            return '';
        }

        $new_interval = '';
        if ($to_type == 'text') {
            //convert single char to text
            switch (strtoupper($interval)) {
                case 'D':
                    $new_interval = 'day';
                    break;
                case 'W':
                    $new_interval = 'week';
                    break;
                case 'M':
                    $new_interval = 'month';
                    break;
                case 'Y':
                    $new_interval = 'year';
                    break;
                default:
                    $new_interval = $interval;
                    break;
            }
        } else {
            //convert text to single char
            switch (strtolower($interval)) {
                case 'day':
                    $new_interval = 'D';
                    break;
                case 'week':
                    $new_interval = 'W';
                    break;
                case 'month':
                    $new_interval = 'M';
                    break;
                case 'year':
                    $new_interval = 'Y';
                    break;
                default:
                    $new_interval = $interval;
                    break;
            }
        }

        return $new_interval;
    }

}
