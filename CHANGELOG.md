# Changelog

## v1.2.0

Backward compatible. Public method names, parameters and return shapes are unchanged.

### Changed
- The Ontraport SDK is now the Composer dependency `ontraport/sdk-php` `^1.3` instead of a
  bundled, patched copy of v1.0.3. `lib/ontraport-sdk/` and `Support\SdkAutoloader` were removed.
  If your app also requires `ontraport/sdk-php`, Composer now resolves a single shared version.
- `logTransaction()` sends the same JSON body as before. The bundled raw-body patch was
  redundant because `processManual()` already JSON-encodes its parameters.

### Known issues
- Upstream `ontraport/sdk-php` 1.3.0 still triggers PHP 8.4/8.5 deprecation notices
  (implicitly nullable parameter, `curl_close()`). They are not errors, and Laravel sends them to the
  `deprecations` log channel.
- Upstream does not guard a missing `x-rate-limit-reset` header on HTTP 429 responses.

## v1.1.0

Backward compatible. Public method names, parameters and return shapes are unchanged.
No action is needed when upgrading from 1.0.x.

### Fixed
- Credentials now work after `php artisan config:cache`. They are read from the new
  `config/ontraport.php`. The `ONTRAPORT_APP_ID`/`ONTRAPORT_APP_KEY` env vars and legacy
  top-level config keys keep working.
- The container binding is now registered in `register()` instead of `boot()`, so the
  service can be resolved from other providers.
- `chargeUser()` no longer crashes when Ontraport returns invalid JSON or no `chargeResult`.
  It returns a `status = "fail"` object instead.
- Bundled SDK exception classes (e.g. `RequiredParamsException`) can now be autoloaded.
- Removed PHP 8.4 and 8.5 deprecations:
  - implicitly nullable parameters
  - `curl_close()`
- The SDK rate-limit retry no longer warns when the `x-rate-limit-reset` header is missing.

### Security
- Card numbers, expiry dates and security codes are now redacted in exception messages.
- `chargeUser()` no longer e-mails failed-charge responses to a hard-coded address.

### Added
- `config/ontraport.php`, publishable with `--tag=ontraport-config`. It includes `chargeUser()`
  defaults (gateway, invoice template, trial and subscription settings).
- `Linups\OntraportLaravel\Events\ChargeFailed` event, plus an error log entry, on failed charges.
- `Linups\OntraportLaravel\Exceptions\OntraportException` (extends `\Exception`), with
  `getContext()` and `getMethodName()`.
- Test suite and README.

### Changed
- The bundled SDK moved from `src/vendor/` to `lib/ontraport-sdk/` and has its own autoloader.
- `composer.json` now declares `php ^8.2` and `illuminate/support ^11|^12|^13`.

## v1.0.8 and earlier

See the git history.
