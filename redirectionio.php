<?php

/**
 * Plugin Name: redirection.io
 * Plugin URI: https://redirection.io
 * Description: Proxy client for redirection.io | Put an end to 404 errors - Track HTTP errors and setup useful HTTP redirections
 * Version: 0.1
 * Author: redirection.io
 * Author Website: https://redirection.io
 * Text Domain: redirectionio
 * Domain Path: /wordpress/languages
 */

namespace RedirectionIO\Client\Wordpress;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/sdk/vendor/autoload.php';

use RedirectionIO\Client\Client;
use RedirectionIO\Client\HTTPMessage\ServerRequest;

class RedirectionIO
{
    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'setUpPlugin']);
        
        add_action('plugins_loaded', [$this, 'findRedirect']);
        add_action('init', [$this, 'setTranslations']);
        add_action('admin_menu', [$this, 'setUpAdminPage']);
        add_action('admin_init', [$this, 'registerAdminSettings']);
        add_action('admin_enqueue_scripts', [$this, 'registerAssets']);
    }

    public function registerAssets()
    {
        wp_enqueue_style('redirectionio', plugins_url('wordpress/assets/css/redirectionio.css', __FILE__));
        wp_enqueue_script('redirectionio', plugins_url('wordpress/assets/js/redirectionio.js', __FILE__), [], false, true);
    }

    public function setTranslations()
    {
        load_plugin_textdomain('redirectionio', false, dirname(plugin_basename(__FILE__)) . '/wordpress/languages');
    }

    public function setUpPlugin()
    {
        add_option('redirectionio', [['name' => '', 'host' => '', 'port' => '']]);
    }

    public function setUpAdminPage()
    {
        add_options_page('redirection.io', 'redirection.io', 'manage_options', 'redirectionio', [$this, 'createAdminPage']);
    }

    public function createAdminPage()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        require_once __DIR__ . '/wordpress/templates/admin.php';
    }

    public function registerAdminSettings()
    {
        register_setting(
            'redirectionio-group',
            'redirectionio',
            [$this, 'sanitizeInput']
        );

        $options = get_option('redirectionio');

        foreach ($options as $i => $option) {
            add_settings_section(
                'redirectionio-section-' . $i,
                sprintf(__('Connection #%s', 'redirectionio'), $i+1),
                [$this, 'printSection'],
                'redirectionio'
            );

            foreach ($option as $key => $value) {
                switch ($key) {
                    case 'name':
                        $title = __('Name', 'redirectionio');
                        break;
                    case 'host':
                        $title = __('Host', 'redirectionio');
                        break;
                    case 'port':
                        $title = __('Port', 'redirectionio');
                        break;
                    default:
                        $title = 'unknown';
                }

                add_settings_field(
                    $id . '_' . $key,
                    $title,
                    [$this, 'printField'],
                    'redirectionio',
                    'redirectionio-section-' . $i,
                    [
                        'id' => $i,
                        'type' => $key,
                        'value' => $value,
                    ]
                );
            }
        }
    }

    public function printSection()
    {
    }

    public function printField($args)
    {
        $id = array_key_exists('id', $args) ? $args['id']: '';
        $type = array_key_exists('type', $args) ? $args['type']: '';
        $value = array_key_exists('value', $args) ? $args['value']: '';
        echo "<input id='redirectionio_{$id}_{$type}' name='redirectionio[$id][$type]' size='40' type='text' value='$value' />";
    }

    public function sanitizeInput($input)
    {
        $newInput = [];

        foreach ($input as $i => $option) {
            foreach ($option as $key => $value) {
                $newInput[$i][$key] = sanitize_text_field($input[$i][$key]);
            }
        }

        return $newInput;
    }

    public function findRedirect()
    {
        $options = get_option('redirectionio');

        // TODO: improve with multihost
        
        set_error_handler(__CLASS__ . '::handleInternalError');

        $isUp = true;

        try {
            $connection = fsockopen($options[0]['host'], (int) $options[0]['port'], $errno, $errstr, 30);
        } catch (\ErrorException $e) {
            $isUp = false;
        }

        restore_error_handler();

        if ($isUp) {
            fclose($connection);
        } else {
            return;
        }

        $connectionOptions = ['agent' => [
            'host' => $options[0]['host'],
            'port' => $options[0]['port']
        ]];
        
        $client = new Client($connectionOptions);
        $request = new ServerRequest(
            $_SERVER['HTTP_HOST'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_REFERER']
        );
        
        $response = $client->findRedirect($request);

        if (null === $response) {
            return;
        }

        // TODO: think about logger here

        wp_redirect($response->getLocation(), $response->getStatusCode());
        exit;
    }

    public static function handleInternalError($type, $message, $file, $line)
    {
        throw new \ErrorException($message, 0, $type, $file, $line);
    }
}

new RedirectionIO();