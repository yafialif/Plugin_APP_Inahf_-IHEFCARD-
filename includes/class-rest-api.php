<?php

namespace ContentAplikasi;

use WP_REST_Response;
use WP_Query;
use WP_Error;


class RestAPI
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route('payment/v1', '/create_order', [
            'methods'  => 'POST',
            'callback' => [$this, 'custom_create_order_midtrans'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('payment/v1', '/change_payment_method', [
            'methods'  => 'POST',
            'callback' => [$this, 'check_status_order'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('payment/v1', '/products', [
        'methods'  => 'GET',
        'callback' => [$this, 'get_products'],
        'permission_callback' => '__return_true'
        ]);

        register_rest_route('ihefcard/content/v1', '/recording', [
        'methods'  => 'GET',
        'callback' => [$this, 'ca_get_recordings'],
        'permission_callback' => '__return_true'
        ]);

        register_rest_route('ihefcard/content/v1', '/faculty_moderator', [
        'methods'  => 'GET',
        'callback' => [$this, 'get_faculty_api'],
        'permission_callback' => '__return_true'
        ]);
    }


    public function get_faculty_api() {

    $args = array(
        'post_type'      => 'ca_faculty',
        'post_status'    => 'publish',
        'posts_per_page' => -1
    );

    $query = new WP_Query($args);

    $faculty = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();

                $faculty[] = array(
                    "name" => get_the_title(),
                    "image_url" => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                    "page_route" => get_permalink()
                );
            }
        }

        wp_reset_postdata();

        $response = array(
            "data" => array(
                "page_title" => "Faculty",
                "page_content" => $faculty
            )
        );

        return rest_ensure_response($response);
    }
    public function ca_get_recordings()
        {

            $args = [
                'post_type' => 'ca_recording',
                'post_status' => 'publish',
                'posts_per_page' => -1
            ];

            $query = new WP_Query($args);

            $items = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {

                    $query->the_post();

                    $items[] = [
                        "titile" => get_the_title(),
                        "description" => wp_strip_all_tags(get_the_content()),
                        "youtube_url" => get_post_meta(get_the_ID(), 'ca_video_url', true),
                        "post_date" => get_the_date('Y-m-d')
                    ];
                }
            }

            wp_reset_postdata();

            return [
                "data" => [
                    "page_title" => "Recording",
                    "page_content" => $items
                ]
            ];
        }

    public function check_status_order($request)
    {
        $params = $request->get_json_params();

        $order_id  = sanitize_text_field($params['order_id'] ?? '');

        $order = wc_get_order($order_id);

        if(!$order){
            return [
                'status' => false,
                'message' => 'Order tidak ditemukan'
            ];
        }

        // cek apakah order sudah dibayar
        if($order->is_paid()){
            return [
                // 'status' => true,
                // 'message' => 'Order sudah dibayar',
                'order_status' => $order->get_status()
            ];
        }

        // cek apakah snap token sudah ada
        $snap_token = $order->get_meta('_midtrans_snap_token');
        $redirect_url = $order->get_meta('_midtrans_redirect_url');

        if($snap_token && $redirect_url){
            return [
                // 'status' => true,
                // 'message' => 'Payment masih pending',
                // 'snap_token' => $snap_token,
                'payment_url' => $redirect_url
            ];
        }

        // jika belum ada snap token → buat transaksi snap baru
        try{

            $params = [
                'transaction_details' => [
                    'order_id' => $order->get_id(),
                    'gross_amount' => (int) $order->get_total(),
                ],
                'customer_details' => [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                ],
            ];

            $snap = \WC_Midtrans_API::createSnapTransactionHandleDuplicate(
                $order,
                $params,
                'midtrans'
            );

            // simpan token
            $order->update_meta_data('_midtrans_snap_token', $snap->token);
            $order->update_meta_data('_midtrans_redirect_url', $snap->redirect_url);
            $order->save();

            return [
                // 'status' => true,
                // 'message' => 'Snap payment dibuat',
                // 'snap_token' => $snap->token,
                'payment_url' => $snap->redirect_url
            ];

        }catch(Exception $e){

            return [
                'status' => false,
                'message' => $e->getMessage()
            ];

        }
    }

    public function custom_create_order_midtrans($request)
    {
        $data = $request->get_json_params();

        $email = sanitize_email($data['email']);
        // $payment_method = $data['payment_method'];
        $product_id = $data['product_id'];

        // cek apakah user sudah ada
        $user = get_user_by('email', $email);

        if($user){
            $customer_id = $user->ID;
        }

        if(!class_exists('WooCommerce')){
            return new WP_REST_Response([
                'error' => 'WooCommerce not active'
            ], 500);
        }

        try {

            // Create order
            $order = wc_create_order([
                'customer_id' => $customer_id
            ]);

            // Add products
            // foreach ($data['line_items'] as $item) {

                $product = wc_get_product($data['product_id']);

                if(!$product){
                    return new WP_REST_Response([
                        'error' => 'Product not found'
                    ], 404);
                }

                $order->add_product($product, 1);
            // }

            // Billing
            // $order->set_address($data['billing'], 'billing');
            $order->set_billing_email($email);

            // Payment
            $order->set_payment_method($payment_method);
            $order->set_payment_method_title("Midtrans");

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
                    // "first_name" => $data['billing']['first_name'],
                    // "last_name" => $data['billing']['last_name'],
                    "email" => $email
                ],
                "callbacks" => [
                    // "finish" => "https://ihefcard.inahfcarmet.org/checkout/order-received/".$order_id."/?key=".$order_key,
                    "finish"=>"inahf://payment/success?orderId=".$order_id,
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

            $order->update_meta_data('_mt_snap_token', $snap->token);
            $order->update_meta_data('_mt_redirect_url', $snap->redirect_url);
            $order->update_meta_data('_mt_transaction_id', $params['transaction_details']['order_id']);
            $order->update_meta_data('_mt_deep_link', 'inahf://payment/success?orderId='.$order_id);
            $order->save();

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

    public function get_products() {

        if(!class_exists('WooCommerce')){
            return new WP_REST_Response([
                "error" => "WooCommerce not active"
            ], 500);
        }

        $args = [
            'status' => 'publish',
            'limit' => -1
        ];

        $products = wc_get_products($args);

        $data = [];

        foreach($products as $product){

            $data[] = [
                "id" => $product->get_id(),
                "name" => $product->get_name(),
                "description" => wp_strip_all_tags($product->get_description()),
                "base_price" => $product->get_regular_price(),
                "final_price" => $product->get_sale_price()
            ];
        }

        return [
            "data" => $data
        ];
    }
}