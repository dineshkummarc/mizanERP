<x-filament-panels::page>
    {{ $this->form }}

    @php($rows = $this->getRows())
    @php($summary = $this->getSummary())
    @php($readiness = $this->getReadiness())
    @php($warnings = $this->getMinimumWageWarnings())

    <x-filament::section>
        <x-slot name="heading">EOBI monthly return</x-slot>
        <x-slot name="description">
            Employee and employer contributions as the payslips actually carry them — nothing is recomputed, so the
            file agrees with what was paid. Download the CSV and upload it to the EOBI portal; this does not submit it.
        </x-slot>

        @if($readiness !== null)
            <p class="text-gray-500 dark:text-gray-400 text-sm">{{ $readiness }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">Employee</th>
                            <th class="py-2 pr-4 font-medium">Name</th>
                            <th class="py-2 pr-4 font-medium">CNIC</th>
                            <th class="py-2 pl-4 font-medium text-right">Employee</th>
                            <th class="py-2 pl-4 font-medium text-right">Employer</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ $row['employee_code'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['name'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['cnic'] ?: '—' }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['employee_contribution'], 2) }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($row['employer_contribution'], 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="3" class="py-2 pr-4">Total ({{ $summary['employees'] }} employees) — {{ number_format($summary['grand_total'], 2) }} payable</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($summary['employee_total'], 2) }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($summary['employer_total'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if($warnings !== [])
        <x-filament::section>
            <x-slot name="heading">Below the minimum wage</x-slot>
            <x-slot name="description">Contributions are assessed on the minimum wage, so a package below it is worth checking before the return goes.</x-slot>
            <ul class="list-disc pl-5 text-sm text-gray-700 dark:text-gray-200">
                @foreach($warnings as $warning)
                    <li>{{ is_array($warning) ? implode(' — ', array_filter($warning)) : $warning }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
