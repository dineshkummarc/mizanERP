<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Notifications\DocumentIssued;
use App\Support\Pdf\DocumentBundle;
use App\Support\Pdf\PdfDocument;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * One certificate for many employees — `docs/bexio-gap-plan.md` Phase 5, item 18.
 *
 * The rendering stays with the certificate (`IncomeCertificate`, `WithholdingCertificate`); this does
 * the loop: who can be issued one, who cannot and why, and whether the result is a zip to download or
 * an email to each person. Both certificates share it, so a third kind is a closure, not a copy.
 *
 * **Email goes where the payslip goes**: the company address when the employee has an account, the
 * personal address otherwise — PayslipDeliveryService's rule, for the same reasons.
 */
class CertificateBatch
{
    /** @var array<string, array<int, string>> employee label => what is missing */
    public array $skipped = [];

    public DocumentBundle $bundle;

    public int $emailed = 0;

    public function __construct()
    {
        $this->bundle = new DocumentBundle;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Closure(Employee): array<int, string>  $missing  what stops a certificate being issued; [] when nothing
     * @param  Closure(Employee): PdfDocument  $render  the certificate itself
     * @param  string  $deliver  `zip` to bundle, `email` to send each one to its employee
     * @param  string  $document  how the email names it: "tax deduction certificate for 2026-2027"
     */
    public function run(Collection $employees, Closure $missing, Closure $render, string $deliver, string $document): self
    {
        foreach ($employees as $employee) {
            $employee->loadMissing('user');
            $label = $employee->display_label ?? $employee->employee_id;

            $problems = $missing($employee);

            if ($problems !== []) {
                $this->skipped[$label] = $problems;

                continue;
            }

            $pdf = $render($employee);

            if ($deliver === 'email') {
                $address = $employee->user?->email ?: $employee->personal_email;

                if (blank($address)) {
                    $this->skipped[$label] = ['no email address on the employee record'];

                    continue;
                }

                $notification = new DocumentIssued($document, $pdf->getName(), $pdf->raw());

                $employee->user && $employee->user->email === $address
                    ? Notification::send($employee->user, $notification)
                    : Notification::route('mail', $address)->notify($notification);

                $this->emailed++;

                continue;
            }

            $this->bundle->add($pdf->getName(), $pdf->raw());
        }

        return $this;
    }

    /** One line for the screen: what went, and who was left out and why. */
    public function summary(string $deliver): string
    {
        $done = $deliver === 'email'
            ? "{$this->emailed} emailed"
            : "{$this->bundle->count()} in the download";

        if ($this->skipped === []) {
            return $done.'.';
        }

        $left = collect($this->skipped)
            ->map(fn (array $problems, string $label): string => $label.' ('.implode('; ', $problems).')')
            ->implode(', ');

        return "{$done}. Not issued: {$left}.";
    }
}
