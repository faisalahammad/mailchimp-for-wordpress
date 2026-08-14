<?php

use PHPUnit\Framework\TestCase;

/**
 * Class TrackingPixelTest
 *
 * @ignore
 */
class TrackingPixelTest extends TestCase
{
    public function tearDown(): void
    {
        unset($GLOBALS['mc4wp_test_home_url'], $GLOBALS['mc4wp_test_multisite'], $GLOBALS['mc4wp_test_bloginfo'], $GLOBALS['mc4wp_test_blog_id']);
        parent::tearDown();
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function invoke($method, array $args = [])
    {
        $reflected = new ReflectionMethod('MC4WP_Tracking_Pixel', $method);

        // Required to invoke private methods on PHP < 8.1, deprecated (and a no-op) after that.
        if (PHP_VERSION_ID < 80100) {
            $reflected->setAccessible(true);
        }

        return $reflected->invokeArgs(null, $args);
    }

    public function test_normalize_domain()
    {
        $tests = [
            'https://example.com'            => 'example.com',
            'http://example.com/'            => 'example.com',
            'https://www.example.com'        => 'example.com',
            'https://example.com/blog'       => 'example.com',
            'https://example.com:8080/blog/' => 'example.com',
            'https://EXAMPLE.com'            => 'example.com',
            'example.com'                    => 'example.com',
            'www.example.com/blog'           => 'example.com',
            'sub.example.com'                => 'sub.example.com',
        ];

        foreach ($tests as $input => $expected) {
            $this->assertEquals($expected, $this->invoke('normalize_domain', [ $input ]), sprintf('Failed normalizing %s', $input));
        }
    }

    public function test_get_site_domain_strips_scheme_path_and_www()
    {
        $GLOBALS['mc4wp_test_home_url'] = 'https://www.example.com/blog';
        $this->assertEquals('example.com', MC4WP_Tracking_Pixel::get_site_domain());
    }

    public function test_get_site_domain_turns_multisite_subdirectory_into_subdomain()
    {
        $GLOBALS['mc4wp_test_home_url']  = 'https://example.com/shop';
        $GLOBALS['mc4wp_test_multisite'] = true;
        $this->assertEquals('shop.example.com', MC4WP_Tracking_Pixel::get_site_domain());
    }

    public function test_get_site_domain_builds_valid_labels_for_nested_multisite_paths()
    {
        $GLOBALS['mc4wp_test_home_url']  = 'https://example.com/network/shop';
        $GLOBALS['mc4wp_test_multisite'] = true;

        $domain = MC4WP_Tracking_Pixel::get_site_domain();

        $this->assertEquals('shop.network.example.com', $domain);
        $this->assertStringNotContainsString('/', $domain);
    }

    public function test_get_site_domain_sanitizes_path_segments_into_hostname_labels()
    {
        $GLOBALS['mc4wp_test_home_url']  = 'https://example.com/My%20Shop/';
        $GLOBALS['mc4wp_test_multisite'] = true;

        $this->assertEquals('my-shop.example.com', MC4WP_Tracking_Pixel::get_site_domain());
    }

    public function test_get_site_domain_ignores_subdirectory_on_single_site()
    {
        $GLOBALS['mc4wp_test_home_url'] = 'https://example.com/shop';
        $this->assertEquals('example.com', MC4WP_Tracking_Pixel::get_site_domain());
    }

    public function test_stored_domain_matches_generated_domain()
    {
        $GLOBALS['mc4wp_test_home_url'] = 'https://www.example.com/blog';

        // Mailchimp stores the bare host, so what it returns should compare equal to what we send.
        $this->assertEquals(
            $this->invoke('normalize_domain', [ 'example.com' ]),
            MC4WP_Tracking_Pixel::get_site_domain()
        );
    }

    public function test_get_foreign_id_is_safe_for_names_without_latin_characters()
    {
        $GLOBALS['mc4wp_test_bloginfo'] = [ 'name' => 'テストサイト' ];
        $this->assertEquals('mc4wp-site-1', $this->invoke('get_foreign_id'));
    }

    public function test_get_foreign_id_uses_site_name_and_blog_id()
    {
        $GLOBALS['mc4wp_test_bloginfo'] = [ 'name' => 'My Site' ];
        $GLOBALS['mc4wp_test_blog_id']  = 3;
        $this->assertEquals('mc4wp-my-site-3', $this->invoke('get_foreign_id'));
    }

    /**
     * @param string $domain
     * @param string $script_url
     * @return object
     */
    private function site($foreign_id, $domain, $script_url)
    {
        return (object) [
            'foreign_id'  => $foreign_id,
            'domain'      => $domain,
            'site_script' => (object) [ 'url' => $script_url ],
        ];
    }

    /**
     * @return MC4WP_Fake_Connected_Sites_API
     */
    private function register_fake_api()
    {
        $api                            = new MC4WP_Fake_Connected_Sites_API();
        $container                      = mc4wp_get_container();
        $container['api']               = $api;
        $container['log']               = new MC4WP_Fake_Log();
        $GLOBALS['mc4wp_test_home_url'] = 'https://example.com';
        $GLOBALS['mc4wp_test_bloginfo'] = [ 'name' => 'My Site' ];
        return $api;
    }

    public function test_connected_site_is_matched_by_foreign_id()
    {
        $api                                = $this->register_fake_api();
        $api->sites_by_id['mc4wp-my-site-1'] = $this->site('mc4wp-my-site-1', 'example.com', 'https://chimpstatic.com/mcjs-connected/js/users/matched.js');

        $result = MC4WP_Tracking_Pixel::fetch_or_create_connected_site();

        $this->assertEquals('mc4wp-my-site-1', $result['site_id']);
        $this->assertEquals('https://chimpstatic.com/mcjs-connected/js/users/matched.js', $result['script_url']);
        $this->assertEquals(0, $api->listed, 'Should not scan all sites after a direct hit.');
        $this->assertNull($api->created);
    }

    public function test_foreign_id_match_for_another_domain_is_discarded()
    {
        $api                                 = $this->register_fake_api();
        $api->sites_by_id['mc4wp-my-site-1'] = $this->site('mc4wp-my-site-1', 'other-site.com', 'https://chimpstatic.com/mcjs-connected/js/users/wrong.js');
        $api->sites                          = [ $this->site('some-store-id', 'example.com', 'https://chimpstatic.com/mcjs-connected/js/users/right.js') ];

        $result = MC4WP_Tracking_Pixel::fetch_or_create_connected_site();

        $this->assertEquals('some-store-id', $result['site_id']);
        $this->assertEquals('https://chimpstatic.com/mcjs-connected/js/users/right.js', $result['script_url']);
        $this->assertNull($api->created);
    }

    public function test_new_site_gets_a_unique_foreign_id_when_the_generated_one_is_taken()
    {
        $api                                 = $this->register_fake_api();
        $api->sites_by_id['mc4wp-my-site-1'] = $this->site('mc4wp-my-site-1', 'other-site.com', 'https://chimpstatic.com/mcjs-connected/js/users/wrong.js');

        $result = MC4WP_Tracking_Pixel::fetch_or_create_connected_site();

        $this->assertEquals('example.com', $api->created['domain']);
        $this->assertNotEquals('mc4wp-my-site-1', $api->created['foreign_id']);
        $this->assertStringStartsWith('mc4wp-my-site-1-', $api->created['foreign_id']);
        $this->assertEquals($api->created['foreign_id'], $result['site_id']);
    }

    public function test_new_site_is_created_when_nothing_matches()
    {
        $api = $this->register_fake_api();

        $result = MC4WP_Tracking_Pixel::fetch_or_create_connected_site();

        $this->assertEquals([ 'foreign_id' => 'mc4wp-my-site-1', 'domain' => 'example.com' ], $api->created);
        $this->assertEquals('mc4wp-my-site-1', $result['site_id']);
    }
}
