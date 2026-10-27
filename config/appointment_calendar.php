<?php

return [
    // Official national non-working holidays; special WORKING holidays stay open.
    // https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/99678
    // https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/102791
    // Eid dates require separate proclamations; do not predict them.
    'holidays' => [
        2026 => [
            '2026-02-17' => 'Chinese New Year',
            '2026-03-20' => "Eid'l Fitr", // Proclamation 1189 (2026).
            '2026-04-04' => 'Black Saturday',
            '2026-05-27' => "Eid'l Adha", // Proclamation 1264 (2026).
            '2026-11-02' => "All Souls' Day",
            '2026-12-24' => 'Christmas Eve',
        ],
        2027 => [
            '2027-02-06' => 'Chinese New Year',
            '2027-03-27' => 'Black Saturday',
            '2027-11-02' => "All Souls' Day",
            '2027-12-24' => 'Christmas Eve',
        ],
    ],

    // Explicit local / clinic closures, keyed by YYYY-MM-DD. No schema change.
    // Example: '2026-10-28' => 'Clinic Closed'.
    'closed_dates' => [],
];
