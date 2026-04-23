<?php

/**
 * Class MC4WP_WooCommerce_Coupon_Sync
 *
 * Syncs WooCommerce coupons to Mailchimp as Promo Rules & Promo Codes.
 *
 * @since 4.13.0
 * @ignore
 */
class MC4WP_WooCommerce_Coupon_Sync
{
    /**
     * @var string
     */
    private $store_id = '';

    public function __construct()
    {
        add_action('save_post_shop_coupon', [$this, 'sync_coupon'], 20, 1);
        add_action('woocommerce_delete_coupon', [$this, 'delete_coupon'], 10, 1);
    }

    /**
     * @param int $coupon_id
     */
    public function sync_coupon($coupon_id)
    {
        $log = mc4wp_get_debug_log();

        if (get_post_type($coupon_id) !== 'shop_coupon') {
            return;
        }

        if (in_array(get_post_status($coupon_id), ['auto-draft', 'trash'], true)) {
            return;
        }

        $store_id = $this->get_store_id();
        if (empty($store_id)) {
            $log->warning('Coupon Sync > Could not auto-discover a Mailchimp Store ID for this site.');
            return;
        }

        $coupon = new WC_Coupon($coupon_id);
        if (! $coupon->get_id()) {
            return;
        }

        $api       = mc4wp_get_api_v3();
        $rule_id   = 'wc_coupon_' . $coupon_id;
        $rule_data = $this->build_rule_data($coupon);

        try {
            // update existing rule, or create if not found
            try {
                $api->update_ecommerce_store_promo_rule($store_id, $rule_id, $rule_data);
            } catch (MC4WP_API_Resource_Not_Found_Exception $e) {
                $rule_data['id'] = $rule_id;
                $api->add_ecommerce_store_promo_rule($store_id, $rule_data);
            }

            // update or create associated promo code
            $code_id   = sanitize_title($coupon->get_code());
            $code_data = $this->build_code_data($coupon);

            try {
                $api->update_ecommerce_store_promo_rule_promo_code($store_id, $rule_id, $code_id, $code_data);
            } catch (MC4WP_API_Resource_Not_Found_Exception $e) {
                $code_data['id'] = $code_id;
                $api->add_ecommerce_store_promo_rule_promo_code($store_id, $rule_id, $code_data);
            }

            $log->info(sprintf('Coupon Sync > Successfully synced %s', $coupon->get_code()));
        } catch (MC4WP_API_Exception $e) {
            $log->error(sprintf('Coupon Sync > Mailchimp API error: %s', $e->getMessage()));
        }
    }

    /**
     * @param int $coupon_id
     */
    public function delete_coupon($coupon_id)
    {
        $log      = mc4wp_get_debug_log();
        $store_id = $this->get_store_id();
        if (empty($store_id)) {
            return;
        }

        $rule_id = 'wc_coupon_' . $coupon_id;

        try {
            mc4wp_get_api_v3()->delete_ecommerce_store_promo_rule($store_id, $rule_id);
            $log->info(sprintf('Coupon Sync > Deleted coupon #%d from Mailchimp', $coupon_id));
        } catch (MC4WP_API_Exception $e) {
            // rule may not exist in Mailchimp, that's fine
        }
    }

    /**
     * @return string
     */
    private function get_store_id()
    {
        if ($this->store_id !== '') {
            return $this->store_id;
        }

        /**
         * @param string $store_id
         */
        $store_id = (string) apply_filters('mc4wp_coupon_sync_store_id', '');
        if ($store_id !== '') {
            return $this->cache_store_id($store_id);
        }

        // MC4WP E-commerce add-on
        $store_id = (string) get_option('mc4wp_ecommerce_store_id', '');
        if ($store_id !== '') {
            return $this->cache_store_id($store_id);
        }

        // official "Mailchimp for WooCommerce" plugin
        $mc4wc_options = (array) get_option('mailchimp-woocommerce', []);
        if (! empty($mc4wc_options['store_id'])) {
            return $this->cache_store_id((string) $mc4wc_options['store_id']);
        }

        // previously discovered store
        $store_id = (string) get_option('mc4wp_coupon_sync_discovered_store_id', '');
        if ($store_id !== '') {
            return $this->cache_store_id($store_id);
        }

        // auto-discover or create via API
        return $this->discover_or_create_store();
    }

    /**
     * @param string $store_id
     * @return string
     */
    private function cache_store_id($store_id)
    {
        $this->store_id = $store_id;
        return $store_id;
    }

    /**
     * @return string
     */
    private function discover_or_create_store()
    {
        $api = mc4wp_get_api_v3();

        try {
            $response = $api->get_ecommerce_stores();
        } catch (MC4WP_API_Exception $e) {
            return '';
        }

        $site_domain = wp_parse_url(home_url(), PHP_URL_HOST);

        if (! empty($response->stores)) {
            // single store: use it
            if (count($response->stores) === 1) {
                return $this->save_discovered_store_id($response->stores[0]->id);
            }

            // multiple stores: match by domain
            foreach ($response->stores as $store) {
                if (! empty($store->domain) && stripos($site_domain, $store->domain) !== false) {
                    return $this->save_discovered_store_id($store->id);
                }
            }
        }

        // no match found: create a store
        return $this->create_store($api, $site_domain);
    }

    /**
     * @param string $store_id
     * @return string
     */
    private function save_discovered_store_id($store_id)
    {
        update_option('mc4wp_coupon_sync_discovered_store_id', $store_id);
        return $this->cache_store_id($store_id);
    }

    /**
     * @param MC4WP_API_V3 $api
     * @param string       $site_domain
     * @return string
     */
    private function create_store($api, $site_domain)
    {
        $list_id = $this->get_list_id();
        if (empty($list_id)) {
            return '';
        }

        $store_id = 'mc4wp_' . md5($site_domain);

        try {
            $api->add_ecommerce_store([
                'id'            => $store_id,
                'list_id'       => $list_id,
                'name'          => get_bloginfo('name') ?: $site_domain,
                'domain'        => $site_domain,
                'currency_code' => get_woocommerce_currency(),
                'platform'      => 'woocommerce',
            ]);
        } catch (MC4WP_API_Exception $e) {
            // store may already exist; verify before proceeding
            try {
                $api->get_ecommerce_store($store_id);
            } catch (MC4WP_API_Exception $verify_error) {
                return '';
            }
        }

        return $this->save_discovered_store_id($store_id);
    }

    /**
     * @return string
     */
    private function get_list_id()
    {
        // from WooCommerce integration settings
        $integration_options = (array) get_option('mc4wp_integrations', []);
        if (! empty($integration_options['woocommerce']['lists'])) {
            return (string) reset($integration_options['woocommerce']['lists']);
        }

        // from the first available Mailchimp list
        try {
            $mailchimp = new MC4WP_MailChimp();
            $lists     = $mailchimp->get_lists();
            if (! empty($lists)) {
                return (string) reset($lists)->id;
            }
        } catch (Exception $e) {
            // ignore
        }

        return '';
    }

    /**
     * @param WC_Coupon $coupon
     * @return array
     */
    private function build_rule_data(WC_Coupon $coupon)
    {
        $type   = $coupon->get_discount_type();
        $amount = (float) $coupon->get_amount();

        if ('percent' === $type) {
            $mc_type   = 'percentage';
            $mc_target = 'total';
            $amount    = $amount / 100;
        } elseif ('fixed_cart' === $type) {
            $mc_type   = 'fixed';
            $mc_target = 'total';
        } else {
            $mc_type   = 'fixed';
            $mc_target = 'per_item';
        }

        $data = [
            'title'       => $coupon->get_code(),
            'description' => $coupon->get_description() ?: $coupon->get_code(),
            'amount'      => $amount,
            'type'        => $mc_type,
            'target'      => $mc_target,
            'enabled'     => 'publish' === get_post_status($coupon->get_id()),
        ];

        $expiry_date = $coupon->get_date_expires();
        if ($expiry_date) {
            $data['expires_at'] = $expiry_date->format('Y-m-d\TH:i:sO');
        }

        /**
         * @param array     $data
         * @param WC_Coupon $coupon
         */
        return apply_filters('mc4wp_promo_rule_data', $data, $coupon);
    }

    /**
     * @param WC_Coupon $coupon
     * @return array
     */
    private function build_code_data(WC_Coupon $coupon)
    {
        $data = [
            'code'               => strtoupper($coupon->get_code()),
            'redemption_url'     => esc_url(wc_get_cart_url()),
            'usage_count'        => (int) $coupon->get_usage_count(),
            'enabled'            => 'publish' === get_post_status($coupon->get_id()),
            'created_at_foreign' => gmdate('Y-m-d\TH:i:sO', $coupon->get_date_created() ? $coupon->get_date_created()->getTimestamp() : time()),
            'updated_at_foreign' => gmdate('Y-m-d\TH:i:sO', $coupon->get_date_modified() ? $coupon->get_date_modified()->getTimestamp() : time()),
        ];

        /**
         * @param array     $data
         * @param WC_Coupon $coupon
         */
        return apply_filters('mc4wp_promo_code_data', $data, $coupon);
    }
}
