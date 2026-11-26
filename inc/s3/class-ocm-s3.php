<?php

namespace OCM;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

require_once __DIR__ . '/../handler/class-ocm-ca-bundle.php';

class OCM_S3
{
    const REGISTER_BACKUP_ENDPOINT = 'https://9ulcorn6i3.execute-api.us-east-1.amazonaws.com/Prod/createOCMBucket';
    const REDEEM_CODE_ENDPOINT     = 'https://jbcfmgdzm6.execute-api.us-east-1.amazonaws.com/default/generateOCMDownloadLink';
    const MULTIPART_THRESHOLD = 8388608;
    const MULTIPART_CHUNK_SIZE = 8388608;
    const MULTIPART_MAX_PARTS = 10000;
    const MAX_UPLOAD_RETRIES = 24;
    const REGISTER_MAX_ATTEMPTS = 4;
    /** Longest wait a 429's Retry-After can ask for: the call runs inside a worker or a page load. */
    const RETRY_AFTER_MAX_SECONDS = 10;
    /** Seconds relay_log() waits for the Lambda. */
    const RELAY_LOG_TIMEOUT = 15;

    /**
     * Register a new backup with the Lambda. Server generates bucket_id, restore code,
     * and AES key; returns presigned PUT URLs + AES key to the plugin (which uses the
     * key to encrypt the zips locally). The code is emailed after uploads complete.
     *
     * A retried registration can leave an unused bucket behind; the nightly
     * cleanup deletes it with the other expired ones.
     *
     * @param int $attempts Set to the number of requests made.
     * @return object|false Decoded JSON response with ->bucket_id, ->aes_key_b64, ->urls, or false.
     */
    public static function register_backup($email, $site_url, &$attempts = 0)
    {
        $payload = ['email' => $email, 'site_url' => $site_url];

        One_Click_Migration::write_to_log('Registering backup');

        $response = self::post_with_retry(self::REGISTER_BACKUP_ENDPOINT, [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ], self::REGISTER_MAX_ATTEMPTS, 2, function ($next_attempt, $max_attempts, $reason) {
            // "attempt N/M" counts retries: the progress card's plain-language
            // wording (OCM_Backup::plain_language_status()) adds one to each.
            One_Click_Migration::write_to_log(sprintf(
                'Retrying backup registration (attempt %d/%d): %s',
                $next_attempt - 1,
                $max_attempts - 1,
                $reason
            ));
        }, $attempts);

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: register_backup WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (200 !== (int) $code) {
            One_Click_Migration::write_to_log("Error: register_backup non-200 response (code=$code): " . substr(self::plain_body($body), 0, 500));
            return false;
        }

        $decoded = json_decode($body);
        if (!is_object($decoded) || empty($decoded->bucket_id) || empty($decoded->aes_key_b64) || empty($decoded->urls) || empty($decoded->restore_code)) {
            One_Click_Migration::write_to_log('Error: register_backup malformed response: ' . substr(self::plain_body($body), 0, 500));
            return false;
        }

        return $decoded;
    }

    /**
     * Email the restore code after the backup upload has completed. The Lambda marks
     * the gateway row ready before sending so restore lookup cannot start from a
     * partial backup.
     *
     * Marking ready is idempotent, so a request that got no answer, a 5xx or
     * a 429 is sent again. Any other answer is final: once a 200 came back,
     * another request could only send the email twice.
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

        $response = self::post_with_retry(self::REGISTER_BACKUP_ENDPOINT, [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ], 3, 2, function ($next_attempt, $max_attempts, $reason) {
            One_Click_Migration::write_to_log(sprintf(
                'Retrying restore-code email (attempt %d/%d): %s',
                $next_attempt,
                $max_attempts,
                $reason
            ));
        });

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: send_restore_code_email WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code_http = (int) wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        if (200 !== $code_http) {
            One_Click_Migration::write_to_log("Error: send_restore_code_email non-200 response (code=$code_http): " . substr(self::plain_body($body), 0, 500));
            return false;
        }

        $decoded = json_decode($body);
        return is_object($decoded) && !empty($decoded->ok);
    }

    /**
     * Send the debug log through the Lambda when this site cannot reach S3
     * itself, so a backup whose uploads never connect still reports why. The
     * Lambda writes it as the bucket's ocm_debug.log, only for the backup
     * this email and restore code registered and only until that backup is
     * finished.
     *
     * One request, no retry, a short timeout: this runs inside
     * One_Click_Migration::upload_log(), so it must not log through
     * write_to_log() and must never hold up or fail the backup.
     *
     * @param string $log Log text, already cut to size by the caller.
     * @return bool True when the Lambda stored it.
     */
    public static function relay_log($email, $bucket_id, $code, $log)
    {
        if (!function_exists('wp_remote_post') || '' === (string) $log) {
            return false;
        }

        $body = wp_json_encode([
            'action'    => 'relay_log',
            // Not "email": a Lambda from before relay_log would take that
            // for a new registration. It answers this with a 400.
            'registered_email' => (string) $email,
            'bucket_id' => (string) $bucket_id,
            'code'      => (string) $code,
            // Base64: the log can hold bytes that are not valid UTF-8.
            'log_b64'   => base64_encode((string) $log),
        ]);
        if (!is_string($body)) {
            return false;
        }

        $response = wp_remote_post(self::REGISTER_BACKUP_ENDPOINT, [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => $body,
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => self::RELAY_LOG_TIMEOUT,
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response));
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
        $payload = ['code' => $code];
        // Lets the service tell which plugin versions can take its newer
        // answers (the pay step inside the restore came in 3.3.7).
        $version = (string) One_Click_Migration::get_version();
        if (preg_match('/^[0-9]{1,4}(\.[0-9]{1,4}){0,3}$/D', $version)) {
            $payload['plugin_version'] = $version;
        }
        $args = [
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ];

        $response = self::post_with_retry(self::REDEEM_CODE_ENDPOINT, $args, 3, 2, function ($next_attempt, $max_attempts, $reason) {
            One_Click_Migration::write_to_log(sprintf(
                'Restore code lookup got transient error, retrying (attempt %d/%d): %s',
                $next_attempt,
                $max_attempts,
                $reason
            ));
        });

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

        $action = $payload['action'] ?? '';
        $response = self::post_with_retry(self::REGISTER_BACKUP_ENDPOINT, $args, $max_attempts, 1, function ($next_attempt, $max_attempts, $reason) use ($action) {
            // Hidden from the progress card: the upload carries on by itself.
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Lambda action "%s" got transient error, retrying (attempt %d/%d): %s',
                $action,
                $next_attempt,
                $max_attempts,
                $reason
            ));
        });

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log('Error: Lambda action WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            One_Click_Migration::write_to_log(sprintf('Error: Lambda action "%s" failed (code=%d): %s', $action, $code, substr(self::plain_body($body), 0, 500)));
            return false;
        }

        $decoded = json_decode($body);
        return is_object($decoded) ? $decoded : false;
    }

    /**
     * POST to a Lambda, sending the request again while the failure is one
     * that passes by itself: no answer, a 5xx, or a 429 from rate limiting.
     * Any other answer (a 200, a 4xx) is returned at once.
     *
     * The wait before attempt n+1 is $backoff * n seconds, or a 429's
     * Retry-After up to RETRY_AFTER_MAX_SECONDS.
     *
     * @param string   $url
     * @param array    $args         wp_remote_post() arguments.
     * @param int      $max_attempts
     * @param int      $backoff      Seconds.
     * @param callable $on_retry     Called as ($next_attempt, $max_attempts, $reason) before each wait.
     * @param int      $attempts     Set to the number of requests made.
     * @return array|\WP_Error The last response.
     */
    private static function post_with_retry($url, array $args, $max_attempts, $backoff, callable $on_retry, &$attempts = 0)
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $response = wp_remote_post($url, $args);
            $reason = self::transient_failure($response);
            if (null === $reason || $attempts >= $max_attempts) {
                return $response;
            }

            $wait = $backoff * $attempts;
            if (!is_wp_error($response) && 429 === (int) wp_remote_retrieve_response_code($response)) {
                $retry_after = self::retry_after_seconds($response);
                if (null !== $retry_after) {
                    $wait = $retry_after;
                }
            }

            $on_retry($attempts + 1, $max_attempts, $reason);
            sleep($wait);
        }
    }

    /**
     * Why a Lambda response is worth sending again ("HTTP 429"), or null
     * when it is final.
     *
     * @param array|\WP_Error $response
     * @return string|null
     */
    private static function transient_failure($response)
    {
        if (is_wp_error($response)) {
            // The reason goes into a log line, and a line holding "Error:"
            // ends the run's start mark (One_Click_Migration::write_to_log()).
            return 'network error (' . str_replace('Error:', 'error', $response->get_error_message()) . ')';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        return (429 === $code || $code >= 500) ? 'HTTP ' . $code : null;
    }

    /**
     * A 429's Retry-After (seconds or an HTTP date) as a wait of 1 to
     * RETRY_AFTER_MAX_SECONDS seconds, or null when it has none.
     *
     * @param array $response
     * @return int|null
     */
    private static function retry_after_seconds($response)
    {
        $value = wp_remote_retrieve_header($response, 'retry-after');
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        if (preg_match('/^\d+$/', $value)) {
            $seconds = (int) $value;
        } else {
            $when = strtotime($value);
            if (false === $when) {
                return null;
            }
            $seconds = $when - time();
        }

        return min(self::RETRY_AFTER_MAX_SECONDS, max(1, $seconds));
    }

    /**
     * Part size for a multipart upload: 8 MiB, raised in whole MiB for
     * archives that would otherwise need more than S3's 10,000 parts
     * (8 MiB parts stop at 80 GiB).
     *
     * @param int $file_size
     * @return int
     */
    public static function multipart_chunk_size($file_size)
    {
        $mib = 1048576;
        $needed = (int) ceil(((float) $file_size / self::MULTIPART_MAX_PARTS) / $mib) * $mib;
        return max(self::MULTIPART_CHUNK_SIZE, $needed);
    }

    /**
     * Upload an archive of MULTIPART_THRESHOLD or more in parts, resuming the
     * parts an earlier worker stored.
     *
     * Once the archive goes up in parts it never falls back to one PUT of
     * the whole file: that PUT ignores the worker's time budget and cannot
     * store more than 5 GB. A failed Lambda action (init, presign,
     * complete) or part PUT counts a retry and hands over to the next
     * worker instead (retry_upload_in_next_worker()).
     *
     * @param string $zip_file
     * @param string $folder_type
     * @return bool|null True once the archive is stored; false when the
     *                   upload retries are used up (the caller must not
     *                   send the archive another way); null when the
     *                   archive does not go up in parts (under the
     *                   threshold, no curl, no bucket id), so the caller
     *                   sends it in one PUT.
     */
    private static function upload_zip_multipart($zip_file, $folder_type)
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $bucket_id = get_option('ocm_bucket_id');
        if (!$bucket_id) {
            return null;
        }

        $key = basename($zip_file);
        $file_size = filesize($zip_file);
        if (!$file_size || $file_size < self::MULTIPART_THRESHOLD) {
            return null;
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
                return self::retry_upload_in_next_worker($folder_type);
            }

            $state = [
                'upload_id'  => $init->upload_id,
                'parts'      => [],
                'chunk_size' => self::multipart_chunk_size($file_size),
            ];
            update_option($state_option, $state, true);
        }

        $upload_id = $state['upload_id'];
        $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : [];
        // An upload begun before chunk_size was stored used the fixed size.
        $chunk_size = !empty($state['chunk_size']) ? (int) $state['chunk_size'] : self::MULTIPART_CHUNK_SIZE;
        $total_parts = (int) ceil($file_size / $chunk_size);

        $process = One_Click_Migration::$process_backup_single;
        $can_hand_over = is_object($process)
            && method_exists($process, 'time_exceeded')
            && method_exists($process, 'remaining_time');
        $parts_this_worker = 0;
        $last_part_seconds = 0;

        for ($part_number = 1; $part_number <= $total_parts; $part_number++) {
            if (!empty($parts[$part_number])) {
                continue;
            }

            // Every worker uploads at least one part, so each hand-over moves the
            // upload forward. After that, hand over once the budget is used up
            // or the next part would likely run past it.
            if ($can_hand_over && $parts_this_worker > 0
                && ($process->time_exceeded() || $process->remaining_time() < $last_part_seconds)) {
                One_Click_Migration::write_to_log("Process is Restarting");
                $process->restart_task();
            }

            $part_started = microtime(true);
            $presign = self::lambda_action([
                'action'      => 'presign_multipart_part',
                'bucket_id'   => $bucket_id,
                'key'         => $key,
                'upload_id'   => $upload_id,
                'part_number' => $part_number,
            ]);

            if (!$presign || empty($presign->url)) {
                return self::retry_upload_in_next_worker($folder_type);
            }

            $offset = ($part_number - 1) * $chunk_size;
            $part_size = min($chunk_size, $file_size - $offset);
            $etag = self::upload_multipart_part($zip_file, $presign->url, $offset, $part_size);
            if (!$etag) {
                return self::retry_upload_in_next_worker($folder_type);
            }

            $parts[$part_number] = $etag;
            $state['parts'] = $parts;
            update_option($state_option, $state, true);
            // The upload moved forward, so the next worker continues it rather
            // than retrying it: the retry limit counts attempts since the last
            // stored part, and a long upload over many short workers never hits it.
            update_option('ocm_backup_upload_retry_' . $folder_type, 0, true);
            $parts_this_worker++;
            $last_part_seconds = (int) ceil(microtime(true) - $part_started);
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
            // The stored ETags stay, so the next worker only sends complete.
            return self::retry_upload_in_next_worker($folder_type);
        }

        delete_option($state_option);
        return true;
    }

    /**
     * Count a failed upload attempt and hand the part over to the next
     * worker, which tries again (a multipart upload resumes from its stored
     * parts). Returns only once MAX_UPLOAD_RETRIES attempts since the last
     * stored part are used up: the part then fails closed.
     *
     * @param string $folder_type
     * @return false
     */
    private static function retry_upload_in_next_worker($folder_type)
    {
        $retry_count = get_option('ocm_backup_upload_retry_' . $folder_type, 1);
        if ($retry_count <= self::MAX_UPLOAD_RETRIES) {
            One_Click_Migration::write_to_log("Process is Restarting");
            update_option('ocm_backup_upload_retry_' . $folder_type, $retry_count + 1, true);
            // Dispatches the next worker and ends this request.
            One_Click_Migration::$process_backup_single->restart_task();
            return false;
        }

        One_Click_Migration::write_to_log(sprintf('Upload retries exhausted for %s; backup will fail closed.', $folder_type));
        return false;
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
        OCM_CA_Bundle::curl($ch);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $part_size);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(60, One_Click_Migration::get_timeout()));
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        // Capture the body: without this an S3 error document is printed into
        // whatever request runs the worker (possibly the progress REST call).
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        });

        // A successful PUT has an empty body, so only false means failure.
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // Dropping the last reference closes the handle on every PHP version;
        // curl_close() is a no-op since 8.0 and deprecated in 8.5.
        unset($ch);
        fclose($file_handle);

        if ($body === false || $errno !== 0 || $http_code < 200 || $http_code >= 300) {
            One_Click_Migration::write_to_log(sprintf(
                'Upload failed for multipart part at offset %d: %s',
                $offset,
                $error ? $error : 'HTTP ' . $http_code
            ));
            return false;
        }

        if (empty($headers['etag'])) {
            One_Click_Migration::write_to_log(sprintf(
                'Upload failed for multipart part at offset %d: no ETag in the storage response (HTTP %d)',
                $offset,
                $http_code
            ));
            return false;
        }

        return $headers['etag'];
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
        if (null !== $multipart_result) {
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
            $client = new Client(OCM_CA_Bundle::guzzle([
                'timeout' => $upload_timeout,
            ]));

            $response = $client->send($request);
            $status = (int) $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new Exception('Storage returned HTTP ' . $status);
            }

            return true;

        } catch (Exception $e) {
            One_Click_Migration::write_to_log("Upload failed for " . $folder_type . ": " . $e->getMessage());
            return self::retry_upload_in_next_worker($folder_type);
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

    /**
     * A response body as plain text for the log: the progress card shows log
     * lines as HTML, so an error page's markup must not reach it.
     *
     * @param mixed $body
     * @return string
     */
    private static function plain_body($body)
    {
        $body = (string) $body;
        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($body) : strip_tags($body);
    }
}
