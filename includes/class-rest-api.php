<?php

namespace ContentAplikasi;

use WP_REST_Response;

class RestAPI
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('package/v1', '/create-order', [
            'methods'  => 'POST',
            'callback' => [$this, 'custom_create_order_midtrans'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function custom_create_order_midtrans($request)
    {
        $data = $request->get_json_params();

        if(!class_exists('WooCommerce')){
            return new WP_REST_Response([
                'error' => 'WooCommerce not active'
            ], 500);
        }

        try {

            // Create order
            $order = wc_create_order([
                'customer_id' => $data['customer_id']
            ]);

            // Add products
            foreach ($data['line_items'] as $item) {

                $product = wc_get_product($item['product_id']);

                if(!$product){
                    return new WP_REST_Response([
                        'error' => 'Product not found'
                    ], 404);
                }

                $order->add_product($product, $item['quantity']);
            }

            // Billing
            $order->set_address($data['billing'], 'billing');

            // Payment
            $order->set_payment_method($data['payment_method']);
            $order->set_payment_method_title($data['payment_method_title']);

            $order->calculate_totals();
            $order->save();

            $order_id = $order->get_id();
            $order_key = $order->get_order_key();

            // Load gateways
            WC()->payment_gateways();
            $gateways = WC()->payment_gateways->payment_gateways();

            if(!isset($gateways['midtrans'])){
                return new WP_REST_Response([
                    'error' => 'Midtrans gateway not found'
                ], 500);
            }

            $midtrans = $gateways['midtrans'];

            // Create payment
            $params = [
                "transaction_details" => [
                    "order_id" => (string)$order->get_id(),
                    "gross_amount" => (int)$order->get_total()
                ],
                "customer_details" => [
                    "first_name" => $data['billing']['first_name'],
                    "last_name" => $data['billing']['last_name'],
                    "email" => $data['billing']['email'],
                    "phone" => $data['billing']['phone']
                ],
                "callbacks" => [
                    "finish" => "https://ihefcard.inahfcarmet.org/checkout/order-received/".$order_id."/?key=".$order_key,
                    "unfinish" => "https://ihefcard.inahfcarmet.org/checkout/order-pay/".$order_id."/?pay_for_order=true&key=".$order_key,
                    "error" => "https://ihefcard.inahfcarmet.org/checkout/order-pay/".$order_id."/?pay_for_order=true&key=".$order_key
                ]
            ];

            $snap = \WC_Midtrans_API::createSnapTransactionHandleDuplicate(
                $order,
                $params,
                'midtrans'
            );

            $payment_url = $snap->redirect_url;

            return [
                "success" => true,
                "order_id" => $order->get_id(),
                "payment_url" => $payment_url
            ];

        } catch (\Exception $e) {

            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);

        }
    }
}