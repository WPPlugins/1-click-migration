<?php

namespace OCM;

/**
 * The certificate bundle for the plugin's own HTTPS transfers to the backup
 * storage: the log, the backup parts, the restore downloads.
 *
 * Those go out through Guzzle and curl directly, not through WordPress's HTTP
 * API, so they used to verify the storage's certificate against whatever
 * certificate store this PHP was set up with. WordPress doesn't: it brings
 * its own bundle (wp-includes/certificates/ca-bundle.crt) for every request
 * it makes. On a PHP with no certificate store, or an outdated one (common on
 * local stacks), registering the backup therefore worked, since that call is
 * a wp_remote_post(), and then every transfer failed with "cURL error 60:
 * SSL certificate problem". Nothing reached the bucket, not even the log, so
 * the run looked like a backup that was started and never finished.
 *
 * Every transfer now verifies against the bundle WordPress itself uses: a
 * site that can register a backup can also upload it.
 */
class OCM_CA_Bundle
{
    /**
     * WordPress's own certificate bundle, or '' to leave the certificate
     * store to PHP (no WordPress bundle on disk, or the filter said so).
     *
     * @return string
     */
    public static function path()
    {
        $path = (defined('ABSPATH') && defined('WPINC'))
            ? ABSPATH . WPINC . '/certificates/ca-bundle.crt'
            : '';

        if (function_exists('apply_filters')) {
            /**
             * The certificate bundle for transfers to the backup storage.
             * Return another file's path to use that one, or '' for the
             * certificate store PHP is configured with.
             *
             * @param string $path WordPress's own bundle.
             */
            $path = apply_filters('ocm_ca_bundle', $path);
        }

        return (is_string($path) && '' !== $path && is_file($path) && is_readable($path)) ? $path : '';
    }

    /**
     * Options for a Guzzle client that talks to the backup storage.
     *
     * @param array $options The client's other options; a 'verify' in them is kept.
     * @return array
     */
    public static function guzzle(array $options = array())
    {
        $path = self::path();
        if ('' !== $path && !array_key_exists('verify', $options)) {
            $options['verify'] = $path;
        }

        return $options;
    }

    /**
     * Point a curl handle that talks to the backup storage at the bundle.
     *
     * @param resource|\CurlHandle $ch
     * @return void
     */
    public static function curl($ch)
    {
        $path = self::path();
        if ('' !== $path) {
            curl_setopt($ch, CURLOPT_CAINFO, $path);
        }
    }
}
