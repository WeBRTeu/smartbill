<?php

return [
    'email' => env('SMARTBILL_EMAIL'),
    'token' => env('SMARTBILL_TOKEN'),
    'cif' => env('SMARTBILL_CIF'),
    'test_mode' => env('SMARTBILL_TEST', false),
    'api_url' => env('SMARTBILL_API_URL', 'https://ws.smartbill.ro/SBORO/api'),
    'series' => [
        'fiscal' => env('SMARTBILL_SERIE_FACTURA', 'WEBRT_FACT_'),
        'proforma' => env('SMARTBILL_SERIE_PROFORMA', 'WEBRT_PROF_'),
    ],
];
