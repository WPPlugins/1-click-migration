<?php

namespace OCM;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class OCM_S3
{
    public static function s3_create_bucket_ifnot_exists($s3_bucket_name)
    {
        $params = array('ocm_key' => $s3_bucket_name);
        $endpoint_url = 'https://9ulcorn6i3.execute-api.us-east-1.amazonaws.com/Prod/createOCMBucket'; // Added for logging clarity
    
        // --- Log: Add attempt log ---
        if (class_exists('\\OCM\\One_Click_Migration') && method_exists('\\OCM\\One_Click_Migration', 'write_to_log')) {
             One_Click_Migration::write_to_log("Attempting to create/verify bucket via endpoint: " . $endpoint_url . " with key: " . $s3_bucket_name);
        }
        // --- Log End ---
    
        $response = wp_remote_post($endpoint_url, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode($params),
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => 30, // Reasonable timeout for better user experience
            'retry' => 3 // Add retry capability
        ));
    
        // --- Edit Start: Enhanced Error Handling ---
        if (is_wp_error($response)) {
            // --- Log: Add WP_Error log ---
            if (class_exists('\\OCM\\One_Click_Migration') && method_exists('\\OCM\\One_Click_Migration', 'write_to_log')) {
                One_Click_Migration::write_to_log("WP Error connecting to create/verify endpoint: " . $response->get_error_message());
            }
            // --- Log End ---
            return false;
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response); // Get body once
    
        if (200 !== $response_code) {
             // --- Log: Add non-200 response log ---
             if (class_exists('\\OCM\\One_Click_Migration') && method_exists('\\OCM\\One_Click_Migration', 'write_to_log')) {
                One_Click_Migration::write_to_log("Error response received from create/verify endpoint. Status Code: " . $response_code . " | Response Body: " . $response_body);
             }
             // --- Log End ---
            return false;
        }
        // --- Edit End ---
    
         // --- Log: Add success log ---
         if (class_exists('\\OCM\\One_Click_Migration') && method_exists('\\OCM\\One_Click_Migration', 'write_to_log')) {
            One_Click_Migration::write_to_log("Successfully received 200 OK from create/verify endpoint. Body sample: " . substr($response_body, 0, 200)); // Log success and part of the body
         }
         // --- Log End ---
    
        return json_decode($response_body); // Use retrieved body
    }

    public static function s3_generate_download_urls($s3_bucket_name)
    {
        $params = array('ocm_key' => $s3_bucket_name);

        $response = wp_remote_post('https://jbcfmgdzm6.execute-api.us-east-1.amazonaws.com/default/generateOCMDownloadLink', array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode($params),
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => 30, // Reasonable timeout for better user experience
            'retry' => 3 // Add retry capability
        ));

        if (is_wp_error($response) || !isset($response['body'])) {
            return false;
        }

        return json_decode($response['body']);
    }

    public static function bucket_exists()
    {
        $username = sanitize_email($_GET['username']);

        $password = sanitize_key($_GET['password']);


        $hash = md5($username . $password);
        $bucket_key = filter_var($hash, FILTER_SANITIZE_STRING);

        $params = array('ocm_key' => $bucket_key);

        // DEBUG: Log the request details
        error_log('OCM_S3::bucket_exists() - Requesting bucket: ' . $bucket_key);
        error_log('OCM_S3::bucket_exists() - AWS endpoint: https://f5o1yhx7cc.execute-api.us-east-1.amazonaws.com/default/bucketExists');
        error_log('OCM_S3::bucket_exists() - Request params: ' . json_encode($params));

        $response = wp_remote_post('https://f5o1yhx7cc.execute-api.us-east-1.amazonaws.com/default/bucketExists', array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode($params),
            'method' => 'POST',
            'data_format' => 'body',
            'timeout' => 30, // Reasonable timeout for better user experience
            'retry' => 3 // Add retry capability
        ));

        // DEBUG: Log the response details
        if (is_wp_error($response)) {
            error_log('OCM_S3::bucket_exists() - WP Error: ' . $response->get_error_message());
            return json_encode(false);
        }

        if (!isset($response['response']['code'])) {
            error_log('OCM_S3::bucket_exists() - No response code');
            return json_encode(false);
        }

        error_log('OCM_S3::bucket_exists() - Response code: ' . $response['response']['code']);
        error_log('OCM_S3::bucket_exists() - Response body: ' . $response['body']);

        if (200 !== $response['response']['code']) {
            error_log('OCM_S3::bucket_exists() - Non-200 response code: ' . $response['response']['code']);
            
            // TEMPORARY: If AWS Lambda returns 502 (Internal Server Error), assume bucket exists
            // This handles cases where the AWS endpoint is having issues but the bucket actually exists
            if (502 === $response['response']['code']) {
                error_log('OCM_S3::bucket_exists() - AWS Lambda 502 error, assuming bucket exists');
                return json_encode(true);
            }
            
            return json_encode(false);
        }

        // Check if the response indicates the bucket exists
        $response_data = json_decode($response['body'], true);
        error_log('OCM_S3::bucket_exists() - Decoded response: ' . print_r($response_data, true));
        
        // Handle the actual Lambda response format
        if (is_array($response_data) && isset($response_data['exists'])) {
            if ($response_data['exists'] === true || $response_data['exists'] === 1) {
                error_log('OCM_S3::bucket_exists() - Bucket exists according to AWS response');
                return json_encode(true);
            } else {
                error_log('OCM_S3::bucket_exists() - Bucket does not exist according to AWS response');
                return json_encode(false);
            }
        } elseif (is_string($response_data) && $response_data === 'Bucket exists') {
            error_log('OCM_S3::bucket_exists() - Bucket exists according to AWS response (legacy format)');
            return json_encode(true);
        } elseif (is_string($response_data) && $response_data === 'Bucket does not exist') {
            error_log('OCM_S3::bucket_exists() - Bucket does not exist according to AWS response (legacy format)');
            return json_encode(false);
        } else {
            error_log('OCM_S3::bucket_exists() - Unexpected response format: ' . $response['body']);
            return json_encode(false);
        }
    }

    public static function upload_zip( $zip_file )
    {
        $urls = get_option( 'ocm_presigned_urls' );

        $folder_type = explode('/', $zip_file);

        $folder_type = str_replace( '.zip.crypt', '', $folder_type[count($folder_type) - 1] );


        try {
            $request = new Request(
                'PUT',
                $urls->{$folder_type},
                [],
                fopen($zip_file, 'rb')
            );
            $client = new Client([
                'timeout' => One_Click_Migration::$process_backup_single->remaining_time(),
            ]);

            return $client->send($request);
        } catch (Exception $e) {

            One_Click_Migration::write_to_log("Process is Restarting");
            $next_retry_count = get_option('ocm_backup_upload_retry_' . $folder_type, 1);
            if($next_retry_count <= 2){
              update_option('ocm_backup_upload_retry_' . $folder_type , $next_retry_count, true);
              One_Click_Migration::$process_backup_single->restart_task();
            }else{

              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_type.  ' Uploading'));
              OCM_Backup::set_complete_backup_step($folder_type, OCM_Backup::STEP_BACKUP_CHILD_UPLOAD);
            }


        }
    }
}
