<?php

namespace Linups\OntraportLaravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Linups\OntraportLaravel\Ontraport;
use Linups\OntraportLaravel\OntraportServiceProvider;
use Linups\OntraportLaravel\Tests\Fakes\FakeHttpClient;
use Linups\OntraportLaravel\Tests\Fakes\RecordingLogger;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Minimal Laravel application for package tests (no Testbench required).
 */
abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected RecordingLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__));
        $this->app->instance('config', new Repository($this->config()));
        $this->log = new RecordingLogger();
        $this->app->instance('log', $this->log);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->app->register(OntraportServiceProvider::class);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        return [];
    }

    /**
     * Build an Ontraport instance whose SDK talks to a FakeHttpClient.
     *
     * @return array{0: Ontraport, 1: FakeHttpClient}
     */
    protected function ontraport(?Ontraport $ontraport = null): array
    {
        $ontraport ??= new Ontraport();
        $http = new FakeHttpClient();

        $sdk = $this->sdkClient($ontraport);
        $property = new \ReflectionProperty($sdk, '_httpClient');
        $property->setValue($sdk, $http);

        return [$ontraport, $http];
    }

    protected function sdkClient(Ontraport $ontraport): \OntraportAPI\Ontraport
    {
        return (new \ReflectionProperty(Ontraport::class, 'client'))->getValue($ontraport);
    }

    protected function sdkCredential(Ontraport $ontraport, string $name): mixed
    {
        return (new \ReflectionProperty(\OntraportAPI\Ontraport::class, $name))->getValue($this->sdkClient($ontraport));
    }

    protected static function ok(array $data = ['id' => 123]): string
    {
        return json_encode(['code' => 0, 'data' => $data, 'account_id' => 1]);
    }
}
