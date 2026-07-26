<?php

namespace PelicanDev\PelicanPangolin\Support;

trait SanitizesErrors
{
    private function sanitizeError(string $message): string
    {
        $message = (string) preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message);
        $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

        return mb_substr($message, 0, 1000);
    }
}
