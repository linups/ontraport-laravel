<?php

namespace Linups\OntraportLaravel\Support;

/**
 * Masks payment-card data before it reaches exception messages, logs or events.
 *
 * @internal
 */
final class Redactor
{
    /** Card numbers: keep the last 4 digits. */
    private const CARD_NUMBER = '/^(cc_?number|payment_number|card_?number)$/i';

    /** Security codes: fully masked. */
    private const SECURITY_CODE = '/^(cvc|cvv|ccv|cc_?cv[cv]|payment_cv[cv]|card_?cv[cv])$/i';

    /** Expiry fields: fully masked. */
    private const EXPIRY = '/^(cc_?expiration.*|payment_expire.*|cc_?exp.*|card_?exp.*)$/i';

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::redactField($key, $item);
            }

            return $result;
        }

        if ($value instanceof \stdClass) {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $result->{$key} = self::redactField($key, $item);
            }

            return $result;
        }

        return $value;
    }

    private static function redactField(int|string $key, mixed $value): mixed
    {
        if (is_string($key) && (is_scalar($value) && $value !== '')) {
            if (preg_match(self::CARD_NUMBER, $key)) {
                $digits = (string) $value;

                return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
            }

            if (preg_match(self::SECURITY_CODE, $key)) {
                return '***';
            }

            if (preg_match(self::EXPIRY, $key)) {
                return '**';
            }
        }

        return self::redact($value);
    }
}
