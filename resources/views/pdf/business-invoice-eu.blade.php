<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document->number }}</title>
    @include('pdf.partials.business-invoice-styles-eu')
</head>
<body>
    @php
        $btcPayUrl = $btcPayUrl ?? null;
        // Ad-hoc renderers (tests) pass a data subset - compact() requires
        // the variables to exist; the body partial falls back to __() labels.
        $vatLabel = $vatLabel ?? null;
        $taxIdLabel = $taxIdLabel ?? null;
        $taxNumberLabel = $taxNumberLabel ?? null;
        $isDe = $isDe ?? false;
    @endphp
    <div class="invoice-doc-body">
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
        'reverseChargeNote',
        'bankQr',
        'btcPayQr',
        'btcPayUrl',
        'logoDataUri',
        'signatureStampDataUri',
        'isUs',
    ))

    </div>

    @include('pdf.partials.business-invoice-footer', ['company' => $company, 'footerFixed' => true])
</body>
</html>
