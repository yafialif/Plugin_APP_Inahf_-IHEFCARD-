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
            $result = $midtrans->process_payment($order_id);

            return [
                "success" => true,
                "order_id" => $order_id,
                "payment_url" => $result['redirect']
            ];

        } catch (\Exception $e) {

            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);

        }
    }
}