<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        /*
         * The two providers behind the failover chain below. Both are API transports, not
         * SMTP: a host that blocks outbound 587 — common on the cheaper VPS plans — still
         * sends, and a provider outage surfaces as an HTTP error the chain can act on
         * rather than a connection that hangs to its timeout.
         *
         * `sendgrid` is not one of Laravel's built-in transports; MailServiceProvider
         * registers it from the Symfony bridge. `mailgun` is built in and reads
         * services.mailgun.
         */
        'sendgrid' => [
            'transport' => 'sendgrid',
            'key' => env('SENDGRID_API_KEY'),
        ],

        'mailgun' => [
            'transport' => 'smtp',
            'host' => env('MAILGUN_SMTP_HOST', 'smtp.mailgun.org'),
            'port' => env('MAILGUN_SMTP_PORT', 587),
            'encryption' => env('MAILGUN_SMTP_ENCRYPTION', 'tls'),
            'username' => env('MAILGUN_SMTP_USERNAME'),
            'password' => env('MAILGUN_SMTP_PASSWORD'),
            'timeout' => env('MAILGUN_SMTP_TIMEOUT', 10),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        /*
         * Production's mailer: SendGrid first, Mailgun when SendGrid fails. In order, and the
         * order is the decision — the second is only ever tried after the first has thrown.
         *
         * SendGrid leads because it is the leg that actually authenticates and delivers: Mailgun
         * led once, on the reasoning that it sends as this installation's own domain (`mg.erbium.ch`,
         * SPF/DKIM aligned), but its SMTP credentials on prod fail 535, so every send failed Mailgun
         * first and fell through to SendGrid anyway — a per-send error logged on the happy path and a
         * "primary" that never delivered. Until the Mailgun SMTP credentials are fixed (postmaster user
         * + SMTP password, not the API key), the working provider leads and Mailgun is the standby.
         * For SendGrid to be domain-aligned rather than sending unauthenticated, its own DKIM/CNAME
         * records for erbium.tech should be set up in the SendGrid dashboard.
         *
         * Deliberately no `log` at the end. A chain that "fails over" to the log delivers
         * nothing and reports success, which for a payslip or a password reset is the worst
         * shape a failure can take: the sender is told it went. Two real providers down at
         * once is a loud failure, and loud is right.
         */
        'failover' => [
            'transport' => 'failover',
            // Mailgun first: it authenticates as mg.erbium.ch, so mail leaves with aligned SPF/DKIM rather
            // than SendGrid's domain. Restored to Mailgun-first on 2026-10-26 once its SMTP auth was
            // confirmed working (it had been reversed to SendGrid-first on 2026-09-30 while auth was broken).
            // SendGrid remains the standby, tried only if Mailgun throws.
            'mailers' => [
                'mailgun',
                'sendgrid',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
