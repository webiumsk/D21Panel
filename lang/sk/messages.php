<?php

return [
    /*
    |--------------------------------------------------------------------------
    | General Application Messages
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for general application messages
    | that we need to display to the user.
    |
    */

    // Authentication
    'login_successful' => 'Login successful',
    'logout_successful' => 'Logout successful',
    'registration_successful' => 'Registration successful! Please check your email to verify your account.',
    'seed_first_registration_required' => 'Vytvorte si účet najprv s obnovovacou frázou. Overený email môžete pridať pri prechode na Free.',
    'password_login_use_recovery_phrase' => 'Tento účet používa na prihlásenie obnovovaciu frázu. Použite „Obnoviť pomocou frázy“ na prihlasovacej stránke.',
    'compliance_registration_unavailable' => 'Registrácia z vašej lokality nie je dostupná alebo ju nebolo možné dokončiť. Ak si myslíte, že ide o chybu, kontaktujte podporu.',
    'verification_email_failed' => 'Nepodarilo sa odoslať overovací e-mail. Skontrolujte, či je vaša e-mailová adresa platná, a skúste to znova.',
    'email_verified' => 'Your email has been verified successfully. Please login to continue.',
    'password_reset_sent' => 'Ak účet s týmto e-mailom existuje, odoslali sme odkaz na obnovenie hesla.',
    'password_reset_successful' => 'Your password has been reset.',

    // Account
    'profile_updated' => 'Profile updated successfully',
    'guest_upgrade_code_sent' => 'Poslali sme vám e-mail so 6-miestnym overovacím kódom.',
    'guest_upgrade_confirmed' => 'E-mail potvrdený. Váš účet je povýšený.',
    'guest_upgrade_already_done' => 'Tento účet je už povýšený.',
    'password_updated' => 'Password updated successfully',

    // Stores
    'store_created' => 'Store created successfully',
    'store_updated' => 'Store updated successfully',
    'store_deleted' => 'Store deleted successfully',
    'store_not_found' => 'Store not found',
    'unauthorized' => 'Unauthorized',

    // Store Settings
    'logo_uploaded' => 'Logo uploaded successfully',
    'logo_deleted' => 'Logo deleted successfully',
    'logo_upload_failed' => 'Failed to upload logo: :error',
    'logo_delete_failed' => 'Failed to delete logo: :error',

    // Wallet Connection
    'wallet_connection_saved' => 'Wallet connection saved successfully',
    'wallet_connection_not_found' => 'Wallet connection not found',
    'wallet_connection_deleted' => 'Wallet connection deleted successfully',
    'wallet_connection_cannot_delete' => 'Cannot delete wallet connection. Only pending connections can be deleted.',
    'wallet_connection_secret_revealed' => 'Secret revealed (will auto-hide after 30 seconds)',
    'wallet_connection_marked_connected' => 'Wallet connection marked as connected',
    'lightning_node_connected' => 'Lightning node connected successfully to BTCPay.',
    'lightning_node_connect_failed' => 'Failed to connect Lightning node: :error',
    'lightning_node_connect_error' => 'An error occurred while connecting Lightning node: :error',

    // Stripe
    'stripe_available_in_pro' => 'Stripe je dostupný v Pro. Prejdite na vyšší plán pre nastavenie Stripe platieb pre váš obchod.',

    // Lightning Addresses
    'lightning_address_saved' => 'Lightning address saved successfully',
    'lightning_address_deleted' => 'Lightning address deleted successfully',
    'lightning_address_not_found' => 'Lightning address not found',
    'lightning_address_username_mismatch' => 'Username in request body must match URL parameter',
    'lightning_address_limit_reached' => 'You have reached the maximum number of Lightning Addresses (:max) for your :plan plan. Please upgrade to add more addresses.',

    // Tickets (events limit)
    'tickets_event_limit_free' => 'Na voľnom pláne môžete mať maximálne :max event na obchod. Pre neobmedzené eventy prejdite na Pro.',
    'raffles_limit_free' => 'Na voľnom pláne môžete mať maximálne :max tombolu na obchod. Pre neobmedzené tomboly prejdite na Pro.',
    'raffles_quota_verification_failed' => 'Nepodarilo sa overiť limit tombol v BTCPay. Skúste to znova.',
    'raffles_cannot_delete' => 'Tombolu je možné zmazať len v stave koncept alebo dokončená.',
    'tickets_bundled_raffle_required' => 'Pri raffle lístkoch na vstupenku vyberte otvorenú tombolu.',
    'tickets_bundled_tickets_required' => 'Pri vybranej tombole nastavte počet raffle lístkov na vstupenku (1–20).',
    'tickets_bundled_raffle_invalid' => 'Vybraná tombola pre tento obchod neexistuje.',
    'tickets_bundled_raffle_not_open' => 'Vybraná tombola musí mať stav otvorený predaj (Open).',
    'tickets_bundled_raffle_verify_failed' => 'Tombolu sa nepodarilo overiť v BTCPay. Skúste to znova.',
    'tickets_quantity_required_when_capacity' => 'Počet vstupeniek je povinný, ak má event nastavenú kapacitu.',
    'tickets_cannot_delete_event_with_sold_tickets' => 'Podujatie s predanými vstupenkami nie je možné zmazať.',
    'tickets_cannot_deactivate_event_with_sold_tickets' => 'Podujatie s predanými vstupenkami nie je možné deaktivovať.',
    'tickets_event_toggle_precheck_failed' => 'Pred prepnutím sa nepodarilo overiť stav podujatia. Skúste to prosím znova.',

    // Apps
    'app_created' => 'App created successfully',
    'app_updated' => 'App updated successfully',
    'app_deleted' => 'App deleted successfully',
    'app_not_found' => 'App not found',

    // Exports
    'export_created' => 'Export created successfully',
    'export_not_found' => 'Export not found',

    // Subscriptions
    'subscription_activated' => 'Subscription activated successfully',
    'subscription_config_incomplete' => 'Subscription configuration is incomplete. Please contact support.',
    'subscription_plan_not_found' => 'Plan or offering not found',
    'subscription_guest_must_upgrade_account' => 'Hostia nemôžu predplácať PRO, kým neprejdú na plný účet so skutočnou e-mailovou adresou.',
    'subscription_checkout_failed' => 'Failed to create checkout. Please try again later.',
    'subscription_checkout_error' => 'An unexpected error occurred. Please try again later.',
    'subscription_missing_checkout_id' => 'Missing checkoutPlanId parameter',
    'subscription_plan_info_not_found' => 'Plan information not found in checkout',
    'subscription_unknown_plan' => 'Unknown subscription plan',
    'subscription_user_not_found' => 'User not found. Please login to activate your subscription.',
    'subscription_process_failed' => 'Failed to process subscription. Please contact support.',
    'subscription_process_error' => 'An unexpected error occurred. Please contact support.',
    'subscription_unauthenticated' => 'Unauthenticated',
    'subscription_details_failed' => 'Failed to fetch subscription details',
    'subscription_credits_failed' => 'Failed to fetch credit balance',
    'subscription_credit_created' => 'Credit invoice created successfully',
    'subscription_credit_failed' => 'Failed to add credit',

    // Business invoice Bitcoin pay
    'business_invoice_pay_title' => 'Platba Bitcoin / Lightning',
    'business_invoice_pay_redirecting' => 'Presmerovanie na platbu s aktuálnym kurzom…',
    'business_invoice_pay_link_notice' => 'QR kód na faktúre je odkaz na túto stránku, nie Lightning faktúra. Nepoužívajte na skenovanie Lightning peňaženku - otvorte odkaz v prehliadači (fotoaparát alebo QR čítačka).',
    'business_invoice_pay_checkout_hint' => 'Na ďalšej stránke sa vytvorí platba s aktuálnym kurzom (Bitcoin alebo Lightning).',
    'business_invoice_pay_continue' => 'Zaplatiť Bitcoin / Lightning',
    'business_invoice_pay_already_paid' => 'Táto faktúra je už uhradená.',
    'business_invoice_pay_error_title' => 'Platbu sa nepodarilo pripraviť',
    'business_invoice_pay_checkout_failed' => 'Nepodarilo sa vytvoriť platobný odkaz. Skúste to znova o chvíľu.',

    // Locale
    'locale_set' => 'Language changed to :locale',

    // General Errors
    'error_occurred' => 'An error occurred',
    'not_authenticated' => 'Not authenticated',
    'not_found' => 'Not found',
    'server_error' => 'Server error',
    'try_again_later' => 'Please try again later',
    'contact_support' => 'Please contact support',

    'business_invoicing_available_in_pro' => 'Firemná fakturácia je dostupná v pláne Pro. Pre vytváranie faktúr si prosím aktivujte Pro.',
    'company_limit_reached' => 'Dosiahli ste maximálny počet firiem (:max) pre váš plán.',

    // SEPA Instant QR
    'sepa_certificate_files_required' => 'Nahrajte buď PKCS#12 (.p12/.pfx) súbor, alebo PEM certifikát spolu s privátnym kľúčom.',
];
