<?php

namespace ContentAplikasi;

class Plugin
{

    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init();
    }

    private function includes()
    {
        require_once CA_PATH . 'includes/class-menu.php';
        // require_once CA_PATH . 'includes/class-cpt.php';
        // require_once CA_PATH . 'includes/class-meta.php';
        require_once CA_PATH . 'includes/class-rest-api.php';
        

    }

    private function init()
    {
        new Menu();
        // new CPT();
        // new Meta();
        new RestAPI();

    }
    
}
