<?php

namespace ContentAplikasi;

class CPT
{

    public function __construct()
    {
        add_action('init', [$this, 'register_cpts']);
    }

    public function register_cpts()
    {
         /**
         * RECORDING
         */
        register_post_type('ca_recording', [
            'labels' => [
                'name' => 'Recordings',
                'singular_name' => 'Recording'
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => 'content-aplikasi',
            'supports' => ['title', 'editor', 'thumbnail'],
            'rewrite' => ['slug' => 'recording']
        ]);

         /**
         * Faculty
         */
        register_post_type('ca_faculty', [
            'labels' => [
                'name' => 'Faculty',
                'singular_name' => 'Faculty'
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => 'content-aplikasi',
            'has_archive' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
            'rewrite' => ['slug' => 'faculty']
        ]);



    }

    

}