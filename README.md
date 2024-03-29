# mod-gravityforms

PayFast Add-on for Gravity Forms v2.6.8

Installation

1. Unzip the module to a temporary location on your computer
2. Copy the “gravityformspayfast” folder in the archive to your base "wp-content/plugins” folder

- This should NOT overwrite any existing files or folders and merely supplement them with the PayFast files
- This is however, dependent on the FTP program you use

3. Login to the WordPress Administrator console
4. Go to ‘Plugins’ and activate the PayFast Gravity Forms plugin.
5. Go to ‘Forms’ -> ’Settings’, under ‘General Settings’, select ‘South African Rands’ for currency and click 'Save
   Settings'.
6. You will then need to create a form with options included from the ‘Pricing Fields’ section and click 'Save'.
7. Go to ‘Forms’ -> ’Settings’ -> ’PayFast’, and add feed settings for PayFast, per form.
8. Complete the PayFast settings as required, and select 'Test' Mode and 'Debug' for testing purposes.

How can I test that it is working correctly? If you followed the installation instructions above, the module is in
“test” mode and you can test it by creating an invoice and completing the payment cycle through the PayFast sandbox,
login with the user account detailed above and make payment using the balance in their wallet.

You will not be able to directly “test” a specific payment method (such as credit card or Instant EFT) in the sandbox,
but you don’t really need to. The inputs to and outputs from PayFast are exactly the same, no matter which payment
method is used, so using the wallet will give you exactly the same results as if you had used another payment method.

Please [click here](https://payfast.io/integration/shopping-carts/gravity-forms/) for more information concerning this module.
