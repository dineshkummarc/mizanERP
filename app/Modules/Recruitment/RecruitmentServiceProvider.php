<?php

namespace App\Modules\Recruitment;

use App\Modules\Recruitment\Filament\Pages\HiringFunnel;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Recruitment\Policies\ApplicantPolicy;
use App\Modules\Recruitment\Policies\ApplicationPolicy;
use App\Modules\Recruitment\Policies\InterviewPolicy;
use App\Modules\Recruitment\Policies\OfferPolicy;
use App\Modules\Recruitment\Policies\VacancyPolicy;
use App\Modules\Recruitment\Support\RecruitmentReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantFileAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class RecruitmentServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Vacancy::class => VacancyPolicy::class,
        Applicant::class => ApplicantPolicy::class,
        Application::class => ApplicationPolicy::class,
        Interview::class => InterviewPolicy::class,
        Offer::class => OfferPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerReports();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // A candidate CV streams only to someone who may view the applicant — security audit finding 10.
        TenantFileAccess::registerRecordPrefix('applicant-resumes/', fn (string $path) => Applicant::query()->where('resume_path', $path)->first());

    }

    /**
     * The hiring funnel — `docs/reports-expansion-plan.md` Phase 3.2.
     *
     * Filed under *Operations*: it is read by whoever is running the hiring, and the section already holds
     * the other "what is in flight" reports.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without recruitment sees no entry rather than a report that
     * fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Operations',
            HiringFunnel::class,
            'Applications by stage per vacancy, offers taken, time to hire and how long each has been open.',
        );

        ReportRenderers::register(
            'HiringFunnel',
            fn (string $asOf): array => app(RecruitmentReports::class)->funnel($asOf),
        );
    }
}
