# Lunar Online Payments for ZenCart

The software is provided “as is”, without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and noninfringement.


## Supported Zen Cart versions

*The plugin has been tested with most versions of Zen Cart at every iteration. We recommend using the latest version of Zen Cart, but if that is not possible for some reason, test the plugin with your Zen Cart version and it would probably function properly.*


## Installation

  After you have your Zen Cart setup, follow these simple steps:
  1. Signup at [lunar.app](https://lunar.app) (it’s free)
  1. Create an account
  1. Create an app key for your Zen Cart website
  1. Upload the files in the upload folder to your root zen cart installation.
  1. Activate the plugin through the 'Modules/Payment' screen in Zen Cart.
  1. Insert the app key and your public key in the settings for the Lunar payment plugin


## Updating settings

Under the Zen Cart Lunar settings, you can:
 * Update the payment method text in the payment gateways list
 * Update the payment method description in the payment gateways list
 * Update the title that shows up in the payment popup
 * Add public & app keys
 * Set the status the order should get into when you do a refund/void/capture
 * Change the capture type (Instant/Manual via the transaction tool)



 ## How to

The following actions are available by clicking on the `Click for Additional Payment Handling Options` link in order details windows.

 1. Capture
 * In Instant mode, the orders are captured automatically
 * In delayed mode you can capture an order using the in order transaction table actions. In that table you will see Capture when this is available
 2. Refund
   * To refund an order you can use the in order transaction table actions, this is only available for orders that have been captured. Click the button and follow the instructions
 3. Void
   * To void an order you can use the in order transaction table actions. You can only do this if the order is not captured, if you have captured already use the refund.

## Available features

1. Capture
   * Zencart admin panel: full capture
   * Lunar admin panel: full/partial capture
2. Refund
   * Zencart admin panel: full/partial refund
   * Lunar admin panel: full/partial refund
3. Void
   * Zencart admin panel: full void
   * Lunar admin panel: full/partial void


## Changelog

### 1.0.0
- Initial commit