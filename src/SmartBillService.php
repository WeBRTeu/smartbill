<?php

namespace WeBRTeu\SmartBill;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmartBillService
{
    private string $apiUrl;
    private string $email;
    private string $token;
    private string $cif;
    private bool $testMode;

    public function __construct()
    {
        // Fallback robust: Încearcă întâi configurarea din pachet publicată, altfel configurarea veche (opțional), la final defaultul direct din env
        $this->email = config('smartbill.email') ?? env('SMARTBILL_EMAIL');
        $this->token = config('smartbill.token') ?? env('SMARTBILL_TOKEN');
        $this->cif = config('smartbill.cif') ?? env('SMARTBILL_CIF');
        $this->testMode = config('smartbill.test_mode') ?? env('SMARTBILL_TEST', false);
        $this->apiUrl = config('smartbill.api_url') ?? env('SMARTBILL_API_URL', 'https://ws.smartbill.ro/SBORO/api');
    }

    /**
     * Trimite o factură către SmartBill
     *
     * @param Invoice $invoice
     * @return array|null
     */
    public function sendInvoice(Invoice $invoice): ?array
    {
        try {
            $payload = $this->buildInvoicePayload($invoice);

            // În modul test, doar loghează payload-ul fără a trimite efectiv
            if ($this->testMode) {
                Log::info('SmartBill Test Mode - Invoice payload', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'payload' => $payload
                ]);

                return [
                    'success' => true,
                    'test_mode' => true,
                    'series' => $payload['seriesName'],
                    'number' => $invoice->number,
                    'message' => 'Test mode - invoice not sent to SmartBill'
                ];
            }

            // Trimite factura către SmartBill
            $response = Http::timeout(30)
                ->withBasicAuth($this->email, $this->token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->apiUrl . '/invoice', $payload);

            if ($response->failed()) {
                Log::error('SmartBill API Error', [
                    'invoice_id' => $invoice->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers()
                ]);

                return [
                    'success' => false,
                    'error' => $response->body(),
                    'status' => $response->status()
                ];
            }

            $data = $response->json();

            Log::info('SmartBill Invoice sent successfully', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'response' => $data
            ]);

            return [
                'success' => true,
                'data' => $data,
                'series' => $data['series'] ?? null,
                'number' => $data['number'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('SmartBill Service Exception', [
                'invoice_id' => $invoice->id ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Construiește payload-ul pentru API-ul SmartBill
     *
     * @param Invoice $invoice
     * @return array
     */
    private function buildInvoicePayload(Invoice $invoice): array
    {
        $order = $invoice->order;
        $client = $invoice->client;
        $items = $invoice->items;

        // Determină seria documentului din configurație
        $seriesName = $invoice->isProforma()
            ? (config('smartbill.series.proforma') ?? env('SMARTBILL_SERIE_PROFORMA', 'WEBRT_PROF_'))
            : (config('smartbill.series.fiscal') ?? env('SMARTBILL_SERIE_FACTURA', 'WEBRT_FACT_'));

        // Construiește payload-ul
        $payload = [
            'companyVatCode' => $this->cif,
            'client' => $this->buildClientData($client, $invoice->buyer_data),
            'issueDate' => $invoice->issued_at->format('Y-m-d'),
            'seriesName' => $seriesName,
            'isDraft' => false,
            'currency' => $invoice->currency ?? 'RON',
            'products' => $this->buildProductsData($items, $invoice),
            'language' => 'RO',
        ];

        // Adaugă data scadenței dacă există (pentru facturi fiscale)
        if (!$invoice->isProforma() && $invoice->due_date) {
            $payload['dueDate'] = $invoice->due_date->format('Y-m-d');
        }

        // Dacă nu e proforma, adaugă informații despre plată
        if (!$invoice->isProforma() && $order && $order->isPaid()) {
            $payload['payment'] = [
                'value' => $invoice->amount,
                'type' => $this->mapPaymentType($order->payment_method ?? 'card'),
                'isCash' => false,
            ];
        }

        return $payload;
    }

    /**
     * Construiește datele clientului pentru SmartBill
     *
     * @param $client
     * @param array $buyerData
     * @return array
     */
    private function buildClientData($client, array $buyerData): array
    {
        // Obține județul - dacă e prescurtat (ex: BV), convertește la numele complet (ex: Brașov)
        $county = $client->county ?? '';
        if ($county && isset(config('counties.list')[$county])) {
            $county = config('counties.list')[$county];
        }

        return [
            'name' => $buyerData['name'] ?? $client->company_name ?? $client->name,
            'vatCode' => $buyerData['cui'] ?? $client->cui ?? '',
            'regCom' => $buyerData['reg_com'] ?? $client->trade_register ?? '',
            'address' => $buyerData['address'] ?? $client->address ?? '',
            'isTaxPayer' => false, // Nu ești plătitor de TVA
            'city' => $client->city ?? '',
            'county' => $county,
            'country' => $client->country ?? 'Romania',
            'email' => $buyerData['email'] ?? $client->email ?? '',
            'phone' => $buyerData['phone'] ?? $client->phone ?? '',
            'saveToDb' => true, // Salvează clientul în nomenclatorul SmartBill
        ];
    }

    /**
     * Construiește datele produselor/serviciilor pentru SmartBill
     *
     * @param array $items
     * @param Invoice $invoice
     * @return array
     */
    private function buildProductsData(array $items, Invoice $invoice): array
    {
        $products = [];

        // Produs principal (pachetul de audit)
        $packageName = $items['package_name'] ?? 'Servicii Audit Web';
        $description = $items['description'] ?? $packageName;
        $basePrice = $items['base_price'] ?? 0;
        $includedUrls = $items['included_urls'] ?? 0;

        // Construiește descrierea detaliată cu CAEN, EU VAT și URL-urile auditate
        // Limită: max 500 caractere pentru descriere (safe limit pentru SmartBill)
        $detailedDescription = $description;

        // Adaugă CAEN dacă există în config
        $caen = config('invoicing.seller.caen');
        if ($caen) {
            $detailedDescription .= "\nCAEN: " . $caen;
        }

        // Adaugă EU VAT dacă există în config
        $euVat = config('invoicing.seller.eu_vat');
        if ($euVat) {
            $detailedDescription .= "\nVIES: " . $euVat;
        }

        // Adaugă URL-urile auditate
        if (isset($items['urls']) && is_array($items['urls']) && count($items['urls']) > 0) {
            $maxUrls = 5; // Primele 5 URL-uri pentru a evita limita de caractere
            $urlsToShow = array_slice($items['urls'], 0, $maxUrls);
            $urlsList = implode(', ', $urlsToShow);

            // Trunchiază dacă e prea lung
            if (strlen($urlsList) > 250) {
                $urlsList = substr($urlsList, 0, 247) . '...';
            }

            $urlsText = "\nURL-uri: " . $urlsList;
            if (count($items['urls']) > $maxUrls) {
                $urlsText .= sprintf(' (+%d)', count($items['urls']) - $maxUrls);
            }

            // Adaugă doar dacă nu depășește limita totală
            if (strlen($detailedDescription . $urlsText) <= 500) {
                $detailedDescription .= $urlsText;
            }
        }

        // Prețul final (fără TVA deoarece nu ești plătitor de TVA)
        $products[] = [
            'name' => $packageName,
            'code' => 'AUDIT-' . strtoupper($items['package_type'] ?? 'standard'),
            'isService' => true,
            'measuringUnitName' => 'buc',
            'currency' => $invoice->currency ?? 'RON',
            'quantity' => 1,
            'price' => round($basePrice, 2),
            'isTaxIncluded' => false,
            'taxName' => 'Neplatitor TVA',
            'taxPercentage' => 0,
            'isDiscount' => false,
            'productDescription' => $detailedDescription,
        ];

        // Adaugă URL-uri suplimentare dacă există
        if (($items['extra_urls'] ?? 0) > 0) {
            $extraUrlPrice = $items['extra_url_price'] ?? 20;
            $extraUrlsCount = $items['extra_urls'];

            $products[] = [
                'name' => 'URL-uri suplimentare audit web',
                'code' => 'AUDIT-EXTRA-URL',
                'isService' => true,
                'measuringUnitName' => 'buc',
                'currency' => $invoice->currency ?? 'RON',
                'quantity' => $extraUrlsCount,
                'price' => round($extraUrlPrice, 2),
                'isTaxIncluded' => false,
                'taxName' => 'Neplatitor TVA',
                'taxPercentage' => 0,
                'isDiscount' => false,
                'productDescription' => "URL-uri suplimentare peste cele {$includedUrls} incluse în pachet",
            ];
        }

        return $products;
    }

    /**
     * Mapează tipul de plată către format SmartBill
     *
     * @param string $paymentMethod
     * @return string
     */
    private function mapPaymentType(string $paymentMethod): string
    {
        return match(strtolower($paymentMethod)) {
            'card', 'stripe' => 'Card',
            'bank_transfer', 'wire' => 'Ordin de plata',
            'cash' => 'Numerar',
            'check' => 'CEC',
            default => 'Alta',
        };
    }

    /**
     * Obține o factură din SmartBill (descarcă PDF)
     *
     * @param string $series
     * @param string $number
     * @return string|null
     */
    public function downloadInvoicePdf(string $series, string $number): ?string
    {
        try {
            if ($this->testMode) {
                Log::info('SmartBill Test Mode - Download invoice PDF', [
                    'series' => $series,
                    'number' => $number
                ]);
                return null;
            }

            $response = Http::timeout(30)
                ->withBasicAuth($this->email, $this->token)
                ->withHeaders([
                    'Accept' => 'application/octet-stream',
                ])
                ->get($this->apiUrl . '/invoice', [
                    'cif' => $this->cif,
                    'seriesName' => $series,
                    'number' => $number,
                ]);

            if ($response->failed()) {
                Log::error('SmartBill PDF Download Error', [
                    'series' => $series,
                    'number' => $number,
                    'status' => $response->status()
                ]);
                return null;
            }

            return $response->body();

        } catch (\Exception $e) {
            Log::error('SmartBill PDF Download Exception', [
                'message' => $e->getMessage(),
                'series' => $series,
                'number' => $number
            ]);
            return null;
        }
    }

    /**
     * Șterge sau anulează o factură din SmartBill
     *
     * @param string $series
     * @param string $number
     * @param bool $cancel (false = delete, true = cancel)
     * @return bool
     */
    public function deleteInvoice(string $series, string $number, bool $cancel = false): bool
    {
        try {
            if ($this->testMode) {
                Log::info('SmartBill Test Mode - Delete/Cancel invoice', [
                    'series' => $series,
                    'number' => $number,
                    'cancel' => $cancel
                ]);
                return true;
            }

            $response = Http::timeout(30)
                ->withBasicAuth($this->email, $this->token)
                ->delete($this->apiUrl . '/invoice', [
                    'cif' => $this->cif,
                    'seriesName' => $series,
                    'number' => $number,
                    'cancel' => $cancel,
                ]);

            if ($response->successful()) {
                Log::info('SmartBill Invoice deleted/cancelled', [
                    'series' => $series,
                    'number' => $number,
                    'cancel' => $cancel
                ]);
                return true;
            }

            Log::error('SmartBill Delete/Cancel Error', [
                'series' => $series,
                'number' => $number,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('SmartBill Delete/Cancel Exception', [
                'message' => $e->getMessage(),
                'series' => $series,
                'number' => $number
            ]);
            return false;
        }
    }

    /**
     * Verifică dacă serviciul este în modul test
     *
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }
}