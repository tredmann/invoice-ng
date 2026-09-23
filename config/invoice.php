<?php

return [

    /*
     * The filesystem disk that frozen invoice documents are written to.
     *
     * Documents are the legal record, so production writes them to object
     * storage rather than container disk. Development uses the local disk.
     * This is a configuration seam, not a code branch: nothing in the
     * application names a disk directly.
     */
    'documents_disk' => env('INVOICE_DOCUMENTS_DISK', 'local'),

];
