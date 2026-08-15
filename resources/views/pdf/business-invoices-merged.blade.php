<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }}</title>
    @include('pdf.partials.business-invoice-styles-eu')
    <style>
        .invoice-page { page-break-after: always; }
        .invoice-page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
@foreach($pages as $page)
    @php
        $document = $page['document'];
        $company = $page['company'];
        $contact = $page['contact'];
        $lines = $page['lines'];
        $bankQr = $page['bankQr'] ?? null;
        $bankQrStandard = $page['bankQrStandard'] ?? null;
        $btcPayQr = $page['btcPayQr'] ?? null;
        $btcPayUrl = $page['btcPayUrl'] ?? null;
        $logoDataUri = $page['logoDataUri'] ?? null;
        $signatureStampDataUri = $page['signatureStampDataUri'] ?? null;
        $taxBreakdown = $page['taxBreakdown'] ?? [];
        $showVatColumn = $page['showVatColumn'] ?? false;
        $showVatBreakdown = $page['showVatBreakdown'] ?? false;
        $showSalesTaxColumn = $page['showSalesTaxColumn'] ?? false;
        $isUs = $page['isUs'] ?? false;
        $reverseChargeNote = $page['reverseChargeNote'] ?? null;
        $vatLabel = $page['vatLabel'] ?? null;
        $taxIdLabel = $page['taxIdLabel'] ?? null;
        $taxNumberLabel = $page['taxNumberLabel'] ?? null;
        $isDe = $page['isDe'] ?? false;
    @endphp
    <div class="invoice-page">
        @include('pdf.partials.business-invoice-body-eu', compact(
            'document',
            'company',
            'contact',
            'lines',
            'vatLabel',
            'taxIdLabel',
            'taxNumberLabel',
            'isDe',
            'taxBreakdown',
            'showVatColumn',
            'showVatBreakdown',
            'showSalesTaxColumn',
            'reverseChargeNote',
            'bankQr',
            'bankQrStandard',
            'btcPayQr',
            'btcPayUrl',
            'logoDataUri',
            'signatureStampDataUri',
            'isUs',
        ))

        @include('pdf.partials.business-invoice-footer', ['company' => $company, 'footerFixed' => false])
    </div>
@endforeach
@include('pdf.partials.business-invoice-page-script')
</body>
</html>
