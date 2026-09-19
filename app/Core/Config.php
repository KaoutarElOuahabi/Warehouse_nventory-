<?php
namespace App\Core;

class Config
{
    private static ?array $data = null;

    private static function load(): array
    {
        if (self::$data === null) {
            $path = __DIR__ . '/../../config/config.php';
            if (!file_exists($path)) {
                http_response_code(503);
                die(json_encode([
                    'error' => 'App is not installed yet. Open /install/ in your browser to run the setup wizard.'
                ]));
            }
            self::$data = require $path;
        }
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        $data = self::load();
        return $data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::load();
    }
}
