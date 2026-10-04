<?php

declare(strict_types=1);

return [
    'invalid_domain' => 'Doména „:domain“ nie je platný názov hostiteľa.',
    'unknown_provider' => 'Ovládač poskytovateľa certifikátov „:driver“ nie je definovaný.',
    'illegal_transition' => 'Stav certifikátu nie je možné zmeniť z „:from“ na „:to“.',
    'not_found' => 'Pre „:domain“ nie je zaregistrovaný žiadny certifikát.',
    'provisioning_in_progress' => 'Certifikát pre „:domain“ sa už pripravuje. Skúste to znova, keď sa príprava dokončí.',
    'no_material' => 'Ovládač filesystem nie je certifikačná autorita a pre „:name“ nie sú uložené žiadne údaje certifikátu. Uložte súbor PEM na disk sami alebo pre lokálny vývoj zapnite drivers.filesystem.self_signed.',
    'not_renewed' => 'Poskytovateľ :driver nevytvoril pre „:domain“ nový certifikát.',
    'provider_reported' => 'Poskytovateľ :driver hlási certifikát pre „:domain“ v stave :status.',

    'commands' => [
        'issuing' => 'Vydáva sa certifikát pre :domain...',
        'issued' => 'Certifikát pre :domain má teraz stav :status.',
        'failed' => 'Certifikát pre :domain sa nepodarilo vydať: :reason',
        'none_found' => 'Nenašli sa žiadne certifikáty.',
        'none_to_renew' => 'Žiadny certifikát nie je potrebné obnoviť.',
        'renewing' => 'Obnovuje sa certifikát pre :domain...',
        'renewed' => 'Certifikát pre :domain bol obnovený.',
        'queued' => 'Obnovenie certifikátu pre :domain bolo zaradené do fronty.',
        'renew_failed' => 'Certifikát pre :domain sa nepodarilo obnoviť: :reason',
        'pruned' => 'Počet odstránených certifikátov: :count.',
        'synced' => 'Počet certifikátov synchronizovaných od poskytovateľa :driver: :count.',
        'none_expiring' => 'V sledovanom období nevyprší platnosť žiadneho certifikátu.',
        'no_alert_notifiable' => 'Pre :domain sa nenašiel príjemca upozornení, upozornenie o stave sa preto preskočí.',
        'alerted' => 'Upozornenie o stave certifikátu pre :domain bolo zaznamenané cez alerts.',
    ],

    'alerts' => [
        'ok' => 'Certifikát pre :domain je v poriadku (dni do vypršania platnosti: :days).',
        'warning' => 'Platnosť certifikátu pre :domain sa blíži ku koncu (zostávajúce dni: :days).',
        'critical' => 'Platnosť certifikátu pre :domain čoskoro vyprší (zostávajúce dni: :days) – obnovte ho, aby nedošlo k výpadku.',
        'expired' => 'Platnosť certifikátu pre :domain vypršala alebo certifikát už nie je platný.',
        'failed' => 'Posledné vydanie alebo obnovenie certifikátu pre :domain zlyhalo.',
        'missing' => 'Certifikát na kontrolu platnosti sa nepodarilo nájsť.',
        'registry_ok' => 'Žiadny certifikát nie je po platnosti, v stave zlyhania ani v kritickom období pred vypršaním platnosti.',
        'registry_failed' => 'Počet certifikátov po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti: :count.',
    ],
];
