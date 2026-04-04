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

        register_post_type('ca_recording', [
            'label' => 'Recordings',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title','editor','thumbnail'],
            'rewrite' => ['slug'=>'recording'],
            'show_in_rest' => true
        ]);

        register_post_type('ca_faculty', [
            'label' => 'Faculty',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title','editor','thumbnail'],
            'rewrite' => ['slug'=>'faculty'],
            'show_in_rest' => true
        ]);

        register_post_type('ca_agenda', [
        'labels' => [
            'name' => 'Agenda',
            'singular_name' => 'Agenda',
            'add_new' => 'Tambah Agenda',
            'add_new_item' => 'Tambah Agenda Baru',
            'edit_item' => 'Edit Agenda',
            'all_items' => 'Semua Agenda',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-calendar',
        'supports' => ['title', 'editor'],
        'rewrite' => ['slug'=>'agenda'],
        'taxonomies' => ['agenda_category'],
        'show_in_rest' => true, // penting untuk Gutenberg & API
        'show_ui' => true,
    ]);

        error_log('REGISTER CPT WORKING');
    }
}
