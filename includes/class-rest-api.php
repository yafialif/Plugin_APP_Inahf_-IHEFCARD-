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

        
        register_rest_route('ihefcard/v1/content', '/schedule', [
        'methods'  => 'GET',
        'callback' => [$this,'get_agenda_grouped'],
        'permission_callback' => '__return_true'
    ]);


     register_rest_route('midtrans/v1', '/webhook', [
        'methods'  => 'POST',
        'callback' => [$this,'handle_midtrans_webhook'],
        'permission_callback' => '__return_true'
        ]);

        
        
    }
    

    public function handle_midtrans_webhook(\WP_REST_Request $request)
    {

        
        $server_key = 'SB-Mid-server-j8HvvpqZ3TY1m0M5xlAyTbJo'; // penting!

        $data = $request->get_json_params();

        

        // Ambil data penting
        $order_id      = $data['order_id'] ?? null;
        $status_code   = $data['status_code'] ?? null;
        $gross_amount  = $data['gross_amount'] ?? null;
        $signature_key = $data['signature_key'] ?? null;
        $transaction_status = $data['transaction_status'] ?? null;
        $fraud_status  = $data['fraud_status'] ?? null;

        // Validasi basic
        if (!$order_id || !$signature_key) {
            return new WP_REST_Response([
                'status' => false,
                'message' => 'Invalid payload'
            ], 400);
        }

        // 🔐 Validasi Signature Midtrans
        $generated_signature = hash('sha512',
            $order_id . $status_code . $gross_amount . $server_key
        );

        if ($signature_key !== $generated_signature) {
            return new WP_REST_Response([
                'status' => false,
                'message' => 'Invalid signature'
            ], 403);
        }

         // Pastikan WooCommerce ke-load
        if (!class_exists('WooCommerce')) {
            include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        }

        if (!function_exists('wc_get_order')) {
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-order-functions.php';
        }

        $order = \wc_get_order($order_id); // tetap pakai \


        if (!$order) {
            return new WP_REST_Response([
                'status' => false,
                'message' => 'Order tidak ditemukan'
            ], 404);
        }

        // 🚀 Logic status
        if ($transaction_status == 'settlement' || 
            ($transaction_status == 'capture' && $fraud_status == 'accept')) {

            // Hindari double proses
            if ($order->get_status() !== 'completed') {

                // Complete order
                $order->payment_complete();

                // Optional: set langsung completed
                $order->update_status('completed', 'Midtrans payment success');

                // Tambahan: simpan VA / bank
                if (!empty($data['va_numbers'][0]['va_number'])) {
                    update_post_meta($order_id, '_va_number', $data['va_numbers'][0]['va_number']);
                    update_post_meta($order_id, '_bank', $data['va_numbers'][0]['bank']);
                }

                // Tambahan: simpan transaction id
                update_post_meta($order_id, '_transaction_id_midtrans', $data['transaction_id']);
            }

            return [
                'status' => true,
                'message' => 'Order completed'
            ];
        }

        // ❌ Pending / expire / cancel
        if (in_array($transaction_status, ['cancel', 'expire', 'deny'])) {
            $order->update_status('cancelled', 'Midtrans payment failed');
        }

        return [
            'status' => true,
            'message' => 'Webhook received'
        ];
    }

    function get_agenda_grouped() {

        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        $result = [];

        foreach ($terms as $term) {
            $term_id = is_array($term) ? $term['term_id'] : $term->term_id;
            $term_name = is_array($term) ? $term['name'] : $term->name;
            // ambil semua agenda berdasarkan category
            $posts = get_posts([
                'post_type' => 'ca_agenda',
                'numberposts' => -1,
                'tax_query' => [
                    [
                        'taxonomy' => 'category',
                        'field'    => 'term_id',
                        'terms'    => $term_id,
                    ]
                ]
            ]);

            $items = [];

            foreach ($posts as $post) {

                $waktu = get_post_meta($post->ID, 'ca_agenda_waktu', true);

                $items[] = [
                    'left_text'  => apply_filters('the_content', $post->post_content),
                    'right_text' => $waktu
                ];
            }

            $result[] = [
                'group_title' => $term_name,
                'group_items' => $items
            ];
        }

        return [
            'data' => [
                'page_title'   => 'Agenda',
                'page_content' => $result
            ]
        ];
    }


    public function handle_checkin(\WP_REST_Request $request)
    {
        global $wpdb;

        $table_attendence  = $wpdb->prefix . 'attendence';
        $table_category    = $wpdb->prefix . 'categoryAttendence';

        $params = $request->get_json_params();
        $code_qr  = sanitize_text_field($params['code_qr'] ?? '');

        // =========================
        // AUTH
        // =========================
        $auth = $request->get_header('authorization');
        $token = str_replace('Bearer ', '', $auth);

        $response = wp_remote_get(
            'https://inahfcarmet.org/wp-json/auth/v1/validate',
            [
                'headers' => ['Authorization' => $token],
                'timeout' => 20
            ]
        );

        if (is_wp_error($response)) {
            return ['status' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $email = sanitize_email($body['email'] ?? '');
        $user_id = get_user_by('email', $email)->ID ?? null;


        if (!$user_id) {
            return ['status' => false, 'message' => 'User tidak ditemukan'];
        }

        // =========================
        // GET CATEGORY + ACTIVITY
        // =========================
        $category = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_category WHERE uid = %s",
                $code_qr
            )
        );

        if (!$category) {
            return ['status' => false, 'message' => 'QR tidak valid'];
        }


        // =========================
        // CHECK ATTENDANCE
        // =========================
        $today = current_time('Y-m-d');

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_attendence 
                WHERE id_user = %d
                AND DATE(time) = %s",
                $user_id,
                $today
            )
        );
        // =========================
        // INSERT
        // =========================
        if ($existing > 0) {
            return new \WP_REST_Response([
                'data' =>[
                    'status'  => false,
                    'message' => 'Anda sudah melakukan check-in'
                ]
                
            ], 200);
        }


        $wpdb->insert(
            $table_attendence,
            [
                'id_user'     => $user_id,
                'id_category' => $category->id,
                'type'        => 'checkin',
                'time'        => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );


        return new WP_REST_Response([
            'data' => [
                'message'   => 'check-in Berhasil'
            ]
        ], 200);
        
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

    $table_category = $wpdb->prefix . 'categoryAttendence';
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

    // =========================
        // BUILD SUMMARY
        // =========================


         $report = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT att.*, cat.*
                FROM $table_attendence att
                JOIN $table_category cat 
                    ON cat.id = att.id_category
                WHERE att.id_user = %d",
                $user_id
            )
        );

        $page_content = [];

        foreach ($report as $att) {

            $date_key   = date('Y-m-d', strtotime($att->time));
            $date_label = date('l, d F Y', strtotime($att->time));

            // init group kalau belum ada
            if (!isset($page_content[$date_key])) {
                $page_content[$date_key] = [
                    'group_title' => $date_label,
                    'group_items' => [
                        [
                            'left_text'  => '',
                            'right_text' => ''
                        ]
                    ]
                ];
            }

            // ambil reference (biar tidak ribet)
            $index = count($page_content[$date_key]['group_items']) - 1;

            // LEFT TEXT
            $page_content[$date_key]['group_items'][$index]['left_text'] 
                .= '- ' . esc_html($att->category_name) . '</br>';

            // STATUS ICON
            $status_icon = $att->time 
                ? '<span style="color:green;">✔</span></br>' 
                : '<span style="color:red;">✘</span></br>';

            // RIGHT TEXT
            $page_content[$date_key]['group_items'][$index]['right_text'] 
                .= '<div>' 
                . esc_html($att->time) . ' ' . $status_icon 
                . '</div>';
        }

        // reset index
        $page_content = array_values($page_content);

        return new WP_REST_Response([
            'data' => [
                'page_title'   => 'Summary',
                'page_content' => $page_content
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
                    "route_url" => get_permalink()
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
                                    "route_url": "/"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Committee",
                                    "type": "webview",
                                    "route_url": "https://ihefcard.com/committee"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Package",
                                    "type": "package",
                                    "route_url": "/package/list"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Recording",
                                    "type": "recording",
                                    "route_url": "/recording/list"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Speakers",
                                    "type": "speakers",
                                    "route_url": "/speaker/list"
                                },
                                {
                                    "icon": "https://www.google.com",
                                    "name": "Satu Sehat",
                                    "type": "webview",
                                    "route_url": "https://lms.kemkes.go.id/"
                                }
                            ]
                    }
                },
                {
                    "type": "section_image_carousel",
                    "payload": {
                       "title": "Our incredible moments",
                        "subtitle": null,
                        "list":[
                            {
                            "id":1,
                            "image_url":"https://ihefcard.com/assets/images/gallery/image-2.webp",
                            "title": "Any title here"
                            },
                            {
                            "id":2,
                            "image_url":"https://ihefcard.com/assets/images/gallery/image-10.webp",
                            "title": "Any title here"
                            },
                            {
                            "id":3,
                            "image_url":"https://ihefcard.com/assets/images/gallery/image-5.webp",
                            "title": "Any title here"
                            },
                            {
                            "id":4,
                            "image_url":"https://ihefcard.com/assets/images/gallery/image-5.webp",
                            "title": "Any title here"
                            }
                        ],
                        "cta": {
                            "title": "See all",
                            "route_url": "https://ihefcard.com/gallery"
                        }
                       
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
            $grouped = [];

            // if ($query->have_posts()) {
            //     while ($query->have_posts()) {

            //         $query->the_post();

            //         $items[] = [
            //             "titile" => get_the_title(),
            //             "description" => wp_strip_all_tags(get_the_content()),
            //             "youtube_url" => get_post_meta(get_the_ID(), 'ca_video_url', true),
            //             "post_date" => get_the_date('Y-m-d')
            //         ];
            //     }
            // }

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();

                    // Ambil taxonomy (category default atau custom)
                    $terms = get_the_terms(get_the_ID(), 'category'); // ganti kalau pakai custom taxonomy

                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {

                            $tab_title = $term->name;

                            if (!isset($grouped[$tab_title])) {
                                $grouped[$tab_title] = [];
                            }

                            $grouped[$tab_title][] = [
                                "titile" => get_the_title(),
                                "description" => wp_strip_all_tags(get_the_content()),
                                "youtube_url" => get_post_meta(get_the_ID(), 'ca_video_url', true),
                                "post_date" => get_the_date('Y-m-d')
                            ];
                        }
                    } else {
                        // fallback kalau tidak ada category
                        $tab_title = "Uncategorized";

                        if (!isset($grouped[$tab_title])) {
                            $grouped[$tab_title] = [];
                        }

                        $grouped[$tab_title][] = [
                            "titile" => get_the_title(),
                            "description" => wp_strip_all_tags(get_the_content()),
                            "youtube_url" => get_post_meta(get_the_ID(), 'ca_video_url', true),
                            "post_date" => get_the_date('Y-m-d')
                        ];
                    }
                }
            }

            wp_reset_postdata();

            // return [
            //     "data" => [
            //         "page_title" => "Recording",
            //         "page_content" => $items
            //     ]
            // ];
             // Format ke structure final
            $page_content = [];

            foreach ($grouped as $tab_title => $items) {
                $page_content[] = [
                    "tab_title" => $tab_title,
                    "tab_content" => $items
                ];
            }

            return [
                "data" => [
                    "page_title" => "Recording",
                    "page_content" => $page_content
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
                    "finish"=>"inahf://ihefcard?shouldBack=true",
                    "unfinish" => "inahf://ihefcard?shouldBack=true",
                    "error" => "inahf://ihefcard?shouldBack=true"
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
            $order->update_meta_data('_mt_deep_link', 'inahf://ihefcard?shouldBack=true');
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