<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers;

use App\Modules\Invoicing\Models\ContactDocument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Contracts and documents on the contact — docs/bexio-gap-plan.md §3.7.
 *
 * The tab the employee screen has for a visa, on the customer and the supplier: a kind, a number, a term
 * and the file, with the expiry watched once per threshold by `invoicing:check-contact-documents`.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->options(ContactDocument::KINDS)
                ->default('contract')
                ->required(),

            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->helperText('What it is, in your words: "Supply agreement 2026", "NDA signed 12 Mar".'),

            TextInput::make('number')
                ->label('Reference number')
                ->maxLength(255),

            DatePicker::make('issued_on')
                ->label('Signed or issued on')
                ->native(false),

            DatePicker::make('expires_on')
                ->native(false)
                ->helperText('Leave blank for a document that never lapses. A renewal is warned about once at '
                    .implode(', ', self::thresholds()).' days, not every day.'),

            FileUpload::make('file_path')
                ->label('File')
                ->disk('public')
                ->directory('contact-documents')
                ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(8192)
                ->openable()
                ->downloadable()
                ->columnSpanFull(),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ContactDocument::KINDS[$state] ?? ucfirst($state)),

                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (ContactDocument $record): ?string => $record->number)
                    ->wrap(),

                TextColumn::make('issued_on')->label('Issued')->date('d M Y')->placeholder('—')->sortable(),

                // Red once it has lapsed, amber inside the widest warning threshold, plain otherwise.
                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('d M Y')
                    ->placeholder('never')
                    ->sortable()
                    ->color(fn (ContactDocument $record): ?string => match (true) {
                        $record->hasExpired() => 'danger',
                        ($record->daysUntilExpiry() ?? PHP_INT_MAX) <= max(self::thresholds()) => 'warning',
                        default => null,
                    })
                    ->description(fn (ContactDocument $record): ?string => match (true) {
                        $record->expires_on === null => null,
                        $record->hasExpired() => 'expired '.abs($record->daysUntilExpiry()).' day(s) ago',
                        default => 'in '.$record->daysUntilExpiry().' day(s)',
                    }),

                TextColumn::make('file_path')
                    ->label('File')
                    ->formatStateUsing(fn (): string => 'Open')
                    ->placeholder('—')
                    ->url(fn (ContactDocument $record): ?string => $record->file_path
                        ? Storage::disk('public')->url($record->file_path)
                        : null, shouldOpenInNewTab: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()->label('Add a document')->icon('heroicon-o-document-plus'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<int, int> */
    private static function thresholds(): array
    {
        return config('invoicing.contact_document_expiry_thresholds', [60, 30, 7]);
    }
}
