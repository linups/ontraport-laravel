<?php

namespace Linups\OntraportLaravel\Tests\Fakes;

use OntraportAPI\CurlClient;

/**
 * Stands in for the SDK's cURL client. Records every request and returns queued
 * responses, so tests can assert on exactly what would be sent to Ontraport.
 */
class FakeHttpClient extends CurlClient
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, string|false> */
    private array $responses = [];

    public function __construct()
    {
        parent::__construct('test-key', 'test-app-id');
    }

    public function queue(string|false ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function httpRequest($requestParams, $url, $method, $requiredParams, $options)
    {
        // Mirror CurlClient's own required-parameter validation.
        foreach ((array) $requiredParams as $param) {
            if (! array_key_exists($param, (array) $requestParams)
                && ! ($param === 'ids' && array_key_exists('group_ids', (array) $requestParams))) {
                throw new \LogicException("SDK validation would fail: missing required parameter [$param] for $url");
            }
        }

        $contentType = $options['headers']['Content-Type'] ?? null;

        if ($contentType === 'application/json') {
            $body = json_encode($requestParams);
        } else {
            $body = is_array($requestParams) ? http_build_query($requestParams) : $requestParams;
        }

        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'params' => $requestParams,
            'required' => $requiredParams,
            'contentType' => $contentType,
            'body' => $body,
        ];

        if ($this->responses === []) {
            throw new \LogicException("Unexpected request (no queued response): $method $url");
        }

        return array_shift($this->responses);
    }

    public function getLastStatusCode()
    {
        return 200;
    }

    /** @return array<string, mixed> */
    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
