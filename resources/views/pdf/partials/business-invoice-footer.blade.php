@php
    use App\Support\Invoicing\InvoiceFooterIcons;

    $footerFixed = $footerFixed ?? true;
    $displayWebsite = $company->website
        ? preg_replace('#^https?://#i', '', rtrim((string) $company->website, '/'))
        : null;
    $phoneHref = $company->issuer_phone
        ? 'tel:'.preg_replace('/[^\d+]/', '', (string) $company->issuer_phone)
        : null;
    $emailHref = $company->issuer_email
        ? 'mailto:'.(string) $company->issuer_email
        : null;
    $websiteHref = null;
    if ($company->website) {
        $websiteHref = preg_match('#^https?://#i', (string) $company->website)
            ? (string) $company->website
            : 'https://'.ltrim((string) $company->website, '/');
    }
    $linkStyle = 'color: #374151; text-decoration: none; font-size: 9px; font-family: DejaVu Sans, sans-serif;';
    $row1Cell = 'width: 25%; font-size: 9px; color: #374151; vertical-align: middle; font-family: DejaVu Sans, sans-serif;';
    $tableStyle = 'width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0; padding: 0;';
    $wrapperStyle = 'position: fixed; bottom: 26px; left: 32px; right: 32px; margin: 0; padding: 0;';
@endphp

@if($footerFixed)
<div style="{{ $wrapperStyle }}">
@endif

<table cellpadding="0" cellspacing="0" style="{{ $tableStyle }}">
    <tr>
        <td colspan="4" style="border-top: 1px dotted #b8c0cc; padding-top: 7px; font-size: 0; line-height: 0;">&nbsp;</td>
    </tr>
    <tr>
        <td align="left" style="{{ $row1Cell }}">
            @if($company->issuer_name)
                <strong style="color: #111827;">{{ __('Issued by') }}:</strong> {{ $company->issuer_name }}
            @endif
        </td>
        <td align="center" style="{{ $row1Cell }}">
            @if($company->issuer_phone && $phoneHref)
                <table cellpadding="0" cellspacing="0" align="center"><tr>
                    <td style="padding-right: 4px; vertical-align: middle;">
                        <img src="{{ InvoiceFooterIcons::dataUri('phone') }}" width="16" height="16" alt="">
                    </td>
                    <td style="vertical-align: middle;">
                        <a href="{{ $phoneHref }}" style="{{ $linkStyle }}">{{ $company->issuer_phone }}</a>
                    </td>
                </tr></table>
            @endif
        </td>
        <td align="center" style="{{ $row1Cell }}">
            @if($displayWebsite && $websiteHref)
                <table cellpadding="0" cellspacing="0" align="center"><tr>
                    <td style="padding-right: 4px; vertical-align: middle;">
                        <img src="{{ InvoiceFooterIcons::dataUri('web') }}" width="16" height="16" alt="">
                    </td>
                    <td style="vertical-align: middle;">
                        <a href="{{ $websiteHref }}" style="{{ $linkStyle }}">{{ $displayWebsite }}</a>
                    </td>
                </tr></table>
            @endif
        </td>
        <td align="right" style="{{ $row1Cell }}">
            @if($company->issuer_email && $emailHref)
                <table cellpadding="0" cellspacing="0" align="right"><tr>
                    <td style="padding-right: 4px; vertical-align: middle;">
                        <img src="{{ InvoiceFooterIcons::dataUri('email') }}" width="16" height="16" alt="">
                    </td>
                    <td style="vertical-align: middle;">
                        <a href="{{ $emailHref }}" style="{{ $linkStyle }}">{{ $company->issuer_email }}</a>
                    </td>
                </tr></table>
            @endif
        </td>
    </tr>
    @php
        // DE Geschaeftsbrief corporate data - statutory German labels on
        // purpose (like the tax clauses, they are not translated). DE-only:
        // a company switched away from eu_de keeps the values but must not
        // render German wording (mirrors InvoiceLivePreview).
        $isDeCorporate = \App\Support\Invoicing\JurisdictionRules::normalizeValue($company->jurisdiction) === 'eu_de';
        $corporateParts = $isDeCorporate ? array_filter([
            ($company->register_court || $company->register_number)
                ? trim(($company->register_court ?? '').' '.($company->register_number ?? ''))
                : null,
            $company->managing_directors ? 'Geschäftsführer: '.$company->managing_directors : null,
            $company->supervisory_board_chair ? 'Vorsitzender des Aufsichtsrats: '.$company->supervisory_board_chair : null,
        ]) : [];
    @endphp
    @if($corporateParts !== [])
    <tr>
        <td colspan="4" align="center" style="padding-top: 5px; font-size: 8px; color: #6b7280; font-family: DejaVu Sans, sans-serif;">
            {{ $company->legal_name }}@if($company->city) · Sitz: {{ $company->city }}@endif · {{ implode(' · ', $corporateParts) }}
        </td>
    </tr>
    @endif
    <tr>
        <td colspan="4" style="height: 8px; font-size: 0; line-height: 0;">&nbsp;</td>
    </tr>
    @if(! $footerFixed)
        <tr>
            <td width="25%" style="height: 10px; font-size: 0; line-height: 0;">&nbsp;</td>
            <td colspan="2" width="50%" align="center" style="font-size: 8px; line-height: 10px; color: #6b7280; vertical-align: middle; font-family: DejaVu Sans, sans-serif;">
                {{ __('Created with SATFLUX.io') }}
            </td>
            <td width="25%" style="height: 10px; font-size: 0; line-height: 0;">&nbsp;</td>
        </tr>
    @else
        {{-- Brand + pagination rendered via page_text on the same baseline (see partial below). --}}
        <tr>
            <td colspan="4" style="height: 6px; font-size: 0; line-height: 0;">&nbsp;</td>
        </tr>
    @endif
</table>

@if($footerFixed)
    @include('pdf.partials.business-invoice-footer-brand-script')
@endif

@if($footerFixed)
</div>
@endif
