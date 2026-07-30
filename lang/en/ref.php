<?php

declare(strict_types=1);

/*
| Reference-data labels — English (Doc 01 FR-10, T-10; N-03).
|
| The ref_* tables store i18n KEYS, not display text, so that an operator running
| an Amharic workspace sees "አማርኛ" rather than "Amharic". This file is where the
| platform's own reference vocabulary is written.
*/

return [
    'language' => ['en' => 'English', 'am' => 'Amharic'],
    'country' => [
        'ET' => 'Ethiopia', 'KE' => 'Kenya', 'DJ' => 'Djibouti', 'SO' => 'Somalia',
        'SS' => 'South Sudan', 'ER' => 'Eritrea', 'GB' => 'United Kingdom',
        'AE' => 'United Arab Emirates',
    ],
    'timezone' => [
        'Africa_Addis_Ababa' => 'Addis Ababa', 'Africa_Nairobi' => 'Nairobi',
        'Africa_Djibouti' => 'Djibouti', 'UTC' => 'UTC',
        'Europe_London' => 'London', 'Asia_Dubai' => 'Dubai',
    ],
    'currency' => [
        'ETB' => 'Ethiopian birr', 'USD' => 'US dollar',
        'EUR' => 'Euro', 'KES' => 'Kenyan shilling',
    ],
];
