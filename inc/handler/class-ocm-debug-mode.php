<?php

namespace OCM;

class OCM_Debug_Mode
{
    private static $active = false;
    private static $original_error_reporting = null;
    private static $original_display_errors = null;
    private static $original_log_errors = null;

    public static function enable()
    {
        self::$original_error_reporting = error_reporting();
        self::$original_display_errors = self::safe_ini_get('display_errors');
        self::$original_log_errors = self::safe_ini_get('log_errors');

        error_reporting(E_ALL);
        self::safe_ini_set('display_errors', '0');
        self::safe_ini_set('log_errors', '0');

        self::$active = true;
        set_transient('ocm_debug_active', true, 15 * MINUTE_IN_SECONDS);
    }

    public static function disable()
    {
        if (self::$original_error_reporting !== null) {
            error_reporting(self::$original_error_reporting);
        }
        if (self::$original_display_errors !== null) {
            self::safe_ini_set('display_errors', self::$original_display_errors);
        }
        if (self::$original_log_errors !== null) {
            self::safe_ini_set('log_errors', self::$original_log_errors);
        }

        self::$active = false;
        self::$original_error_reporting = null;
        self::$original_display_errors = null;
        self::$original_log_errors = null;

        delete_transient('ocm_debug_active');
    }

    public static function is_active()
    {
        return self::$active || get_transient('ocm_debug_active');
    }

    public static function restore_if_active()
    {
        if (!self::$active && get_transient('ocm_debug_active')) {
            self::$original_error_reporting = error_reporting();
            self::$original_display_errors = self::safe_ini_get('display_errors');
            self::$original_log_errors = self::safe_ini_get('log_errors');

            error_reporting(E_ALL);
            self::safe_ini_set('display_errors', '0');
            self::safe_ini_set('log_errors', '0');

            self::$active = true;
        }
    }

    /**
     * Safe wrapper for ini_get() — some hosts disable it via disable_functions.
     * Uses global namespace prefix to avoid OCM\ini_get() resolution.
     *
     * @param string $option
     * @return string|null
     */
    private static function safe_ini_get($option)
    {
        if (function_exists('ini_get')) {
            return \ini_get($option);
        }
        return null;
    }

    /**
     * Safe wrapper for ini_set() — some hosts disable it via disable_functions.
     * Uses global namespace prefix to avoid OCM\ini_set() resolution.
     *
     * @param string $option
     * @param string $value
     * @return void
     */
    private static function safe_ini_set($option, $value)
    {
        if (function_exists('ini_set')) {
            \ini_set($option, $value);
        }
    }
}
