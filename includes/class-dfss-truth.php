<?php
/**
 * DataFirefly Server-Side — the shop's own daily totals.
 *
 * Once a day the plugin tells the dispatcher what the shop ACTUALLY sold the
 * day before: how many orders, how much revenue, how much refunded. That is
 * the reference the delivered events get judged against — without it,
 * "we measured 87 purchases" is a number with nothing to compare it to.
 *
 * Totals only. No order id, no customer, no line item.
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
     * Order statuses that count as a sale. Deliberately the paid ones only:
     * a pending or cancelled order is not revenue, and counting it would make
     * the shop look under-tracked when the tracking is in fact correct.
     *
     * `refunded` is a paid order too: the money came in, the purchase event
     * was sent, and the refund is what the refund columns are for. Leaving it
     * out made a fully refunded order vanish from BOTH sides of the day
     * (neither a sale nor a refund), so the dispatcher saw one purchase event
     * with no order behind it.
     *
     * @var string[]
     */
    private static $paid_statuses = array('processing', 'on-hold', 'completed', 'refunded');

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Just after the shop's own 00:30 — late enough that yesterday is
            // definitely closed, early enough to be same-morning data.
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
     * WP-Cron only fires when the site gets traffic, so a quiet shop can skip a
     * day. The stored date makes the job catch up rather than lose the day: it
     * reports whatever day has not been reported yet, up to a week back.
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
                        // Our own bug, not a transient failure — recording the
                        // day stops it retrying forever, and the log says why.
                        update_option(self::LAST_SENT_OPTION, $day, false);
                        continue;
                    }

                    return; // transient: stop here, tomorrow's run resumes
                }
                update_option(self::LAST_SENT_OPTION, $day, false);
            }
        } catch (Throwable $e) {
            // A failed report costs nothing. Throwable, not Exception: a PHP
            // Error raised here must not take the whole WP-Cron request down
            // with it, which also silences every hook queued after this one.
            return;
        }
    }

    /**
     * The days still owed, oldest first, capped at 7 so a plugin dormant for
     * months does not wake up and replay a year.
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

    /** Today in the SHOP's timezone, not the server's. */
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

        // WooCommerce reads these dates in the site's timezone, which is
        // exactly the day the shop means.
        // `type` is NOT optional: without it wc_get_orders() also returns
        // refund objects (WC_Order_Refund, which has no get_total_refunded()).
        // The first refund of the shop then killed this job with a fatal, and
        // since the cursor never advanced past that day, every night after it
        // died on the same day: the dispatcher stopped hearing from the shop.
        // `lang` => '' is NOT optional either: Polylang for WooCommerce joins
        // every typed order query on the CURRENT language (the default one
        // under WP-Cron), and the shop's orders in its other languages simply
        // vanish from the count. Empty string is Polylang's "all languages".
        // Harmless without Polylang: WooCommerce ignores the key.
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

    /** Next 00:30 in the shop's timezone, as a UTC timestamp. */
    private static function next_half_past_midnight()
    {
        $tz = wp_timezone();
        $next = new DateTime('tomorrow 00:30', $tz);

        return $next->getTimestamp();
    }
}
