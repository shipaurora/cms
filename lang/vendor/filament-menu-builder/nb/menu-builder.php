<?php

return [
    'form' => [
        'title' => 'Tittel',
        'url' => 'URL',
        'icon' => 'Ikon',
        'classes' => 'CSS-klasser',
        'rel' => 'Rel-attributt',
        'linkable_type' => 'Type',
        'linkable_id' => 'ID',
    ],
    'resource' => [
        'name' => [
            'label' => 'Navn',
        ],
        'locations' => [
            'label' => 'Plasseringer',
            'empty' => 'Ikke tildelt',
        ],
        'items' => [
            'label' => 'Menypunkter',
        ],
        'is_visible' => [
            'label' => 'Synlighet',
            'visible' => 'Synlig',
            'hidden' => 'Skjult',
        ],
    ],
    'actions' => [
        'add' => [
            'label' => 'Legg til i meny',
        ],
        'edit' => 'Rediger',
        'delete' => 'Slett',
        'indent' => 'Rykk inn',
        'unindent' => 'Rykk ut',
        'locations' => [
            'label' => 'Plasseringer',
            'heading' => 'Administrer plasseringer',
            'description' => 'Velg hvilken meny som skal vises på hver plassering.',
            'submit' => 'Oppdater',
            'form' => [
                'location' => [
                    'label' => 'Plassering',
                ],
                'menu' => [
                    'label' => 'Tildelt meny',
                ],
            ],
            'empty' => [
                'heading' => 'Ingen plasseringer er registrert',
            ],
        ],
    ],
    'items' => [
        'expand' => 'Vis',
        'collapse' => 'Skjul',
        'empty' => [
            'heading' => 'Det finnes ingen menypunkter i denne menyen.',
        ],
    ],
    'custom_link' => 'Egendefinert lenke',
    'custom_text' => 'Egendefinert tekst',
    'open_in' => [
        'label' => 'Åpne i',
        'options' => [
            'self' => 'Samme fane',
            'blank' => 'Ny fane',
            'parent' => 'Overordnet fane',
            'top' => 'Øverste fane',
        ],
    ],
    'notifications' => [
        'created' => [
            'title' => 'Lenken er opprettet',
        ],
        'locations' => [
            'title' => 'Menyplasseringene er oppdatert',
        ],
        'move_rejected' => [
            'title' => 'Menypunktet kunne ikke flyttes',
            'body' => 'Menypunkter kan ikke nestes mer enn :depth nivå dypt.|Menypunkter kan ikke nestes mer enn :depth nivåer dypt.',
        ],
    ],
    'panel' => [
        'empty' => [
            'heading' => 'Ingen elementer funnet',
            'description' => 'Det finnes ingen menypunkter i denne menyen.',
        ],
        'pagination' => [
            'previous' => 'Forrige',
            'next' => 'Neste',
        ],
    ],
];
