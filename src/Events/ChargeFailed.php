<?php

namespace Linups\OntraportLaravel\Events;

/**
 * Dispatched when Ontraport::chargeUser() gets a non-successful response.
 *
 * Replaces the hard-coded e-mail sent by versions before 1.1. Listen to it to notify
 * your team, e.g. in a listener registered for this class.
 */
class ChargeFailed
{
    /**
     * @param array<string, mixed> $data   The charge data passed to chargeUser(), card fields redacted.
     * @param object               $result The object chargeUser() returns (status "fail", message, ...).
     */
    public function __construct(
        public readonly array $data,
        public readonly object $result,
    ) {
    }
}
