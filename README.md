PayPal Component for CakePHP 2.0+
=================================

PayPal plugin for CakePHP allows you to interact with convenience with the PayPal REST APIs.


Installation
------------
To be able to use the PayPal plugin component for CakePHP you will need to own a PayPal developer account and have created an application. If you don't have a PayPal developer account, please visit https://developer.paypal.com and sign up.

*   Clone/Copy the files into `app/Plugin/pay_pal`
*   Set your application client ID and application secret in `app/Plugin/pay_pal/Config/bootstrap.php`

<!-- -->

    Configure::write('PayPal', array(
        'endpoint' => 'api.sandbox.paypal.com',
        'version' => 1,
        'applicationClientId' => 'IXwDJZmjcYejw1lzJrBLQdkaxeL3UPYhEUUosIDUcXllqcZGSoeZb1q89c1r',
        'applicationSecret' => 'd1Zhd7P3Ex315e2Es67I51UO12n85l31S149371Z84C6Z1sqZSJ4za4nML14'
    ));

*   Ensure the plugin is loaded in `app/Config/bootstrap.php` by calling `CakePlugin::load('DebugKit', array('bootstrap' = > true));`
*   Include the PayPal component in your controller `public $components = array('PayPal.PayPal');`

