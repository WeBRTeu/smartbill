# WeBRTeu SmartBill

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webrteu/smartbill.svg?style=flat-square)](https://packagist.org/packages/webrteu/smartbill)
[![Total Downloads](https://img.shields.io/packagist/dt/webrteu/smartbill.svg?style=flat-square)](https://packagist.org/packages/webrteu/smartbill)
[![License](https://img.shields.io/packagist/l/webrteu/smartbill.svg?style=flat-square)](https://packagist.org/packages/webrteu/smartbill)

A Laravel package providing seamless integration with the SmartBill API for generating, downloading, and managing invoices (proforma and fiscal).

## Requirements

- PHP 8.2+
- Laravel 10.0+ / 11.0+ / 12.0+

## Installation

You can install the package via composer:

```bash
composer require webrteu/smartbill
```

Publish the config file with:

```bash
php artisan vendor:publish --tag="smartbill-config"
```

Add the required variables to your `.env` file:

```env
SMARTBILL_EMAIL=your_email@example.com
SMARTBILL_TOKEN=your_smartbill_api_token
SMARTBILL_CIF=RO12345678
SMARTBILL_TEST=false
SMARTBILL_API_URL=https://ws.smartbill.ro/SBORO/api
SMARTBILL_SERIE_FACTURA=WEBRT_FACT_
SMARTBILL_SERIE_PROFORMA=WEBRT_PROF_
```

## Usage

The `SmartBillService` is registered as a singleton in the Laravel Service Container. You can resolve it via dependency injection or using the `app()` helper.

### Sending an Invoice

```php
use WeBRTeu\SmartBill\SmartBillService;

// Resolve the instance
$smartBillService = app(SmartBillService::class);

// Send an invoice
$result = $smartBillService->sendInvoice($invoice);

if ($result['success']) {
    echo "Invoice saved with series: " . $result['series'] . " and number: " . $result['number'];
} else {
    echo "Error: " . $result['error'];
}
```

### Available Methods

* `$smartBillService->sendInvoice(Invoice $invoice)`: Sends a new invoice to SmartBill.
* `$smartBillService->downloadInvoicePdf(string $series, string $number)`: Downloads the requested invoice PDF.
* `$smartBillService->deleteInvoice(string $series, string $number, bool $cancel = false)`: Deletes or cancels a specific invoice in the SmartBill system.

### Testing / Sandbox Mode

For testing purposes, a sandbox mode is available by making sure the test variable is set to true in your `.env` file:

```env
SMARTBILL_TEST=true
```

## Credits

- [WeBRTeu](https://webrteu.eu)

## License

The MIT License (MIT).
