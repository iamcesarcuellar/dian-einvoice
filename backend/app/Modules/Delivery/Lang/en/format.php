<?php

// How the language writes dates in the PDF and the emails, as the web app does (frontend/src/i18n): the day
// (:day, or :dd with two digits), the month (:month in letters, or :mm with two digits) and the year (:year).
return [

    'date' => ':day :month :year',
    'date_time' => ':date, :time',
    'months' => [
        '01' => 'Jan',
        '02' => 'Feb',
        '03' => 'Mar',
        '04' => 'Apr',
        '05' => 'May',
        '06' => 'Jun',
        '07' => 'Jul',
        '08' => 'Aug',
        '09' => 'Sep',
        '10' => 'Oct',
        '11' => 'Nov',
        '12' => 'Dec',
    ],

];
