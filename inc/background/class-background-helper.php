<?php

namespace OCM;

class OCM_BackgroundHelper
{
    public static function delete_all_batch_process()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $wpdb->get_results($wpdb->prepare("DELETE FROM {$table} where {$column} LIKE %s", $wpdb->base_prefix . 'ocm_background_%_batch_%'));
        $wpdb->get_results($wpdb->prepare("DELETE FROM {$table} where {$column} LIKE %s", '_transient_ocm_%'));
        $wpdb->get_results($wpdb->prepare("DELETE FROM {$table} where {$column} LIKE %s", '_site_transient_ocm_%'));
        $wpdb->get_results("DELETE FROM {$table} where {$column} = 'backup_steps'");
        $wpdb->get_results("DELETE FROM {$table} where {$column} = 'restore_steps'");
    }

    public static function delete_deprecated_batch_process()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $like_upload = $wpdb->base_prefix . 'ocm_background_upload_batch_%';
        $like_backup = $wpdb->base_prefix . 'ocm_background_backup_batch_%';
        $like_download = $wpdb->base_prefix . 'ocm_background_download_batch_%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE {$column} LIKE %s OR {$column} LIKE %s OR {$column} LIKE %s",
            $like_upload, $like_backup, $like_download
        ));
    }
}
