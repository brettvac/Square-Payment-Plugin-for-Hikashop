<?php
/**
 * @package    Square Payment Plugin for HikaShop
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\Database\DatabaseInterface;

class plgHikashoppaymentSquare extends hikashopPaymentPlugin
{
    protected $autoloadLanguage = true;
    public $multiple = true;              // Allow the plugin to support multiple instances
    public $name = 'square';              // Internal name of the plugin (matches plugin entry file)
    public $doc_form = 'square';          // Generate the help link in the backend configuration          
    public $use_cache = false;            
    public $menu = [];
    
    public $environments = array(
        'test' => 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links',
        'prod' => 'https://connect.squareup.com/v2/online-checkout/payment-links'
    );

    var $pluginConfig = array(
        'application_id' => array('PLG_HIKASHOP_PAYMENT_SQUARE_APPLICATION_ID', 'input'),
        'access_token' => array('PLG_HIKASHOP_PAYMENT_SQUARE_ACCESS_TOKEN', 'password', ''),
        'location_id' => array('PLG_HIKASHOP_PAYMENT_SQUARE_LOCATION_ID', 'input'),
        'webhook_url_info' => array('PLG_HIKASHOP_PAYMENT_SQUARE_WEBHOOK_URL_INFO', 'html', ''),
        'webhook_signature_key' => array('PLG_HIKASHOP_PAYMENT_SQUARE_WEBHOOK_SIGNATURE_KEY', 'password', ''),
        'payment_mode' => array('PLG_HIKASHOP_PAYMENT_SQUARE_PAYMENT_MODE', 'list', array(
            'true' => 'PLG_HIKASHOP_PAYMENT_SQUARE_MODE_TEST', 
            'false' => 'PLG_HIKASHOP_PAYMENT_SQUARE_MODE_LIVE'
        )),
        'items_details' => array('PLG_HIKASHOP_PAYMENT_SQUARE_ITEMS_DETAILS', 'boolean', '0'),
        'create_customer' => array('PLG_HIKASHOP_PAYMENT_SQUARE_CREATE_CUSTOMER', 'boolean', '1'),
        'debug' => array('PLG_HIKASHOP_PAYMENT_SQUARE_DEBUG', 'boolean', '0'),
        'invalid_status' => array('PLG_HIKASHOP_PAYMENT_SQUARE_INVALID_STATUS', 'orderstatus'),
        'verified_status' => array('PLG_HIKASHOP_PAYMENT_SQUARE_VERIFIED_STATUS', 'orderstatus')
    );

    public function __construct(&$subject, $config)
    {
        return parent::__construct($subject, $config);
    }

    protected function init()
    {
        static $init = null;
        if ($init !== null) return $init;

        $app = Factory::getApplication();
        
        if (!function_exists('curl_version')) {
            if ($app->isClient('administrator')) {
                $app->enqueueMessage('cURL is not enabled on your server. This plugin requires cURL to connect to Square.', 'error');
            }
            return false;
        }

        $init = true;
        return $init;
    }

    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_NAME');
        $element->payment_description = Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_DESC');
        $element->payment_images = 'square';
        
        $element->payment_params->payment_mode = 'true';
        $element->payment_params->items_details = 0;
        $element->payment_params->create_customer = 1;
        $element->payment_params->debug = 0;
        $element->payment_params->invalid_status = 'cancelled';
        $element->payment_params->verified_status = 'confirmed';
    }

    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);
        $app = Factory::getApplication();
        
        if ($app->isClient('administrator')) {
            $this->init();
            
            // Render the exact Webhook URL the merchant needs to paste into the Square Developer Dashboard
            $webhook_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . $this->name;
            $this->pluginConfig['webhook_url_info'][2] = '<strong>' . htmlspecialchars($webhook_url, ENT_QUOTES, 'UTF-8') . '</strong><br/><small>' .
                Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_WEBHOOK_INSTRUCTIONS') . '</small>';
            $langs = LanguageHelper::getLanguages();
            if (!empty($langs)) {
                $this->menu = AbstractMenu::getInstance('site');
                foreach ($langs as $lang) {
                    $this->pluginConfig['redirect_url_' . $lang->lang_code] = array(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_REDIRECT_URL') . ' ' . $lang->lang_code, array('menu_itemid', $lang->lang_code));
                }
            }
        }
    }

    public function pluginConfigDisplay($fieldType, $data, $type, $paramsType, $key, $element)
    {
        if (!is_array($fieldType)) {
            if ($fieldType == 'password') {
                $map = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
                $value = (isset($element->$paramsType->$key) ? $element->$paramsType->$key : '');
                return '<input type="password" name="' . $map . '" value="' . $value . '" />';
            }
        } else {
            if ($fieldType[0] == 'menu_itemid') {
                $lang_code = $fieldType[1];
                $menuItems = $this->menu->getItems(array('language', 'type'), array(array('*', $lang_code), 'component'));
                if (!empty($menuItems)) {
                    $groups = [];
                    $group = array('value' => '', 'text' => '', 'items' => []);
                    $option = new stdClass; 
                    $option->value = ''; 
                    $option->text = Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_REDIRECT_URL_DEFAULT');
                    $group['items'][] = $option; $groups[] = $group;
                    
                    foreach ($menuItems as $menuItem) {
                        if (!isset($groups[$menuItem->menutype]) || !is_array($groups[$menuItem->menutype])) {
                            $groups[$menuItem->menutype] = array('value' => '', 'text' => $menuItem->menutype, 'items' => []);
                        }
                        $option = new stdClass; $option->value = $menuItem->id; $option->text = $menuItem->title;
                        $groups[$menuItem->menutype]['items'][] = $option;
                    }
                }
                $map = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
                $value = (isset($element->$paramsType->$key) ? $element->$paramsType->$key : '');
                return HTMLHelper::_('select.groupedlist', $groups, $map, array('list.select' => $value, 'list.attr' => 'class=""'));
            }
        }
    }

    public function onPaymentDisplay(&$order, &$methods, &$usable_methods)
    {
        if (!$this->init()) return false;
        return parent::onPaymentDisplay($order, $methods, $usable_methods);
    }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);
        $app = Factory::getApplication();
        
        $this->order = $order;
        $this->loadPaymentParams($order);
        
        $isTest = ($this->payment_params->payment_mode === 'true');
        $env = $isTest ? 'test' : 'prod';
        $endpoint = $this->environments[$env];

        $lang = Factory::getLanguage();
        $lang_code = $lang->getTag();
        $itemId = $app->getMenu()->getActive() ? $app->getMenu()->getActive()->id : 0;
        
        // This URL purely returns the user to HikaShop's thank you page. It does NOT confirm the order.
        $success_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . $order->order_id . '&Itemid=' . $itemId;
        
        $currencyClass = hikashop_get('class.currency');
        $currency = $currencyClass->get($order->cart->full_total->prices[0]->price_currency_id);
        $currencyCode = strtoupper($currency->currency_code);

        $lineItems = [];
        $totalAmount = round($order->cart->full_total->prices[0]->price_value_with_tax, 2) * 100;

        if ($this->payment_params->items_details == '1') {
            foreach ($order->cart->products as $product) {
                $amount = round($product->order_product_price + @$product->order_product_tax, 2) * 100;
                $lineItems[] = [
                    'name' => strip_tags($product->order_product_name),
                    'quantity' => (string)$product->order_product_quantity,
                    'base_price_money' => [
                        'amount' => (int)$amount,
                        'currency' => $currencyCode
                    ]
                ];
            }
        } else {
            $lineItems[] = [
                'name' => 'Order #' . $order->order_number,
                'quantity' => '1',
                'base_price_money' => [
                    'amount' => (int)$totalAmount,
                    'currency' => $currencyCode
                ]
            ];
        }

        // Persistent Idempotency Key bound to the order ID and cart amount.
        // If the user's cart total changes, a new key is generated to prevent Square 409 Conflict errors.
        $idempotency_hash = md5($order->order_id . '_' . $totalAmount);
        
        if (empty($order->order_payment_params->square_idempotency) || @$order->order_payment_params->square_idempotency_hash !== $idempotency_hash) {
            $idempotency_key = uniqid('hika_' . $order->order_id . '_', true);
            
            $updateOrder = new stdClass();
            $updateOrder->order_id = $order->order_id;
            $updateOrder->order_payment_params = empty($order->order_payment_params) ? new stdClass() : clone $order->order_payment_params;
            
            $updateOrder->order_payment_params->square_idempotency = $idempotency_key;
            $updateOrder->order_payment_params->square_idempotency_hash = $idempotency_hash;
            hikashop_get('class.order')->save($updateOrder);
            
            $order->order_payment_params->square_idempotency = $idempotency_key;
            $order->order_payment_params->square_idempotency_hash = $idempotency_hash;
        }

        $payload = [
            'idempotency_key' => $order->order_payment_params->square_idempotency,
            'order' => [
                'location_id' => $this->payment_params->location_id,
                'reference_id' => (string)$order->order_id,
                'line_items' => $lineItems
            ],
            'checkout_options' => [
                'redirect_url' => $success_url
            ]
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        // Strictly enforce HTTPS and SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Add cURL timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Square-Version: 2026-07-15',
            'Authorization: Bearer ' . $this->payment_params->access_token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Handle completely failed connections or non-2xx HTTP responses cleanly
        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Square API Connection Error. HTTP Code: $httpCode | cURL Error: $curlError | Response: $response");
            }
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_GENERAL_ERROR'), 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout');
            return false;
        }

        $responseData = json_decode($response, true);

        if (isset($responseData['payment_link']['url'])) {
            $this->checkout_url = $responseData['payment_link']['url'];

            $updateOrder = new stdClass();
            $updateOrder->order_id = $order->order_id;

            $updateOrder->order_payment_params =
                empty($order->order_payment_params)
                    ? new stdClass()
                    : clone $order->order_payment_params;

            $updateOrder->order_payment_params->square_payment_link_id =
                $responseData['payment_link']['id'];

            if (!empty($responseData['payment_link']['order_id'])) {
                $updateOrder->order_payment_params->square_order_id =
                    $responseData['payment_link']['order_id'];
            }

            hikashop_get('class.order')->save($updateOrder);

            $this->removeCart = true;

            $app->enqueueMessage(
                Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_PLEASE_WAIT')
            );

            return $this->showPage('end');
        } else {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Square Link Generation Failed. Response: " . print_r($responseData, true));
            }
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_GENERAL_ERROR'), 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout');
            return false;
        }
    }

    /**
     * Retrieve a Square Order using the Square Orders API.
     *
     * @param string $squareOrderId
     * @return array|false
     */
    private function getSquareOrder($squareOrderId)
    {
        $isTest = ($this->payment_params->payment_mode === 'true');

        $baseUrl = $isTest
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';

        $url = $baseUrl . '/v2/orders/' . rawurlencode($squareOrderId);

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Square-Version: 2026-07-15',
            'Authorization: Bearer ' . $this->payment_params->access_token,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug(
                    'Square Get Order failed. ' .
                    'Order: ' . $squareOrderId .
                    ' | HTTP: ' . $httpCode .
                    ' | cURL: ' . $curlError .
                    ' | Response: ' . $response
                );
            }

            return false;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || empty($data['order'])) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug(
                    'Square Get Order returned invalid response for ' .
                    $squareOrderId
                );
            }

            return false;
        }

        return $data['order'];
    }

    /**
     * Dedicated Webhook Endpoint
     * Processes background notifications securely from Square.
     */
    public function onPaymentNotification(&$statuses)
    {
        $app = Factory::getApplication();
        
        // First ensure it is a valid POST webhook
        if ($app->input->getMethod() !== 'POST') {
            http_response_code(405);
            hikashop_writeToLog('Square plugin: Invalid request method');
            exit();
        }

        $body = file_get_contents('php://input');
        if (empty($body)) {
            http_response_code(400);
            hikashop_writeToLog('Square plugin: Empty webhook payload');
            exit();
        }

        $payload = json_decode($body, true);
                     
        // If it's not a payment event, return 200 OK immediately so Square stops retrying.
        // We don't need to verify signatures for events we are ignoring anyway.
        if (!isset($payload['type']) || !in_array($payload['type'], ['payment.updated', 'payment.created'])) {
            http_response_code(200);
            exit(); 
        }
        
        // Early validation to extract Location ID
        if (!isset($payload['data']['object']['payment']['location_id'])) {
            http_response_code(400);
            hikashop_writeToLog('Square plugin: Invalid webhook payload structure or missing location_id');
            exit();
        }

        $location_id = $payload['data']['object']['payment']['location_id'];
        
        // Load the correct payment parameters for the plugin
        // $db = Factory::getDbo(); method is deprecated
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        // $db = Factory::getContainer()->get('DatabaseDriver'); is supposed to work as well apparently
        $query = $db->getQuery(true)
            ->select([$db->quoteName('payment_id'),$db->quoteName('payment_params')])
            ->from($db->quoteName('#__hikashop_payment'))
            ->where($db->quoteName('payment_type') . ' = ' . $db->quote('square'))
            ->where($db->quoteName('payment_published') . ' = 1');
        $db->setQuery($query);
        
        $methods = $db->loadObjectList();
        
        $methodFound = false;
        
        if (!empty($methods)) {
            foreach ($methods as $method) {
                $params = null;
                if (!empty($method->payment_params)) {
                    // Handle both serialized (older HikaShop) and JSON (newer HikaShop)
                    $params = (strpos(trim($method->payment_params), '{') === 0) ? json_decode($method->payment_params) : unserialize($method->payment_params);
                }

                if (!empty($params) && isset($params->location_id) && $params->location_id === $location_id) {
                    $this->payment_params = $params;
                    $methodFound = true;
                    break;
                }
            }
        }

        if (!$methodFound) {
            hikashop_writeToLog('Could not find Square payment method for location ID ' . $location_id);
            http_response_code(200);
            exit();
        }

        if (empty($this->payment_params)) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Webhook Error: Square payment method found for location ID $location_id, but payment parameters are empty");
            }
            hikashop_writeToLog('Square payment method found, but payment parameters are empty');
            http_response_code(200);
            exit();
        }

        // Validate the Webhook Signature securely now that the key is populated
        $signature = $app->input->server->getString('HTTP_X_SQUARE_HMACSHA256_SIGNATURE');
        $signatureKey = $this->payment_params->webhook_signature_key;
        
        if (!empty($signatureKey)) {
            $notificationUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . $this->name;
            $stringToSign = $notificationUrl . $body;
            $expectedHash = base64_encode(hash_hmac('sha256', $stringToSign, $signatureKey, true));
            
            if (!hash_equals($expectedHash, $signature)) {
                if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                    $this->logSquareDebug('Webhook verification failed: Signature mismatch.');
                }
                http_response_code(401);
                if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                    $this->logSquareDebug('Signature verification failed');
                }
                exit();
            }
        }

        // Process Event Types
        if (!isset($payload['type']) || !in_array($payload['type'], ['payment.updated', 'payment.created'])) {
            http_response_code(200);
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $eventType = $payload['type'] ?? 'undefined';
                $this->logSquareDebug('Ignored event type: ' . $eventType);
            }
            exit(); // Respond 200 OK for irrelevant webhooks
        }

        if (!isset($payload['data']['object']['payment'])) {
            http_response_code(400);
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug('Invalid webhook payload structure');
            }
            exit();
        }

        $eventId = isset($payload['id']) ? $payload['id'] : 'unknown';
        $paymentData = $payload['data']['object']['payment'];
        $squarePaymentId = isset($paymentData['id']) ? $paymentData['id'] : 'unknown';
        
        // We only fulfill on COMPLETED status
        if ($paymentData['status'] !== 'COMPLETED') {
            http_response_code(200);
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug('Payment not completed');
            }
            exit();
        }

        $squareOrderId = isset($paymentData['order_id']) ? $paymentData['order_id'] : '';
        
        if (empty($squareOrderId)) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug('No order_id found in payment ' . $squarePaymentId);
            }
            http_response_code(400);
            exit();
        }

        // Fetch the order from Square (authentication and sandbox mode will work)
        $squareOrder = $this->getSquareOrder($squareOrderId);
        
        if (empty($squareOrder) || empty($squareOrder['reference_id'])) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug('Square Order ' . $squareOrderId . ' not found or lacks reference_id.');
            }
            http_response_code(404);
            exit();
        }

        // Verify the HikaShop Database Order
        $order_id = (int)$squareOrder['reference_id'];
        $dbOrder = $this->getOrder($order_id);
        
        if (empty($dbOrder)) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Order ID $order_id not found for webhook.");
            }
            http_response_code(404);
            exit();
        }

        // Parameters are already loaded, but we still need to load HikaShop order logic
        $this->loadOrderData($dbOrder);

        // Validate Currency
        $currencyClass = hikashop_get('class.currency');
        $currency = $currencyClass->get($dbOrder->order_currency_id);
        $expectedCurrency = strtoupper($currency->currency_code);
        $actualCurrency = isset($paymentData['amount_money']['currency']) ? strtoupper($paymentData['amount_money']['currency']) : '';
        
        if ($expectedCurrency !== $actualCurrency) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Currency mismatch on Order $order_id. Expected: $expectedCurrency, Got: $actualCurrency");
            }
            http_response_code(400);
            exit();
        }

        // Calculate expected amount
        $expectedAmount = (int) round(((float) $dbOrder->order_full_price) * 100);

        $actualAmount = isset($paymentData['amount_money']['amount'])
            ? (int) $paymentData['amount_money']['amount']
            : 0;

        if ($expectedAmount !== $actualAmount) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug(
                    'Amount mismatch for order ' . $order_id .
                    '. Expected: ' . $expectedAmount .
                    ' | Received: ' . $actualAmount .
                    ' | Square Payment: ' . $squarePaymentId
                );
            }

            http_response_code(400);
            exit();
        }

        // Check order status for idempotency
        if ($dbOrder->order_status === $this->payment_params->verified_status) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug('Order ' . $order_id . ' already has verified status. Payment: ' . $squarePaymentId);
            }
            http_response_code(200);
            exit();
        }

        // Confirm the HikaShop order
        $history = new stdClass();
        $history->notified = 1;
        $history->amount = number_format($actualAmount / 100, 2, '.', '') . ' ' . $actualCurrency;
        $history->data = 'Square Payment ID: ' . $squarePaymentId . "\nSquare Order ID: " . $squareOrderId . "\nSquare Event ID: " . $eventId;

        $this->modifyOrder($order_id, $this->payment_params->verified_status, $history, true);

        // Tell Square the webhook was successfully processed
        http_response_code(200);
        if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
            $this->logSquareDebug('Order ' . $order_id . ' successfully confirmed. Square Payment: ' . $squarePaymentId . ' | Square Order: ' . $squareOrderId . ' | Event: ' . $eventId);
        }
        exit();
    }

    /**
     * Sanitizes and logs data for debugging purposes.
     */
    private function logSquareDebug($message)
    {
        if (!empty($this->payment_params->access_token)) {
            $message = str_replace($this->payment_params->access_token, '***TOKEN_REDACTED***', $message);
        }
        if (!empty($this->payment_params->webhook_signature_key)) {
            $message = str_replace($this->payment_params->webhook_signature_key, '***SIG_KEY_REDACTED***', $message);
        }

        hikashop_writeToLog('Square Payment: ' . $message);
    }
}