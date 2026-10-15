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
