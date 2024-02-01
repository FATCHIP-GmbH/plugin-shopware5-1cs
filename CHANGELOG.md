# Changelog - Shopware First Cash Solution Payment Connector

## 1.1.15
2024-02-01
* Fixed class loading problem with SW 5.6.9 and PHP 7.4

## 1.1.14
2023-08-08
* Added cancel button to credit card iFrame and payment page
* Fixed display of error messages in some cases

## 1.1.13
2023-07-11
* Optimized session handling for iDeal payments
* Respect customer group when calculation Paypal express shipping costs

## 1.1.12
2023-05-15
* Added transfer of standard shipping costs with Paypal Express

## 1.1.11
2023-04-03
* Added option for encryption method used
* Fixed iDEAL bank retrieval

## 1.1.10
2023-02-07
* Added automatic credit card detection in silent mode
* Fixed test mode for credit card silent mode

## 1.1.9
2023-01-16
* Fixed PayPal Express Cookie
* Added fallback for empty house number on address splitting exception
* Increased minimum Shopware version from 5.0.0 to 5.6
* Added check for all payments to make sure all products in basket are still available
* Added handling of AmazonPay partial refunds
* Fixed Paypal order status not set due to variable re-use
* Klarna remnants removed from risk rules

## 1.1.8
2022-12-06
* Initial release
