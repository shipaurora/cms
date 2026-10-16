<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':Attribute må aksepteres.',
    'accepted_if' => ':Attribute må aksepteres når :other er :value.',
    'active_url' => ':Attribute må være en gyldig URL.',
    'after' => ':Attribute må være en dato etter :date.',
    'after_or_equal' => ':Attribute må være en dato etter eller lik :date.',
    'alpha' => ':Attribute kan kun inneholde bokstaver.',
    'alpha_dash' => ':Attribute kan kun inneholde bokstaver, tall, bindestreker og understreker.',
    'alpha_num' => ':Attribute kan kun inneholde bokstaver og tall.',
    'any_of' => ':Attribute er ugyldig.',
    'array' => ':Attribute må være en matrise.',
    'array_keys' => ':Attribute kan kun inneholde følgende nøkler: :values.',
    'ascii' => ':Attribute kan kun inneholde alfanumeriske tegn og symboler på én byte.',
    'base64' => ':Attribute må være en gyldig Base64-streng.',
    'before' => ':Attribute må være en dato før :date.',
    'before_or_equal' => ':Attribute må være en dato før eller lik :date.',
    'between' => [
        'array' => ':Attribute må ha mellom :min og :max elementer.',
        'file' => ':Attribute må være mellom :min og :max kilobyte.',
        'numeric' => ':Attribute må være mellom :min og :max.',
        'string' => ':Attribute må være mellom :min og :max tegn.',
    ],
    'boolean' => ':Attribute må være sann eller usann.',
    'can' => ':Attribute inneholder en ikke-autorisert verdi.',
    'confirmed' => 'Bekreftelsen av :attribute stemmer ikke overens.',
    'contains' => ':Attribute mangler en påkrevd verdi.',
    'current_password' => 'Passordet er feil.',
    'date' => ':Attribute må være en gyldig dato.',
    'date_equals' => ':Attribute må være en dato lik :date.',
    'date_format' => ':Attribute må samsvare med formatet :format.',
    'decimal' => ':Attribute må ha :decimal desimaler.',
    'declined' => ':Attribute må avslås.',
    'declined_if' => ':Attribute må avslås når :other er :value.',
    'different' => ':Attribute og :other må være forskjellige.',
    'digits' => ':Attribute må være på :digits sifre.',
    'digits_between' => ':Attribute må være mellom :min og :max sifre.',
    'dimensions' => ':Attribute har ugyldige bildedimensjoner.',
    'distinct' => ':Attribute har en duplisert verdi.',
    'doesnt_contain' => ':Attribute kan ikke inneholde noen av følgende: :values.',
    'doesnt_end_with' => ':Attribute kan ikke slutte med en av følgende: :values.',
    'doesnt_start_with' => ':Attribute kan ikke starte med en av følgende: :values.',
    'email' => ':Attribute må være en gyldig e-postadresse.',
    'encoding' => ':Attribute må være kodet i :encoding.',
    'ends_with' => ':Attribute må slutte med en av følgende: :values.',
    'enum' => 'Valgt :attribute er ugyldig.',
    'exists' => 'Valgt :attribute er ugyldig.',
    'extensions' => ':Attribute må ha en av følgende filendelser: :values.',
    'file' => ':Attribute må være en fil.',
    'filled' => ':Attribute må ha en verdi.',
    'gt' => [
        'array' => ':Attribute må ha mer enn :value elementer.',
        'file' => ':Attribute må være større enn :value kilobyte.',
        'numeric' => ':Attribute må være større enn :value.',
        'string' => ':Attribute må være lengre enn :value tegn.',
    ],
    'gte' => [
        'array' => ':Attribute må ha :value elementer eller flere.',
        'file' => ':Attribute må være større enn eller lik :value kilobyte.',
        'numeric' => ':Attribute må være større enn eller lik :value.',
        'string' => ':Attribute må være minst :value tegn.',
    ],
    'hex_color' => ':Attribute må være en gyldig heksadesimal farge.',
    'image' => ':Attribute må være et bilde.',
    'in' => 'Valgt :attribute er ugyldig.',
    'in_array' => ':Attribute må finnes i :other.',
    'in_array_keys' => ':Attribute må inneholde minst én av følgende nøkler: :values.',
    'integer' => ':Attribute må være et heltall.',
    'ip' => ':Attribute må være en gyldig IP-adresse.',
    'ipv4' => ':Attribute må være en gyldig IPv4-adresse.',
    'ipv6' => ':Attribute må være en gyldig IPv6-adresse.',
    'json' => ':Attribute må være en gyldig JSON-streng.',
    'list' => ':Attribute må være en liste.',
    'lowercase' => ':Attribute må bestå av små bokstaver.',
    'lt' => [
        'array' => ':Attribute må ha færre enn :value elementer.',
        'file' => ':Attribute må være mindre enn :value kilobyte.',
        'numeric' => ':Attribute må være mindre enn :value.',
        'string' => ':Attribute må være kortere enn :value tegn.',
    ],
    'lte' => [
        'array' => ':Attribute kan ikke ha flere enn :value elementer.',
        'file' => ':Attribute må være mindre enn eller lik :value kilobyte.',
        'numeric' => ':Attribute må være mindre enn eller lik :value.',
        'string' => ':Attribute kan ikke være lengre enn :value tegn.',
    ],
    'mac_address' => ':Attribute må være en gyldig MAC-adresse.',
    'max' => [
        'array' => ':Attribute kan ikke ha flere enn :max elementer.',
        'file' => ':Attribute kan ikke være større enn :max kilobyte.',
        'numeric' => ':Attribute kan ikke være større enn :max.',
        'string' => ':Attribute kan ikke være lengre enn :max tegn.',
    ],
    'max_digits' => ':Attribute kan ikke ha flere enn :max sifre.',
    'mimes' => ':Attribute må være en fil av typen: :values.',
    'mimetypes' => ':Attribute må være en fil av typen: :values.',
    'min' => [
        'array' => ':Attribute må ha minst :min elementer.',
        'file' => ':Attribute må være minst :min kilobyte.',
        'numeric' => ':Attribute må være minst :min.',
        'string' => ':Attribute må være minst :min tegn.',
    ],
    'min_digits' => ':Attribute må ha minst :min sifre.',
    'missing' => ':Attribute må mangle.',
    'missing_if' => ':Attribute må mangle når :other er :value.',
    'missing_unless' => ':Attribute må mangle med mindre :other er :value.',
    'missing_with' => ':Attribute må mangle når :values er til stede.',
    'missing_with_all' => ':Attribute må mangle når :values er til stede.',
    'multiple_of' => ':Attribute må være et multiplum av :value.',
    'not_in' => 'Valgt :attribute er ugyldig.',
    'not_regex' => 'Formatet på :attribute er ugyldig.',
    'numeric' => ':Attribute må være et tall.',
    'password' => [
        'letters' => ':Attribute må inneholde minst én bokstav.',
        'mixed' => ':Attribute må inneholde minst én stor og én liten bokstav.',
        'numbers' => ':Attribute må inneholde minst ett tall.',
        'symbols' => ':Attribute må inneholde minst ett symbol.',
        'uncompromised' => 'Oppgitt :attribute har dukket opp i en datalekkasje. Vennligst velg et annet :attribute.',
    ],
    'present' => ':Attribute må være til stede.',
    'present_if' => ':Attribute må være til stede når :other er :value.',
    'present_unless' => ':Attribute må være til stede med mindre :other er :value.',
    'present_with' => ':Attribute må være til stede når :values er til stede.',
    'present_with_all' => ':Attribute må være til stede når :values er til stede.',
    'prohibited' => ':Attribute er ikke tillatt.',
    'prohibited_if' => ':Attribute er ikke tillatt når :other er :value.',
    'prohibited_if_accepted' => ':Attribute er ikke tillatt når :other er akseptert.',
    'prohibited_if_declined' => ':Attribute er ikke tillatt når :other er avslått.',
    'prohibited_unless' => ':Attribute er ikke tillatt med mindre :other er i :values.',
    'prohibits' => ':Attribute forhindrer at :other er til stede.',
    'regex' => 'Formatet på :attribute er ugyldig.',
    'required' => ':Attribute må fylles ut.',
    'required_array_keys' => ':Attribute må inneholde oppføringer for: :values.',
    'required_if' => ':Attribute må fylles ut når :other er :value.',
    'required_if_accepted' => ':Attribute må fylles ut når :other er akseptert.',
    'required_if_declined' => ':Attribute må fylles ut når :other er avslått.',
    'required_unless' => ':Attribute må fylles ut med mindre :other er i :values.',
    'required_with' => ':Attribute må fylles ut når :values er til stede.',
    'required_with_all' => ':Attribute må fylles ut når :values er til stede.',
    'required_without' => ':Attribute må fylles ut når :values ikke er til stede.',
    'required_without_all' => ':Attribute må fylles ut når ingen av :values er til stede.',
    'same' => ':Attribute må samsvare med :other.',
    'size' => [
        'array' => ':Attribute må inneholde :size elementer.',
        'file' => ':Attribute må være :size kilobyte.',
        'numeric' => ':Attribute må være :size.',
        'string' => ':Attribute må være :size tegn.',
    ],
    'starts_with' => ':Attribute må starte med en av følgende: :values.',
    'string' => ':Attribute må være en tekststreng.',
    'timezone' => ':Attribute må være en gyldig tidssone.',
    'unique' => ':Attribute er allerede i bruk.',
    'uploaded' => ':Attribute kunne ikke lastes opp.',
    'uppercase' => ':Attribute må bestå av store bokstaver.',
    'url' => ':Attribute må være en gyldig URL.',
    'ulid' => ':Attribute må være en gyldig ULID.',
    'uuid' => ':Attribute må være en gyldig UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This helps us make our message more expressive to us.
    |
    */

    'attributes' => [],

];
