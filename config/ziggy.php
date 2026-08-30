<?php

return [

    /*
     * Route groups Ziggy can expose to the browser.
     *
     * Anonymous visitors receive the 'public' group only. Without it, a
     * plain `curl` of the marketing homepage returned the whole named-route
     * map — every staff URI, method, and parameter name, including
     * patients.destroy and providers.destroy — to anyone who asked. Those
     * routes are all correctly behind `auth`, so this is reconnaissance
     * hardening rather than an authorization fix, but it is exactly the
     * map an attacker wants before attempting CSRF or XSS against a staff
     * session, and it enumerates the product's whole internal feature set
     * to any scraper.
     *
     * Authenticated staff still get the full map — see
     * resources/views/app.blade.php.
     *
     * This list is maintained by hand: a public page that starts calling a
     * new route needs its name added here, and a missing entry surfaces as
     * a route() error on the public site. PublicPagesTest is the net.
     */
    'groups' => [
        'public' => [
            'home',
            'services',
            'dentists',
            'about',
            'contact',
            'book',
            'bookings.store',
            'inquiries.store',
            'appointments.lookup.create',
            'appointments.lookup.send',
            'appointments.lookup.show',

            // The auth screens are guest-facing too, so their own routes
            // have to be in this group or the pages render blank.
            // Deliberately only the ones a guest page calls: verify-email
            // and confirm-password are behind `auth`, so those pages get
            // the full map anyway.
            'login',
            'register',
            'password.request',
            'password.email',
            'password.store',
        ],
    ],

];
