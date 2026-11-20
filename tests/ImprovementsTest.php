<?php

namespace Linups\OntraportLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Linups\OntraportLaravel\Events\ChargeFailed;
use Linups\OntraportLaravel\Exceptions\OntraportException;
use Linups\OntraportLaravel\Ontraport;
use Linups\OntraportLaravel\OntraportServiceProvider;
use Linups\OntraportLaravel\Support\Redactor;
use OntraportAPI\Exceptions\RequiredParamsException;

/**
 * Behaviour added or fixed in v1.1.
 */
class ImprovementsTest extends TestCase
{
    // ---------------------------------------------------------------- service provider & config

    public function test_binding_is_available_right_after_register(): void
    {
        $app = new Application(dirname(__DIR__));
        $app->instance('config', new \Illuminate\Config\Repository());
        $app->register(OntraportServiceProvider::class);

        $this->assertTrue($app->bound(Ontraport::class));
        $this->assertTrue($app->isShared(Ontraport::class));
    }

    public function test_config_is_merged_with_the_previous_hardcoded_defaults(): void
    {
        $this->assertSame(6, config('ontraport.charge.gateway_id'));
        $this->assertSame(1, config('ontraport.charge.invoice_template'));
        $this->assertSame('month', config('ontraport.charge.trial_period_unit'));
        $this->assertSame(6, config('ontraport.charge.trial_period_count'));
        $this->assertSame(999, config('ontraport.charge.subscription_count'));
    }

    public function test_config_file_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(OntraportServiceProvider::class, 'ontraport-config');

        $this->assertCount(1, $paths);
        $this->assertFileExists(array_key_first($paths));
    }

    public function test_credentials_from_package_config_work_without_env(): void
    {
        // Simulates `php artisan config:cache`: env() is empty, values live in config.
        config(['ontraport.app_id' => 'cfg-id', 'ontraport.app_key' => 'cfg-key']);

        $ontraport = new Ontraport();

        $this->assertSame('cfg-id', $this->sdkCredential($ontraport, '_siteID'));
        $this->assertSame('cfg-key', $this->sdkCredential($ontraport, '_apiKey'));
    }

    public function test_legacy_config_key_still_wins_over_package_config(): void
    {
        config([
            'ontraport.app_id' => 'cfg-id',
            'ontraport.app_key' => 'cfg-key',
            'ONTRAPORT_APP_ID' => 'runtime-id',
            'ONTRAPORT_APP_KEY' => 'runtime-key',
        ]);

        $ontraport = new Ontraport();

        $this->assertSame('runtime-id', $this->sdkCredential($ontraport, '_siteID'));
        $this->assertSame('runtime-key', $this->sdkCredential($ontraport, '_apiKey'));
    }

    // ---------------------------------------------------------------- exceptions & redaction

    public function test_exception_is_typed_and_redacts_card_data(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1]));

        try {
            $ontraport->addUser([
                'email' => 'a@b.c',
                'payment_number' => '4111111111111111',
                'payment_expire_month' => '12',
                'payment_expire_year' => '2030',
                'cvv' => '123',
            ]);
            $this->fail('Exception expected');
        } catch (OntraportException $e) {
            $this->assertInstanceOf(\Exception::class, $e);
            $this->assertSame('addUser', $e->getMethodName());

            $message = $e->getMessage();
            $this->assertStringStartsWith('<pre>', $message);
            $this->assertStringNotContainsString('4111111111111111', $message);
            $this->assertStringContainsString('[ccNumber] => ************1111', $message);
            $this->assertStringContainsString('[payment_number] => ************1111', $message);
            $this->assertStringContainsString('[ccExpirationYear] => **', $message);
            $this->assertStringNotContainsString('2030', $message);
            $this->assertStringContainsString('[cvv] => ***', $message);

            $this->assertSame('************1111', $e->getContext()['mergedParams']['ccNumber']);
        }

        // The request itself is unchanged: Ontraport still receives the real values.
        $this->assertSame('4111111111111111', $http->last()['params']['ccNumber']);
    }

    public function test_redactor_handles_nested_arrays_and_objects(): void
    {
        $input = (object) [
            'card' => ['cc_number' => '5555444433332222', 'CCV' => '999'],
            'list' => [(object) ['payment_expire_month' => '01']],
            'email' => 'a@b.c',
            'ccNumber' => '',
        ];

        $redacted = Redactor::redact($input);

        $this->assertSame('************2222', $redacted->card['cc_number']);
        $this->assertSame('***', $redacted->card['CCV']);
        $this->assertSame('**', $redacted->list[0]->payment_expire_month);
        $this->assertSame('a@b.c', $redacted->email);
        $this->assertSame('', $redacted->ccNumber);
        $this->assertSame('5555444433332222', $input->card['cc_number'], 'input is not modified');
    }

    // ---------------------------------------------------------------- chargeUser failures

    public function test_failed_charge_logs_and_dispatches_an_event_instead_of_mailing(): void
    {
        $events = [];
        $this->app['events']->listen(ChargeFailed::class, function (ChargeFailed $event) use (&$events) {
            $events[] = $event;
        });

        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1, 'chargeResult' => ['message' => 'Card declined']]));

        $result = $ontraport->chargeUser([
            'contact_id' => 1,
            'price' => 10,
            'product_id' => 2,
            'type' => 'sale',
            'payment_number' => '4111111111111111',
        ]);

        $this->assertSame('fail', $result->status);
        $this->assertSame('Card declined', $result->message);

        $this->assertCount(1, $this->log->records);
        $this->assertSame('error', $this->log->records[0]['level']);
        $this->assertSame('Ontraport charge failed', $this->log->records[0]['message']);
        $this->assertStringNotContainsString('4111111111111111', json_encode($this->log->records[0]['context']));

        $this->assertCount(1, $events);
        $this->assertSame($result, $events[0]->result);
        $this->assertSame('************1111', $events[0]->data['ccNumber']);
    }

    public function test_unparseable_charge_response_returns_a_fail_object(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('<html>502 Bad Gateway</html>');

        $result = $ontraport->chargeUser(['contact_id' => 1, 'price' => 1, 'product_id' => 1, 'type' => 'sale']);

        $this->assertSame('fail', $result->status);
        $this->assertSame('Unknown Ontraport error', $result->message);
    }

    public function test_charge_failure_without_charge_result_uses_api_message(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1, 'message' => 'Invalid gateway']));

        $result = $ontraport->chargeUser(['contact_id' => 1, 'price' => 1, 'product_id' => 1, 'type' => 'sale']);

        $this->assertSame('fail', $result->status);
        $this->assertSame('Invalid gateway', $result->message);
    }

    public function test_a_throwing_listener_does_not_change_the_charge_result(): void
    {
        $this->app['events']->listen(ChargeFailed::class, fn () => throw new \RuntimeException('listener broke'));

        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1, 'chargeResult' => ['message' => 'Declined']]));

        $result = $ontraport->chargeUser(['contact_id' => 1, 'price' => 1, 'product_id' => 1, 'type' => 'sale']);

        $this->assertSame('fail', $result->status);
    }

    public function test_charge_defaults_come_from_config(): void
    {
        config([
            'ontraport.charge.gateway_id' => 3,
            'ontraport.charge.invoice_template' => 2,
            'ontraport.charge.trial_period_count' => 1,
            'ontraport.charge.subscription_count' => 12,
        ]);

        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok());

        $ontraport->chargeUser(['contact_id' => 1, 'price' => 1, 'product_id' => 1, 'type' => 'sale']);

        $params = $http->last()['params'];
        $this->assertSame(3, $params['gateway_id']);
        $this->assertSame(2, $params['invoice_template']);
        $this->assertSame(1, $params['offer']['products'][0]['trial_period_count']);
        $this->assertSame(12, $params['offer']['products'][0]['subscription_count']);
    }

    // ---------------------------------------------------------------- empty fields (v1.2.1)

    public function test_add_user_does_not_send_empty_fields(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 55]));

        $ontraport->addUser([
            'email' => 'a@b.c',
            'firstname' => '',
            'lastname' => null,
            'billing_city' => '',
            'zero_int' => 0,
            'zero_string' => '0',
            'flag' => false,
        ]);

        $this->assertSame([
            'objectID' => \OntraportAPI\ObjectType::CONTACT,
            'email' => 'a@b.c',
            'zero_int' => 0,
            'zero_string' => '0',
            'flag' => false,
        ], $http->last()['params']);
    }

    public function test_update_user_does_not_send_empty_fields(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(self::ok(['id' => 77]), self::ok(['id' => 77]));

        $ontraport->updateUser('a@b.c', ['firstname' => '', 'lastname' => 'Doe', 'cell_phone' => null]);

        $this->assertSame(['id' => 77, 'lastname' => 'Doe'], $http->last()['params']);
    }

    // ---------------------------------------------------------------- getUserList

    public function test_get_user_list_pages_contacts_without_since(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 0, 'data' => [
            ['id' => 1, 'email' => 'a@b.c', 'cell_phone' => '+1555'],
            ['id' => 2, 'email' => 'd@e.f'],
        ]]));

        $users = $ontraport->getUserList(50, 100);

        $this->assertCount(2, $users);
        $this->assertSame('a@b.c', $users[0]->email);
        $this->assertSame('+1555', $users[0]->cell_phone);
        $this->assertSame('get', $http->last()['method']);
        $this->assertSame('https://api.ontraport.com/1/Contacts', $http->last()['url']);
        $this->assertSame(
            ['start' => 100, 'range' => 50, 'sort' => 'id', 'sortDir' => 'asc'],
            $http->last()['params'],
        );
    }

    public function test_get_user_list_filters_on_creation_date_when_since_is_given(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('{"code":0,"data":[]}');

        $this->assertSame([], $ontraport->getUserList(50, 0, 1700000000));
        $this->assertSame(
            json_encode([['field' => ['field' => 'date'], 'op' => '>', 'value' => ['value' => 1700000000]]]),
            $http->last()['params']['condition'],
        );
    }

    public function test_get_user_list_throws_on_error_response(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue(json_encode(['code' => 1, 'message' => 'Invalid']));

        $this->expectException(OntraportException::class);
        $this->expectExceptionMessage('[name] => getUserList');

        $ontraport->getUserList(50, 0);
    }

    public function test_get_user_list_throws_on_unreadable_response(): void
    {
        [$ontraport, $http] = $this->ontraport();
        $http->queue('not json');

        $this->expectException(OntraportException::class);
        $this->expectExceptionMessage('[name] => getUserList');

        $ontraport->getUserList();
    }

    // ---------------------------------------------------------------- bundled SDK

    public function test_sdk_exception_classes_are_autoloadable(): void
    {
        // Every SDK exception lives in one file; v1.0.x could not autoload them.
        $sdk = $this->sdkClient(new Ontraport());

        $this->expectException(RequiredParamsException::class);
        $this->expectExceptionMessage('missing required parameter(s): add_list,ids');

        $sdk->object()->addTag(['objectID' => 0]);
    }
}
