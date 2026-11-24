<?php

namespace OCM;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

class OCM_Local_Scheduler
{
    const CRON_HOOK = 'ocm_local_scheduled_backup';
    const SCHEDULE_OPTION = 'ocm_local_schedule';
    const STATUS_OPTION = 'ocm_local_schedule_status';
    const FREQUENCIES = array('hourly', 'daily', 'weekly', 'monthly');

    /**
     * @return array
     */
    public static function default_schedule()
    {
        return array(
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'day_of_week' => 0,
            'day_of_month' => 1,
            'parts' => array('db', 'themes', 'plugins', 'uploads'),
        );
    }

    /**
     * Sanitize schedule settings. Returns WP_Error when explicitly validating
     * an empty parts selection on save.
     *
     * @param mixed $input
     * @param bool  $strict_parts
     * @return array|WP_Error
     */
    public static function sanitize_schedule($input, $strict_parts = false)
    {
        $defaults = self::default_schedule();
        if (!is_array($input)) {
            return $defaults;
        }

        $enabled = !empty($input['enabled']);

        $frequency = isset($input['frequency']) ? strtolower(trim((string) $input['frequency'])) : $defaults['frequency'];
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            $frequency = $defaults['frequency'];
        }

        $time = isset($input['time']) ? trim((string) $input['time']) : $defaults['time'];
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time, $m)) {
            $time = $defaults['time'];
        } else {
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        $day_of_week = isset($input['day_of_week']) && is_numeric($input['day_of_week'])
            ? (int) $input['day_of_week']
            : $defaults['day_of_week'];
        $day_of_week = max(0, min(6, $day_of_week));

        $day_of_month = isset($input['day_of_month']) && is_numeric($input['day_of_month'])
            ? (int) $input['day_of_month']
            : $defaults['day_of_month'];
        $day_of_month = max(1, min(28, $day_of_month));

        $raw_parts = isset($input['parts']) && is_array($input['parts']) ? $input['parts'] : array();
        $clean_parts = array();
        foreach (OCM_Local_Storage::PARTS as $part) {
            if (in_array($part, $raw_parts, true)) {
                $clean_parts[] = $part;
            }
        }

        if (empty($clean_parts)) {
            if ($strict_parts) {
                return new WP_Error('no_parts_selected', __('Select at least one component for scheduled backups.', '1-click-migration'));
            }
            $clean_parts = $defaults['parts'];
        }

        return array(
            'enabled' => $enabled,
            'frequency' => $frequency,
            'time' => $time,
            'day_of_week' => $day_of_week,
            'day_of_month' => $day_of_month,
            'parts' => $clean_parts,
        );
    }

    /**
     * @return array
     */
    public static function get_schedule()
    {
        $stored = get_option(self::SCHEDULE_OPTION, array());
        $clean = self::sanitize_schedule($stored, false);
        return is_array($clean) ? $clean : self::default_schedule();
    }

    /**
     * @param array $input
     * @return array|WP_Error
     */
    public static function save_schedule(array $input)
    {
        $clean = self::sanitize_schedule($input, true);
        if (is_wp_error($clean)) {
            return $clean;
        }
        update_option(self::SCHEDULE_OPTION, $clean, false);
        self::sync_cron_schedule($clean);
        return $clean;
    }

    /**
     * Resolve WordPress site timezone safely on WP 5.3+ and test harnesses.
     *
     * @return DateTimeZone
     */
    public static function site_timezone()
    {
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof DateTimeZone) {
                return $tz;
            }
        }
        $tz_string = (string) get_option('timezone_string', '');
        if ($tz_string !== '') {
            try {
                return new DateTimeZone($tz_string);
            } catch (\Throwable $e) {
                // Fall back to offset/UTC.
            }
        }
        $offset = (float) get_option('gmt_offset', 0);
        if ($offset != 0.0) {
            $sign = $offset < 0 ? '-' : '+';
            $abs = abs($offset);
            $hours = (int) floor($abs);
            $mins = (int) round(($abs - $hours) * 60);
            try {
                return new DateTimeZone(sprintf('%s%02d:%02d', $sign, $hours, $mins));
            } catch (\Throwable $e) {
                // Fall back to UTC.
            }
        }
        return new DateTimeZone('UTC');
    }

    /**
     * Compute the next UTC unix timestamp strictly greater than $now.
     *
     * @param array             $schedule
     * @param int|null          $now
     * @param DateTimeZone|null $tz
     * @return int
     */
    public static function compute_next_run(array $schedule, $now = null, $tz = null)
    {
        $schedule = self::sanitize_schedule($schedule, false);
        if (!is_array($schedule)) {
            $schedule = self::default_schedule();
        }
        $now = null === $now ? time() : (int) $now;
        if (!($tz instanceof DateTimeZone)) {
            $tz = self::site_timezone();
        }

        list($hour, $minute) = array_map('intval', explode(':', $schedule['time']));
        $now_dt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimestamp($now)->setTimezone($tz);

        switch ($schedule['frequency']) {
            case 'hourly':
                $candidate = $now_dt->setTime((int) $now_dt->format('G'), $minute, 0);
                if ($candidate->getTimestamp() <= $now) {
                    $candidate = $candidate->modify('+1 hour');
                }
                break;

            case 'weekly':
                $target_dow = (int) $schedule['day_of_week'];
                $current_dow = (int) $now_dt->format('w');
                $days_ahead = ($target_dow - $current_dow + 7) % 7;
                $candidate = $now_dt->modify('+' . $days_ahead . ' days')->setTime($hour, $minute, 0);
                if ($candidate->getTimestamp() <= $now) {
                    $candidate = $candidate->modify('+7 days')->setTime($hour, $minute, 0);
                }
                break;

            case 'monthly':
                $target_dom = max(1, min(28, (int) $schedule['day_of_month']));
                $year = (int) $now_dt->format('Y');
                $month = (int) $now_dt->format('n');
                $candidate = $now_dt->setDate($year, $month, $target_dom)->setTime($hour, $minute, 0);
                if ($candidate->getTimestamp() <= $now) {
                    $candidate = $candidate->modify('+1 month')->setTime($hour, $minute, 0);
                }
                break;

            case 'daily':
            default:
                $candidate = $now_dt->setTime($hour, $minute, 0);
                if ($candidate->getTimestamp() <= $now) {
                    $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
                }
                break;
        }

        return max($now + 1, $candidate->getTimestamp());
    }

    /**
     * Clear any existing scheduled cron event and re-arm if enabled.
     *
     * @param array|null $schedule
     * @param int|null   $after
     * @return int Scheduled UTC timestamp, or 0 when disabled.
     */
    public static function sync_cron_schedule($schedule = null, $after = null)
    {
        if (!is_array($schedule)) {
            $schedule = self::get_schedule();
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        if (empty($schedule['enabled'])) {
            return 0;
        }
        $next = self::compute_next_run($schedule, $after);
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event($next, self::CRON_HOOK);
        }
        return $next;
    }

    /**
     * Unschedule all pending scheduled backup events.
     *
     * @return void
     */
    public static function unschedule()
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * @return int UTC timestamp or 0 if not scheduled.
     */
    public static function next_scheduled_timestamp()
    {
        if (!function_exists('wp_next_scheduled')) {
            return 0;
        }
        $ts = wp_next_scheduled(self::CRON_HOOK);
        return $ts ? (int) $ts : 0;
    }

    /**
     * Format a UTC timestamp in the site's timezone.
     *
     * @param int $timestamp
     * @return string
     */
    public static function format_timestamp($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '';
        }
        if (function_exists('wp_date')) {
            return (string) wp_date('Y-m-d H:i', $timestamp, self::site_timezone());
        }
        $dt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimestamp($timestamp)->setTimezone(self::site_timezone());
        return $dt->format('Y-m-d H:i');
    }

    /**
     * @return array{last_run_at:int,last_outcome:string,last_message:string,last_backup_id:string}
     */
    public static function get_status()
    {
        $raw = get_option(self::STATUS_OPTION, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        return array(
            'last_run_at' => isset($raw['last_run_at']) ? (int) $raw['last_run_at'] : 0,
            'last_outcome' => isset($raw['last_outcome']) ? (string) $raw['last_outcome'] : '',
            'last_message' => isset($raw['last_message']) ? (string) $raw['last_message'] : '',
            'last_backup_id' => isset($raw['last_backup_id']) ? (string) $raw['last_backup_id'] : '',
        );
    }

    /**
     * @param string $outcome   'started'|'success'|'skipped'|'failed'
     * @param string $message
     * @param string $backup_id
     * @return void
     */
    public static function record_status($outcome, $message, $backup_id = '')
    {
        update_option(self::STATUS_OPTION, array(
            'last_run_at' => time(),
            'last_outcome' => sanitize_key((string) $outcome),
            'last_message' => sanitize_text_field((string) $message),
            'last_backup_id' => (string) $backup_id,
        ), false);
    }

    /**
     * WP-Cron callback for ocm_local_scheduled_backup.
     * Always re-arms the next single event before running so failures or skips
     * never silently disable future scheduled backups.
     *
     * @return void
     */
    public static function handle_cron_event()
    {
        $schedule = self::get_schedule();
        if (empty($schedule['enabled'])) {
            self::unschedule();
            return;
        }

        // Arm the next occurrence strictly ahead of this run.
        self::sync_cron_schedule($schedule, time() + 60);

        if (class_exists(__NAMESPACE__ . '\\OCM_Local_Backup')) {
            OCM_Local_Backup::start_scheduled_backup($schedule);
        }
    }
}
