<?php

use PHPUnit\Framework\TestCase;

/**
 * Class MailchimpTest
 *
 * @ignore
 */
class MailchimpTest extends TestCase
{
    public function tearDown(): void
    {
        $container = mc4wp_get_container();
        if ($container->has('api')) {
            unset($container['api']);
        }
    }

    /**
     * @covers MC4WP_MailChimp::list_subscribe
     */
    public function test_list_subscribe_keeps_existing_non_empty_merge_fields()
    {
        $api = new class () {
            public $update_args;

            public function get_list_member($list_id, $email_address)
            {
                return (object) [
                    'status' => 'subscribed',
                    'merge_fields' => (object) [
                        'UTM_SOURCE' => 'initial-source',
                        'FNAME' => 'Alice',
                        'LNAME' => '',
                    ],
                ];
            }

            public function update_list_member($list_id, $email_address, array $args)
            {
                $this->update_args = $args;

                return (object) [
                    'id' => 'member-id',
                    'status' => 'subscribed',
                ];
            }

            public function add_new_list_member($list_id, array $args)
            {
                throw new Exception('add_new_list_member should not be called');
            }
        };

        $container = mc4wp_get_container();
        $container['api'] = $api;

        $mailchimp = new MC4WP_MailChimp();
        $result = $mailchimp->list_subscribe(
            'list-id',
            'person@example.com',
            [
                'merge_fields' => [
                    'UTM_SOURCE' => 'new-source',
                    'FNAME' => 'Bob',
                    'LNAME' => 'Smith',
                ],
            ],
            true,
            true,
            true
        );

        self::assertSame('member-id', $result->id);
        self::assertSame('subscribed', $result->status);
        self::assertSame('person@example.com', $api->update_args['email_address']);
        self::assertSame('subscribed', $api->update_args['status']);
        self::assertSame([ 'LNAME' => 'Smith' ], $api->update_args['merge_fields']);
    }

    /**
     * @covers MC4WP_MailChimp::list_subscribe
     */
    public function test_list_subscribe_keeps_existing_values_when_setting_is_disabled()
    {
        $api = new class () {
            public $update_args;

            public function get_list_member($list_id, $email_address)
            {
                return (object) [
                    'status' => 'subscribed',
                    'merge_fields' => (object) [
                        'UTM_SOURCE' => 'initial-source',
                    ],
                ];
            }

            public function update_list_member($list_id, $email_address, array $args)
            {
                $this->update_args = $args;

                return (object) [
                    'id' => 'member-id',
                    'status' => 'subscribed',
                ];
            }

            public function add_new_list_member($list_id, array $args)
            {
                throw new Exception('add_new_list_member should not be called');
            }
        };

        $container = mc4wp_get_container();
        $container['api'] = $api;

        $mailchimp = new MC4WP_MailChimp();
        $mailchimp->list_subscribe(
            'list-id',
            'person@example.com',
            [
                'merge_fields' => [
                    'UTM_SOURCE' => 'new-source',
                ],
            ],
            true,
            true,
            false
        );

        self::assertSame([ 'UTM_SOURCE' => 'new-source' ], $api->update_args['merge_fields']);
    }
}
