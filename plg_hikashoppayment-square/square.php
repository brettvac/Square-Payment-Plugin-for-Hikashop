<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_hikashoppayment_square
 * @version     1.0
 * @license     GNU General Public License version 3
 */
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Http\HttpFactory;

class plgHikashoppaymentSquare extends hikashopPaymentPlugin
{
    protected $autoloadLanguage = true;
    
    public $multiple = true;              // Allow the plugin to support multiple instances
    public $name = 'square';              // Internal name of the plugin (matches plugin entry file)
    public $doc_form = 'square';          // Generate the help link in the backend configuration          
    public $use_cache = false;            
    
    public $environments = array(
        'test' => 'https://connect.squareupsandbox.com/v2/online-checkout/payment-links',
        'prod' => 'https://connect.squareup.com/v2/online-checkout/payment-links'
    );
    
    var $squareLocations = array();
    
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    ];

    var $pluginConfig = array(
        'application_id' => array('PLG_HIKASHOP_PAYMENT_SQUARE_APPLICATION_ID', 'input'),
        'access_token' => array('PLG_HIKASHOP_PAYMENT_SQUARE_ACCESS_TOKEN', 'password', ''),
        'location_id' => array('PLG_HIKASHOP_PAYMENT_SQUARE_LOCATION_ID', 'square_location', ''),
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

    protected function init()
    {
        static $init = null;
        if ($init !== null) return $init;

        $app = Factory::getApplication();

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

            $token = isset($element->payment_params->access_token) ? trim($element->payment_params->access_token) : '';
            $isTest = (isset($element->payment_params->payment_mode) && $element->payment_params->payment_mode === 'true');

            if (!empty($token)) {
                $locationResult = $this->getSquareLocations($token, $isTest);

                if (!empty($locationResult['locations'])) {
                    $this->squareLocations = $locationResult['locations'];
                }
            }
            
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

    /**
     * Fired when the payment method is being saved in the backend
     * Used here to securely validate the saved Square keys and Webhook subscriptions via API.
     */
    public function onPaymentConfigurationSave(&$element)
    {
        $app = Factory::getApplication();

        if (!isset($element->payment_params) || !is_object($element->payment_params)) {
            return;
        }

        $params = $element->payment_params;

        $isTest = (isset($params->payment_mode) && $params->payment_mode === 'true');
        $baseUrl = $isTest ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
        
        $token = isset($params->access_token) ? trim($params->access_token) : '';
        $locationId = isset($params->location_id) ? trim($params->location_id) : '';
        $appId = isset($params->application_id) ? trim($params->application_id) : '';

        // Validate Access Token and Application ID Keys
        if (empty($token) || empty($appId)) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_VALIDATION_SKIPPED'), 'warning');
            return;
        }

        /*
         * Retrieve Square locations and automatically populate the Location ID
         * when no Location ID has been saved yet.
         */
        $locationResult = $this->getSquareLocations($token, $isTest);

        if ($locationResult['http_code'] === 401) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_VALIDATION_WARNING'), 'warning');
            return; // No point in continuing to webhooks if unauthorized
        } elseif ($locationResult['http_code'] >= 200 && $locationResult['http_code'] < 300) {
            $locations = $locationResult['locations'];

            if (!empty($locations)) {
                $locationFound = false;

                foreach ($locations as $loc) {
                    if (isset($loc['id']) && $loc['id'] === $locationId) {
                        $locationFound = true;
                        break;
                    }
                }

                /*
                 * Automatically select the oldest ACTIVE location when
                 * no Location ID has been provided yet.
                 */
                if (empty($locationId)) {
                    $activeLocations = array();

                    foreach ($locations as $loc) {
                        if (
                            isset($loc['id']) &&
                            isset($loc['status']) &&
                            $loc['status'] === 'ACTIVE'
                        ) {
                            $activeLocations[] = $loc;
                        }
                    }

                    if (!empty($activeLocations)) {
                        usort($activeLocations, function($a, $b) {
                            $aCreated = isset($a['created_at']) ? $a['created_at'] : '';
                            $bCreated = isset($b['created_at']) ? $b['created_at'] : '';

                            return strcmp($aCreated, $bCreated);
                        });

                        $locationId = $activeLocations[0]['id'];
                        $params->location_id = $locationId;
                        $locationFound = true;

                        $app->enqueueMessage(
                            Text::sprintf(
                                'PLG_HIKASHOP_PAYMENT_SQUARE_LOCATION_ID_AUTOMATIC',
                                $locationId
                            ),
                            'message'
                        );
                    }
                } elseif (!$locationFound) {
                    $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_LOCATION_ID_MISMATCH'), 'warning');
                }
            } else {
                $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_NO_LOCATIONS'), 'warning');
            }
        } else {
            $app->enqueueMessage(Text::sprintf('PLG_HIKASHOP_PAYMENT_SQUARE_CREDENTIALS_CONNECTION_WARNING', $locationResult['http_code']), 'warning');
        }

        // Validate Webhook Subscription Setup
        $expectedUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=' . $this->name;
        
        $http = HttpFactory::getHttp(['timeout' => 30]);
        $headers = [
            'Square-Version' => '2026-07-15',
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ];

        try {
            $response = $http->get($baseUrl . '/v2/webhooks/subscriptions', $headers);
            $webHttpCode = $response->code;
            $webResponse = $response->body;
        } catch (\Throwable $e) {
            $webHttpCode = 0;
            $webResponse = $e->getMessage();
        }

        if ($webHttpCode >= 200 && $webHttpCode < 300) {
            $webData = json_decode($webResponse, true);
            $webhookFound = false;
            
            if (!empty($webData['subscriptions'])) {
                foreach ($webData['subscriptions'] as $sub) {
                    if (isset($sub['notification_url']) && $sub['notification_url'] === $expectedUrl) {
                        $webhookFound = true;
                        break;
                    }
                }
            }
            
            if (!$webhookFound) {
                $app->enqueueMessage(Text::sprintf('PLG_HIKASHOP_PAYMENT_SQUARE_WEBHOOK_WARNING', htmlspecialchars($expectedUrl, ENT_QUOTES, 'UTF-8')), 'warning');
            }
        } elseif ($webHttpCode !== 401) {
            $app->enqueueMessage(Text::sprintf('PLG_HIKASHOP_PAYMENT_SQUARE_WEBHOOK_CONNECTION_WARNING', $webHttpCode), 'warning');
        }
    }
 

    public function pluginConfigDisplay($fieldType, $data, $type, $paramsType, $key, $element)
    {
        if (!is_array($fieldType)) {
            if ($fieldType == 'password') {
                $map = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
                $value = (isset($element->$paramsType->$key) ? $element->$paramsType->$key : '');
                return '<input type="password" name="' . $map . '" value="' . $value . '" />';
            } elseif ($fieldType == 'square_location') {
                $map = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
                $value = (isset($element->$paramsType->$key) ? $element->$paramsType->$key : '');

                $options = array();

                if (!empty($this->squareLocations)) {
                    foreach ($this->squareLocations as $location) {
                        if (!isset($location['id'])) {
                            continue;
                        }

                        $name = isset($location['name']) ? $location['name'] : $location['id'];
                        $status = isset($location['status']) ? $location['status'] : '';

                        if (!empty($status) && $status !== 'ACTIVE') {
                            $name .= ' (' . $status . ')';
                        }

                        $option = new stdClass;
                        $option->value = $location['id'];
                        $option->text = $name . ' — ' . $location['id'];
                        $options[] = $option;
                    }
                }

                if (empty($options)) {
                    $option = new stdClass;
                    $option->value = '';
                    $option->text = Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_LOCATION_ID_NO_LOCATIONS');
                    $options[] = $option;
                }

                return HTMLHelper::_('select.genericlist', $options, $map, array(
                    'list.select' => $value,
                    'list.attr' => 'class=""'
                ));
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
    
    /**
     * Retrieve the Square locations associated with the Access Token.
     */
    protected function getSquareLocations($token, $isTest)
    {
        $baseUrl = $isTest ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

        $http = HttpFactory::getHttp(['timeout' => 30]);
        $headers = [
            'Square-Version' => '2026-07-15',
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ];

        try {
            $response = $http->get($baseUrl . '/v2/locations', $headers);
            $httpCode = $response->code;
            $body = $response->body;
        } catch (\Throwable $e) {
            return array(
                'locations' => array(),
                'http_code' => 0,
                'response' => $e->getMessage()
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return array(
                'locations' => array(),
                'http_code' => $httpCode,
                'response' => $body
            );
        }

        $data = json_decode($body, true);

        return array(
            'locations' => !empty($data['locations']) ? $data['locations'] : array(),
            'http_code' => $httpCode,
            'response' => $body
        );
    }

    public function onPaymentDisplay(&$order, &$methods, &$usable_methods)
        {
            if (!$this->init()) return false;
            
            $result = parent::onPaymentDisplay($order, $methods, $usable_methods);
            
            // Graceful Frontend Disabling if credentials are empty
            if (empty($this->payment_params->access_token) || empty($this->payment_params->location_id) || empty($this->payment_params->application_id)) {
                if (isset($methods[$this->name])) {
                    // Add a warning message below the payment name
                    $methods[$this->name]->custom_html = '<div style="opacity: 0.7; color: #cc0000; font-size: 0.9em; margin-top: 5px;">' . 
                        Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_VALIDATION_SKIPPED') . '</div>';
                    
                    // Inject JS to disable the specific radio button
                    $doc = Factory::getDocument();
                    $doc->addScriptDeclaration("
                        document.addEventListener('DOMContentLoaded', function() {
                            var radios = document.querySelectorAll('input[type=\"radio\"][name=\"payment_method\"][value^=\"" . $this->name . "_\"]');
                            for (var i = 0; i < radios.length; i++) {
                                radios[i].disabled = true;
                                var container = radios[i].closest('.hikashop_checkout_payment');
                                if (container) { container.style.opacity = '0.5'; }
                            }
                        });
                    ");
                }
            }
            
            return $result;
        }

    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);
        $app = Factory::getApplication();
        $session = Factory::getSession();
        
        $this->order = $order;
        $this->loadPaymentParams($order);
        
        //Page Refresh Protection (Session Caching)
        $sessionKey = 'square_checkout_url_' . $order->order_id;
        if ($session->has($sessionKey)) {
            $this->checkout_url = $session->get($sessionKey);
            return $this->showPage('end');
        }
        
        $isTest = ($this->payment_params->payment_mode === 'true');
        $env = $isTest ? 'test' : 'prod';
        $endpoint = $this->environments[$env];

        // Get the customer's current Joomla language.
        $lang = Factory::getLanguage();
        $lang_code = $lang->getTag();

        // Build the language-specific redirect configuration key.
        // Example: redirect_url_en-GB
        $redirectKey = 'redirect_url_' . $lang_code;

        // Retrieve the selected Joomla menu item ID.
        // An empty value means: use the default HikaShop confirmation page.
        $redirectItemId = !empty($this->payment_params->$redirectKey)
            ? (int)$this->payment_params->$redirectKey
            : 0;

        if ($redirectItemId > 0) {

            // A custom redirect page was selected for this language.
            $success_url = HIKASHOP_LIVE
                . 'index.php?Itemid=' . $redirectItemId
                . '&order_id=' . (int)$order->order_id;

        } else {

            // No custom redirect page was selected.
            // Use the standard HikaShop confirmation page.
            $itemId = $app->getMenu()->getActive()
                ? $app->getMenu()->getActive()->id
                : 0;

            $success_url = HIKASHOP_LIVE
                . 'index.php?option=com_hikashop'
                . '&ctrl=checkout'
                . '&task=after_end'
                . '&order_id=' . (int)$order->order_id
                . '&Itemid=' . (int)$itemId;
        }
        
        $currencyClass = hikashop_get('class.currency');
        $currency = $currencyClass->get($order->cart->full_total->prices[0]->price_currency_id);
        $currencyCode = strtoupper($currency->currency_code);

        $lineItems = [];
        // Get the order's total amount, including taxes.
        $totalAmount = (float) $order->cart->full_total->prices[0]->price_value_with_tax;

        // Utilize Zero-Decimal Currency Formatter
        if ($this->payment_params->items_details == '1') {
            foreach ($order->cart->products as $product) {
                $amount = (float) ($product->order_product_price + @$product->order_product_tax);
                $lineItems[] = [
                    'name' => strip_tags((string)$product->order_product_name),
                    'quantity' => (string)$product->order_product_quantity,
                    'base_price_money' => [
                        'amount' => $this->formatAmount($amount, $currencyCode),
                        'currency' => $currencyCode
                    ]
                ];
            }
        } else {
            $lineItems[] = [
                'name' => 'Order #' . $order->order_number,
                'quantity' => '1',
                'base_price_money' => [
                    'amount' => $this->formatAmount($totalAmount, $currencyCode),
                    'currency' => $currencyCode
                ]
            ];
        }

        // Generate an idempotency key based on the order ID and total amount so a changed amount produces a new key.
        $idempotency_hash = md5($order->order_id . '_' . $totalAmount);
        
        if (empty($order->order_payment_params->square_idempotency) || @$order->order_payment_params->square_idempotency_hash !== $idempotency_hash) {
            $idempotency_key = uniqid('hika_' . $order->order_id . '_', true);
            
            $updateOrder = new stdClass();
            $updateOrder->order_id = $order->order_id;
            $updateOrder->order_payment_params = empty($order->order_payment_params) ? new stdClass() : clone $order->order_payment_params;
            
            $updateOrder->order_payment_params->square_idempotency = $idempotency_key;
            $updateOrder->order_payment_params->square_idempotency_hash = $idempotency_hash;
            hikashop_get('class.order')->save($updateOrder);
            
            if (empty($order->order_payment_params)) {
                $order->order_payment_params = new stdClass(); //Initialize a new standard class before assigning properties to it
            }
                    
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

        $http = HttpFactory::getHttp(['timeout' => 30]);
        $headers = [
            'Square-Version' => '2026-07-15',
            'Authorization' => 'Bearer ' . $this->payment_params->access_token,
            'Content-Type' => 'application/json'
        ];

        try {
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $app->enqueueMessage(
                'Unable to encode the Square payment request: ' . $e->getMessage(),
                'error'
            );
            return false;
        }

        try {
            $response = $http->post($endpoint, $jsonPayload, $headers);
            $httpCode = $response->code;
            $body = $response->body;
            $httpError = '';
        } catch (\Throwable $e) {
            $httpCode = 0;
            $body = '';
            $httpError = $e->getMessage();
        }

        // Handle completely failed connections or non-2xx HTTP responses cleanly
        if ($httpError || $httpCode < 200 || $httpCode >= 300) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Square API Connection Error. HTTP Code: $httpCode | HTTP Client Error: $httpError | Response: $body");
            }
            $app->enqueueMessage(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_GENERAL_ERROR'), 'error');
            $app->redirect(HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout');
            return false;
        }

        $responseData = json_decode($body, true);

        if (isset($responseData['payment_link']['url'])) {
            $this->checkout_url = $responseData['payment_link']['url'];

            // Save the newly generated URL to the session cache
            $session->set($sessionKey, $this->checkout_url);

            $updateOrder = new stdClass();
            $updateOrder->order_id = $order->order_id;

            if (empty($order->order_payment_params)) {
                $updateOrder->order_payment_params = new stdClass();
            } else {
                $updateOrder->order_payment_params = clone $order->order_payment_params;
            }

            $updateOrder->order_payment_params->square_payment_link_id =
                $responseData['payment_link']['id'];

            if (!empty($responseData['payment_link']['order_id'])) {
                $updateOrder->order_payment_params->square_order_id =
                    $responseData['payment_link']['order_id'];
            }

            hikashop_get('class.order')->save($updateOrder);

            return $this->showPage('end');
        } else {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug("Square Link Generation Failed. Response: " . print_r($responseData, true));
            }
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

        $http = HttpFactory::getHttp(['timeout' => 30]);
        $headers = [
            'Square-Version' => '2026-07-15',
            'Authorization' => 'Bearer ' . $this->payment_params->access_token,
            'Content-Type' => 'application/json'
        ];

        try {
            $response = $http->get($url, $headers);
            $httpCode = $response->code;
            $body = $response->body;
            $httpError = '';
        } catch (\Throwable $e) {
            $httpCode = 0;
            $body = '';
            $httpError = $e->getMessage();
        }

        if ($httpError || $httpCode < 200 || $httpCode >= 300) {
            if (isset($this->payment_params->debug) && $this->payment_params->debug == '1') {
                $this->logSquareDebug(
                    'Square Get Order failed. ' .
                    'Order: ' . $squareOrderId .
                    ' | HTTP: ' . $httpCode .
                    ' | HTTP Client Error: ' . $httpError .
                    ' | Response: ' . $body
                );
            }

            return false;
        }

        $data = json_decode($body, true);

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

        // Idempotency: skip the webhook if this order has already been marked with the verified payment status
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
    
    /**
     * Formats the amount into the lowest denomination for Square.
     */
    private function formatAmount(float $amount, string $currencyCode): int
    {
        if (in_array(strtoupper($currencyCode), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        
        return (int) round($amount * 100);
    }
}