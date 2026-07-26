<?php

namespace PelicanDev\PelicanPangolin\Exceptions;

use RuntimeException;

final class PangolinApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
