<?php

namespace App\Providers;

use App\Health\BackupConfigurationCheck;
use App\Health\DiskSpaceCheck;
use App\Health\FailedJobsCheck;
use App\Health\HorizonCheck;
use App\Health\LedgerControlsCheck;
use App\Health\MailConfigurationCheck;
use App\Health\PublicStorageLinkCheck;
use App\Health\RedisPersistenceCheck;
use App\Health\TenantDatabaseCheck;
use App\Listeners\SyncSpatieTenant;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Accounting\Support\LocalPatternModel;
use App\Modules\Accounting\Support\RegisterCommandResolver;
use App\Modules\Expenses\Support\ExpenseClaimCommandResolver;
use App\Modules\Invoicing\Support\InvoiceCommandResolver;
use App\Modules\Quotations\Support\QuotationCommandResolver;
use App\Multitenancy\RefuseTenantlessTenantAwareJobs;
use App\Support\Ai\Claude;
use App\Support\Ai\StructuredModel;
use App\Support\EmployeeAccess;
use App\Support\ModuleAuthorization;
use App\Support\ModuleMap;
use App\Support\Modules;
use App\Support\NavigationBadge;
use App\Support\TenantSettings;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\TwilioWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Events\TenantSet;
use Filament\Resources\Resource;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantSettings::class);
        $this->app->singleton(EmployeeAccess::class);
        $this->app->singleton(Modules::class);

        /*
         * The model behind the command bot — docs/ai-command-bot-plan.md §4.
         *
         * Bound to the contract rather than the class so the whole feature below it
         * — resolution, the sign rules, the confirmation, the booking — is testable
         * without a key or a network call. `FakeStructuredModel` is what the suite
         * swaps in; the one genuinely non-deterministic step is the only thing it
         * stands in for.
         *
         * Not `singleton`: `Claude` holds a client keyed to config that a test may
         * change between cases, and a memoised instance would outlive the change.
         */
        $this->app->bind(StructuredModel::class, fn (): StructuredModel => match (config('ai.driver')) {
            'claude' => new Claude,
            // `local` and anything unrecognised. Falling back to the driver that needs no key and cannot
            // fail on the network is the safe direction: a typo'd driver name gives a working command bar
            // rather than one that throws on the first keystroke.
            default => new LocalPatternModel,
        });

        /*
         * The command bot's resolvers — docs/ai-command-bot-plan.md §7.
         *
         * Registered here rather than discovered, so the set is readable in one place and its order is
         * the routing order. Each resolver gates itself on its own module and permission
         * (`CommandResolver::isAvailable()`), so listing one costs a licence-check, not a leak: a module
         * this tenant has not bought contributes nothing to the prompt.
         *
         * The default resolver must be last-resort, not first-listed — `route()` reads `isDefault()`
         * rather than position, so adding a resolver above or below the cash one changes nothing.
         */
        $this->app->bind(CommandInterpreter::class, fn ($app) => new CommandInterpreter(
            $app->make(StructuredModel::class),
            [
                // Quote before invoice: both claim a verb, and "quote" must never read as an invoice —
                // docs/bexio-gap-plan.md Phase 4, item 15.
                $app->make(QuotationCommandResolver::class),
                $app->make(InvoiceCommandResolver::class),
                $app->make(ExpenseClaimCommandResolver::class),
                $app->make(RegisterCommandResolver::class),
            ],
        ));

        // The sidebar's badge counts, memoised for the length of one request.
        //
        // `scoped` rather than `singleton`, and the difference is the whole
        // point: these figures belong to one company and one user, and an
        // instance that answered a second request would answer it with the first
        // one's numbers. Same reasoning as NavigationSnapshot, which says more
        // about why the container is where this belongs.
        $this->app->scoped(NavigationBadge::class);

        // Whichever WhatsApp sender the environment is configured for. The log
        // sender is the default and the fallback: an install with the driver set
        // to "cloud" but no credentials sends nothing rather than throwing in the
        // middle of a payroll run, and says so in the log.
        $this->app->singleton(WhatsAppSender::class, function (): WhatsAppSender {
            $driver = config('whatsapp.driver');
            $cloud = config('whatsapp.cloud');
            $twilio = config('whatsapp.twilio');

            if ($driver === 'cloud' && filled($cloud['phone_number_id']) && filled($cloud['token'])) {
                return new CloudApiWhatsAppSender(
                    phoneNumberId: (string) $cloud['phone_number_id'],
                    token: (string) $cloud['token'],
                    apiVersion: (string) ($cloud['api_version'] ?? 'v21.0'),
                    template: $cloud['template'] ?: null,
                    templateLanguage: (string) ($cloud['template_language'] ?? 'en'),
                );
            }

            if ($driver === 'twilio' && filled($twilio['account_sid']) && filled($twilio['auth_token']) && filled($twilio['from'])) {
                return new TwilioWhatsAppSender(
                    accountSid: (string) $twilio['account_sid'],
                    authToken: (string) $twilio['auth_token'],
                    from: (string) $twilio['from'],
                    apiBase: (string) ($twilio['api_base'] ?? 'https://api.twilio.com/2010-04-01'),
                    contentSid: $twilio['content_sid'] ?: null,
                    templateMediaBase: $twilio['template_media_base'] ?: null,
                );
            }

            // Either no driver chosen or one chosen without its credentials. Both
            // land here rather than throwing mid-payroll, and the log says what
            // would have gone where.
            return new LogWhatsAppSender;
        });

        // Registered here, in register() rather than boot(), and this is load
        // bearing. Gate::before callbacks run in registration order, and
        // spatie/laravel-permission registers its own from its provider's boot()
        // (PermissionRegistrar::registerPermissions) which returns true the moment
        // the user holds the permission. A module check registered in boot() lands
        // behind it and never runs for exactly the users who do have the
        // permission — which is everyone the check is meant to stop. register()
        // runs before every provider's boot(), so this callback is first.
        //
        // It returns a hard false: a module the company has not licensed is not a
        // permission question, so neither a super admin nor an Administrator
        // bypasses it (see the two bypasses in boot()).
        Gate::before(function ($user, $ability, $arguments = []) {
            if (ModuleAuthorization::blockingModule($user, (string) $ability, (array) $arguments) !== null) {
                return false;
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Row scoping is turned off globally below (scopeToTenant(false)) and isolation rests entirely on the
        // per-company connection. An empty TENANT_DATABASE_CONNECTION reads as "" and would put every company
        // on one database with no scoping and no error — security audit finding 8. Refuse to boot production
        // in that state rather than serve every tenant the same rows.
        throw_if(
            app()->environment('production') && blank(config('multitenancy.tenant_database_connection_name')),
            \RuntimeException::class,
            'multitenancy.tenant_database_connection_name is empty: tenants would share one database. Set TENANT_DATABASE_CONNECTION.',
        );

        // Five tries a minute per email and address, then 429 — docs/desktop-mobile-plan.md A1. Keyed on
        // both so a guess at one account from many addresses and at many accounts from one are both slowed.
        // Every API request: 90 a minute per account, or per address before sign-in — security audit finding 11.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(90)
            ->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip())));

        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * Lazy loading is an error everywhere except production.
         *
         * A relation read without being loaded is a query per row, and the rows are the part nobody
         * sees while developing: a table of four in a test looks fine and the same page over four
         * hundred employees is four hundred queries. This turns that into a failure at the moment it
         * is written instead of a support ticket about a slow screen.
         *
         * **Not in production**, deliberately, and this is the whole reason for the condition rather
         * than a bare `true`: the guard throws. A relation nobody exercised in development would take
         * a customer's page down rather than serve it a little slower, which is a worse trade than the
         * one it is here to make. Phase 6 of docs/page-load-performance-plan.md.
         *
         * **Named environments rather than `! isProduction()`**, because that phrasing arms the guard
         * by default and disarms it only on one exact spelling of one word. A live server running
         * `APP_ENV=prod` — which `isProduction()` does not recognise, since it tests for `production` —
         * had a throwing N+1 guard in front of paying customers, from a line whose own docblock says
         * it must never be on there. The safe state is now the default: it is on where somebody chose
         * it, and anything unrecognised gets production's behaviour.
         */
        Model::preventLazyLoading($this->app->environment('local', 'testing'));

        // Landlord (central) migrations always run on the default connection.
        // Tenant migrations live in their own path and are applied per-company
        // during provisioning; in the testing environment we also load them onto
        // the default connection so the suite runs against a single database.
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/tenant'));
        }

        // Routes outside the panel (file downloads, report pages) guard with the
        // plain `auth` middleware, which redirects guests to a route named
        // `login`. Only Filament defines a login screen here, so without this a
        // signed-out visitor gets a 500 instead of the sign-in page.
        Authenticate::redirectUsing(fn () => route('filament.admin.auth.login'));

        // Class names are stored as strings in customer data — comments.commentable_type,
        // payments.payable_type, activity_log.subject_type, custom_fields.model_type,
        // model_has_roles.model_type — so moving a model class would orphan every
        // one of those rows. The aliases are deliberately the legacy
        // `App\Models\…` strings that are already in the data: the alias stays
        // fixed while the target class moves into its module, so old and new rows
        // agree and no per-tenant data migration is needed.
        //
        // enforceMorphMap (rather than plain morphMap) makes a model missing from
        // ModuleMap throw on first use instead of silently writing an unmapped
        // FQCN back into the data. That noise is the point.
        Relation::enforceMorphMap(ModuleMap::morphMap());

        // Laravel derives a factory's name from the model's namespace — for
        // App\Modules\Core\Models\Company it looks for
        // Database\Factories\Modules\Core\Models\CompanyFactory. Factories stay in
        // one flat directory (the landlord/tenant split is orthogonal to modules,
        // and there are three of them), so resolve on the class basename instead.
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory'
        );

        // Every policy is registered by the module that owns the model — see any
        // module's ServiceProvider. Laravel's App\Models\X -> App\Policies\XPolicy
        // guess cannot resolve a class in a module directory, and Filament reads
        // "no policy" as "allowed", so ModuleCoverageTest asserts the coverage.

        // The module deny that runs ahead of both of these is registered in
        // register() — see the comment there for why the ordering matters.
        Gate::before(function ($user, $ability) {
            // Global super admin bypasses all authorization.
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            if ($user->hasRole('Administrator') && $ability !== 'create') {
                return true;
            }
        });
        /*
         * The table context menu — `docs/table-context-menu-plan.md` §2 and §3.
         *
         * **Both halves live here rather than in a panel provider, and that is a correction to §2's
         * wording.** `Table::configureUsing()` is a global Filament configuration: it reaches every table
         * in every panel, so registering it inside `AdminPanelProvider` would place a global effect in a
         * file that names one panel. `FilamentAsset::register()` is global in the same way — the registry
         * is a singleton and Filament renders its scripts in any panel's layout — so the asset was moved
         * here alongside it, keeping one feature in one place.
         *
         * Both panels share `resources/css/filament/admin/theme.css` (see `PlatformPanelProvider`), so a
         * global registration is a *styled* menu everywhere rather than an unstyled box on the platform
         * panel. That was worth checking before making it global.
         */
        FilamentAsset::register(
            [
                /*
                 * **A registered asset, not an inline render-hook partial.**
                 *
                 * `docs/page-load-performance-plan.md` records the audit: Livewire re-executes body
                 * scripts on every `wire:navigate` unless they carry `data-navigate-once`, and the two
                 * inline scripts this application has are idempotent by construction — they only ever
                 * write an absent localStorage key or *open* a branch. A context menu is bigger than
                 * either and would stack a document listener per navigation if written the same way.
                 *
                 * `navigateOnce()` is what stops that: the script executes on a real page load and never
                 * again, so its document listeners bind exactly once. It needs no per-navigation
                 * rebinding because the listener is *delegated* — it resolves the row at event time, so a
                 * table that arrives with new markup is already covered. What it does need, and has, is
                 * to close on `livewire:navigated`.
                 *
                 * Filament also tags the asset `data-navigate-track` in SPA mode, which is the reason to
                 * prefer this over a Vite entry of our own: a deploy that changes the file forces a real
                 * reload instead of running new markup against old JS.
                 */
                Js::make('table-context-menu', resource_path('js/table-context-menu.js'))
                    ->navigateOnce(),
            ],
            package: 'app',
        );

        /*
         * The off switch's server-side state — `docs/table-context-menu-plan.md` Phase 4, revisited.
         *
         * Phase 4 left the preference in localStorage because no per-user store existed; `users.preferences`
         * now does. The script above stays static, so the state travels as a meta tag: 'off'/'on' when the
         * user has ever chosen, 'unset' when they have not — three states, because 'unset' is what tells the
         * script to migrate an old localStorage-only choice up to the server exactly once.
         *
         * Registered globally (not per panel) for the same reason the asset is: the script runs in every
         * panel, and a hook on one panel would leave the other resolving the preference from a stale mirror.
         * A meta tag rather than an inline script — nothing executes, so §2's listener-stacking concern
         * cannot apply.
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): string {
                $preferences = (array) (auth()->user()?->preferences ?? []);

                $state = array_key_exists('tableContextMenuDisabled', $preferences)
                    ? ($preferences['tableContextMenuDisabled'] ? 'off' : 'on')
                    : 'unset';

                return '<meta name="table-context-menu-preference" content="'.$state
                    .'" data-endpoint="'.e(route('user.preferences')).'">';
            },
        );

        /*
         * **Every table gains the menu without a single table file being edited** — §3.
         *
         * The menu itself needs no configuration: it reads the DOM. What this adds is the record key, so
         * the script has a first-class way to identify the row it was opened on rather than parsing
         * Filament's internal `wire:key`. That fallback stays — see the script — because a table with a
         * `recordAction` instead of a `recordUrl` renders no anchor at all, and so receives no link
         * attributes to carry this one.
         *
         * **`merge: true` matters even though no table sets its own link attributes today.** That is
         * precisely why it is easy to leave off and expensive later: the first table that needed one
         * would have it silently discarded here, and the symptom would appear in that table rather than
         * in this file. `TableContextMenuTest` holds the other half of that — it fails the day a table
         * declares link attributes, so whoever writes them is pointed at this question.
         */
        Table::configureUsing(function (Table $table): void {
            /*
             * `Model|array`, matching Filament's own `getExtraRecordLinkAttributes()` signature rather
             * than the narrower `Model`. No table in this application is array-backed today — nothing
             * calls `->records()` — but this closure runs for *every* table in *every* panel, so a
             * narrow hint would be a TypeError at render time on the first one that is, thrown from a
             * global provider and surfacing as a broken page nowhere near its cause. The parameter is
             * named `$record` because that is the named injection Filament supplies for both shapes.
             */
            $table->extraRecordLinkAttributes(
                fn (Model|array $record): array => ['data-record-key' => $table->getRecordKey($record)],
                merge: true,
            );
        });

        Schema::defaultStringLength(191);

        // Store Livewire temp uploads on a fixed, non-tenant-scoped disk so the
        // file written during upload is still found when validation runs under a
        // (possibly different) current tenant. See the `livewire-tmp` disk.
        config(['livewire.temporary_file_upload.disk' => 'livewire-tmp']);

        // Isolation is enforced at the database level (one database per company),
        // so Filament's row-level tenant scoping is disabled — resource queries
        // already run against the current tenant's database connection.
        //
        // This holds only for models in a tenant database. Anything in the
        // landlord database is shared by every company and has to draw the
        // boundary by hand: ActivityLog and TableView carry a company_id and
        // scope on it themselves, and UserResource turns row scoping back on
        // (see the $isScopedToTenant there) because membership is a pivot.
        Resource::scopeToTenant(false);

        // Keep spatie/laravel-multitenancy's current tenant in sync with the
        // tenant Filament resolves from the /admin/{company} route.
        Event::listen(TenantSet::class, SyncSpatieTenant::class);

        // A tenant-aware job queued with no tenant is deleted silently by the worker. Refuse it here
        // instead, where the mistake can be reported to whoever made it. See the listener.
        Event::listen(JobQueueing::class, RefuseTenantlessTenantAwareJobs::class);

        $this->registerHealthChecks();
    }

    /**
     * What `health:check` looks at.
     *
     * Registered here rather than in a module, because every one of these is a fact about the
     * *installation* — is the disk full, is Redis answering, is the scheduler running — and not
     * about any company. A module's checks would come and go with its licence, which is the
     * wrong lifetime for "is the server alright".
     *
     * The set is deliberately small. A dashboard of thirty checks where two are always amber is
     * a dashboard nobody reads, and the point of this is that somebody looks when it goes red.
     * Notably absent:
     *
     *  - **`QueueCheck`** needs `Schedule::job(new HealthQueueJob)` plus a worker consuming it.
     *    Registering it without both means a permanent failure that says "queue broken" when
     *    what is broken is the check's own plumbing. `HorizonCheck` below covers the same ground
     *    more directly now that Horizon supervises the workers.
     *  - **`OptimizedAppCheck`** fails by design in local development, where nothing is cached.
     */
    private function registerHealthChecks(): void
    {
        Health::checks([
            // The landlord. Its own check, named so, because in this application "the database"
            // is an ambiguous phrase and a green tick against it has meant less than people think.
            DatabaseCheck::new()
                ->connectionName(config('database.default'))
                ->name('Landlord database'),

            // Every company's database. The one the package cannot express — see the class.
            TenantDatabaseCheck::new()->name('Company databases'),

            /*
             * Do the control accounts agree with the documents behind them — `docs/erpnext-gap-plan.md`
             * Phase 2. Hourly rather than every minute, because this one sums each company's ledger while
             * its neighbours here are a PDO connect and a disk stat, and a reconciliation drift found
             * fifty-nine minutes late is found in time.
             */
            LedgerControlsCheck::new()
                ->name('Control accounts')
                ->if(fn (): bool => now()->minute === 0),

            // Redis carries the queue (QUEUE_CONNECTION=redis) and broadcasting, so when it is
            // down, scheduled work silently stops being done rather than failing loudly.
            //
            // Only where something actually uses it. An installation on the database queue and the
            // database cache has no Redis and needs none, and this was a permanent red tile with an
            // hourly email behind it — which is how a dashboard stops being read, and the reasoning
            // the two production-only checks at the bottom of this method already follow.
            RedisCheck::new()->if(fn (): bool => in_array('redis', [
                config('queue.default'),
                config('cache.default'),
                config('session.driver'),
                config('broadcasting.default'),
            ], true)),

            // And whether what it carries survives a restart. Only where Redis *is* the queue: an
            // installation on the database driver has nothing here to lose.
            RedisPersistenceCheck::new()
                ->name('Redis persistence')
                ->if(fn (): bool => config('queue.default') === 'redis'),

            CacheCheck::new(),

            // Backups, uploads and PDF temp files all land on the same volume, and the failure
            // mode of a full disk is a backup that half-writes.
            //
            // Ours rather than the package's: `UsedDiskSpaceCheck` shells out to `df` and parses
            // the output, which on a production host with no usable `df` crashed on every
            // scheduled run and reported a regex error instead of a disk reading. See the class.
            DiskSpaceCheck::new()
                ->name('Disk space')
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(85),

            // And that nothing has linked that volume's uploads into the web root. The suite forbids
            // `public/storage`; this is the same assertion made on the server, where deploy recipes run.
            PublicStorageLinkCheck::new()->name('Public storage link'),

            // Proof that `schedule:run` is on cron. This application leans on it heavily —
            // payroll posting, leave-year opening, document expiry, quote expiry, compensatory
            // off — and every one of those fails by simply never happening, which is invisible.
            // Needs `health:schedule-check-heartbeat` scheduled every minute; it is, in
            // routes/console.php, beside this comment's twin.
            ScheduleCheck::new(),

            // The archives spatie/laravel-backup writes. This watches the LANDLORD destination
            // only, for the reason config/backup.php's monitor_backups block spells out: tenant
            // archives live under sibling backup names on purpose, so they cannot keep this
            // green while the landlord backup fails. A stale *tenant* archive is still not
            // monitored — `backup:tenants` exiting non-zero is the signal.
            BackupsCheck::new()
                ->locatedAt(storage_path('app/private/'.config('backup.backup.name').'/*.zip'))
                ->numberOfBackups(min: 1)
                ->youngestBackShouldHaveBeenMadeBefore(now()->subDay()),

            // Horizon supervises the queue workers, so "is Horizon running" is the closest thing
            // to "is queued work being done at all". It reports paused and inactive separately,
            // which matters: a paused Horizon looks alive to `ps` and does nothing.
            //
            // This is what makes the scheduled work in every module observable end to end —
            // ScheduleCheck proves cron fires the dispatcher, this proves something is on the
            // other end to run what it dispatched.
            //
            // Ours rather than the package's, and only where Redis carries the queue. The package's
            // check throws its Redis error straight out of `run()` instead of failing, and an
            // installation whose queue is not Redis has no Horizon to watch at all — see the class.
            HorizonCheck::new()->if(fn (): bool => config('queue.default') === 'redis'),

            /*
             * Is *anything* consuming the queue — whatever supervises it.
             *
             * HorizonCheck above answers for installations that run Horizon. This one answers for every
             * installation: `health:queue-check-heartbeat` (scheduled every minute in routes/console.php)
             * pushes a tiny job, and the check fails when that job has not been *processed* recently. It is
             * the check that would have said, the day the bell was first asked about, that eighteen jobs
             * were sitting in Redis because no worker had ever run.
             */
            QueueCheck::new(),

            // And whether what did run is failing and being left there. The table is otherwise invisible.
            FailedJobsCheck::new()
                ->name('Failed jobs')
                ->warnWhenCountIsAtLeast(1)
                ->failWhenCountIsAtLeast(25),
        ]);

        // Two that are only meaningful in production, and are registered only there.
        //
        // Both fail by definition in local development — `APP_DEBUG` is true and `APP_ENV` is
        // `local`, which is correct and not a problem. Registering them anyway would mean every
        // developer's `health:check` shows two permanent reds, and two permanent reds are how a
        // dashboard stops being read. Absent locally rather than green: a check that lies in the
        // reassuring direction is worse than one that is not there.
        if ($this->app->environment('production')) {
            Health::checks([
                DebugModeCheck::new(),
                EnvironmentCheck::new(),

                // Whether the backup can report its own failure, and whether the archive is encrypted.
                // `BackupsCheck` above watches that archives exist; this watches what they are worth.
                // Production-only for the same reason as the two above it: a developer's dump never
                // leaves the machine, so encrypting it is not a finding worth a permanent amber.
                BackupConfigurationCheck::new()->name('Backup configuration'),

                // And whether an email can leave the building at all: a mailer named in the chain but
                // not defined, a provider with no credentials, the shipped placeholder still in
                // MAIL_FROM_ADDRESS. All three were live on one server on the same day, and the first
                // of them broke the notification that was reporting a different failure.
                MailConfigurationCheck::new()->name('Mail configuration'),
            ]);
        }
    }
}
