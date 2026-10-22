<?php

namespace App\Modules\Invoicing\Filament\Settings;

use App\Modules\Invoicing\Services\OverdueReminderService;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * Whether and when to chase overdue invoices — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * Three settings, and the first is the important one: **off by default**. This is the only thing in the
 * application that emails somebody who is not a member of the company, so it cannot be a feature that starts
 * working because a deploy happened. A company switches it on having read what it says.
 *
 * The wording of the letter is not here. It is an `EmailTemplate` (`invoice_overdue`), where every other
 * company-specific wording lives, and the shipped text is sent until somebody writes their own.
 *
 * **No interest and no fee**, which is where this stops being ERPNext's Dunning. Theirs books both through
 * the payment's deductions; that is a posting decision and §4 of the plan says to leave it until somebody
 * charges one. Adding fields for them here would promise arithmetic nothing does.
 */
class DunningSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'invoicing.dunning';
    }

    public function components(): array
    {
        return [
            Section::make('Chasing overdue invoices')
                ->description('Email customers whose invoices are past due. Nothing is sent while this is off, '
                    .'and each reminder is recorded on the invoice. The letter hardens with each repeat — '
                    .'write "invoice overdue 2" and "invoice overdue 3" under Email templates for your own '
                    .'second and final wording.')
                ->schema([
                    Toggle::make('invoicing.dunning_enabled')
                        ->label('Send overdue reminders')
                        ->helperText('The only thing in this application that emails somebody outside the company. '
                            .'Run php artisan invoicing:send-overdue-reminders --dry-run first to see who would '
                            .'be chased.')
                        ->live(),

                    TextInput::make('invoicing.dunning_after_days')
                        ->label('First reminder after')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->suffix('days past due')
                        ->required()
                        ->visible(fn (callable $get): bool => (bool) $get('invoicing.dunning_enabled')),

                    TextInput::make('invoicing.dunning_repeat_days')
                        ->label('Chase again every')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix('days')
                        ->required()
                        ->helperText('Counted from the last reminder actually sent, per invoice.')
                        ->visible(fn (callable $get): bool => (bool) $get('invoicing.dunning_enabled')),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'invoicing.dunning_enabled' => (bool) setting('invoicing.dunning_enabled', false),
            'invoicing.dunning_after_days' => (int) setting(
                'invoicing.dunning_after_days',
                OverdueReminderService::DEFAULT_AFTER_DAYS,
            ),
            'invoicing.dunning_repeat_days' => (int) setting(
                'invoicing.dunning_repeat_days',
                OverdueReminderService::DEFAULT_REPEAT_DAYS,
            ),
        ];
    }

    public function save(array $state): void
    {
        $settings = app(TenantSettings::class);

        $settings->set('invoicing.dunning_enabled', (bool) ($state['invoicing.dunning_enabled'] ?? false));
        $settings->set('invoicing.dunning_after_days', max(0, (int) ($state['invoicing.dunning_after_days'] ?? OverdueReminderService::DEFAULT_AFTER_DAYS)));
        $settings->set('invoicing.dunning_repeat_days', max(1, (int) ($state['invoicing.dunning_repeat_days'] ?? OverdueReminderService::DEFAULT_REPEAT_DAYS)));
    }
}
