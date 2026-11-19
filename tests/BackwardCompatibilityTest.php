<?php

namespace Linups\OntraportLaravel\Tests;

use Linups\OntraportLaravel\ABSontraport;
use Linups\OntraportLaravel\Ontraport;
use OntraportAPI\ObjectType;

/**
 * Characterization tests: these were written against v1.0.8 and pin down the public
 * behaviour existing installs rely on (request payloads sent to Ontraport, return
 * shapes and exception format). They must stay green across refactors.
 */
class BackwardCompatibilityTest extends TestCase
{
    private const API = 'https://api.ontraport.com/1/';

    private static function notFound(): string
    {
        return json_encode(['code' => 1, 'message' => 'Not found']);
    }

    // ---------------------------------------------------------------- container

    public function test_it_is_resolvable_from_the_container_as_a_singleton(): void
    {
        $first = $this->app->make(Ontraport::class);

        $this->assertInstanceOf(Ontraport::class, $first);
        $this->assertInstanceOf(ABSontraport::class, $first);
        $this->assertSame($first, $this->app->make(Ontraport::class));
        $this->assertSame($first, $this->app->make('Linups\OntraportLaravel\Ontraport'));
    }

    public function test_it_can_still_be_instantiated_directly(): void
    {
        $this->assertInstanceOf(Ontraport::class, new Ontraport());
    }

    // ---------------------------------------------------------------- credentials

    public function test_credentials_from_legacy_top_level_config_keys(): void
    {
        config(['ONTRAPORT_APP_ID' => 'legacy-id', 'ONTRAPORT_APP_KEY' => 'legacy-key']);

        $ontraport = new Ontraport();

        $this->assertSame('legacy-id', $this->sdkCredential($ontraport, '_siteID'));
        $this->assertSame('legacy-key', $this->sdkCredential($ontraport, '_apiKey'));
    }

    public function test_credentials_from_environment_variables(): void
    {
        $this->withEnv(['ONTRAPORT_APP_ID' => 'env-id', 'ONTRAPORT_APP_KEY' => 'env-key'], function () {
            $ontraport = new Ontraport();

            $this->assertSame('env-id', $this->sdkCredential($ontraport, '_siteID'));
            $this->assertSame('env-key', $this->sdkCredential($ontraport, '_apiKey'));
        });
    }

    // ---------------------------------------------------------------- addUser

    public function test_add_user_sends_save_or_update_with_mapped_fields(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 55]));

        $result = $ontraport->addUser([
            'email' => ' john doe@example.com ',
            'firstname' => 'John',
            'lastname' => '',
            'billing_address1' => 'Main st. 1',
            'billing_city' => 'Vilnius',
            'billing_zip' => '',
            'billing_country' => 'LT',
            'cell_phone' => '+370600',
        ]);

        $this->assertIsObject($result);
        $this->assertSame(55, $result->data->id);

        $request = $http->last();
        $this->assertSame('post', $request['method']);
        $this->assertSame(self::API . 'objects/saveorupdate', $request['url']);
        $this->assertSame('application/x-www-form-urlencoded', $request['contentType']);
        $this->assertSame([
            'objectID' => ObjectType::CONTACT,
            'email' => 'johndoe@example.com',
            'firstname' => 'John',
            'billing_address1' => 'Main st. 1',
            'billing_city' => 'Vilnius',
            'billing_country' => 'LT',
            'cell_phone' => '+370600',
            'address' => 'Main st. 1',
            'city' => 'Vilnius',
            'country' => 'LT',
        ], $request['params']);
    }

    public function test_add_user_maps_payment_fields_in_the_original_order(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok());

        $ontraport->addUser([
            'email' => 'a@b.c',
            'billing_address2' => 'Apt 2',
            'billing_state' => 'NY',
            'payment_number' => '4111111111111111',
            'payment_expire_month' => '12',
            'payment_expire_year' => '2030',
            'ccExpirationDate' => '12/30',
        ]);

        $this->assertSame([
            'objectID' => ObjectType::CONTACT,
            'email' => 'a@b.c',
            'billing_address2' => 'Apt 2',
            'billing_state' => 'NY',
            'payment_number' => '4111111111111111',
            'payment_expire_month' => '12',
            'payment_expire_year' => '2030',
            'ccExpirationDate' => '12/30',
            'state' => 'NY',
            'address2' => 'Apt 2',
            'ccExpirationMonth' => '12',
            'ccExpirationYear' => '2030',
            'ccNumber' => '4111111111111111',
        ], $http->last()['params']);
    }

    public function test_add_user_throws_a_pre_formatted_exception_on_api_error(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1, 'message' => 'Bad']));

        try {
            $ontraport->addUser(['email' => 'a@b.c', 'firstname' => 'A']);
            $this->fail('Exception expected');
        } catch (\Exception $e) {
            $this->assertStringStartsWith("<pre>Array\n(\n    [name] => addUser\n", $e->getMessage());
            $this->assertStringEndsWith("</pre>", $e->getMessage());
            $this->assertStringContainsString('[postData] => Array', $e->getMessage());
            $this->assertStringContainsString('[rawResponse] => {"code":1,"message":"Bad"}', $e->getMessage());
            $this->assertStringContainsString('[requestParams] => Array', $e->getMessage());
            $this->assertStringContainsString('[mergedParams] => Array', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- contact lookup

    public function test_update_user_looks_up_contact_by_email_then_updates(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 77]), self::ok(['id' => 77, 'firstname' => 'New']));

        $result = $ontraport->updateUser('a@b.c', ['firstname' => 'New']);

        $this->assertSame('New', $result->data->firstname);

        [$lookup, $update] = $http->requests;
        $this->assertSame('get', $lookup['method']);
        $this->assertSame(self::API . 'object/getByEmail', $lookup['url']);
        $this->assertSame(['objectID' => ObjectType::CONTACT, 'email' => 'a@b.c', 'all' => 0], $lookup['params']);

        $this->assertSame('put', $update['method']);
        $this->assertSame(self::API . 'Contacts', $update['url']);
        $this->assertSame(['id' => 77, 'firstname' => 'New'], $update['params']);
    }

    public function test_unknown_email_creates_the_contact_first(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::notFound(), self::ok(['id' => 99]), self::ok(['id' => 99]));

        $ontraport->updateUser('new@b.c', ['firstname' => 'X']);

        $this->assertCount(3, $http->requests);
        $this->assertSame(self::API . 'objects/saveorupdate', $http->requests[1]['url']);
        $this->assertSame(['objectID' => ObjectType::CONTACT, 'email' => 'new@b.c'], $http->requests[1]['params']);
        $this->assertSame(['id' => 99, 'firstname' => 'X'], $http->requests[2]['params']);
    }

    public function test_update_user_throws_on_api_error(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 1]), json_encode(['code' => 1]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => updateUser');

        $ontraport->updateUser('a@b.c', ['firstname' => 'X']);
    }

    public function test_lookup_without_an_id_throws(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 0, 'data' => []]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => getCustomerID');

        $ontraport->retrieveUser('a@b.c');
    }

    // ---------------------------------------------------------------- tags & sequences

    public function test_add_tag_returns_the_raw_json_string(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('{"code":0,"data":"queued"}');

        $this->assertSame('{"code":0,"data":"queued"}', $ontraport->addTag('1,2', '5'));

        $request = $http->last();
        $this->assertSame('put', $request['method']);
        $this->assertSame(self::API . 'objects/tag', $request['url']);
        $this->assertSame(['objectID' => ObjectType::CONTACT, 'ids' => '5', 'add_list' => '1,2'], $request['params']);
    }

    public function test_add_tag_by_email_accepts_array_or_string(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 5]), self::ok([]), self::ok(['id' => 5]), self::ok([]));

        $this->assertIsObject($ontraport->addTagByEmail('a@b.c', [1, 2]));
        $this->assertSame('1,2', $http->requests[1]['params']['add_list']);
        $this->assertSame(5, $http->requests[1]['params']['ids']);

        $ontraport->addTagByEmail('a@b.c', '3');
        $this->assertSame('3', $http->requests[3]['params']['add_list']);
    }

    public function test_add_tag_by_email_throws_on_api_error(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 5]), json_encode(['code' => 1]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => addTagByEmail');

        $ontraport->addTagByEmail('a@b.c', [1]);
    }

    public function test_remove_tags_by_email(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 5]), json_encode(['code' => 0, 'data' => 'removed']));

        $result = $ontraport->removeTagsByEmail('a@b.c', [3, 4]);

        $this->assertSame('removed', $result->data);
        $request = $http->last();
        $this->assertSame('delete', $request['method']);
        $this->assertSame(self::API . 'objects/tag', $request['url']);
        $this->assertSame(['objectID' => ObjectType::CONTACT, 'ids' => 5, 'remove_list' => '3,4'], $request['params']);
    }

    public function test_remove_tags_by_email_returns_api_errors_without_throwing(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 5]), json_encode(['code' => 1]));

        $this->assertSame(1, $ontraport->removeTagsByEmail('a@b.c', [3])->code);
    }

    public function test_remove_tags_by_email_throws_on_empty_response(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 5]), false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => removeTagsByEmail');

        $ontraport->removeTagsByEmail('a@b.c', [3]);
    }

    public function test_add_sequence_by_email_returns_the_raw_json_string(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 8]), '{"code":0}');

        $this->assertSame('{"code":0}', $ontraport->addSequenceByEmail('a@b.c', [10, 11]));

        $request = $http->last();
        $this->assertSame('put', $request['method']);
        $this->assertSame(self::API . 'objects/sequence', $request['url']);
        $this->assertSame(['objectID' => ObjectType::CONTACT, 'ids' => 8, 'add_list' => '10,11'], $request['params']);
    }

    // ---------------------------------------------------------------- reads

    public function test_get_transactions_returns_the_raw_json_string(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 8]), '{"code":0,"data":[]}');

        $this->assertSame('{"code":0,"data":[]}', $ontraport->getTransactions('a@b.c'));
        $this->assertSame(self::API . 'objects', $http->last()['url']);
        $this->assertSame(['objectID' => ObjectType::PURCHASE, 'condition' => 'contact_id=8'], $http->last()['params']);
    }

    public function test_get_contact_list_passes_params_through(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('{"code":0,"data":[]}');

        $this->assertSame('{"code":0,"data":[]}', $ontraport->getContactList(['range' => 10]));
        $this->assertSame('get', $http->last()['method']);
        $this->assertSame(self::API . 'Contacts', $http->last()['url']);
        $this->assertSame(['range' => 10], $http->last()['params']);
    }

    public function test_get_affiliate_list_with_and_without_filter(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('{"code":0}', '{"code":0}');

        $this->assertSame('{"code":0}', $ontraport->getAffiliateList());
        $this->assertSame(
            ['objectID' => ObjectType::COMMISSION, 'sort' => 'lastname', 'sortDir' => 'desc'],
            $http->requests[0]['params'],
        );

        $filter = [['field' => ['field' => 'product_id'], 'op' => 'IN', 'value' => ['list' => [['value' => 30]]]]];
        $ontraport->getAffiliateList($filter);
        $this->assertSame(json_encode($filter), $http->requests[1]['params']['condition']);
    }

    public function test_get_user_email(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 3, 'email' => 'x@y.z']));

        $this->assertSame('x@y.z', $ontraport->getUserEmail(3));
        $this->assertSame(self::API . 'Contact', $http->last()['url']);
        $this->assertSame(['id' => 3], $http->last()['params']);
    }

    public function test_get_user_email_throws_when_missing(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 0, 'data' => []]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => getUserEmail');

        $ontraport->getUserEmail(3);
    }

    public function test_retrieve_user_returns_decoded_object(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 4]), self::ok(['id' => 4, 'firstname' => 'Ann']));

        $this->assertSame('Ann', $ontraport->retrieveUser('a@b.c')->data->firstname);
        $this->assertSame(['id' => 4], $http->last()['params']);
    }

    public function test_delete_user(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 4]), '{"code":0,"data":"Deleted"}');

        $this->assertSame('Deleted', $ontraport->deleteUser('a@b.c')->data);
        $this->assertSame('delete', $http->last()['method']);
        $this->assertSame(self::API . 'Contact', $http->last()['url']);
        $this->assertSame(['id' => 4], $http->last()['params']);
    }

    public function test_delete_user_throws_on_unparseable_response(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 4]), 'not json');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('[name] => deleteUser');

        $ontraport->deleteUser('a@b.c');
    }

    // ---------------------------------------------------------------- transactions

    public function test_log_transaction_sends_the_json_offer(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 12]), '{"code":0,"data":{"invoice_id":1}}');

        $this->assertSame(
            '{"code":0,"data":{"invoice_id":1}}',
            $ontraport->logTransaction('a@b.c', ['price' => 49.5, 'sync_id' => 7]),
        );

        $request = $http->last();
        $this->assertSame('post', $request['method']);
        $this->assertSame(self::API . 'transaction/processManual', $request['url']);
        $this->assertSame('application/json', $request['contentType']);
        $this->assertSame(
            '{"contact_id":12,"chargeNow":"chargeLog","offer":{"products":[{"quantity":1,"price":[{"price":49.5,"id":7}],"id":7}]}}',
            $request['body'],
        );
    }

    public function test_charge_user_success(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 21]), json_encode(['code' => 0, 'data' => ['result_code' => 1]]));

        $before = time() * 1000;
        $result = $ontraport->chargeUser([
            'email' => 'a@b.c',
            'price' => 149,
            'product_id' => 40,
            'type' => 'subscription',
        ]);

        $this->assertSame('success', $result->status);
        $this->assertSame(0, $result->code);

        $request = $http->last();
        $this->assertSame('post', $request['method']);
        $this->assertSame(self::API . 'transaction/processManual', $request['url']);
        $this->assertSame('application/json', $request['contentType']);

        $params = $request['params'];
        $this->assertGreaterThanOrEqual($before, $params['trans_date']);
        unset($params['trans_date']);

        $this->assertSame([
            'contact_id' => 21,
            'chargeNow' => 'chargeNow',
            'invoice_template' => 1,
            'gateway_id' => 6,
            'offer' => ['products' => [[
                'quantity' => 1,
                'shipping' => false,
                'tax' => false,
                'price' => [['price' => 149, 'payment_count' => 1, 'unit' => 'month', 'id' => 40]],
                'type' => 'payment_plan',
                'owner' => 1,
                'offer_to_affiliates' => false,
                'trial_period_unit' => 'month',
                'trial_period_count' => 6,
                'trial_price' => 0,
                'delay_start' => 0,
                'subscription_count' => 999,
                'subscription_unit' => 'month',
                'taxable' => false,
                'id' => 40,
            ]]],
        ], $params);
    }

    public function test_charge_user_with_contact_id_gateway_and_trial(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok());

        $ontraport->chargeUser([
            'contact_id' => 300,
            'payment_gateway' => 9,
            'price' => 10,
            'payment_count' => 3,
            'product_id' => 2,
            'type' => 'sale',
            'trial' => ['price' => 0, 'payment_count' => 1, 'unit' => 'week'],
        ]);

        $params = $http->last()['params'];
        $this->assertCount(1, $http->requests, 'no contact lookup when contact_id is given');
        $this->assertSame(300, $params['contact_id']);
        $this->assertSame(9, $params['gateway_id']);
        $this->assertSame(3, $params['offer']['products'][0]['price'][0]['payment_count']);
        $this->assertSame('one_time', $params['offer']['products'][0]['type']);
        $this->assertSame(['price' => 0, 'payment_count' => 1, 'unit' => 'week'], $params['offer']['products'][0]['trial']);
        $this->assertSame(
            ['quantity', 'shipping', 'tax', 'price', 'trial', 'type'],
            array_slice(array_keys($params['offer']['products'][0]), 0, 6),
        );
    }

    public function test_charge_user_maps_transaction_types(): void
    {
        $expected = [
            'sale' => 'one_time',
            'charge' => 'one_time',
            'subscription' => 'payment_plan',
            'infinite-subscription' => 'subscription',
            'something-else' => null,
        ];

        foreach ($expected as $type => $mapped) {
            [$ontraport, $http] = $this->ontraport();
            $http->queue(self::ok());
            $ontraport->chargeUser(['contact_id' => 1, 'price' => 1, 'product_id' => 1, 'type' => $type]);

            $this->assertSame($mapped, $http->last()['params']['offer']['products'][0]['type'], $type);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function withEnv(array $vars, callable $callback): void
    {
        foreach ($vars as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach (array_keys($vars) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
