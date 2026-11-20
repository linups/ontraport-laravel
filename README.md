# ontraport-laravel

Laravel integration for the [Ontraport](https://ontraport.com) API: create and update contacts,
manage tags and sequences, and log or charge transactions.

- PHP 8.1+
- Laravel 10, 11, 12 or 13

## Installation

```bash
composer require linups/ontraport-laravel
```

The service provider is auto-discovered. Add your API credentials to `.env`:

```dotenv
ONTRAPORT_APP_ID=your-app-id
ONTRAPORT_APP_KEY=your-api-key
```

Optionally publish the config file (`config/ontraport.php`):

```bash
php artisan vendor:publish --tag=ontraport-config
```

Credentials are resolved in this order:

1. `config('ONTRAPORT_APP_ID')` / `config('ONTRAPORT_APP_KEY')`: legacy top-level keys, if your app sets them.
2. `config('ontraport.app_id')` / `config('ontraport.app_key')`: works with `php artisan config:cache`.
3. `env('ONTRAPORT_APP_ID')` / `env('ONTRAPORT_APP_KEY')`.

## Usage

Resolve the client from the container (it is a singleton) or inject it:

```php
use Linups\OntraportLaravel\Ontraport;

public function store(Request $request, Ontraport $ontraport)
{
    $ontraport->addUser(['email' => $request->email, 'firstname' => $request->name]);
    $ontraport->addTagByEmail($request->email, [12, 15]);
}
```

> **Contacts are created automatically.** Every method that takes an e-mail looks the contact
> up first and **creates it if it does not exist**. This includes `deleteUser()`,
> `retrieveUser()` and `getTransactions()`.

> **Empty values are skipped.** `addUser()` and `updateUser()` do not send fields whose value is
> `''` or `null`, so blank form input never overwrites data already stored in Ontraport.

### Methods

| Method | Returns |
|---|---|
| `addUser(array $data)` | object: create or update a contact by e-mail |
| `updateUser($email, array $data)` | object |
| `retrieveUser(string $email)` | object (contact in `->data`) |
| `getUserEmail(int $id)` | string |
| `deleteUser($email)` | object |
| `getContactList(array $params)` | raw JSON string |
| `getUserList($limit = 50, $offset = 0, ?int $since = null)` | array of contact objects, oldest first; `$since` (unix seconds) keeps only contacts created after it |
| `addTag($tagIds, $contactIds)` | raw JSON string |
| `addTagByEmail($email, array\|string $tags)` | object |
| `removeTagsByEmail(string $email, array $tags)` | object (API errors are returned, not thrown) |
| `addSequence($seqIds, $contactIds)` | raw JSON string |
| `addSequenceByEmail($email, array\|string $seqIds)` | raw JSON string |
| `getTransactions($email)` | raw JSON string (purchases) |
| `getAffiliateList(?array $filter = null)` | raw JSON string (commissions) |
| `logTransaction($email, ['price' => .., 'sync_id' => ..])` | raw JSON string |
| `chargeUser(array $data)` | object with `->status` `success` or `fail` (and `->message`) |

`addUser()` and `chargeUser()` also accept checkout-style keys and map them to Ontraport fields:

- `billing_address1` → `address`
- `billing_address2` → `address2`
- `billing_city` → `city`
- `billing_state` → `state`
- `billing_zip` → `zip`
- `billing_country` → `country`
- `payment_number` → `ccNumber`
- `payment_expire_month` → `ccExpirationMonth`
- `payment_expire_year` → `ccExpirationYear`

### Charging

```php
$result = $ontraport->chargeUser([
    'email'      => 'jane@example.com',   // or 'contact_id' => 123
    'price'      => 149,
    'product_id' => 40,
    'type'       => 'subscription',       // sale | charge | subscription | infinite-subscription
    // optional: 'payment_gateway', 'payment_count', 'trial' => ['price', 'payment_count', 'unit']
]);

if ($result->status === 'fail') {
    // $result->message
}
```

The defaults for the gateway, invoice template, trial period and subscription count are set
under `ontraport.charge` in the config file.

When a charge fails, the package logs an error and dispatches
`Linups\OntraportLaravel\Events\ChargeFailed`. Card fields in the event and log are redacted.
To be notified, listen for the event:

```php
Event::listen(ChargeFailed::class, function (ChargeFailed $event) {
    Mail::to('billing@example.com')->send(new ChargeFailedMail($event->data, $event->result));
});
```

### Errors

Failed calls throw `Linups\OntraportLaravel\Exceptions\OntraportException`, which extends `\Exception`.
The message keeps its `<pre>print_r(...)</pre>` format. Card numbers, expiry dates and security
codes are redacted. `getContext()` returns the same details as an array, and `getMethodName()`
returns the name of the method that failed.

## Ontraport SDK

The package uses the official [`ontraport/sdk-php`](https://github.com/Ontraport/SDK-PHP) (`^1.3`),
installed by Composer. Its `OntraportAPI\` classes are available to your app as well.

## Testing

```bash
composer install
composer test
```

## License

MIT
