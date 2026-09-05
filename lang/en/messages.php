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
    'seed_first_registration_required' => 'Create your account with a recovery phrase first. You can add a verified email later when upgrading to Free.',
    'password_login_use_recovery_phrase' => 'This account uses a recovery phrase for sign-in. Use "Restore with recovery phrase" on the login page.',
    'compliance_registration_unavailable' => 'Registration is not available from your location or could not be completed. Please contact support if you believe this is an error.',
    'contact_inquiry_received' => 'Thank you. We received your message and will respond by email.',
    'verification_email_failed' => 'Unable to send verification email. Please verify your email address is valid and try again.',
    'email_verified' => 'Your email has been verified successfully. Please login to continue.',
    'password_reset_sent' => 'If an account with that email exists, we have sent a password reset link.',
    'password_reset_successful' => 'Your password has been reset.',

    // Account
    'profile_updated' => 'Profile updated successfully',
    'guest_upgrade_code_sent' => 'We emailed you a 6-digit verification code.',
    'guest_upgrade_confirmed' => 'Email confirmed. Your account is upgraded.',
    'guest_upgrade_already_done' => 'This account is already upgraded.',
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
    'stripe_available_in_pro' => 'Stripe is available in Pro. Please upgrade to configure Stripe payments for your store.',

    // Lightning Addresses
    'lightning_address_saved' => 'Lightning address saved successfully',
    'lightning_address_deleted' => 'Lightning address deleted successfully',
    'lightning_address_not_found' => 'Lightning address not found',
    'lightning_address_username_mismatch' => 'Username in request body must match URL parameter',
    'lightning_address_limit_reached' => 'You have reached the maximum number of Lightning Addresses (:max) for your :plan plan. Please upgrade to add more addresses.',

    // Tickets (events limit)
    'tickets_event_limit_free' => 'Ticket events are limited to :max per store on the Free plan. Upgrade to PRO for unlimited events.',
    'raffles_limit_free' => 'Raffles are limited to :max per store on the Free plan. Upgrade to PRO for unlimited raffles.',
    'raffles_quota_verification_failed' => 'Could not verify raffle limit with BTCPay. Please try again.',
    'raffles_cannot_delete' => 'This raffle can only be deleted when it is a draft or completed.',
    'tickets_bundled_raffle_required' => 'Select an open raffle when including raffle tickets per admission.',
    'tickets_bundled_tickets_required' => 'Set raffle tickets per admission (1–20) when a raffle is selected.',
    'tickets_bundled_raffle_invalid' => 'The selected raffle was not found for this store.',
    'tickets_bundled_raffle_not_open' => 'The selected raffle must be open for ticket sales.',
    'tickets_bundled_raffle_verify_failed' => 'Could not verify the raffle with BTCPay. Please try again.',
    'tickets_quantity_required_when_capacity' => 'Quantity is required when the event has a capacity limit.',
    'tickets_cannot_delete_event_with_sold_tickets' => 'Cannot delete an event that has sold tickets.',
    'tickets_cannot_deactivate_event_with_sold_tickets' => 'Cannot deactivate an event that has sold tickets.',
    'tickets_event_toggle_precheck_failed' => 'Unable to verify event status before toggle. Please try again.',

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
    'subscription_guest_must_upgrade_account' => 'Guest accounts cannot subscribe until you upgrade to a full account with a real email address.',
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
    'business_invoice_pay_title' => 'Bitcoin / Lightning payment',
    'business_invoice_pay_redirecting' => 'Redirecting to checkout with the current exchange rate…',
    'business_invoice_pay_link_notice' => 'The QR code on the invoice is a link to this page, not a Lightning invoice. Do not scan it with a Lightning wallet app - open the link in your browser (camera app or QR reader).',
    'business_invoice_pay_checkout_hint' => 'On the next page, payment will be created with the current exchange rate (Bitcoin or Lightning).',
    'business_invoice_pay_continue' => 'Pay with Bitcoin / Lightning',
    'business_invoice_pay_already_paid' => 'This invoice has already been paid.',
    'business_invoice_pay_error_title' => 'Could not prepare payment',
    'business_invoice_pay_checkout_failed' => 'Could not create a payment link. Please try again shortly.',

    // Locale
    'locale_set' => 'Language changed to :locale',

    // General Errors
    'error_occurred' => 'An error occurred',
    'not_authenticated' => 'Not authenticated',
    'not_found' => 'Not found',
    'server_error' => 'Server error',
    'try_again_later' => 'Please try again later',
    'contact_support' => 'Please contact support',

    // Documentation
    'documentation_article_created' => 'Documentation article created successfully',
    'documentation_article_updated' => 'Documentation article updated successfully',
    'documentation_article_deleted' => 'Documentation article deleted successfully',
    'documentation_category_created' => 'Documentation category created successfully',
    'documentation_category_updated' => 'Documentation category updated successfully',
    'documentation_category_deleted' => 'Documentation category deleted successfully',
    'documentation_category_has_articles' => 'Cannot delete category with articles',

    // FAQ
    'faq_item_created' => 'FAQ item created successfully',
    'faq_item_updated' => 'FAQ item updated successfully',
    'faq_item_deleted' => 'FAQ item deleted successfully',
    'faq_category_created' => 'FAQ category created successfully',
    'faq_category_updated' => 'FAQ category updated successfully',
    'faq_category_deleted' => 'FAQ category deleted successfully',
    'faq_category_has_items' => 'Cannot delete category with items',
    'faq_marked_helpful' => 'Thank you for your feedback!',

    // Business invoicing
    'business_invoicing_available_in_pro' => 'Business invoicing is available on the PRO plan. Please upgrade to create invoices.',
    'company_limit_reached' => 'You have reached the maximum number of companies (:max) for your plan.',

    // SEPA Instant QR
    'sepa_certificate_files_required' => 'Upload either a PKCS#12 (.p12/.pfx) file or the PEM certificate together with its private key.',
];
