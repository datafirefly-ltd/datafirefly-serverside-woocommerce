<?php
/**
 * DataFirefly Server-Side, the shop's own daily totals.
 *
 * @package DataFirefly_ServerSide
 */

if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Truth
{
    const CRON_HOOK = 'dfss_daily_truth';
    const LAST_SENT_OPTION = 'dfss_truth_last_date';

    /**
     * Order statuses that count as a sale.
     *
     * @var string[]
     */
    private static $paid_statuses = array('processing', 'on-hold', 'completed', 'refunded');

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Just after the shop's own 00:30, late enough that yesterday is definitely closed, early
            // enough to be same-morning data.
            wp_schedule_event(self::next_half_past_midnight(), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Send yesterday's totals unless they already went out.
     *
     * @param array       $opts   Plugin options.
     * @param DFSS_Client $client Configured client.
     */
    public static function run($opts, $client)
    {
        try {
            $last = (string) get_option(self::LAST_SENT_OPTION, '');
            foreach (self::pending_days($last) as $day) {
                $totals = self::totals_for($day);
                if ($totals === null) {
                    return;
                }
                $result = $client->send_truth($totals);
                if (empty($result['ok'])) {
                    if ((int) $result['code'] === 400) {
                        // A 400 is our own bug, not a transient failure: record the day so it stops retrying.
                        update_option(self::LAST_SENT_OPTION, $day, false);
                        continue;
                    }

                    return; // transient: stop here, tomorrow's run resumes
                }
                update_option(self::LAST_SENT_OPTION, $day, false);
            }
        } catch (Throwable $e) {
            // A failed report costs nothing.
            return;
        }
    }

    /**
     * The days still owed, oldest first, capped at 7 so a plugin dormant for months does not wake up
     * and replay a year.
     *
     * @param string $last Last reported day (YYYY-MM-DD), '' on first run.
     *
     * @return string[]
     */
    public static function pending_days($last, $today = null)
    {
        $today = $today !== null ? $today : self::today();
        $yesterday = gmdate('Y-m-d', strtotime($today . ' -1 day'));

        if ($last === '' || $last >= $yesterday) {
            return $last !== '' && $last >= $yesterday ? array() : array($yesterday);
        }

        $days = array();
        $cursor = gmdate('Y-m-d', strtotime($last . ' +1 day'));
        while ($cursor <= $yesterday && count($days) < 7) {
            $days[] = $cursor;
            $cursor = gmdate('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return $days;
    }

    /**
     * Today in the SHOP's timezone, not the server's.
     */
    public static function today()
    {
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);

        return $now->format('Y-m-d');
    }

    /**
     * Aggregate one day.
     *
     * @param string $day YYYY-MM-DD in the shop's timezone.
     *
     * @return array|null
     */
    public static function totals_for($day)
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        $tz = wp_timezone_string();

        // Dates are read in the site's timezone. 'type' excludes refund objects (they have no
        // get_total_refunded()); 'lang' => '' counts every Polylang language, not just the default.
        $orders = wc_get_orders(array(
            'type' => 'shop_order',
            'lang' => '',
            'limit' => -1,
            'status' => self::$paid_statuses,
            'date_created' => $day . '...' . $day . ' 23:59:59',
            'return' => 'objects',
        ));

        $count = 0;
        $revenue = 0.0;
        $refunds = 0;
        $refund_amount = 0.0;
        foreach ($orders as $order) {
            ++$count;
            $revenue += (float) $order->get_total();
            $r = (float) $order->get_total_refunded();
            if ($r > 0) {
                ++$refunds;
                $refund_amount += $r;
            }
        }

        return array(
            'date' => $day,
            'orders' => $count,
            'revenue' => round($revenue, 2),
            'currency' => strtoupper(get_woocommerce_currency()),
            'refunds' => $refunds,
            'refundAmount' => round($refund_amount, 2),
            'timezone' => $tz !== '' ? $tz : 'UTC',
        );
    }

    /**
     * Next 00:30 in the shop's timezone, as a UTC timestamp.
     */
    private static function next_half_past_midnight()
    {
        $tz = wp_timezone();
        $next = new DateTime('tomorrow 00:30', $tz);

        return $next->getTimestamp();
    }
}
