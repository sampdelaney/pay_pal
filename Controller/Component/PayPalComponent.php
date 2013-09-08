<?php
App::uses('HttpSocket', 'Network/Http');
class PayPalComponent extends Component
{
    protected $_defaults = array(
        'cacheRoot' => 'PayPal',
        'returnUrl' => '/',
        'cancelUrl' => '/',
        'accessTokenRequest' => array(
            'uri' => 'oauth2/token',
            'method' => 'POST',
            'header' => array(
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US',
                'content-type' => 'application/x-www-form-urlencoded'
            ),
            'auth' => array(
                'method' => 'Basic'
            ),
            'body' => array('grant_type' => 'client_credentials')
        ),
        'createPaymentRequest' => array(
            'uri' => 'payments/payment',
            'method' => 'POST',
            'header' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => array(
                'intent' => 'sale',
                'redirect_urls' => array(
                    'return_url' => '/',
                    'cancel_url' => '/'
                ),
                'payer' => array('payment_method' => 'paypal'),
                'transactions' => array()
            )
        ),
        'executePaymentRequest' => array(
            'uri' => 'payments/payment/%s/execute/',
            'method' => 'POST',
            'header' => array(
                'Content-Type' => 'application/json'
            )
        )
    );

    private $_controller = null;

    public function __construct(ComponentCollection $collection, $settings = array())
    {
        parent::__construct($collection, $settings);
        $this->settings = Set::merge($this->_defaults, $settings);
    }

    public function initialize(Controller $controller)
    {
        // get a reference to the controller to redirect it for approve payment
        $this->_controller = $controller;
        // check for paypal redirect query params
        if(array_key_exists('token', $controller->request->query) &&
            array_key_exists('PayerID', $controller->request->query)){
            $this->settings['paymentToken'] = $controller->request->query['token'];
            $this->settings['paymentPayerId']  = $controller->request->query['PayerID'];
        }
    }

    public function getAccessToken()
    {
        extract($this->settings);
        // add application credentials
        $accessTokenRequest['auth']['user'] = Configure::read('PayPal.applicationClientId');
        $accessTokenRequest['auth']['pass'] = Configure::read('PayPal.applicationSecret');
        // get the access token from paypal
        $accessTokenResult = $this->_performRequest($accessTokenRequest);
        // timestamp the access token so we know when it expires
        $accessTokenResult->body['timestamp'] = date('Y-m-d H:i:s');
        // write to cache
        Cache::write($cacheRoot . '.accessToken', $accessTokenResult->body);
        return $accessTokenResult->body;
    }

    public function createPayment($transactions = array())
    {
        extract($this->settings);
        // sign the request with the access token
        $this->_signApiCallRequest($createPaymentRequest);
        // add redirect urls
        if(is_array($returnUrl)){
            $createPaymentRequest['body']['redirect_urls']['return_url'] = Router::url($returnUrl, true);
        }else{
            $createPaymentRequest['body']['redirect_urls']['return_url'] = $returnUrl;
        }
        if(is_array($cancelUrl)){
            $createPaymentRequest['body']['redirect_urls']['cancel_url'] = Router::url($cancelUrl, true);
        }else{
            $createPaymentRequest['body']['redirect_urls']['cancel_url'] = $cancelUrl;
        }
        // modify the api call
        $createPaymentRequest['body']['transactions'] = $transactions;
        // json encode the transactions
        $createPaymentRequest['body'] = json_encode($createPaymentRequest['body']);
        // make the call
        $apiCallResponse = $this->_performRequest($createPaymentRequest);
        // check that the call succeeded
        if($apiCallResponse->code != 201 && $apiCallResponse->body['state'] != 'created'){
            return false;
        }
        return $apiCallResponse->body;
    }

    public function approvePayment($payment)
    {
        return $this->_controller->redirect($payment['links'][1]['href']);
    }

    public function executePayment($payment)
    {
        extract($this->settings);
        // sign the request with access token
        $this->_signApiCallRequest($executePaymentRequest);
        // insert the payment ID into the request URI
        $executePaymentRequest['uri'] = sprintf($executePaymentRequest['uri'], $payment['id']);
        // set payer id
        $executePaymentRequest['body'] = json_encode(array('payer_id' => $paymentPayerId));
        // make the call
        $paymentResponse = $this->_performRequest($executePaymentRequest);
        // check response
        // TODO: this could be moved to another function?
        if($paymentResponse->code != 200 && $paymentResponse->body['state'] == 'sale'){
            return false;
        }
        // must return boolean
        return $paymentResponse->body;
    }

    private function _performRequest($request)
    {
        // build the request uri
        $request['uri'] = $this->_buildUri($request['uri']);
        $httpSocket = new HttpSocket();
        $response = $httpSocket->request($request);
        // decode JSON string in body if content type is set to json
        if(!empty($response->headers['Content-Type']) && $response->headers['Content-Type'] == 'application/json'){
            $response->body = json_decode($response->body, true);
        }
        return $response;
    }

    private function _isValidAccessToken($accessToken)
    {
        $accessTokenTimestamp = DateTime::createFromFormat('Y-m-d H:i:s', $accessToken['timestamp']);
        $accessTokenTimestamp->modify(sprintf('+%d seconds', $accessToken['expires_in']));
        $now = new DateTime('now');
        return $now < $accessTokenTimestamp;
    }

    private function _signApiCallRequest(&$apiCallRequest)
    {
        extract($this->settings);
        // check cache for access token
        $accessToken = Cache::read($cacheRoot . '.accessToken');
        // if not in cache or it's out of date, get a fresh access token
        if (empty($accessToken) || !$this->_isValidAccessToken($accessToken)) {
            $accessToken = $this->getAccessToken();
        }
        // sign the request
        $apiCallRequest['header']['Authorization'] = sprintf("%s %s", $accessToken['token_type'], $accessToken['access_token']);
    }

    private function _buildUri($path)
    {
        $uriTemplate = ":protocol\\://:endpoint/v:version/:path";
        return String::insert($uriTemplate, array(
            'protocol' => 'https',
            'endpoint' => Configure::read('PayPal.endpoint'),
            'version' => Configure::read('PayPal.version'),
            'path' => $path
        ));
    }
}
