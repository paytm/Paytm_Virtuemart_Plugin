# Introduction

This is the readme file for Paytm Payment Gateway Plugin Integration for Joomla-Virtuemart v3.0 based e-Commerce Websites.
The provided Plugin helps store merchants to redirect customers to the Paytm Payment Gateway when they choose PAYTM 
as their payment method. After the customer has finished the transaction they are redirected back to an appropriate 
page on the merchant site depending on the status of the transaction.

The aim of this document is to explain the procedure of installation and configuration of the Plugin on the merchant website.

# Joomla-Virtuemart Plugin

The Plugin is provided in the form of a zip file.


# Installation and Configuration

 1. Upload the paytm_Virtuemart3.0_Kit.zip using extension manager in joomla.
 2. Put php file encdec_paytm.php to location <ROOT Directory>/plugins/vmpayment/paytm/ .
 3. Enable Paytm payment method in plugin manager.
 4. Login to Administrator Area - site administrator panel.
 5. Select VirtueMart Store panel in the drop list.
 6. Click on Shop in left menu than Click on Payment Methods.
 7. Give the Payment method name, and select YES to publish.
 8. Choose the paytm Payment Method in dropdown box. 
 9. Then Click on Save button to generate the configuration parameters.
 10. Go to the Configuration tab.
 11. Now you can fill the parameters listed in Configuration tab.
 12. You should give the paytm Merchant id, Secret key, Transaction URL, Transaction Status URL and description in the listed parameters on configuration tab. These parameters are Mandatory.
 13. Then click Save & Close.
