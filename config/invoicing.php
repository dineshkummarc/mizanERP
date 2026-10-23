<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contact document expiry warnings
    |--------------------------------------------------------------------------
    |
    | When to warn that a contract or document on a contact is about to lapse, in
    | days — docs/bexio-gap-plan.md §3.7. The same three as Lifecycle's employee
    | documents, and the same rule: crossing each threshold warns ONCE. 60 to plan a
    | renewal, 30 to chase it, 7 because it is now urgent.
    |
    */

    'contact_document_expiry_thresholds' => [60, 30, 7],

];
