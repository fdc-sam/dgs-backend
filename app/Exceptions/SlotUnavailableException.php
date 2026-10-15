<?php

namespace App\Exceptions;

class SlotUnavailableException extends ApiException
{
    public function __construct(string $message = 'The selected time slot is no longer available. Please choose another time.')
    {
        parent::__construct($message, 'SLOT_UNAVAILABLE', 409);
    }
}
