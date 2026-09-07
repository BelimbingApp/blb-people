<?php

return [
    /*
     * How many people must hold a critical skill at its required level before
     * a department is covered for it (0007-c). Two is the default because one
     * holder is a single point of failure; a tenant with small teams may set
     * its own.
     */
    'backup_minimum' => 2,

    'workbook' => [
        'blocking_defects' => [
            'blank_key',
            'cell_error',
            'formula',
            'merged_cells',
        ],
    ],
];
