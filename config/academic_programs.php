<?php

/*
|--------------------------------------------------------------------------
| Academic Programs (Course / Department dropdown)
|--------------------------------------------------------------------------
|
| Departments and the courses under each one. The web portal and the mobile
| app both read this list through GET /api/academic-programs, and patient
| forms only accept values from it. Edit this file to match the current
| TMC Expansion program offerings.
|
*/

return [
    'College of Computing Studies' => [
        'Bachelor of Science in Information Technology',
    ],
    'College of Criminal Justice Education' => [
        'Bachelor of Science in Criminal Justice Education',
    ],
    'College of Teacher Education' => [
        'Bachelor of Secondary Education - Major in English',
        'Bachelor in Elementary Education',
    ],
    'College of Arts and Sciences' => [
        'Bachelor of Arts in Communication',
        'Bachelor of Arts in Political Science',
    ],
    'College of Business Administration' => [
        'Bachelor of Science in Office Administration',
    ],
];
