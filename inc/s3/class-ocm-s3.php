<?php

namespace OCM;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class OCM_S3
{
    const REGISTER_BACKUP_ENDPOINT = 'https://9ulcorn6i3.execute-api.us-east-1.amazonaws.com/Prod/createOCMBucket';
    const REDEEM_CODE_ENDPOINT     = 'https://jbcfmgdzm6.execute-api.us-east-1.amazonaws.com/default/generateOCMDownloadLink';
    const MULTIPART_THRESHOLD = 8388608;
    const MULTIPART_CHUNK_SIZE = 8388608;
    const MAX_UPLOAD_RETRIES = 24;

    /**
     * Register a new backup with the Lambda. Server generates bucket_id, restore code,
     * and AES key; returns presigned PUT URLs + AES key to the plugin (which uses the
     * key to encrypt the zips locally). The code is emailed after uploads complete.
     *
     * @return object|false Decoded JSON response with ->bucket_id, ->aes_key_b64, ->urls, or false.
     */
    public static function register_backup($email, $site_url)
    {
        $payload = ['email' => $email, 'site_url' => $site_url];

        One_Click_Migration::write_to_log('Registering backup');

        $response = wp_remote_post(self::REGISTER_BACKUP_ENDPOINT, [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ]);

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: register_backup WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (200 !== (int) $code) {
            One_Click_Migration::write_to_log("Error: register_backup non-200 response (code=$code): " . substr($body, 0, 500));
            return false;
        }

        $decoded = json_decode($body);
        if (!is_object($decoded) || empty($decoded->bucket_id) || empty($decoded->aes_key_b64) || empty($decoded->urls) || empty($decoded->restore_code)) {
            One_Click_Migration::write_to_log('Error: register_backup malformed response: ' . substr($body, 0, 500));
            return false;
        }

        return $decoded;
    }

    /**
     * Email the restore code after the backup upload has completed. The Lambda marks
     * the gateway row ready before sending so restore lookup cannot start from a
     * partial backup.
     *
     * @return bool
     */
    public static function send_restore_code_email($email, $bucket_id, $code, $site_url, $missing_parts = array())
    {
        $payload = [
            'action'    => 'send_restore_code',
            'email'     => $email,
            'bucket_id' => $bucket_id,
            'code'      => $code,
            'site_url'  => $site_url,
        ];

        if (is_array($missing_parts) && $missing_parts) {
            $payload['missing_parts'] = array_values($missing_parts);
        }

        $response = wp_remote_post(self::REGISTER_BACKUP_ENDPOINT, [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ]);

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: send_restore_code_email WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code_http = (int) wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        if (200 !== $code_http) {
            One_Click_Migration::write_to_log("Error: send_restore_code_email non-200 response (code=$code_http): " . substr($body, 0, 500));
            return false;
        }

        $decoded = json_decode($body);
        return is_object($decoded) && !empty($decoded->ok);
    }

    /**
     * Redeem a restore code on the Lambda. Returns presigned GET URLs + AES key + email
     * on success, or a structured error object the caller can branch on (e.g. invalid_or_expired).
     *
     * @return object
     */
    public static function redeem_restore_code($code)
    {
        $args = [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode(['code' => $code]),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ];

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = wp_remote_post(self::REDEEM_CODE_ENDPOINT, $args);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 500) {
                break;
            }

            if ($attempt < 3) {
                One_Click_Migration::write_to_log(sprintf(
                    'Restore code lookup got transient error, retrying (attempt %d/3)...',
                    $attempt + 1
                ));
                sleep($attempt * 2);
            }
        }

        if (is_wp_error($response)) {
            return (object) ['error' => 'network', 'message' => $response->get_error_message()];
        }

        $code_http = (int) wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        $decoded   = json_decode($body);
        if (!is_object($decoded)) {
            $decoded = (object) [];
        }
        $decoded->_status = $code_http;

        return $decoded;
    }

    private static function lambda_action($payload, $timeout = 30, $max_attempts = 3)
    {
        $args = [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => $timeout,
        ];

        $attempt = 0;
        do {
            $attempt++;
            $response = wp_remote_post(self::REGISTER_BACKUP_ENDPOINT, $args);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 500) {
                break;
            }

            if ($attempt < $max_attempts) {
                sleep($attempt);
            }
        } while ($attempt < $max_attempts);

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: Lambda action WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            One_Click_Migration::write_to_log(sprintf('Error: Lambda action "%s" failed (code=%d): %s', $payload['action'] ?? '', $code, substr($body, 0, 500)));
            return false;
        }

        $decoded = json_decode($body);
        return is_object($decoded) ? $decoded : false;
    }

    private static function upload_zip_multipart($zip_file, $folder_type)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $bucket_id = get_option('ocm_bucket_id');
        if (!$bucket_id) {
            return false;
        }

        $key = basename($zip_file);
        $file_size = filesize($zip_file);
        if (!$file_size || $file_size < self::MULTIPART_THRESHOLD) {
            return false;
        }

        $state_option = 'ocm_multipart_upload_' . $folder_type;
        $state = get_option($state_option, []);
        if (!is_array($state)) {
            $state = [];
        }

        if (empty($state['upload_id'])) {
            $init = self::lambda_action([
                'action'    => 'init_multipart',
                'bucket_id' => $bucket_id,
                'key'       => $key,
            ]);

            if (!$init || empty($init->upload_id)) {
                return false;
            }

            $state = [
                'upload_id' => $init->upload_id,
                'parts'     => [],
            ];
            update_option($state_option, $state, true);
        }

        $upload_id = $state['upload_id'];
        $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : [];
        $total_parts = (int) ceil($file_size / self::MULTIPART_CHUNK_SIZE);

        for ($part_number = 1; $part_number <= $total_parts; $part_number++) {
            if (!empty($parts[$part_number])) {
                continue;
            }

            if (is_object(One_Click_Migration::$process_backup_single)
                && method_exists(One_Click_Migration::$process_backup_single, 'remaining_time')
                && One_Click_Migration::$process_backup_single->remaining_time() < 20) {
                One_Click_Migration::write_to_log("Process is Restarting");
                One_Click_Migration::$process_backup_single->restart_task();
            }

            $presign = self::lambda_action([
                'action'      => 'presign_multipart_part',
                'bucket_id'   => $bucket_id,
                'key'         => $key,
                'upload_id'   => $upload_id,
                'part_number' => $part_number,
            ]);

            if (!$presign || empty($presign->url)) {
                return false;
            }

            $offset = ($part_number - 1) * self::MULTIPART_CHUNK_SIZE;
            $part_size = min(self::MULTIPART_CHUNK_SIZE, $file_size - $offset);
            $etag = self::upload_multipart_part($zip_file, $presign->url, $offset, $part_size);
            if (!$etag) {
                $retry_count = get_option('ocm_backup_upload_retry_' . $folder_type, 1);
                if ($retry_count <= self::MAX_UPLOAD_RETRIES) {
                    One_Click_Migration::write_to_log("Process is Restarting");
                    update_option('ocm_backup_upload_retry_' . $folder_type, $retry_count + 1, true);
                    One_Click_Migration::$process_backup_single->restart_task();
                }
                return false;
            }

            $parts[$part_number] = $etag;
            $state['parts'] = $parts;
            update_option($state_option, $state, true);
            One_Click_Migration::write_to_log(sprintf(
                'Uploaded %s part %d/%d',
                $folder_type,
                $part_number,
                $total_parts
            ));
        }

        ksort($parts, SORT_NUMERIC);
        $complete_parts = [];
        foreach ($parts as $part_number => $etag) {
            $complete_parts[] = [
                'PartNumber' => (int) $part_number,
                'ETag'       => $etag,
            ];
        }

        $complete = self::lambda_action([
            'action'    => 'complete_multipart',
            'bucket_id' => $bucket_id,
            'key'       => $key,
            'upload_id' => $upload_id,
            'parts'     => $complete_parts,
        ], 60);

        if (!$complete || empty($complete->ok)) {
            return false;
        }

        delete_option($state_option);
        return true;
    }

    private static function upload_multipart_part($zip_file, $url, $offset, $part_size)
    {
        $file_handle = fopen($zip_file, 'rb');
        if (!$file_handle) {
            return false;
        }

        if (fseek($file_handle, $offset) !== 0) {
            fclose($file_handle);
            return false;
        }

        $headers = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $part_size);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(60, One_Click_Migration::get_timeout()));
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        });

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($file_handle);

        if (!$ok || $errno !== 0 || $http_code < 200 || $http_code >= 300) {
            One_Click_Migration::write_to_log(sprintf(
                'Upload failed for multipart part at offset %d: %s',
                $offset,
                $error ? $error : 'HTTP ' . $http_code
            ));
            return false;
        }

        return $headers['etag'] ?? false;
    }

    public static function upload_zip( $zip_file )
    {
        if (empty($zip_file)) {
            $error_msg = "Error: Zip file path is empty in upload_zip()";
            One_Click_Migration::write_to_log($error_msg);
            throw new Exception($error_msg);
        }

        if (!file_exists($zip_file)) {
            $error_msg = "Error: Zip file does not exist: " . $zip_file;
            One_Click_Migration::write_to_log($error_msg);
            throw new Exception($error_msg);
        }

        if (!is_readable($zip_file)) {
            $error_msg = "Error: Zip file is not readable: " . $zip_file;
            One_Click_Migration::write_to_log($error_msg);
            throw new Exception($error_msg);
        }

        $urls = get_option( 'ocm_presigned_urls' );
        if (!$urls || !is_object($urls)) {
            One_Click_Migration::write_to_log("Error: No presigned URLs available for upload");
            throw new Exception("No presigned URLs available for upload");
        }

        $folder_type = explode('/', $zip_file);
        $folder_type = str_replace( '.zip.crypt', '', $folder_type[count($folder_type) - 1] );

        if (!isset($urls->{$folder_type}) || empty($urls->{$folder_type})) {
            $error_msg = "Error: No URL available for folder type: " . $folder_type;
            One_Click_Migration::write_to_log($error_msg);
            throw new Exception($error_msg);
        }

        $multipart_result = self::upload_zip_multipart($zip_file, $folder_type);
        if ($multipart_result) {
            return $multipart_result;
        }

        $file_handle = null;
        try {
            $file_handle = fopen($zip_file, 'rb');
            if (!$file_handle) {
                $error_msg = "Failed to open file handle for: " . $zip_file;
                One_Click_Migration::write_to_log($error_msg);
                throw new Exception($error_msg);
            }

            $upload_timeout = max(60, One_Click_Migration::get_timeout());
            @set_time_limit($upload_timeout + 10);

            $request = new Request(
                'PUT',
                $urls->{$folder_type},
                [],
                $file_handle
            );
            $client = new Client([
                'timeout' => $upload_timeout,
            ]);

            $response = $client->send($request);
            $status = (int) $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new Exception('Storage returned HTTP ' . $status);
            }

            return true;

        } catch (Exception $e) {
            One_Click_Migration::write_to_log("Upload failed for " . $folder_type . ": " . $e->getMessage());

            $next_retry_count = get_option('ocm_backup_upload_retry_' . $folder_type, 1);
            if($next_retry_count <= self::MAX_UPLOAD_RETRIES){
              One_Click_Migration::write_to_log("Process is Restarting");
              update_option('ocm_backup_upload_retry_' . $folder_type , $next_retry_count + 1, true);
              One_Click_Migration::$process_backup_single->restart_task();
            }else{
              One_Click_Migration::write_to_log(sprintf('Upload retries exhausted for %s; backup will fail closed.', $folder_type));
            }
            return false;
        } finally {
            if (is_resource($file_handle)) {
                fclose($file_handle);
            }
        }
    }

    /**
     * @param int $bytes
     * @return string
     */
    private static function convert_to_readable_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
