<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$settings = \App\Models\CompanySetting::first();
if (!$settings) {
    echo "No settings found.\n";
    exit;
}

$arr = $settings->toArray();
$json = json_encode($arr);
if ($json === false) {
    echo "JSON Error: " . json_last_error_msg() . "\n";
    foreach ($arr as $key => $value) {
        if (!is_string($value)) continue;
        if (!mb_check_encoding($value, 'UTF-8')) {
            echo "Key '$key' is not valid UTF-8. Value hex: " . bin2hex($value) . "\n";
        }
    }
} else {
    echo "JSON encoded fine.\n";
}
