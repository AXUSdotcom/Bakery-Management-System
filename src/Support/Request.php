<?php
namespace App\Support;

class Request
{
    private static ?array $body = null;

    public static function json(): array
    {
        if (self::$body === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            self::$body = is_array($decoded) ? $decoded : [];
        }
        return self::$body;
    }
}
