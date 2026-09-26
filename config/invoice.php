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

    /*
     * The filesystem disk that company logos are written to.
     *
     * A separate disk from the documents one on purpose. The two have
     * different lifetimes and different rules: a document is the legal record,
     * written once and never touched again, on a versioned bucket; a logo is
     * master data the owner replaces whenever the branding changes, and
     * replacing it must not disturb the PDFs already issued with the old one.
     * Keeping them apart also means the retention policy for the documents
     * bucket does not have to make an exception.
     */
    'logos_disk' => env('INVOICE_LOGOS_DISK', 'public'),

];
