<?php

namespace OCM;

/**
 * Where the plugin keeps files that must stay out of the web root: the debug
 * log, the restore worker lock.
 *
 * The shared root is .ocm-private beside the WordPress root, so sibling
 * installs (/var/www/site-a and /var/www/site-b, or several sites in one
 * cPanel home) share it. Each site gets its own directory inside it, named
 * after its ABSPATH. Keying on ABSPATH rather than home_url keeps the name
 * stable while a restore imports another site's URLs.
 *
 * Where that folder can't be written, resolve() falls back to a directory
 * with an unguessable name inside wp-content.
 *
 * Earlier plugin versions wrote ocm_debug.log and restore-worker.lock to the
 * root itself. Those may belong to any of the siblings, so nothing here reads,
 * moves or deletes them.
 */
class OCM_Private_Dir
{
    const ROOT_NAME = '.ocm-private';

    /**
     * The .ocm-private directory shared by every install in the same parent.
     *
     * @param string $abspath
     * @return string
     */
    public static function root_for($abspath)
    {
        return dirname(self::normalize_abspath($abspath)) . '/' . self::ROOT_NAME;
    }

    /**
     * This site's directory inside root_for(): the WordPress folder's name,
     * for whoever lists the root, plus a hash of its full path, so two sites
     * never share one.
     *
     * @param string $abspath
     * @return string
     */
    public static function site_dir_for($abspath)
    {
        $path = self::normalize_abspath($abspath);
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($path)), '.-');
        $name = $name === '' ? 'site' : substr($name, 0, 40);

        return self::root_for($abspath) . '/' . $name . '-' . substr(md5($path), 0, 8);
    }

    /**
     * Whether a site directory exists and can be written, or could be created:
     * nothing is created here, so this is safe on every page load.
     *
     * @param string $site_dir
     * @return bool
     */
    public static function usable($site_dir)
    {
        $site_dir = rtrim((string) $site_dir, '/\\');
        if ($site_dir === '') {
            return false;
        }
        if (@is_dir($site_dir)) {
            return @is_writable($site_dir);
        }
        $root = dirname($site_dir);
        if (@is_dir($root)) {
            return @is_writable($root);
        }

        return @is_dir(dirname($root)) && @is_writable(dirname($root));
    }

    /**
     * The fallback for hosts where the folder above the WordPress root can't
     * be written (it belongs to root, or open_basedir stops at the site): a
     * directory inside wp-content whose name nobody can guess. The name comes
     * from wp-config.php secrets, not from the database, so it stays the same
     * while a restore replaces the options table. It carries the same deny
     * files as the shared root; on servers that ignore them, the name is what
     * keeps the log private.
     *
     * @param string $abspath
     * @param string $content_dir WP_CONTENT_DIR
     * @param string $secret      Site secret (salts, database password)
     * @return string '' when there is no secret to build an unguessable name from.
     */
    public static function fallback_site_dir_for($abspath, $content_dir, $secret)
    {
        $content_dir = rtrim((string) $content_dir, '/\\');
        if ($content_dir === '' || strlen((string) $secret) < 16) {
            return '';
        }
        $token = substr(hash_hmac('sha256', self::normalize_abspath($abspath), (string) $secret), 0, 32);

        return $content_dir . '/ocm-private-' . $token . '/site';
    }

    /**
     * This site's private directory: beside the WordPress root when that
     * works, else the wp-content fallback when that works. When neither can be
     * written the preferred path comes back, so callers fail the way they
     * always did.
     *
     * @param string $abspath
     * @param string $content_dir
     * @param string $secret
     * @return string
     */
    public static function resolve($abspath, $content_dir, $secret)
    {
        $preferred = self::site_dir_for($abspath);
        if (self::usable($preferred)) {
            return $preferred;
        }
        $fallback = self::fallback_site_dir_for($abspath, $content_dir, $secret);
        if ($fallback !== '' && self::usable($fallback)) {
            return $fallback;
        }

        return $preferred;
    }

    /**
     * Secret for fallback_site_dir_for(), from constants wp-config.php defines.
     *
     * @return string
     */
    public static function site_secret()
    {
        $secret = '';
        foreach (array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'DB_PASSWORD', 'DB_NAME', 'DB_USER') as $name) {
            if (defined($name) && is_string(constant($name))) {
                $secret .= '|' . constant($name);
            }
        }

        return $secret;
    }

    /**
     * Create a site directory and the root above it, each with deny files in
     * case the parent of the WordPress root is itself served.
     *
     * @param string $site_dir
     * @return bool False when either directory can't be created.
     */
    public static function ensure($site_dir)
    {
        $site_dir = rtrim($site_dir, '/\\');
        foreach (array(dirname($site_dir), $site_dir) as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return false;
            }
            self::write_deny_files($dir);
        }

        return true;
    }

    private static function write_deny_files($dir)
    {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\nRequire all denied\n");
        }
        if (!is_file($dir . '/web.config')) {
            @file_put_contents(
                $dir . '/web.config',
                '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'
            );
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(404);\n");
        }
    }

    /**
     * ABSPATH without its trailing slash, with symlinks resolved: WordPress
     * builds ABSPATH from __DIR__ (resolved), but WP-CLI uses --path as given.
     *
     * @param string $abspath
     * @return string
     */
    private static function normalize_abspath($abspath)
    {
        $real = @realpath((string) $abspath);
        $path = is_string($real) && $real !== '' ? $real : (string) $abspath;

        return rtrim($path, '/\\');
    }
}
