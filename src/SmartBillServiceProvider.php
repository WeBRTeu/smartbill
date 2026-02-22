<?php

namespace WeBRTeu\SmartBill;

use Illuminate\Support\ServiceProvider;

class SmartBillServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Dacă pachetul are propriul .env (rulare standalone/izolată), îl încarcă automat
        $packageEnv = __DIR__.'/../.env';
        if (file_exists($packageEnv) && function_exists('putenv')) {
            foreach (file($packageEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\"'");
                    if (!getenv($key)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }

        $this->mergeConfigFrom(__DIR__.'/../config/smartbill.php', 'smartbill');

        $this->app->singleton(SmartBillService::class, function ($app) {
            return new SmartBillService();
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smartbill.php' => config_path('smartbill.php'),
            ], 'smartbill-config');
        }
    }
}
