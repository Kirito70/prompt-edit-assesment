<?php

// Fake purchase packages: the only amounts a user can add. Amounts are USD
// strings so they stay exact; no payment is taken.
return [
    'packages' => [
        'small' => '5.00',
        'standard' => '10.00',
        'large' => '25.00',
    ],
];
