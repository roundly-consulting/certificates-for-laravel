<?php

declare(strict_types=1);

return [
    'invalid_domain' => 'Doména „:domain“ nie je platný názov hostiteľa.',
    'unknown_provider' => 'Ovládač poskytovateľa certifikátov „:driver“ nie je definovaný.',
    'illegal_transition' => 'Stav certifikátu nie je možné zmeniť z „:from“ na „:to“.',
    'not_found' => 'Pre „:domain“ nie je zaregistrovaný žiadny certifikát.',
    'provisioning_in_progress' => 'Certifikát pre „:domain“ sa už pripravuje. Skúste to znova, keď sa príprava dokončí.',
    'name_taken' => 'Certifikát pre „:domain“ nie je možné vydať: jeho názov „:name“ už používa iná doména.',
    'no_material' => 'Ovládač filesystem nie je certifikačná autorita a pre „:name“ nie sú uložené žiadne údaje certifikátu. Uložte súbor PEM na disk sami alebo pre lokálny vývoj zapnite drivers.filesystem.self_signed.',
    'not_renewed' => 'Poskytovateľ :driver nevytvoril pre „:domain“ nový certifikát.',
    'provider_reported' => 'Poskytovateľ :driver hlási certifikát pre „:domain“ v stave „:status“.',
    'no_alert_notifiable' => 'Pre „:domain“ sa nepodarilo určiť príjemcu upozornení. Odovzdajte ho metóde monitorExpiry(), nastavte certificates.alerts.notifiable alebo certifikát priraďte vlastníkovi (certifiable).',

    'statuses' => [
        'pending' => 'Čakajúci',
        'requested' => 'Vyžiadaný',
        'issued' => 'Vydaný',
        'renewing' => 'Obnovuje sa',
        'renewed' => 'Obnovený',
        'failed' => 'Neúspešný',
        'expired' => 'Po platnosti',
        'revoked' => 'Zrušený',
    ],

    'commands' => [
        'issuing' => 'Vydáva sa certifikát pre :domain...',
        'issued' => 'Certifikát pre :domain má teraz stav „:status“.',
        'failed' => 'Certifikát pre :domain sa nepodarilo vydať: :reason',
        'none_found' => 'Nenašli sa žiadne certifikáty.',
        'none_to_renew' => 'Žiadny certifikát nie je potrebné obnoviť.',
        'renewing' => 'Obnovuje sa certifikát pre :domain...',
        'renewed' => 'Certifikát pre :domain bol obnovený.',
        'queued' => 'Obnovenie certifikátu pre :domain bolo zaradené do fronty.',
        'renew_failed' => 'Certifikát pre :domain sa nepodarilo obnoviť: :reason',
        'pruned' => '{0} Odstránilo sa :count certifikátov.|{1} Odstránil sa :count certifikát.|[2,4] Odstránili sa :count certifikáty.|[5,*] Odstránilo sa :count certifikátov.',
        'synced' => '{0} Od poskytovateľa :driver sa synchronizovalo :count certifikátov.|{1} Od poskytovateľa :driver sa synchronizoval :count certifikát.|[2,4] Od poskytovateľa :driver sa synchronizovali :count certifikáty.|[5,*] Od poskytovateľa :driver sa synchronizovalo :count certifikátov.',
        'none_expiring' => 'V sledovanom období nevyprší platnosť žiadneho certifikátu.',
        'no_alert_notifiable' => 'Pre :domain sa nenašiel príjemca upozornení, upozornenie o stave sa preto preskočí.',
        'alerted' => 'Upozornenie o stave certifikátu pre :domain bolo zaznamenané cez alerts.',
    ],

    'alerts' => [
        'ok' => '{0} Certifikát pre :domain je v poriadku (platnosť vyprší o :days dní).|{1} Certifikát pre :domain je v poriadku (platnosť vyprší o :days deň).|[2,4] Certifikát pre :domain je v poriadku (platnosť vyprší o :days dni).|[5,*] Certifikát pre :domain je v poriadku (platnosť vyprší o :days dní).',
        'warning' => '{0} Platnosť certifikátu pre :domain vyprší o :days dní.|{1} Platnosť certifikátu pre :domain vyprší o :days deň.|[2,4] Platnosť certifikátu pre :domain vyprší o :days dni.|[5,*] Platnosť certifikátu pre :domain vyprší o :days dní.',
        'critical' => '{0} Platnosť certifikátu pre :domain vyprší o :days dní – obnovte ho, aby nedošlo k výpadku.|{1} Platnosť certifikátu pre :domain vyprší o :days deň – obnovte ho, aby nedošlo k výpadku.|[2,4] Platnosť certifikátu pre :domain vyprší o :days dni – obnovte ho, aby nedošlo k výpadku.|[5,*] Platnosť certifikátu pre :domain vyprší o :days dní – obnovte ho, aby nedošlo k výpadku.',
        'expired' => 'Platnosť certifikátu pre :domain vypršala alebo certifikát už nie je platný.',
        'failed' => 'Posledné vydanie alebo obnovenie certifikátu pre :domain zlyhalo.',
        'missing' => 'Certifikát na kontrolu platnosti sa nepodarilo nájsť.',
        'registry_ok' => 'Žiadny certifikát nie je po platnosti, v stave zlyhania ani v kritickom období pred vypršaním platnosti.',
        'registry_failed' => '{0} :count certifikátov je po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.|{1} :count certifikát je po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.|[2,4] :count certifikáty sú po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.|[5,*] :count certifikátov je po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.',
    ],
];
