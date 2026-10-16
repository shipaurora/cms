<?php

return [
    'column_manager' => [
        'position' => 'Posisjon :position av :count.',
        'position_in_group' => 'Posisjon :position av :count i :group.',
        'reordered' => ':column flyttet til posisjon :position av :count.',
        'reordered_deferred' => ':column flyttet til posisjon :position av :count. Bruk kolonnene for å lagre endringene.',
        'reordered_in_group' => ':column flyttet til posisjon :position av :count i :group.',
        'reordered_in_group_deferred' => ':column flyttet til posisjon :position av :count i :group. Bruk kolonnene for å lagre endringene.',
        'actions' => [
            'move_up' => [
                'label' => 'Flytt :column opp',
            ],
            'move_down' => [
                'label' => 'Flytt :column ned',
            ],
            'reorder' => [
                'label' => 'Endre rekkefølge på kolonne',
            ],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Lagrer endring...',
            'saved' => 'Endringen er lagret.',
            'failure' => 'Lagringen kunne ikke bekreftes. Last inn siden på nytt før du prøver igjen.',
            'unavailable' => 'Dette elementet kan ikke lenger redigeres.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Ja',
                'false' => 'Nei',
            ],
        ],
    ],
    'actions' => [
        'reorder_record' => [
            'label' => 'Endre rekkefølge på element :key',
        ],
        'move_record_up' => [
            'label' => 'Flytt :label opp',
        ],
        'move_record_down' => [
            'label' => 'Flytt :label ned',
        ],
        'toggle_record_content' => [
            'label' => 'Vis/skjul element :key',
        ],
    ],
    'loading' => 'Laster...',
    'reordering' => [
        'position' => ':label, posisjon :position av :count.',
        'moved' => ':label flyttet til posisjon :position av :count.',
    ],
    'result_count' => '{0} Ingen resultater|{1} :count resultat|[2,*] :count resultater',
];
