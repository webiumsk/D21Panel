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
    'seed_first_registration_required' => 'Cree su cuenta primero con una frase de recuperación. Puede añadir un email verificado al pasar a Free.',
    'password_login_use_recovery_phrase' => 'Esta cuenta usa la frase de recuperación para iniciar sesión. Use «Restaurar con frase» en el inicio de sesión.',
    'compliance_registration_unavailable' => 'El registro no está disponible desde su ubicación o no pudo completarse. Contacte con soporte si cree que se trata de un error.',
    'verification_email_failed' => 'No se pudo enviar el correo de verificación. Verifique que su dirección de correo electrónico sea válida e intente de nuevo.',
    'email_verified' => 'Your email has been verified successfully. Please login to continue.',
    'password_reset_sent' => 'Si existe una cuenta con ese correo, hemos enviado un enlace para restablecer la contraseña.',
    'password_reset_successful' => 'Your password has been reset.',

    // Account
    'profile_updated' => 'Profile updated successfully',
    'guest_upgrade_code_sent' => 'Le hemos enviado un código de verificación de 6 dígitos.',
    'guest_upgrade_confirmed' => 'Correo confirmado. Su cuenta ha sido actualizada.',
    'guest_upgrade_already_done' => 'Esta cuenta ya está actualizada.',
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
    'stripe_available_in_pro' => 'Stripe está disponible en Pro. Actualice para configurar pagos Stripe para su tienda.',

    // Lightning Addresses
    'lightning_address_saved' => 'Lightning address saved successfully',
    'lightning_address_deleted' => 'Lightning address deleted successfully',
    'lightning_address_not_found' => 'Lightning address not found',
    'lightning_address_username_mismatch' => 'Username in request body must match URL parameter',
    'lightning_address_limit_reached' => 'You have reached the maximum number of Lightning Addresses (:max) for your :plan plan. Please upgrade to add more addresses.',

    // Tickets (events limit)
    'tickets_event_limit_free' => 'En el plan gratuito puedes tener como máximo :max evento por tienda. Actualiza a PRO para eventos ilimitados.',
    'raffles_limit_free' => 'En el plan gratuito puedes tener como máximo :max rifa por tienda. Actualiza a PRO para rifas ilimitadas.',
    'raffles_quota_verification_failed' => 'No se pudo verificar el límite de rifas en BTCPay. Inténtalo de nuevo.',
    'raffles_cannot_delete' => 'Esta rifa solo se puede eliminar en borrador o cuando está completada.',
    'tickets_bundled_raffle_required' => 'Selecciona una rifa abierta cuando incluyas entradas de rifa por admisión.',
    'tickets_bundled_tickets_required' => 'Indica entradas de rifa por admisión (1–20) cuando selecciones una rifa.',
    'tickets_bundled_raffle_invalid' => 'La rifa seleccionada no existe en esta tienda.',
    'tickets_bundled_raffle_not_open' => 'La rifa seleccionada debe estar abierta para la venta (Open).',
    'tickets_bundled_raffle_verify_failed' => 'No se pudo verificar la rifa en BTCPay. Inténtalo de nuevo.',
    'tickets_quantity_required_when_capacity' => 'La cantidad es obligatoria cuando el evento tiene límite de capacidad.',
    'tickets_cannot_delete_event_with_sold_tickets' => 'No se puede eliminar un evento con entradas vendidas.',
    'tickets_cannot_deactivate_event_with_sold_tickets' => 'No se puede desactivar un evento con entradas vendidas.',
    'tickets_event_toggle_precheck_failed' => 'No se pudo verificar el estado del evento antes de activarlo/desactivarlo. Inténtalo de nuevo.',

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
    'subscription_guest_must_upgrade_account' => 'Las cuentas invitado no pueden suscribirse hasta que pasen a una cuenta completa con un correo real.',
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
    'business_invoice_pay_title' => 'Pago Bitcoin / Lightning',
    'business_invoice_pay_redirecting' => 'Redirigiendo al pago con el tipo de cambio actual…',
    'business_invoice_pay_link_notice' => 'El código QR de la factura es un enlace a esta página, no una factura Lightning. No lo escanee con una billetera Lightning; abra el enlace en el navegador (cámara o lector QR).',
    'business_invoice_pay_checkout_hint' => 'En la siguiente página se creará el pago con el tipo de cambio actual (Bitcoin o Lightning).',
    'business_invoice_pay_continue' => 'Pagar con Bitcoin / Lightning',
    'business_invoice_pay_already_paid' => 'Esta factura ya está pagada.',
    'business_invoice_pay_error_title' => 'No se pudo preparar el pago',
    'business_invoice_pay_checkout_failed' => 'No se pudo crear el enlace de pago. Inténtelo de nuevo en un momento.',

    // Locale
    'locale_set' => 'Language changed to :locale',

    // General Errors
    'error_occurred' => 'An error occurred',
    'not_authenticated' => 'Not authenticated',
    'not_found' => 'Not found',
    'server_error' => 'Server error',
    'try_again_later' => 'Please try again later',
    'contact_support' => 'Please contact support',

    'business_invoicing_available_in_pro' => 'La facturación empresarial está disponible en el plan Pro. Actualice para crear facturas.',
    'company_limit_reached' => 'Ha alcanzado el número máximo de empresas (:max) de su plan.',

    // SEPA Instant QR
    'sepa_certificate_files_required' => 'Sube un archivo PKCS#12 (.p12/.pfx) o el certificado PEM junto con su clave privada.',
];
