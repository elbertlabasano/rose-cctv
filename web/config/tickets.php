<?php

return [
    'endpoint' => env('TICKET_ENDPOINT', 'https://itsupporthub.rosebakeshop.net/api/ticket'),
    'token' => env('TICKET_TOKEN'),
    'department_id' => env('TICKET_DEPARTMENT_ID'),
    'category_id' => env('TICKET_CATEGORY_ID'),
    'assignee_id' => env('TICKET_ASSIGNEE_ID'),
    'board_id' => env('TICKET_BOARD_ID'),
    'boards' => [
        ['id' => 10, 'name' => 'CCTV Reports/Concerns'],
        ['id' => 9, 'name' => 'CCTV Footage/Defiance Daily Reporting'],
    ],
    'priority' => 'medium',
    'ticket_type' => 'request',
];
