<?php
/**
 * Exchange rates: the European Central Bank's public daily reference rates (EUR base),
 * https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml, published on working days around 16:00 CET.
 * The last good set is kept if a fetch fails; nothing is guessed.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets_Rates
{
    public const OPTION = 'omg_markets_rates';
    public const CRON = 'omg_markets_refresh_rates';
    public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private static ?array $data = null;

    public static function init(): void
    {
        add_action(self::CRON, [__CLASS__, 'refresh']);
        if (!wp_next_scheduled(self::CRON)) {
            self::schedule();
        }
    }

    public static function schedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 300, 'twicedaily', self::CRON);
    }

    /** ['date' => 'Y-m-d', 'fetched' => unix, 'rates' => ['USD' => 1.08, ...], 'error' => ''] */
    public static function data(): array
    {
        if (self::$data === null) {
            $d = get_option(self::OPTION, []);
            self::$data = is_array($d) ? $d : [];
        }
        return self::$data;
    }

    /** Currencies the ECB publishes, plus EUR. */
    public static function currencies(): array
    {
        $c = array_keys(self::data()['rates'] ?? []);
        $c[] = 'EUR';
        sort($c);
        return array_values(array_unique($c));
    }

    /** How many units of $to one unit of $from buys, or null if either is not published. */
    public static function rate(string $from, string $to): ?float
    {
        if ($from === $to) {
            return 1.0;
        }
        $r = self::data()['rates'] ?? [];
        $r['EUR'] = 1.0;
        if (empty($r[$from]) || empty($r[$to])) {
            return null;
        }
        return (float) $r[$to] / (float) $r[$from];
    }

    /** @return true|string true on success, otherwise the reason it failed. */
    public static function refresh()
    {
        $res = wp_remote_get(self::URL, ['timeout' => 20, 'user-agent' => 'OMGGamer-Markets/' . OMG_MARKETS_VERSION]);
        $error = '';
        $rates = [];
        $date = '';
        if (is_wp_error($res)) {
            $error = $res->get_error_message();
        } elseif ((int) wp_remote_retrieve_response_code($res) !== 200) {
            $error = 'ECB answered HTTP ' . wp_remote_retrieve_response_code($res);
        } else {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string(wp_remote_retrieve_body($res), 'SimpleXMLElement', LIBXML_NONET);
            libxml_use_internal_errors($prev);
            if (!$xml) {
                $error = 'The ECB response was not valid XML';
            } else {
                foreach ($xml->xpath('//*[@currency and @rate]') ?: [] as $el) {
                    $cur = strtoupper((string) $el['currency']);
                    $rate = (float) $el['rate'];
                    if (strlen($cur) === 3 && $rate > 0) {
                        $rates[$cur] = $rate;
                    }
                }
                $t = $xml->xpath('//*[@time]');
                $date = $t ? (string) $t[0]['time'] : '';
                if (!$rates) {
                    $error = 'The ECB response contained no rates';
                }
            }
        }
        $data = self::data();
        if ($error) {
            $data['error'] = $error;
            $data['failed'] = time();
        } else {
            $data = ['date' => $date, 'fetched' => time(), 'rates' => $rates, 'error' => ''];
        }
        update_option(self::OPTION, $data, true);
        self::$data = $data;
        if (!$error) {
            do_action('omg_markets_rates_updated');
        }
        return $error ?: true;
    }
}
