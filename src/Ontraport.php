<?php

namespace Linups\OntraportLaravel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Linups\OntraportLaravel\Events\ChargeFailed;
use Linups\OntraportLaravel\Exceptions\OntraportException;
use Linups\OntraportLaravel\Support\Redactor;
use Linups\OntraportLaravel\Support\SdkAutoloader;
use OntraportAPI\ObjectType;
use OntraportAPI\Ontraport as APIontraport;

SdkAutoloader::register();

/**
 * Ontraport API client for Laravel.
 *
 * Methods that take an e-mail look the contact up by e-mail and create it
 * if it does not exist yet (this includes deleteUser(), retrieveUser() and getTransactions()).
 *
 * Backward compatibility: public method signatures and return shapes are part of the
 * package's contract. Some methods return the raw JSON string from Ontraport, others a decoded
 * object; see each method's @return. Do not add native parameter/return types to existing
 * public methods, since that would break subclasses that override them.
 */
class Ontraport extends ABSontraport
{
    /** Charge-data keys mapped to their Ontraport contact field names (only when non-empty). */
    private const FIELD_MAP = [
        'billing_address1' => 'address',
        'billing_city' => 'city',
        'billing_state' => 'state',
        'billing_zip' => 'zip',
        'billing_address2' => 'address2',
        'billing_country' => 'country',
        'payment_expire_month' => 'ccExpirationMonth',
        'payment_expire_year' => 'ccExpirationYear',
        'payment_number' => 'ccNumber',
    ];

    private APIontraport $client;

    public function __construct()
    {
        $this->client = new APIontraport(
            $this->credential('app_id', 'ONTRAPORT_APP_ID'),
            $this->credential('app_key', 'ONTRAPORT_APP_KEY'),
        );
    }

    // ------------------------------------------------------------------ contacts

    /**
     * Create or update a contact (matched by e-mail).
     *
     * @param array<string, mixed> $data Contact fields. Checkout-style keys (billing_address1,
     *                                   billing_city, payment_number, ...) are also mapped to Ontraport fields.
     * @return object Decoded Ontraport response.
     * @throws OntraportException
     */
    public function addUser($data)
    {
        $requestParams = [
            'objectID' => ObjectType::CONTACT,
            'email' => $this->normalizeEmail($data['email']),
        ];
        $requestParamsMerged = array_merge($requestParams, $this->prepareFields($data));
        $rawResponse = $this->client->object()->saveOrUpdate($requestParamsMerged);
        $result = json_decode($rawResponse);

        if ($this->isOk($result)) {
            return $result;
        }

        $this->fail('addUser', [
            'postData' => $data,
            'rawResponse' => $rawResponse,
            'response' => $result,
            'requestParams' => $requestParams,
            'mergedParams' => $requestParamsMerged,
        ]);
    }

    /**
     * Update a contact's fields. Creates the contact first if the e-mail is unknown.
     *
     * @param string               $email
     * @param array<string, mixed> $data
     * @return object Decoded Ontraport response.
     * @throws OntraportException
     */
    public function updateUser($email, $data)
    {
        $idArray = ['id' => $this->getCustomerID($email)];
        $requestParams = array_merge($idArray, $data);
        $rawResponse = $this->client->contact()->update($requestParams);
        $response = json_decode($rawResponse);

        if ($this->isOk($response)) {
            return $response;
        }

        $this->fail('updateUser', [
            'email' => $email,
            'postData' => $data,
            'rawResponse' => $rawResponse,
            'response' => $response,
            'idArray' => $idArray,
            'requestParams' => $requestParams,
        ]);
    }

    /**
     * @return string Contact e-mail.
     * @throws OntraportException
     */
    public function getUserEmail(int $id): string
    {
        $requestParams = ['id' => $id];
        $rawResponse = $this->client->contact()->retrieveSingle($requestParams);
        $response = json_decode($rawResponse);

        if (isset($response->data->email)) {
            return $response->data->email;
        }

        $this->fail('getUserEmail', [
            'id' => $id,
            'response' => $response,
            'rawResponse' => $rawResponse,
            'requestParams' => $requestParams,
        ]);
    }

    /**
     * Note: like every e-mail based method, an unknown e-mail is created first.
     *
     * @param string $email
     * @return object Decoded Ontraport response.
     * @throws OntraportException
     */
    public function deleteUser($email)
    {
        $requestParams = ['id' => $this->getCustomerID($email)];
        $rawResponse = $this->client->contact()->deleteSingle($requestParams);
        $response = json_decode($rawResponse);

        if (isset($response->code)) {
            return $response;
        }

        $this->fail('deleteUser', [
            'email' => $email,
            'response' => $response,
            'rawResponse' => $rawResponse,
            'requestParams' => $requestParams,
        ]);
    }

    /**
     * @return object Decoded Ontraport response (contact in ->data).
     */
    public function retrieveUser(string $email): object
    {
        $requestParams = ['id' => $this->getCustomerID($email)];

        return json_decode($this->client->contact()->retrieveSingle($requestParams));
    }

    /**
     * @param array<string, mixed> $requestParams Passed to the Contacts "retrieve multiple" endpoint as-is.
     * @return string|false Raw JSON response.
     */
    public function getContactList($requestParams)
    {
        return $this->client->contact()->retrieveMultiple($requestParams);
    }

    // ------------------------------------------------------------------ tags & sequences

    /**
     * @param string|int $tag_ids     Comma separated tag IDs.
     * @param string|int $contact_ids Comma separated contact IDs.
     * @return string|false Raw JSON response.
     */
    public function addTag($tag_ids, $contact_ids)
    {
        return $this->client->object()->addTag([
            'objectID' => ObjectType::CONTACT,
            'ids' => $contact_ids,
            'add_list' => $tag_ids,
        ]);
    }

    /**
     * @param string           $email
     * @param array|string|int $tags Tag IDs (array or comma separated).
     * @return object Decoded Ontraport response.
     * @throws OntraportException
     */
    public function addTagByEmail($email, $tags)
    {
        $contactID = $this->getCustomerID($email);
        if (is_array($tags)) {
            $tags = implode(',', $tags);
        }
        $rawResponse = $this->addTag($tags, $contactID);
        $response = json_decode($rawResponse);

        if ($this->isOk($response)) {
            return $response;
        }

        $this->fail('addTagByEmail', [
            'email' => $email,
            'tags' => $tags,
            'response' => $response,
            'rawResponse' => $rawResponse,
        ]);
    }

    /**
     * @param array<int, string|int> $tags Tag IDs.
     * @return object Decoded Ontraport response. API errors are returned, not thrown.
     * @throws OntraportException When Ontraport returns an empty response.
     */
    public function removeTagsByEmail(string $email, array $tags): object
    {
        $contactID = $this->getCustomerID($email);
        $tags = implode(',', $tags);

        $requestParams = [
            'objectID' => ObjectType::CONTACT,
            'ids' => $contactID,
            'remove_list' => $tags,
        ];
        $response = $this->client->object()->removeTag($requestParams);

        if ($response) {
            return json_decode($response);
        }

        $this->fail('removeTagsByEmail', [
            'email' => $email,
            'tags' => $tags,
            'response' => $response,
            'requestParams' => $requestParams,
        ]);
    }

    /**
     * @param string           $email
     * @param array|string|int $seq_ids Sequence IDs (array or comma separated).
     * @return string|false Raw JSON response.
     */
    public function addSequenceByEmail($email, $seq_ids)
    {
        $contactID = $this->getCustomerID($email);
        if (is_array($seq_ids)) {
            $seq_ids = implode(',', $seq_ids);
        }

        return $this->addSequence($seq_ids, $contactID);
    }

    /**
     * @param string|int $seq_ids     Comma separated sequence IDs.
     * @param string|int $contact_ids Comma separated contact IDs.
     * @return string|false Raw JSON response.
     */
    public function addSequence($seq_ids, $contact_ids)
    {
        return $this->client->object()->addToSequence([
            'objectID' => ObjectType::CONTACT,
            'ids' => $contact_ids,
            'add_list' => $seq_ids,
        ]);
    }

    // ------------------------------------------------------------------ purchases & commissions

    /**
     * @param string $email
     * @return string|false Raw JSON response (purchases of the contact).
     */
    public function getTransactions($email)
    {
        $contactID = $this->getCustomerID($email);

        return $this->client->object()->retrieveMultiple([
            'objectID' => ObjectType::PURCHASE,
            'condition' => 'contact_id=' . $contactID,
        ]);
    }

    /**
     * @param array|null $filter Ontraport condition objects; JSON encoded into "condition".
     * @return string|false Raw JSON response.
     */
    public function getAffiliateList(?array $filter = null)
    {
        $requestParams = [
            'objectID' => ObjectType::COMMISSION,
            'sort' => 'lastname',
            'sortDir' => 'desc',
        ];
        if (! is_null($filter)) {
            $requestParams['condition'] = json_encode($filter);
        }

        return $this->client->object()->retrieveMultiple($requestParams);
    }

    // ------------------------------------------------------------------ transactions

    /**
     * Log (record without charging) a one-product transaction for a contact.
     *
     * @param string                                   $email
     * @param array{price: int|float, sync_id: int} $data
     * @return string|false Raw JSON response.
     */
    public function logTransaction($email, $data)
    {
        $payload = [
            'contact_id' => $this->getCustomerID($email),
            'chargeNow' => 'chargeLog',
            'offer' => [
                'products' => [[
                    'quantity' => 1,
                    'price' => [[
                        'price' => $data['price'],
                        'id' => $data['sync_id'],
                    ]],
                    'id' => $data['sync_id'],
                ]],
            ],
        ];

        // The second argument uses the bundled SDK's raw-body patch (lib/ontraport-sdk/PATCHES.md).
        return $this->client->transaction()->processManual($payload, $payload);
    }

    /**
     * Charge a contact for one product.
     *
     * @param array<string, mixed> $data Requires price, product_id, type (sale|charge|subscription|infinite-subscription)
     *                                   and either contact_id or email. Optional: payment_gateway, payment_count, trial.
     * @return object Decoded Ontraport response with ->status "success" or "fail" (and ->message on failure).
     */
    public function chargeUser($data)
    {
        $data = $this->prepareFields($data);
        $charge = (array) config('ontraport.charge', []);

        $product = [
            'quantity' => 1,
            'shipping' => false,
            'tax' => false,
            'price' => [[
                'price' => $data['price'],
                'payment_count' => $data['payment_count'] ?? 1,
                'unit' => 'month',
                'id' => $data['product_id'],
            ]],
        ];
        if (isset($data['trial'])) {
            $product['trial'] = [
                'price' => $data['trial']['price'],
                'payment_count' => $data['trial']['payment_count'],
                'unit' => $data['trial']['unit'],
            ];
        }
        $product += [
            'type' => $this->transactionType($data['type']),
            'owner' => 1,
            'offer_to_affiliates' => false,
            'trial_period_unit' => $charge['trial_period_unit'] ?? 'month',
            'trial_period_count' => $charge['trial_period_count'] ?? 6,
            'trial_price' => 0,
            'delay_start' => 0,
            'subscription_count' => $charge['subscription_count'] ?? 999,
            'subscription_unit' => 'month',
            'taxable' => false,
            'id' => $data['product_id'],
        ];

        $requestParams = [
            'contact_id' => $data['contact_id'] ?? $this->getCustomerID($data['email']),
            'chargeNow' => 'chargeNow',
            'trans_date' => time() * 1000,
            'invoice_template' => $charge['invoice_template'] ?? 1,
            'gateway_id' => $data['payment_gateway'] ?? $charge['gateway_id'] ?? 6,
            'offer' => ['products' => [$product]],
        ];

        $rawResponse = $this->client->transaction()->processManual($requestParams);
        $result = json_decode($rawResponse);
        if (! is_object($result)) {
            $result = new \stdClass();
        }

        if ($this->isOk($result)) {
            $result->status = 'success';
        } else {
            $result->status = 'fail';
            $result->message = $result->chargeResult->message
                ?? (is_string($result->message ?? null) ? $result->message : 'Unknown Ontraport error');
            $this->reportChargeFailure($data, $result, $rawResponse);
        }

        return $result;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Ontraport contact ID for an e-mail; creates the contact when it does not exist.
     *
     * @throws OntraportException
     */
    private function getCustomerID($email)
    {
        $result = json_decode($this->getContactObj(['email' => $email]));

        $response = $this->isOk($result) ? $result : $this->addUser(['email' => $email]);

        if (isset($response->data->id)) {
            return $response->data->id;
        }

        $this->fail('getCustomerID', [
            'email' => $email,
            'result' => $result,
            'response' => $response,
        ]);
    }

    private function getContactObj(array $array)
    {
        return $this->client->object()->retrieveIdByEmail([
            'objectID' => ObjectType::CONTACT,
            'email' => $array['email'],
            'all' => 0,
        ]);
    }

    /**
     * Normalizes the e-mail and maps checkout-style keys onto Ontraport field names.
     * Original keys are kept; mapped keys are only set for non-empty values.
     */
    private function prepareFields(array $data): array
    {
        $return = $data;
        if (isset($data['email']) && $data['email'] != '') {
            $return['email'] = $this->normalizeEmail($data['email']);
        }
        foreach (self::FIELD_MAP as $from => $to) {
            if (isset($data[$from]) && $data[$from] != '') {
                $return[$to] = $data[$from];
            }
        }

        return $return;
    }

    private function transactionType($type): ?string
    {
        return match ($type) {
            'sale', 'charge' => 'one_time',
            'subscription' => 'payment_plan',
            'infinite-subscription' => 'subscription',
            default => null,
        };
    }

    private function normalizeEmail($email)
    {
        return str_replace(' ', '', $email);
    }

    private function isOk($response): bool
    {
        return isset($response->code) && $response->code == 0;
    }

    /**
     * Credential lookup, in order: legacy top-level config key (set at runtime by some apps),
     * config/ontraport.php (works with config:cache), then the environment variable.
     */
    private function credential(string $key, string $legacyKey): ?string
    {
        $value = config($legacyKey) ?? config('ontraport.' . $key) ?? env($legacyKey);

        return $value === null ? null : (string) $value;
    }

    /**
     * @throws OntraportException Message keeps the pre-v1.1 `<pre>print_r(...)</pre>` format.
     */
    private function fail(string $name, array $context): never
    {
        $context = Redactor::redact(['name' => $name] + $context);

        throw new OntraportException('<pre>' . print_r($context, true) . '</pre>', $context);
    }

    private function reportChargeFailure(array $data, object $result, $rawResponse): void
    {
        try {
            $redactedData = Redactor::redact($data);

            Log::error('Ontraport charge failed', [
                'message' => $result->message,
                'data' => $redactedData,
                'response' => Redactor::redact($result),
                'rawResponse' => $rawResponse,
            ]);

            Event::dispatch(new ChargeFailed($redactedData, $result));
        } catch (\Throwable $e) {
            // Reporting (logging or a listener) must never change what chargeUser() returns.
            try {
                report($e);
            } catch (\Throwable) {
                // No exception handler available; nothing more we can do.
            }
        }
    }
}
