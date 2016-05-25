PayFast Add-on for Gravity Forms v1.9.9
------------------------------------------------------------------------------
Copyright (c) 2010-2016 PayFast (Pty) Ltd

LICENSE:
 
This payment module is free software; you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published
by the Free Software Foundation; either version 3 of the License, or (at
your option) any later version.

This payment module is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
License for more details.

Please see http://www.opensource.org/licenses/ for a copy of the GNU Lesser
General Public License.

INTEGRATION:
1. Unzip the module to a temporary location on your computer
2. Copy the “wp-content” folder in the archive to your base “wordpress” folder
- This should NOT overwrite any existing files or folders and merely supplement them with the PayFast files
- This is however, dependent on the FTP program you use
3. Login to the WordPress Administrator console
4. Go to ‘Plugins’ and activate the PayFast Gravity Forms plugin.
5. Go to ‘Forms’?’Settings’?’PayFast’ and input your Merchant ID and Key, click save settings.
6. Go to ‘Forms’?’Settings’, under ‘General Settings’, select ‘South African Rands’ for your currency, click save settings.
7. You need to create a form with options included from the ‘Pricing Fields’ section and then go to ‘Forms’?’PayFast’?’Add New’ complete the form accordingly, select Sandbox and Debug for testing purposes.
8. Click “Save”
9. To test with the sandbox, use the following login credentials when redirected to the PayFast site:
- Username: sbtu01@payfast.co.za
- Password: clientpass

How can I test that it is working correctly?
If you followed the installation instructions above, the module is in “test” mode and you can test it by creating an invoice and completing the payment cycle through the PayFast sandbox, login with the user account detailed above and make payment using the balance in their wallet.

You will not be able to directly “test” a credit card, Instant EFT or Ukash payment in the sandbox, but you don’t really need to. The inputs to and outputs from PayFast are exactly the same, no matter which payment method is used, so using the wallet of the test user will give you exactly the same results as if you had used another payment method.

***************************************************************************
*                                                                         *
*   Please see the URL below for all information concerning this module:  *
*                                                                         *
*          https://www.payfast.co.za/shopping-carts/gravity-forms/        *
*                                                                         *
***************************************************************************
