<?php

namespace ContentAplikasi;

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


    }

    // Call function 
}