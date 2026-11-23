<?php

// How Spanish writes dates in the PDF and the emails, as the web app does (frontend/src/i18n): dd/mm/aaaa, from the
// day (:day, or :dd with two digits), the month (:month in letters, or :mm with two digits) and the year (:year).

return [

    'date' => ':dd/:mm/:year',
    'date_time' => ':date, :time',
    'months' => [
        '01' => 'ene',
        '02' => 'feb',
        '03' => 'mar',
        '04' => 'abr',
        '05' => 'may',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'ago',
        '09' => 'sept',
        '10' => 'oct',
        '11' => 'nov',
        '12' => 'dic',
    ],

];
