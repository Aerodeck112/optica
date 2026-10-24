<?php

// Mesajele pentru formularele publice. Regulile lipsă cad pe limba de rezervă a aplicației.
return [
    'accepted' => 'A(z) :attribute mezőt el kell fogadni.',
    'after' => 'A(z) :attribute mezőnek :date utáni dátumnak kell lennie.',
    'after_or_equal' => 'A(z) :attribute mező nem lehet korábbi, mint :date.',
    'array' => 'A(z) :attribute mezőnek listának kell lennie.',
    'before' => 'A(z) :attribute mezőnek :date előtti dátumnak kell lennie.',
    'between' => [
        'numeric' => 'A(z) :attribute értékének :min és :max között kell lennie.',
        'string' => 'A(z) :attribute hossza :min és :max karakter között kell legyen.',
    ],
    'boolean' => 'A(z) :attribute mező értéke igaz vagy hamis lehet.',
    'date' => 'A(z) :attribute nem érvényes dátum.',
    'email' => 'A(z) :attribute mezőnek érvényes e-mail-címnek kell lennie.',
    'exists' => 'A kiválasztott :attribute érvénytelen.',
    'in' => 'A kiválasztott :attribute érvénytelen.',
    'integer' => 'A(z) :attribute mezőnek egész számnak kell lennie.',
    'max' => [
        'numeric' => 'A(z) :attribute értéke legfeljebb :max lehet.',
        'string' => 'A(z) :attribute legfeljebb :max karakter lehet.',
        'array' => 'A(z) :attribute legfeljebb :max elemet tartalmazhat.',
    ],
    'min' => [
        'numeric' => 'A(z) :attribute értéke legalább :min kell legyen.',
        'string' => 'A(z) :attribute legalább :min karakter kell legyen.',
        'array' => 'A(z) :attribute legalább :min elemet kell tartalmazzon.',
    ],
    'numeric' => 'A(z) :attribute mezőnek számnak kell lennie.',
    'regex' => 'A(z) :attribute formátuma érvénytelen.',
    'required' => 'A(z) :attribute mező kitöltése kötelező.',
    'required_if' => 'A(z) :attribute mező kitöltése kötelező, ha :other értéke :value.',
    'size' => [
        'numeric' => 'A(z) :attribute értékének :size kell lennie.',
        'string' => 'A(z) :attribute hossza :size karakter kell legyen.',
    ],
    'string' => 'A(z) :attribute mezőnek szövegnek kell lennie.',

    'attributes' => [
        'name' => 'név',
        'contact_name' => 'teljes név',
        'email' => 'e-mail',
        'contact_email' => 'e-mail',
        'phone' => 'telefon',
        'contact_phone' => 'telefon',
        'participants' => 'résztvevők száma',
        'date' => 'dátum',
        'starts_at' => 'kezdési időpont',
        'package' => 'élmény',
        'message' => 'üzenet',
        'company_name' => 'cég',
        'preferred_date' => 'kívánt dátum',
        'code' => 'kód',
    ],
];
