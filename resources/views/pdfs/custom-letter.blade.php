{{--
    Custom letter — an administrator's own wording on the company letterhead.

    Same sheet as the income certificate and the experience letter, deliberately: three company letters
    that look like they came from three companies is the thing a recipient notices first. The body is
    escaped text, never trusted markup — whatever was typed prints as typed.

    Everything arrives resolved from `App\Modules\Employees\Services\CustomLetter`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $subject !== '' ? $subject : 'Letter' }}</title>
    <style>
        /* Zero, so the green footer bar reaches the paper edge — the inset lives on `.sheet`, as in
           the sibling letters. */
        @page { margin: 0; }
    </style>

    @include('pdfs.partials.letterhead-styles')

    {{-- The experience letter's roomier rhythm: a custom letter rarely fills the page either.
         Before the Dompdf block, deliberately — the last plain selector wins, and it must be Dompdf's. --}}
    <style>
        p { margin: 0 0 13pt; }
        .addressee { margin: 24pt 0 14pt; }
        .subject { margin-bottom: 12pt; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Letter'])

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

@if ($to !== '')
    <p class="addressee"><strong>{{ $to }}</strong></p>
@endif

<div class="subject">
    SUBJECT: {{ $subject }}
</div>

@foreach ($paragraphs as $paragraph)
    <p>{!! nl2br(e($paragraph)) !!}</p>
@endforeach

<p class="sincerely">Sincerely,</p>

<div class="sign">

    {{-- The same seal the sibling letters carry, guarded the same way: no asset, no broken image. --}}
    @if ($seal = \App\Support\CompanyLetterhead::signatureDataUri())
        <img src="{{ $seal }}" class="seal-image" alt="">
    @elseif (file_exists(public_path('signatures/employer_signature1.png')))
        <img src="{{ public_path('signatures/employer_signature1.png') }}" class="seal-image" alt="">
    @endif

    <div class="rule"></div>
    <div class="who">{{ $signatory['name'] }}</div>
    <div>{{ $signatory['title'] }}</div>
    <div>{{ $company['legal_name'] }}</div>
</div>

</div>

@include('pdfs.partials.letterhead-footer', ['company' => $company])

</body>
</html>
