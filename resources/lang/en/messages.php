<?php

declare(strict_types=1);

return [
    'status' => [
        'pending' => 'Pending',
        'requested' => 'Requested',
        'issued' => 'Issued',
        'renewing' => 'Renewing',
        'renewed' => 'Renewed',
        'failed' => 'Failed',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'invalid_domain' => 'The domain ":domain" is not a valid hostname.',
    'unknown_provider' => 'Certificate provider driver ":driver" is not defined.',
    'illegal_transition' => 'Cannot transition a certificate from ":from" to ":to".',

    'commands' => [
        'issuing' => 'Issuing certificate for :domain...',
        'issued' => 'Certificate for :domain is now :status.',
        'failed' => 'Failed to issue certificate for :domain: :reason',
        'none_found' => 'No certificates found.',
        'none_to_renew' => 'No certificates are due for renewal.',
        'renewing' => 'Renewing certificate for :domain...',
        'renewed' => 'Renewed certificate for :domain.',
        'queued' => 'Queued renewal for :domain.',
        'pruned' => 'Pruned :count certificate(s).',
        'synced' => 'Synced :count certificate(s) from the :driver provider.',
        'none_expiring' => 'No certificates are expiring within the threshold.',
        'no_notifiable' => 'No notification route or notifiable configured; skipping notifications.',
    ],

    'notifications' => [
        'expiring' => [
            'subject' => 'Certificate for :domain is expiring soon',
            'line' => 'The certificate for :domain expires in :days day(s). Renew it to avoid an outage.',
        ],
    ],
];
