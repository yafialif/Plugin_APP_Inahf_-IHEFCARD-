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


    }

}