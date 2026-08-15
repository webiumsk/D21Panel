@php
    use App\Support\Invoicing\InvoiceUnitLabel;

    $isUs = !empty($isUs);
    $dateFmt = $isUs ? 'm/d/Y' : 'd.m.Y';
    $fmtAmount = static function (float $amount) use ($isUs): string {
        return $isUs
            ? number_format($amount, 2, '.', ',')
            : number_format($amount, 2, ',', ' ');
    };
    $docTitle = match ($document->type->value) {
        'proforma' => __('Proforma invoice'),
        'credit_note' => __('Credit note'),
        'quote' => __('Quote'),
        default => __('Invoice'),
    };
    $isQuote = $document->type->value === 'quote';
    $amountDue = max(0, (float) $document->total - (float) ($document->amount_paid ?? 0));
    $showPaidInfo = ! $isQuote
        && $document->pdf_show_payment_info
        && ($document->status->value === 'paid' || $amountDue < 0.005);
    $showPaymentTotals = ! $isQuote && ($showPaidInfo || $document->payment_bank_enabled || $document->payment_btc_enabled);
    $showPayBar = ! $isQuote && $document->payment_bank_enabled && $company->iban;
    $formatIban = static function (?string $iban): string {
        if (! $iban) {
            return '';
        }
        $clean = preg_replace('/\s+/', '', $iban) ?? '';

        return trim(chunk_split($clean, 4, ' '));
    };
    $formatQty = static function ($qty): string {
        $s = number_format((float) $qty, 4, '.', '');

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    };
@endphp

<table class="header-table">
    <tr>
        <td class="supplier-col">
            <div class="section-label">{{ __('Supplier') }}</div>
            <strong style="font-size:11px;color:#111827;">{{ $company->displayName() }}</strong><br>
            @if($company->street){{ $company->street }}<br>@endif
            @if($isUs)
                @if($company->city || $company->state_region || $company->postal_code)
                    @if($company->city){{ $company->city }}@endif
                    @if($company->city && ($company->state_region || $company->postal_code)), @endif
                    {{ trim($company->state_region.' '.$company->postal_code) }}<br>
                @endif
                @if($company->country){{ $company->country }}<br>@endif
            @else
                @if($company->postal_code || $company->city)
                    {{ $company->postal_code }} {{ $company->city }}@if($company->country), {{ $company->country }}@endif<br>
                @endif
            @endif
            @if($company->registration_number)
                @if($isUs)
                    {{ __('EIN') }}: {{ $company->registration_number }}<br>
                @else
                    {{ __('Reg. no.') }}: {{ $company->registration_number }}<br>
                @endif
            @endif
            @if($company->tax_id){{ $taxNumberLabel ?? __('Tax no.') }}: {{ $company->tax_id }}<br>@endif
            @if($company->vat_number){{ $taxIdLabel ?? __('VAT ID') }}: {{ $company->vat_number }}<br>@endif
            @if($company->commercial_register)<span class="muted">{{ $company->commercial_register }}</span><br>@endif
        </td>
        <td class="logo-col">
            @if(!empty($logoDataUri))
                <img src="{{ $logoDataUri }}" alt="">
            @endif
        </td>
        <td class="title-col">
            <div class="doc-title">{{ $docTitle }} {{ $document->number }}</div>
            @if($document->variable_symbol)
                <div class="doc-subtitle">{{ __('Variable symbol') }}: <strong>{{ $document->variable_symbol }}</strong></div>
            @endif
            @if($document->title && $document->title !== $docTitle.' '.$document->number)
                <div class="doc-subtitle muted">{{ $document->title }}</div>
            @endif
            @if(! $isUs)
                <div style="margin-top:10px;font-size:8px;font-weight:bold;color:#4b6a8a;letter-spacing:0.06em;">ISDOC 6.0</div>
            @endif
        </td>
    </tr>
</table>

<hr class="divider">

<table class="meta-table">
    <tr>
        <td class="bank-col">
            @if($company->bank_name)
                <span class="label">{{ __('Bank') }}:</span> {{ $company->bank_name }}<br>
            @endif
            @if($company->iban)
                <span class="label">{{ __('IBAN') }}:</span> {{ $formatIban($company->iban) }}@if($company->bic)<br><span class="label">SWIFT:</span> {{ $company->bic }}@endif<br>
            @endif
            @if($company->bank_account)
                <span class="label">{{ __('Account no.') }}:</span> {{ $company->bank_account }}@if($company->bank_code) / {{ $company->bank_code }}@endif<br>
            @endif
            @if($document->variable_symbol)
                <span class="label">{{ __('Variable symbol') }}:</span> {{ $document->variable_symbol }}<br>
            @endif
            @if($document->constant_symbol)
                <span class="label">{{ __('Constant symbol') }}:</span> {{ $document->constant_symbol }}<br>
            @endif
            @if($company->website)
                <span class="label">{{ __('Website') }}:</span> {{ $company->website }}<br>
            @endif
        </td>
        <td class="customer-col">
            <div class="section-label">{{ __('Customer') }}</div>
            @if($contact)
                <div class="customer-name">{{ $contact->name }}</div>
                @if($contact->street){{ $contact->street }}<br>@endif
                @if($contact->postal_code || $contact->city || ($isUs && $contact->state_region))
                    @if($isUs)
                        @if($contact->city || $contact->state_region || $contact->postal_code)
                            @if($contact->city){{ $contact->city }}@endif
                            @if($contact->city && ($contact->state_region || $contact->postal_code)), @endif
                            {{ trim($contact->state_region.' '.$contact->postal_code) }}<br>
                        @endif
                    @else
                        {{ $contact->postal_code }} {{ $contact->city }}<br>
                    @endif
                @endif
                @if($contact->country){{ $contact->country }}<br>@endif
                {{-- Buyer identifiers - a reverse-charge invoice must carry
                     both parties' VAT IDs. Generic labels on purpose: the
                     supplier-scoped overrides (Steuernummer/USt-IdNr.) do
                     not fit a foreign counterparty. --}}
                @if(!empty($contact->registration_number)){{ __('Reg. no.') }}: {{ $contact->registration_number }}<br>@endif
                @if(!empty($contact->tax_id)){{ __('Tax no.') }}: {{ $contact->tax_id }}<br>@endif
                @if(!empty($contact->vat_id)){{ __('VAT ID') }}: {{ $contact->vat_id }}<br>@endif
                @if($contact->email){{ $contact->email }}<br>@endif
            @endif
            <table class="dates-table">
                <tr>
                    <td class="date-label">{{ __('Issue date') }}:</td>
                    <td class="date-value">{{ $document->issue_date?->format($dateFmt) }}</td>
                </tr>
                {{-- DE §14 UStG: the Leistungsdatum must be stated explicitly
                     even when it equals the issue date. --}}
                @if($document->delivery_date || !empty($isDe))
                    <tr>
                        <td class="date-label">{{ __('Delivery date') }}:</td>
                        <td class="date-value">{{ ($document->delivery_date ?? $document->issue_date)?->format($dateFmt) }}</td>
                    </tr>
                @endif
                @if($document->due_date)
                    <tr>
                        <td class="date-label">{{ $isQuote ? __('Quote valid until') : __('Due date') }}:</td>
                        <td class="date-value">{{ $document->due_date->format($dateFmt) }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<hr class="divider">

@if(!empty($reverseChargeNote))
    <div class="note-above" style="font-weight:600;">{{ $reverseChargeNote }}</div>
@endif

@if($document->note_above_lines)
    <div class="note-above">{{ $document->note_above_lines }}</div>
@endif

<table class="lines-table">
    <thead>
        <tr>
            <th class="col-item">{{ __('Item name and description') }}</th>
            <th class="col-qty">{{ __('Qty') }}</th>
            <th class="col-unit">{{ __('Unit') }}</th>
            <th class="col-price">{{ $isUs ? __('Rate') : __('Unit price') }}</th>
            @if(!empty($showSalesTaxColumn))
                <th class="col-vat">{{ __('Tax %') }}</th>
            @elseif(!empty($showVatColumn))
                <th class="col-vat">{{ $vatLabel ?? __('VAT') }} %</th>
            @endif
            <th class="col-total">{{ __('Line total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr>
                <td class="col-item">
                    <strong>{{ $line->name }}</strong>
                    @if($line->description)<div class="line-desc">{{ $line->description }}</div>@endif
                </td>
                <td class="col-qty">{{ $formatQty($line->quantity) }}</td>
                <td class="col-unit">{{ InvoiceUnitLabel::format($line->unit) }}</td>
                <td class="col-price">{{ $fmtAmount((float) $line->unit_price) }} {{ $document->currency }}</td>
                @if(!empty($showSalesTaxColumn))
                    <td class="col-vat">{{ number_format((float) $line->tax_rate, 2, $isUs ? '.' : ',', $isUs ? ',' : ' ') }}%</td>
                @elseif(!empty($showVatColumn))
                    <td class="col-vat">{{ number_format((float) $line->tax_rate, 0, ',', ' ') }} %</td>
                @endif
                <td class="col-total"><strong>{{ $fmtAmount((float) $line->line_total) }} {{ $document->currency }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="footer-table">
    <tr>
        <td class="note-col">
            @if($document->note_footer)
                <div class="section-label">{{ __('Note') }}</div>
                <div style="white-space:pre-wrap;">{{ $document->note_footer }}</div>
            @endif
        </td>
        <td class="totals-col">
            <table class="totals-box">
                @if((float) $document->discount_percent > 0)
                    <tr>
                        <td class="label">{{ __('Discount') }} {{ $document->discount_percent }}%</td>
                        <td class="value"></td>
                    </tr>
                @endif
                @if(!empty($showVatBreakdown))
                    <tr>
                        <td class="label">{{ __('Subtotal') }}</td>
                        <td class="value">{{ $fmtAmount((float) $document->subtotal) }} {{ $document->currency }}</td>
                    </tr>
                    @if(!empty($taxBreakdown))
                        @foreach($taxBreakdown as $row)
                            <tr>
                                <td class="label">{{ $vatLabel ?? __('VAT') }} {{ number_format($row->ratePercent, 0, ',', ' ') }} %</td>
                                <td class="value">{{ $fmtAmount((float) $row->taxAmount) }} {{ $document->currency }}</td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td class="label">{{ $vatLabel ?? __('VAT') }}</td>
                            <td class="value">{{ $fmtAmount((float) $document->tax_total) }} {{ $document->currency }}</td>
                        </tr>
                    @endif
                @elseif(!empty($showSalesTaxColumn))
                    <tr>
                        <td class="label">{{ __('Subtotal') }}</td>
                        <td class="value">{{ $fmtAmount((float) $document->subtotal) }} {{ $document->currency }}</td>
                    </tr>
                    @if(!empty($taxBreakdown))
                        @foreach($taxBreakdown as $row)
                            <tr>
                                <td class="label">{{ $row->label ?? (__('Sales tax').' '.number_format($row->ratePercent, 2, '.', '').'%') }}</td>
                                <td class="value">{{ $fmtAmount((float) $row->taxAmount) }} {{ $document->currency }}</td>
                            </tr>
                        @endforeach
                    @elseif((float) $document->tax_total > 0)
                        <tr>
                            <td class="label">{{ __('Sales tax') }}</td>
                            <td class="value">{{ $fmtAmount((float) $document->tax_total) }} {{ $document->currency }}</td>
                        </tr>
                    @endif
                @endif
                <tr class="grand">
                    <td class="label">{{ $isUs && ! $isQuote ? __('Total due') : __('Grand total') }}</td>
                    <td class="value">{{ $fmtAmount((float) $document->total) }} {{ $document->currency }}</td>
                </tr>
                @if($showPaidInfo)
                    <tr class="paid-row">
                        <td class="label">{{ __('Paid') }}</td>
                        <td class="value">{{ $fmtAmount((float) ($document->amount_paid ?? $document->total)) }} {{ $document->currency }}</td>
                    </tr>
                    <tr class="due">
                        <td class="label">{{ __('Amount due') }}</td>
                        <td class="value">{{ $isUs ? '0.00' : '0,00' }} {{ $document->currency }}</td>
                    </tr>
                    @if($document->paid_at)
                        <tr>
                            <td class="label muted">{{ __('Payment date') }}</td>
                            <td class="value muted">{{ $document->paid_at->format($dateFmt) }}</td>
                        </tr>
                    @endif
                @elseif($showPaymentTotals)
                    <tr class="due">
                        <td class="label">{{ __('Amount due') }}</td>
                        <td class="value">{{ $fmtAmount($amountDue) }} {{ $document->currency }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if($document->pdf_show_signature)
    <div class="signature-block">
        @if(!empty($signatureStampDataUri))
            <img src="{{ $signatureStampDataUri }}" alt="">
        @else
            <div class="signature-placeholder">
                {{ __('Signature and stamp') }}
                <div class="signature-line"></div>
            </div>
        @endif
    </div>
@endif

@if($showPayBar)
    <table class="pay-bar">
        <tr>
            <td>
                <strong>{{ __('IBAN') }}</strong>
                {{ $formatIban($company->iban) }}
            </td>
            @if($document->variable_symbol)
                <td>
                    <strong>{{ __('Variable symbol') }}</strong>
                    {{ $document->variable_symbol }}
                </td>
            @endif
            @if($document->due_date)
                <td>
                    <strong>{{ __('Due date') }}</strong>
                    {{ $document->due_date->format($dateFmt) }}
                </td>
            @endif
            <td>
                <strong>{{ __('Amount due') }}</strong>
                <span class="amount">{{ $fmtAmount($showPaidInfo ? 0 : $amountDue) }} {{ $document->currency }}</span>
            </td>
        </tr>
    </table>
@endif

@include('pdf.partials.business-invoice-qr-block')
