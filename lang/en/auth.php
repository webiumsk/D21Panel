<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'lightning_key_already_registered' => 'This Lightning wallet is already registered.',
    'nostr_key_already_registered' => 'This Nostr key is already linked to an account.',
    'invalid_password' => 'Invalid password.',
    'email_not_verified' => 'Please verify your email address before signing in. Check your inbox for the confirmation link.',
    'email_code_missing' => 'No verification code is pending. Request a new one.',
    'email_code_expired' => 'This verification code has expired. Request a new one.',
    'email_code_locked' => 'Too many wrong attempts. Request a new verification code.',
    'email_code_mismatch' => 'Wrong code. :attempts attempt(s) left.',
    'email_code_cooldown' => 'Please wait :seconds seconds before requesting another code.',
    'email_code_send_limit' => 'Too many codes were sent to this address. Try again later.',
    'email_code_send_failed' => 'We could not send the verification email. Try again in a moment.',
    'guest_feature_requires_account' => 'This action requires a full account. Upgrade from Account settings.',
    'guest_one_store_only' => 'Guest sessions are limited to one store. Create a full account to add more stores.',
    'guest_pos_limit_one' => 'Guest mode allows only one Point of Sale app per store.',
];
