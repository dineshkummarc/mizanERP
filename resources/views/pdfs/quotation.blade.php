<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f4f4f4; }
        .num { text-align: right; }
        .totals { margin-top: 12px; width: 40%; margin-left: auto; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .grand { font-weight: bold; border-top: 2px solid #222; }
        .status { text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
        .terms { margin-top: 24px; white-space: pre-line; }
    </style>

    {{-- The invoice sheet's layout, so the invoice sheet's Dompdf fixes: the header is a flex
         row and the totals table is pushed right with an auto margin, and Dompdf resolves
         neither. Same class names, same partial — see docs/bexio-gap-plan.md Phase 1. --}}
    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-invoice')
    @endif
</head>
<body>
    <div class="header">
        <div>
            @if ($logo = \App\Support\CompanyLetterhead::logoDataUri())
                <img src="{{ $logo }}" alt="" style="height: 40px; margin-bottom: 8px;">
            @endif
            <h1>Quotation {{ $quotation->number }}</h1>
            {{-- Version 2 is a different document from version 1, and the customer may hold
                 both: saying which this is, and what it replaces, is what stops them paying
                 against the wrong one. --}}
            @if ($quotation->version > 1)
                <div class="muted">
                    Version {{ $quotation->version }}@if ($quotation->supersedes) — replaces {{ $quotation->supersedes->number }}@endif
                </div>
            @endif
            <div class="muted">Date: {{ $quotation->issue_date->format('d M Y') }}</div>
            @if ($quotation->valid_until)
                <div class="muted">Valid until: {{ $quotation->valid_until->format('d M Y') }}</div>
            @endif
            <div class="status">{{ str_replace('_', ' ', $quotation->status) }}</div>
        </div>
        <div>
            <strong>For</strong><br>
            {{ $quotation->partyLabel() }}<br>
            @if ($quotation->contact)
                @if ($quotation->contact->address_line_1) {{ $quotation->contact->address_line_1 }}<br> @endif
                @if ($quotation->contact->address_line_2) {{ $quotation->contact->address_line_2 }}<br> @endif
                @if ($quotation->contact->ntn) NTN: {{ $quotation->contact->ntn }} @endif
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Discount</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            {{-- Net of discount and before tax, so the column adds up to the subtotal below it —
                 the same figure the invoice line will carry once the quote converts. --}}
            @foreach ($quotation->lines as $line)
                <tr>
                    <td>{{ $line->description }}@if ($line->product) <span class="muted">({{ $line->product->sku }})</span>@endif</td>
                    <td class="num">{{ number_format($line->quantity, 2) }}</td>
                    <td class="num">{{ number_format($line->unit_price, 2) }}</td>
                    <td class="num">{{ (float) $line->discount_pct > 0 ? number_format($line->discount_pct, 2).'%' : '—' }}</td>
                    <td class="num">{{ number_format($line->netTotal(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format($quotation->subtotal, 2) }}</td></tr>
        <tr><td>Tax</td><td class="num">{{ number_format($quotation->tax_total, 2) }}</td></tr>
        <tr class="grand"><td>Total ({{ $quotation->currency_code ?: 'PKR' }})</td><td class="num">{{ number_format($quotation->total, 2) }}</td></tr>
    </table>

    @if ($quotation->terms)
        <div class="terms"><strong>Terms</strong><br>{{ $quotation->terms }}</div>
    @endif
</body>
</html>
