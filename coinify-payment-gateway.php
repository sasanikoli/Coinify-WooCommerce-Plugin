<?php
/**
 * Plugin Name: Coinify Payment Gateway
 * Description: Плагин для подключения Coinify к WooCommerce через Payment Intent API.
 * Version: 1.1
 * Author: Manuk
 */

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

// Подключение Guzzle через Composer
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/coinify-blocks.php';

use GuzzleHttp\Client;

// Добавляем новый метод оплаты Coinify в список методов WooCommerce
add_filter('woocommerce_payment_gateways', 'add_coinify_gateway_class');
function add_coinify_gateway_class($gateways)
{
    $gateways[] = 'WC_Coinify_Gateway';
    return $gateways;
}

// Инициализация класса платежного шлюза Coinify
add_action('plugins_loaded', 'init_coinify_gateway_class');
function init_coinify_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Coinify_Gateway extends WC_Payment_Gateway
    {
        private $api_key;
        private $api_url;

        public function __construct()
        {
            $this->id = 'coinify';
            $this->icon = ''; // URL иконки, если нужно
            $this->has_fields = false;
            $this->method_title = 'Coinify Payment Gateway';
            $this->method_description = 'Allows you to accept cryptocurrency via Coinify API.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->api_key = $this->get_option('api_key');
            $this->api_url = 'https://api.payment.sandbox.coinify.com';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_coinify_payment_webhook', array($this, 'coinify_payment_webhook_handler'));

            // Обработка возвратов
            add_action('woocommerce_order_refunded', array($this, 'process_refund'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Coinify Payment Gateway',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Crypto Payment (Coinify)',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description that the customer will see on your checkout.',
                    'default' => 'Pay with cryptocurrency through Coinify.'
                ),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'Enter your Coinify API Key here.',
                    'default' => ''
                )
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $payment_intent = $this->create_payment_intent($order);

            if ($payment_intent && isset($payment_intent->paymentWindowUrl)) {
                return array(
                    'result' => 'success',
                    'redirect' => $payment_intent->paymentWindowUrl
                );
            } else {
                wc_add_notice('Error creating payment via Coinify.', 'error');
                return;
            }
        }

        private function create_payment_intent($order)
        {
            $client = new Client();
            $body = json_encode(array(
                'amount' => (string)$order->get_total(),
                'currency' => $order->get_currency(),
                'orderId' => (string)$order->get_id(),
                'customerId' => (string)$order->get_customer_id(),
                'customerEmail' => $order->get_billing_email(),
                'successUrl' => $this->get_return_url($order),
                'failureUrl' => wc_get_checkout_url(),
                'pluginIdentifier' => 'WooCommerce-Coinify-Gateway',
            ));

            try {
                $response = $client->request('POST', $this->api_url . '/v1/payment-intents', [
                    'body' => $body,
                    'headers' => [
                        'X-API-KEY' => $this->api_key,
                        'Content-Type' => 'application/json',
                    ],
                ]);

                return json_decode($response->getBody());
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                wc_add_notice('Error connecting to Coinify API: ' . $e->getMessage(), 'error');
                return null;
            }
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            $client = new Client();
            $body = json_encode(array(
                'amount' => (string)$amount,
                'currency' => $order->get_currency(),
                'reason' => $reason,
            ));

            try {
                $response = $client->request('POST', $this->api_url . "/v1/payment-intents/{$order->get_transaction_id()}/refund", [
                    'body' => $body,
                    'headers' => [
                        'X-API-KEY' => $this->api_key,
                        'Content-Type' => 'application/json',
                    ],
                ]);
                $order->add_order_note(
                    __('Refund processed successfully via Coinify.', 'woocommerce')
                );
                return true;
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice('Error processing refund via Coinify: ' . $e->getMessage(), 'error');
                } else {
                    error_log('Error processing refund via Coinify: ' . $e->getMessage());
                }
                return false;
            }
        }

        public function coinify_payment_webhook_handler()
        {
            $shared_secret = 'c8e64285-29f0-412c-989c-1e2054a26561';
            $body = file_get_contents('php://input');
            $decoded_body = json_decode(wp_unslash($body), true);
            $signature_header = $_SERVER['HTTP_X_COINIFY_WEBHOOK_SIGNATURE'] ?? '';

            if (!$this->verify_coinify_webhook_signature($body, $signature_header, $shared_secret)) {
                error_log('Invalid Coinify webhook signature.');
                http_response_code(400);
                exit;
            }

            if ($decoded_body && isset($decoded_body['event'])) {
                $event = $decoded_body['event'];
                $data = $decoded_body['data'];

                if (!empty($data['orderId'])) {
                    $order_id = $data['orderId'];
                    $order = wc_get_order($order_id);

                    if ($order) {
                        switch ($event) {
                            case 'payment_complete':
                                $order->payment_complete();
                                $order->add_order_note(
                                    __('Payment via Coinify completed successfully.', 'woocommerce')
                                );
                                break;

                            case 'payment_cancelled':
                                $order->update_status('cancelled', __('The payment was cancelled.', 'woocommerce'));
                                break;

                            case 'payment_failed':
                                $order->update_status('failed', __('The payment failed via Coinify.', 'woocommerce'));
                                break;

                            case 'payment-intent.refund.completed':
                                $order->update_status('refunded', __('The refund was completed via Coinify.', 'woocommerce'));
                                break;

                            default:
                                $order->add_order_note(
                                    sprintf(__('Received unknown event "%s" from Coinify.', 'woocommerce'), $event)
                                );
                                error_log('Unknown event from Coinify: ' . print_r($event, true));
                                break;
                        }
                    } else {
                        error_log('Order not found for received webhook: ' . print_r($data, true));
                    }
                }
            }

            http_response_code(200);
            exit;
        }

        private function verify_coinify_webhook_signature($body, $signature_header, $shared_secret)
        {
            $calculated_signature = hash_hmac('sha256', $body, $shared_secret);
            return hash_equals($calculated_signature, strtolower($signature_header));
        }
    }
}