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

        register_rest_route('ihefcard/v1/content', '/recording', [
        'methods'  => 'GET',
        'callback' => [$this, 'ca_get_recordings'],
        'permission_callback' => '__return_true'
        ]);

        register_rest_route('ihefcard/v1/content', '/faculty_moderator', [
        'methods'  => 'GET',
        'callback' => [$this, 'get_faculty_api'],
        'permission_callback' => '__return_true'
        ]);

        register_rest_route('ihefcard/v1/content', '/home', [
        'methods'  => 'GET',
        'callback' => [$this, 'get_home'],
        'permission_callback' => '__return_true'
        ]);

        
        register_rest_route('ihefcard/v1/content', '/attendee_list', array(
            'methods'  => 'GET',
            'callback' => [$this,'get_attendee_summary'],
            'permission_callback' => '__return_true'
        ));

       
        register_rest_route('auth/v1', '/create_user', [
            'methods'  => 'POST',
            'callback' => [$this,'my_create_user'],
            'permission_callback' => '__return_true', // nanti bisa diamankan pakai API key
        ]);

        register_rest_route('ihefcard/v1/content', '/checkin', [
            'methods'  => 'POST',
            'callback' => [$this,'handle_checkin'],
            'permission_callback' => '__return_true', // nanti bisa diamankan pakai API key
        ]);


    }


    public function handle_checkin(\WP_REST_Request $request)
    {
        global $wpdb;

        $table_days        = $wpdb->prefix . 'event_days';
        $table_activities  = $wpdb->prefix . 'activities';
        $table_attendence  = $wpdb->prefix . 'attendence';
        $table_category    = $wpdb->prefix . 'categoryAttendence';
        $table_users       = $wpdb->prefix . 'users';

        // =========================
        // 1. GET USER
        // =========================
        $user = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ID, user_email FROM $table_users WHERE user_email = %s",
                $user_email
            )
        );

        if (!$user) {
            return ['status' => false, 'message' => 'User tidak ditemukan'];
        }

        // =========================
        // 2. GET CATEGORY (UID)
        // =========================
        $category = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_category WHERE uid = %s",
                $uid_category
            )
        );

        if (!$category) {
            return ['status' => false, 'message' => 'UID tidak valid'];
        }

        // =========================
        // 3. CHECK EXISTING CHECKIN
        // =========================
        $already = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM $table_attendence 
                WHERE user_id = %d AND category_id = %d",
                $user->ID,
                $category->id
            )
        );

        if (!$already) {
            $wpdb->insert(
                $table_attendence,
                [
                    'user_id'     => $user->ID,
                    'category_id' => $category->id,
                    'checkin_at'  => current_time('mysql')
                ],
                ['%d', '%d', '%s']
            );
        }

        // =========================
        // 4. BUILD SUMMARY
        // =========================
        $days = $wpdb->get_results("SELECT * FROM $table_days ORDER BY date ASC");

        $result = [];

        foreach ($days as $day) {

            $activities = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT a.*, c.id as category_id
                    FROM $table_activities a
                    LEFT JOIN $table_category c ON c.activity_id = a.id
                    WHERE a.day_id = %d",
                    $day->id
                )
            );

            $activity_list = [];

            foreach ($activities as $act) {

                // cek attendance user
                $att = null;
                if ($act->category_id) {
                    $att = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT checkin_at 
                            FROM $table_attendence
                            WHERE user_id = %d AND category_id = %d",
                            $user->ID,
                            $act->category_id
                        )
                    );
                }

                $activity_list[] = [
                    'title'      => $act->title,
                    'time_start' => $act->time_start ?: null,
                    'time_end'   => $act->time_end ?: null,
                    'checkin'    => $att ? date('H:i', strtotime($att->checkin_at)) : null,
                    'status'     => $att ? true : null,
                    'type'       => $act->type
                ];
            }

            $result[] = [
                'date'       => date('l, d F Y', strtotime($day->date)),
                'activities' => $activity_list
            ];
        }

        // =========================
        // 5. FINAL RESPONSE
        // =========================
        return [
            'data' => [
                'page_title'   => 'Summary',
                'page_content' => $result
            ]
        ];
    }

    // Create User

    public function my_create_user(\WP_REST_Request $request)
    {
         // Ambil header Authorization
        $auth = $request->get_header('authorization');
        if ($auth !== 'InahfCarmet2026') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // ambil semua kemungkinan input
        // $params = $request->get_json_params();
        $params = $request->get_params();

        if (empty($params)) {
            $params = $request->get_params(); // fallback
        }

        // validasi params
        if (!is_array($params)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request body'
            ], 400);
        }

        $email = sanitize_email($params['email'] ?? '');
        $name  = sanitize_text_field($params['name'] ?? '');
            if (!$email) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Email is required'
                ], 400);
            }

            // Cek apakah email sudah ada
            if (email_exists($email)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Email already exists'
                ], 409);
            }

            $username = sanitize_user(explode('@', $email)[0]);

            if (username_exists($username)) {
                $username .= '_' . rand(100, 999);
            }
            // Create user
            $user_id = wp_create_user($username, 'ihefcard', $email);

            if (is_wp_error($user_id)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $user_id->get_error_message()
                ], 500);
            }

            // Set role
            // $user = new WP_User($user_id);
            $user = get_user_by('id', $user_id);
             if (!get_role('um_user_app')) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Role um_user_app not found'
                ], 500);
            }

            // Update display name
            if ($name) {
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $name,
                ]);
            }

            return [
                'success' => true,
                'user_id' => $user_id,
                'email'   => $email,
                'username'=> $username,
                'password'=> 'ihefcard' // optional, bisa dihapus kalau tidak mau expose
            ];
        
    }

    public function get_attendee_summary(\WP_REST_Request $request) {
    global $wpdb;

    $table_days       = $wpdb->prefix . 'event_days';
    $table_activities = $wpdb->prefix . 'activities';
    $table_attendence = $wpdb->prefix . 'attendence';

    $params = $request->get_json_params();

    $auth = $request->get_header('authorization');
        $token = str_replace('Bearer ', '', $auth);
        $response = wp_remote_get(
            'https://inahfcarmet.org/wp-json/auth/v1/validate',
            array(
                'headers' => array(
                    'Authorization' => $token
                ),
                'timeout' => 20
            )
        );

        // Cek error
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => $response->get_error_message()
            ];
        }

        // Ambil body
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $email = sanitize_email($data['email']);
        // return $product_id;
    // $email  = $params['data']['email'] ?? null;

    if (!$email) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Email wajib diisi'
        ], 400);
    }

    // ========================
    // GET USER
    // ========================
    $user = get_user_by('email', $email);

    if (!$user) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'User tidak ditemukan'
        ], 404);
    }

    $user_id = $user->ID;

    // ========================
    // GET ALL DAYS
    // ========================
    $days = $wpdb->get_results("SELECT * FROM $table_days ORDER BY event_date ASC");

    $result = [];

    foreach ($days as $day) {

        // format tanggal seperti JSON
        $formatted_date = date('l, d F Y', strtotime($day->event_date));

        // ========================
        // GET ACTIVITIES PER DAY
        // ========================
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_activities WHERE event_day_id = %d",
            $day->id
        ));

        $activity_list = [];

        foreach ($activities as $act) {

            // ========================
            // CEK ATTENDANCE
            // ========================
            $attend = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_attendence 
                 WHERE id_user = %d AND activity_id = %d",
                $user_id,
                $act->id
            ));

            $activity_list[] = [
                "title"       => $act->title,
                "time_start"  => $act->time_start,
                "time_end"    => $act->time_end,
                "checkin"     => $act->checkin,
                "status"      => $attend > 0 ? true : false,
                "type"        => $act->type
            ];
        }

        $result[] = [
            "date" => $formatted_date,
            "activities" => $activity_list
        ];
    }

    return new WP_REST_Response([
        "data" => [
            "page_title" => "Summary",
            "page_content" => $result
        ]
    ], 200);
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


    //get Home
    public function get_home() {
    // $repo = new \ContentAplikasi\App_Repository();
        $data = '
        {
            "data": [
                {
                    "type": "section_ads",
                    "payload": {
                        "title": "THE 6th I-HEFCARD 2026",
                        "subtitle": "From Limited to Limitless: The Rise of Heart Failure Care",
                        "background_gradient": "GradientOrange",
                          "image_ads_url": [
                        "https://static.vecteezy.com/system/resources/previews/020/091/311/non_2x/sponsor-rubber-stamp-red-sponsor-rubber-grunge-stamp-seal-illustration-free-vector.jpg",
                        "https://static.vecteezy.com/system/resources/previews/020/091/311/non_2x/sponsor-rubber-stamp-red-sponsor-rubber-grunge-stamp-seal-illustration-free-vector.jpg",
                        "https://static.vecteezy.com/system/resources/previews/020/091/311/non_2x/sponsor-rubber-stamp-red-sponsor-rubber-grunge-stamp-seal-illustration-free-vector.jpg",
                        "https://static.vecteezy.com/system/resources/previews/020/091/311/non_2x/sponsor-rubber-stamp-red-sponsor-rubber-grunge-stamp-seal-illustration-free-vector.jpg",
                        "https://static.vecteezy.com/system/resources/previews/020/091/311/non_2x/sponsor-rubber-stamp-red-sponsor-rubber-grunge-stamp-seal-illustration-free-vector.jpg"
                    ]
                    }
                },
                {
                    "type": "section_menu",
                    "payload": {
                        "row_item_count": 4,
                        "menus": [
                                {
                                    "icon": "https://www.google.com",
                                    "name": "InaHF",
                                    "type": "inahf",
                                    "page_route": null
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Committee",
                                    "type": "webview",
                                    "page_route": "https://ihefcard.com/committee"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Package",
                                    "type": "package",
                                    "page_route": null
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Recording",
                                    "type": "recording",
                                    "page_route": null
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Speakers",
                                    "type": "speakers",
                                    "page_route": null
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Satu Sehat",
                                    "type": "satusehat",
                                    "page_route": "https://lms.kemkes.go.id/"
                                }
                            ]
                    }
                },
                {
                    "type": "section_image_carousel",
                    "payload": {
                       "title": "Our incredible moments",
                        "subtitle": null,
                        "images_url": [
                            "https://ihefcard.com/assets/images/gallery/image-2.webp",
                            "https://ihefcard.com/assets/images/gallery/image-10.webp",
                            "https://ihefcard.com/assets/images/gallery/image-5.webp",
                            "https://ihefcard.com/assets/images/gallery/image-5.webp"
                        ]
                    }
                },
                {
                    "type": "section_location",
                    "payload": {
                        "title": "Sheraton Grand Jakarta Gandaria City Hotel",
                        "subtitle": "11-13 June 2026",
                        "location_point": {
                            "latitude": "-6.244909053384983",
                            "longitude": "106.78272544998704"
                        }
                    }
                }
            ]
        }
        ';
     
        // return [
        //     // 'data' => $repo->get_sections('home')
        //     'data' => $data;
        // ];
        // return 1;
        return json_decode($data, true);
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

    public function custom_create_order_midtrans(\WP_REST_Request $request)
    {

        $params = $request->get_json_params();

        $product_id  = sanitize_text_field($params['product_id'] ?? '');
        //  $product_id = $data['product_id'];
        // Ambil header Authorization
        $auth = $request->get_header('authorization');
        $token = str_replace('Bearer ', '', $auth);
        $response = wp_remote_get(
            'https://inahfcarmet.org/wp-json/auth/v1/validate',
            array(
                'headers' => array(
                    'Authorization' => $token
                ),
                'timeout' => 20
            )
        );

        // Cek error
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => $response->get_error_message()
            ];
        }

        // Ambil body
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $email = sanitize_email($data['email']);
        // return $product_id;

        
        // $payment_method = $data['payment_method'];
       

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

                $product = wc_get_product($product_id);

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