<?php

declare(strict_types=1);

return [
    'invalid_domain' => 'The domain ":domain" is not a valid hostname.',
    'unknown_provider' => 'Certificate provider driver ":driver" is not defined.',
    'illegal_transition' => 'Cannot transition a certificate from ":from" to ":to".',
    'not_found' => 'No certificate is registered for ":domain".',
    'provisioning_in_progress' => 'The certificate for ":domain" is already being provisioned; try again once that finishes.',
    'no_material' => 'The filesystem driver is not a CA and no certificate material is stored for ":name". Place the PEM on the disk yourself, or enable drivers.filesystem.self_signed for local development.',
    'not_renewed' => 'The :driver provider did not produce a new certificate for ":domain".',
    'provider_reported' => 'The :driver provider reports the certificate for ":domain" as :status.',
    'no_alert_notifiable' => 'No alert notifiable could be resolved for [:domain]. Pass one to monitorExpiry(), set certificates.alerts.notifiable, or associate the certificate with a certifiable owner.',

    'commands' => [
        'issuing' => 'Issuing certificate for :domain...',
        'issued' => 'Certificate for :domain is now :status.',
        'failed' => 'Failed to issue certificate for :domain: :reason',
        'none_found' => 'No certificates found.',
        'none_to_renew' => 'No certificates are due for renewal.',
        'renewing' => 'Renewing certificate for :domain...',
        'renewed' => 'Renewed certificate for :domain.',
        'queued' => 'Queued renewal for :domain.',
        'renew_failed' => 'Failed to renew certificate for :domain: :reason',
        'pruned' => 'Pruned :count certificate(s).',
        'synced' => 'Synced :count certificate(s) from the :driver provider.',
        'none_expiring' => 'No certificates are expiring within the threshold.',
        'no_alert_notifiable' => 'No alert notifiable resolved for :domain; skipping the health alert.',
        'alerted' => 'Recorded a health alert for :domain via alerts.',
    ],

    'alerts' => [
        'ok' => 'The certificate for :domain is healthy (:days day(s) until expiry).',
        'warning' => 'The certificate for :domain expires in :days day(s).',
        'critical' => 'The certificate for :domain expires in :days day(s) — renew it to avoid an outage.',
        'expired' => 'The certificate for :domain has expired or is no longer valid.',
        'failed' => 'The last issuance or renewal of the certificate for :domain failed.',
        'missing' => 'The certificate could not be resolved for the expiry check.',
        'registry_ok' => 'No certificate is expired, failed or within the critical expiry window.',
        'registry_failed' => ':count certificate(s) are expired, failed or within the critical expiry window.',
    ],
];
