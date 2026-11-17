<?php

namespace Linups\OntraportLaravel\Support;

/**
 * Autoloads the bundled (locally patched) Ontraport SDK from lib/ontraport-sdk.
 *
 * The loader is prepended, as the nested Composer autoloader used before v1.1 was,
 * so the patched copy takes precedence over an `ontraport/sdk-php` package the host
 * application may also have installed. See lib/ontraport-sdk/PATCHES.md.
 *
 * @internal
 */
final class SdkAutoloader
{
    private const PREFIX = 'OntraportAPI\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register([self::class, 'load'], true, true);
        self::$registered = true;
    }

    public static function load(string $class): void
    {
        if (! str_starts_with($class, self::PREFIX)) {
            return;
        }

        $base = dirname(__DIR__, 2) . '/lib/ontraport-sdk/src/';
        $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX)));
        $file = $base . $relative . '.php';

        if (is_file($file)) {
            require_once $file;

            return;
        }

        // The SDK declares all of its exception classes in a single file.
        if (str_starts_with($relative, 'Exceptions/')) {
            require_once $base . 'Exceptions/OntraportAPIException.php';
        }
    }
}
