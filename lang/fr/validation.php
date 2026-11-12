<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'required_without' => 'Le champ :attribute est obligatoire.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'same' => 'Les champs :attribute et :other doivent correspondre.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'unique' => 'Cette valeur est déjà utilisée pour :attribute.',
    'string' => 'Le champ :attribute doit être du texte.',
    'current_password' => 'Le mot de passe actuel est incorrect.',
    'max' => ['string' => 'Le champ :attribute ne doit pas dépasser :max caractères.'],
    'min' => ['string' => 'Le champ :attribute doit contenir au moins :min caractères.'],
    'password' => [
        'mixed' => 'Le champ :attribute doit contenir une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir un symbole.',
    ],
];
