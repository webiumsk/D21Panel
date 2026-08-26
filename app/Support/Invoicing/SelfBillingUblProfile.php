<?php

namespace App\Support\Invoicing;

/**
 * Peppol BIS Self-Billing 3.0 identifiers (Track D). A self-billed invoice is
 * created by the customer on behalf of the supplier; the profile differs from
 * ordinary Peppol BIS Billing only in these two URNs (and InvoiceTypeCode 389).
 */
final class SelfBillingUblProfile
{
    public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:selfbilling:3.0';

    public const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:selfbilling:01:1.0';
}
