<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **The spine of the construction suite, and it requires nothing.** That is
 * `docs/construction-management-plan.md` §18's one architectural decision, and the precedent is CRM's,
 * stated in the same terms: a contractor buying site management, document control and safety while keeping
 * its books in another system is a real and common customer. Requiring `accounting` here would undo the
 * self-contained decision of §1 in the registry, where it is least visible.
 *
 * The four modules that build on this one declare it: `construction_costing` (which does require
 * `accounting`, genuinely — job costing with no books is a spreadsheet), `construction_contracts`,
 * `construction_field` and `construction_qhse`.
 *
 * **The document register lives here, not in the field module** — §18 again. RFIs reference drawings,
 * submittals *are* documents, transmittals carry contract notices and ITPs reference
 * approved-for-construction drawings, so a customer who bought contracts and quality but not site
 * operations would otherwise have no register at all.
 */
return [
    'key' => 'construction',
    'label' => 'Construction',
    'description' => 'Jobs and sites, the work breakdown, the cost-code library and the ISO 19650 document register.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Construction\ConstructionPlugin::class,

    /*
     * Morph aliases take the legacy `App\Models\{Basename}` form even for classes that never lived there.
     * `ModuleCoverageTest` asserts it unconditionally with no exemption for new models, and
     * docs/new-module-checklist.md §4 records this being hit for real by a module that used short keys.
     */
    'models' => [
        // `App\Models\Job`, not `App\Models\ConstructionJob`: the rule is the *class basename*, and
        // `ModuleCoverageTest` asserts it unconditionally. §18.2's example reads `ConstructionCostEntry`
        // because that class is named that; this one is `Job`, per §1.1's rule that the model is `Job` and
        // never `Project`. The namespace is what disambiguates it, not the alias — which is an opaque
        // storage token nobody reads except the map.
        'App\\Models\\Job' => \App\Modules\Construction\Models\Job::class,
        'App\\Models\\WbsNode' => \App\Modules\Construction\Models\WbsNode::class,
        'App\\Models\\Location' => \App\Modules\Construction\Models\Location::class,
        'App\\Models\\CostCode' => \App\Modules\Construction\Models\CostCode::class,
        'App\\Models\\NamingConvention' => \App\Modules\Construction\Models\NamingConvention::class,
        'App\\Models\\Document' => \App\Modules\Construction\Models\Document::class,
        'App\\Models\\DocumentRevision' => \App\Modules\Construction\Models\DocumentRevision::class,
        'App\\Models\\Transmittal' => \App\Modules\Construction\Models\Transmittal::class,
        'App\\Models\\TransmittalItem' => \App\Modules\Construction\Models\TransmittalItem::class,
        'App\\Models\\TransmittalRecipient' => \App\Modules\Construction\Models\TransmittalRecipient::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Construction\\JobResource' => \App\Modules\Construction\Filament\Resources\Jobs\JobResource::class,
        'App\\Filament\\Resources\\Construction\\CostCodeResource' => \App\Modules\Construction\Filament\Resources\CostCodes\CostCodeResource::class,
    ],

    /*
     * Named once and forever. The `permissions` table has no unique index and the seeder matches on name
     * *and* group, so regrouping one later creates a second row with the same name while existing roles keep
     * pointing at the first, and nothing reports it — §18.2. Regrouping after release is a data migration.
     *
     * The WBS and the location tree ride on `ConstructionJob` rather than carrying groups of their own,
     * following the Leave precedent §18.2 cites: eight more permission names for two tables nobody navigates
     * to separately is eight more rows in every role form for no decision anybody makes separately. Whoever
     * may change a job may change its breakdown and its places.
     */
    /**
     * Where this module's dropdowns are edited — App\Support\OptionLists.
     */
    'option_lists' => [
        'construction.cost_code' => [
            'label' => 'Cost codes',
            'help' => 'The breakdown every cost on a job is filed under. A row rather than a word: each carries its cost type and its place in the code structure.',
            'managed_by' => 'App\\Filament\\Resources\\Construction\\CostCodeResource',
        ],
    ],

    'permission_groups' => [
        'ConstructionJob',
        'ConstructionCostCode',
        'ConstructionDocument',
    ],

    'permissions' => [
        ['name' => 'ConstructionJobView', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobCreate', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobUpdate', 'group' => 'ConstructionJob'],
        ['name' => 'ConstructionJobDelete', 'group' => 'ConstructionJob'],

        // The library is company-wide reference data shared by every job (§2.2), so changing it is a
        // separate decision from running a job — hence its own group rather than riding on the job's.
        ['name' => 'ConstructionCostCodeView', 'group' => 'ConstructionCostCode'],
        ['name' => 'ConstructionCostCodeCreate', 'group' => 'ConstructionCostCode'],
        ['name' => 'ConstructionCostCodeUpdate', 'group' => 'ConstructionCostCode'],
        ['name' => 'ConstructionCostCodeDelete', 'group' => 'ConstructionCostCode'],

        // Transmittals and revisions ride on the document's group, following the Leave precedent §18.2 cites:
        // whoever may issue a drawing may transmit it, and a separate group per child table is more rows in
        // every role form for no decision anybody makes separately.
        //
        // `Publish` is its own name because it is its own decision made by a different person — §18.2 lists it
        // among the non-CRUD names that matter. Publishing is what says "build this".
        ['name' => 'ConstructionDocumentView', 'group' => 'ConstructionDocument'],
        ['name' => 'ConstructionDocumentCreate', 'group' => 'ConstructionDocument'],
        ['name' => 'ConstructionDocumentUpdate', 'group' => 'ConstructionDocument'],
        ['name' => 'ConstructionDocumentDelete', 'group' => 'ConstructionDocument'],
        ['name' => 'ConstructionDocumentPublish', 'group' => 'ConstructionDocument'],
        // Restricted documents — a tender build-up, an incident investigation — need this on top of View.
        ['name' => 'ConstructionDocumentRestrictedView', 'group' => 'ConstructionDocument'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // A site engineer reads the job they are on; row scoping is what narrows it, not the permission.
        // The cost-code library is read-only to them and needed: a material issue or a daywork sheet has to
        // name a code, and a picker with nothing in it is a form nobody can complete.
        'Employee' => [
            'ConstructionCostCodeView',
            'ConstructionDocumentView',
            'ConstructionJobView',
        ],
        // The commercial side builds and maintains jobs, and owns the cost-code library — it is the thing
        // the next tender is priced from, so a surveyor edits it and a site engineer reads it.
        'Accountant' => [
            'ConstructionCostCodeCreate',
            // View as well as create and update: the roles are separate leaves rather than a chain, so
            // Accountant does not inherit Employee's grants — and without this a surveyor could upload a
            // drawing and then not be able to open it.
            'ConstructionDocumentCreate',
            'ConstructionDocumentUpdate',
            'ConstructionDocumentView',
            'ConstructionCostCodeUpdate',
            'ConstructionCostCodeView',
            'ConstructionJobCreate',
            'ConstructionJobUpdate',
            'ConstructionJobView',
        ],
        // Deleting a job with cost against it is refused by the model regardless; this is who may
        // remove one raised in error.
        // Publishing is what says "build this", so it sits with the approval powers rather than with the
        // people who upload drawings — the same segregation the journal-entry powers already keep.
        'Manager' => [
            'ConstructionDocumentPublish',
            'ConstructionDocumentRestrictedView',
        ],
        'CEO' => [
            'ConstructionCostCodeDelete',
            'ConstructionDocumentDelete',
            'ConstructionJobDelete',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     *
     * **Construction is a seventh domain**, decided in Phase 0 and not a fold into Finance: Finance is
     * already 24 classes across three groups, and this suite's four groups would push it past fifty across
     * seven — the flat-many-groups problem NavigationDomains exists to solve. A company that never buys
     * construction still sees six icons, because `rail()` omits a domain whose groups are all empty.
     */
    'navigation' => [
        'Construction' => 'construction',
    ],
];
