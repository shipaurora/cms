<?php

return [
    'placeholder' => 'Søk etter en side, post eller handling',
    'trigger' => [
        'label' => 'Søk',
        'shortcut_mac' => '⌘K',
        'shortcut_other' => 'Ctrl+K',
    ],
    'groups' => [
        'navigation' => 'Navigasjon',
        'pages' => 'Sider',
        'resources' => 'Ressurser',
        'actions' => 'Handlinger',
        'records' => 'Poster',
        'recents' => 'Nylige',
        'command_palette' => 'Kommandopalett',
    ],
    'commands' => [
        'list' => 'Vis :label',
        'create' => 'Opprett :label',
        'profile' => 'Åpne profilen din',
        'logout' => 'Logg ut',
        'switch_panel' => 'Bytt til :panel',
        'switch_tenant' => 'Bytt til :tenant',
        'publish_views' => 'Publiser visninger for kommandopaletten',
        'theme' => [
            'light' => 'Bytt til lyst tema',
            'dark' => 'Bytt til mørkt tema',
            'system' => 'Følg systemtemaet',
        ],
    ],
    'badges' => [
        // Empty hides the badge: search results are obviously records.
        'record' => '',
    ],
    'empty' => [
        'title' => 'Søk i panelet',
        'description' => 'Begynn å skrive for å finne en side, en post eller en handling.',
    ],
    'no_results' => [
        'title' => 'Ingen treff på :query',
        'description' => 'Sjekk stavemåten, eller prøv et kortere søk.',
    ],
    'preview' => [
        'open' => 'Åpne',
        'group' => 'Gruppe',
        'url' => 'URL',
    ],
    'searching' => 'Søker i poster',
    'loading' => 'Laster inn kommandoer',
    'error' => [
        'title' => 'Kommandopaletten kunne ikke lastes inn',
        'description' => 'Last inn siden på nytt for å prøve igjen.',
    ],
    'modes' => [
        'hint' => 'Skriv > for handlinger, # for poster, ? for hjelp',
        'help' => [
            'title' => 'Hurtigtaster',
            'navigate' => 'Flytt mellom resultater',
            'select' => 'Åpne det markerte resultatet',
            'new_tab' => 'Åpne i ny fane',
            'close' => 'Lukk kommandopaletten',
            'actions' => 'Vis bare handlinger',
            'records' => 'Søk bare i poster',
        ],
    ],
    'a11y' => [
        'dialog_label' => 'Kommandopalett',
        'results_label' => 'Resultater',
        'clear' => 'Tøm søk',
        'no_results' => 'Ingen resultater',
        'one_result' => '1 resultat',
        'many_results' => ':count resultater',
    ],
    'publish_views' => [
        'title' => 'Publiser visninger for kommandopaletten',
        'navigation_label' => 'Publiser visninger',
        'group' => 'Kommandopalett',
        'action' => 'Publiser visninger',
        'heading' => 'Tilpass kommandopaletten',
        'description' => 'Publisering kopierer Blade-visningene til kommandopaletten inn i applikasjonen din under resources/views/vendor/command-palette, der du fritt kan redigere dem. Fra da av har kopiene dine forrang foran pakkens visninger, så fremtidige pakkeoppdateringer vil ikke nå dem.',
        'notification' => [
            'title' => 'Visningene er publisert',
            'body' => 'Rediger dem i resources/views/vendor/command-palette.',
        ],
    ],
];
