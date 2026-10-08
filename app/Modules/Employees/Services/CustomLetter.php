<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Support\CompanyLetterhead;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;

/**
 * The letter the other two don't cover — a warning, an appreciation, a no-objection — with the wording
 * typed by an administrator on the day.
 *
 * The income certificate and the experience letter refuse to say anything a record doesn't back, which is
 * right for documents a bank verifies and wrong for everything else HR puts on paper. This is the escape
 * hatch: the company letterhead, reference and seal around text a person wrote. **Administrator only**,
 * precisely because the text is unchecked — the other letters are offered to anyone who may view the
 * record, but free text on company paper is a statement the company makes, not the employee.
 *
 * Nothing is stored, same position as its siblings: the wording lives with whoever typed it, and a copy
 * on disk would be one more place personal text about an employee outlives the click that made it.
 */
class CustomLetter
{
    /**
     * Only the letterhead can be missing — the content is supplied, not looked up.
     *
     * @return array<int, string>
     */
    public function missingFor(Employee $employee): array
    {
        return CompanyLetterhead::missing();
    }

    public function filename(Employee $employee): string
    {
        $name = str($employee->fullName() ?: $employee->employee_id)->slug()->value();

        return 'letter-'.($name ?: 'employee').'-'.Carbon::today()->format('Y-m-d').'.pdf';
    }

    /** @param  array<string, mixed>  $input */
    public function renderPdf(Employee $employee, array $input): PdfDocument
    {
        return Pdf::view('pdfs.custom-letter', $this->data($employee, $input))
            ->format('a4')
            ->name($this->filename($employee));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function data(Employee $employee, array $input): array
    {
        $employee->loadMissing('user');

        $issuedOn = Carbon::today();

        return [
            'employee' => $employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory($input),
            'issued_on' => $issuedOn,
            // /LTR so it never collides with the income certificate's or the experience letter's
            // reference for the same employee — a recipient files by this.
            'reference' => CompanyLetterhead::reference(
                ($employee->employee_id ?: 'EMP-'.$employee->getKey()).'/LTR',
                $issuedOn,
            ),
            'to' => trim((string) ($input['to'] ?? '')),
            'subject' => trim((string) ($input['subject'] ?? '')),
            // A blank line starts a new paragraph; single newlines survive as line breaks in the
            // template. Split here so a test can assert on paragraphs without rendering a PDF.
            'paragraphs' => array_values(array_filter(array_map(
                'trim',
                preg_split('/\R{2,}/', (string) ($input['body'] ?? '')) ?: [],
            ))),
        ];
    }
}
