# Bundled Ontraport SDK

This directory holds a copy of [`ontraport/sdk-php`](https://github.com/Ontraport/SDK-PHP) **v1.0.3**
(commit `37726bb`, January 2018), licensed under MIT (see `LICENSE.md`).

It is bundled rather than required from Packagist because it carries local changes the package
depends on. **Do not replace it with the upstream package** without porting these changes:
`Ontraport::logTransaction()` would break.

It is autoloaded by `src/Support/SdkAutoloader.php` (namespace `OntraportAPI\`).

## Local changes

1. **Raw JSON body for transactions** (needed by `logTransaction()`):
   - `Transactions::processManual($requestParams, $raw = false)`
   - `Ontraport::request(..., $raw = false)`
   - `CurlClient::httpRequest(..., $raw = false)`

   When `$raw` is given, it is `json_encode`d and sent as the request body instead of
   `$requestParams`. `$requestParams` is still used for required-parameter validation.

2. **PHP 8.4 / 8.5 compatibility** (v1.1.0):
   - `Exceptions\OntraportAPIException::__construct()`: `?\Exception $previous = null`
     (implicitly nullable parameters are deprecated in PHP 8.4).
   - `CurlClient::httpRequest()`: `curl_close()` is skipped on PHP 8+ (it does nothing there and
     is deprecated in PHP 8.5).

3. **Rate-limit retry hardening** (v1.1.0):
   - `CurlClient::httpRequest()` / `retry()`: a missing `x-rate-limit-reset` header no longer
     raises an "undefined index" warning; the retry waits at least 1 second.

4. Removed leftover debugging comments from `CurlClient::httpRequest()`.
