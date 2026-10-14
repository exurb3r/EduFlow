<?php

namespace App\Filament\Resources\Users;

use AlizHarb\ActivityLog\RelationManagers\ActivitiesRelationManager;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use UnitEnum;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $unverified = User::query()->whereNull('email_verified_at')->count();

        return $unverified > 0 ? (string) $unverified : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    #[\Override]
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Users awaiting email verification';
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Account Details')
                    ->description('User identity credentials and linked system access roles.')
                    ->icon(Heroicon::UserCircle)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::User),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->prefixIcon(Heroicon::Envelope),
                        TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->confirmed()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::Key),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->visible(fn (string $context): bool => $context === 'create' || $context === 'edit')
                            ->prefixIcon(Heroicon::Key),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->prefixIcon(Heroicon::ShieldCheck),
                    ])->columns(2),

                Section::make('Account Security & Verification')
                    ->description('Email confirmation and authentication timestamp.')
                    ->icon(Heroicon::ShieldCheck)
                    ->components([
                        DateTimePicker::make('email_verified_at')
                            ->label('Email Verified At')
                            ->prefixIcon(Heroicon::CheckBadge)
                            ->helperText('Leave empty to keep unverified.'),
                    ])->columns(2)
                    ->collapsible(),
            ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Account & Personal Identity')
                    ->description('System authentication credentials and identity record.')
                    ->icon(Heroicon::UserCircle)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('name')
                                ->label('Full Name')
                                ->weight('bold')
                                ->icon(Heroicon::User),

                            TextEntry::make('email')
                                ->label('Email Address')
                                ->icon(Heroicon::Envelope)
                                ->copyable(),

                            TextEntry::make('roles.name')
                                ->label('Account Role')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'super_admin', 'admin' => 'danger',
                                    'finance_officer' => 'info',
                                    'student', 'user' => 'success',
                                    default => 'gray',
                                }),

                            TextEntry::make('id')
                                ->label('User ID')
                                ->fontFamily('mono')
                                ->prefix('#'),

                            TextEntry::make('created_at')
                                ->label('Registered At')
                                ->dateTime('M d, Y H:i:s'),

                            TextEntry::make('updated_at')
                                ->label('Last Profile Update')
                                ->dateTime('M d, Y H:i:s'),
                        ]),
                    ]),

                Section::make('Security & Multi-Factor Authentication')
                    ->description('Account verification and multi-factor authentication standing.')
                    ->icon(Heroicon::ShieldCheck)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('email_verified_at')
                                ->label('Email Verification')
                                ->dateTime('M d, Y')
                                ->placeholder('Unverified')
                                ->badge()
                                ->color(fn ($state): string => $state ? 'success' : 'warning')
                                ->icon(fn ($state): string => $state ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'),

                            TextEntry::make('app_authentication_secret')
                                ->label('App MFA (Authenticator)')
                                ->formatStateUsing(fn ($state): string => filled($state) ? 'Enabled' : 'Disabled')
                                ->badge()
                                ->color(fn ($state): string => filled($state) ? 'success' : 'gray')
                                ->icon(fn ($state): string => filled($state) ? 'heroicon-m-qr-code' : 'heroicon-m-x-circle'),

                            TextEntry::make('has_email_authentication')
                                ->label('Email MFA (OTP)')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                                ->badge()
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                                ->icon(fn (bool $state): string => $state ? 'heroicon-m-envelope' : 'heroicon-m-x-circle'),
                        ]),
                    ]),

                Section::make('Student Profile & Academic Dossier')
                    ->description('Linked institutional student record, tuition accounts, and Arc Network payout destination.')
                    ->icon(Heroicon::AcademicCap)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('student.student_number')
                                ->label('Institutional Student ID')
                                ->placeholder('No student profile linked')
                                ->fontFamily('mono')
                                ->badge()
                                ->color(fn (User $record): string => $record->student ? 'primary' : 'gray')
                                ->icon(Heroicon::Identification)
                                ->copyable(fn (User $record): bool => (bool) $record->student?->student_number),

                            TextEntry::make('student.program')
                                ->label('Degree Program')
                                ->placeholder('—')
                                ->icon(Heroicon::BookOpen)
                                ->weight('bold'),

                            TextEntry::make('student.year_level')
                                ->label('Curriculum Standing')
                                ->placeholder('—')
                                ->state(fn (User $record): string => $record->student ? "Year {$record->student->year_level}" : '—')
                                ->icon(Heroicon::AcademicCap),

                            TextEntry::make('student.enrollment_status')
                                ->label('Enrollment Status')
                                ->placeholder('—')
                                ->badge()
                                ->color(fn (User $record): string => $record->student ? $record->student->enrollmentStatusColor() : 'gray')
                                ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '—'),

                            TextEntry::make('student.academic_status')
                                ->label('Academic Standing')
                                ->placeholder('—')
                                ->badge()
                                ->color(fn (User $record): string => $record->student ? $record->student->academicStatusColor() : 'gray')
                                ->formatStateUsing(fn (?string $state): string => $state ? ucfirst(str_replace('_', ' ', $state)) : '—'),

                            TextEntry::make('student.payout_address')
                                ->label('Arc Payout Wallet')
                                ->placeholder('Not configured')
                                ->fontFamily('mono')
                                ->copyable(fn (User $record): bool => filled($record->student?->payout_address))
                                ->url(fn (User $record): ?string => $record->student?->payout_address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->student->payout_address) : null)
                                ->openUrlInNewTab()
                                ->icon(Heroicon::Wallet),

                            TextEntry::make('student_tuition_balance')
                                ->label('Outstanding Tuition')
                                ->placeholder('—')
                                ->state(fn (User $record): string => $record->student ? (new Money($record->student->totalTuitionBalance(), CurrencyCode::USDC))->format(2).' USDC' : '—')
                                ->badge()
                                ->color(fn (User $record): string => ($record->student && $record->student->totalTuitionBalance() > 0) ? 'danger' : 'success')
                                ->icon(Heroicon::Banknotes),

                            TextEntry::make('student_action_link')
                                ->label('Student Resource')
                                ->state(fn (User $record): string => $record->student ? 'Open Full Student Dossier ↗' : 'Link a Student Record')
                                ->badge()
                                ->color(fn (User $record): string => $record->student ? 'primary' : 'gray')
                                ->url(fn (User $record): string => $record->student ? StudentResource::getUrl('view', ['record' => $record->student->id]) : StudentResource::getUrl('create')),
                        ]),
                    ]),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['student', 'roles']))
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon(Heroicon::User),

                TextColumn::make('email')
                    ->label('Email Address')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin', 'admin' => 'danger',
                        'finance_officer' => 'info',
                        'student', 'user' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('student.student_number')
                    ->label('Student Profile')
                    ->placeholder('Non-student')
                    ->fontFamily('mono')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'primary' : 'gray')
                    ->icon(Heroicon::AcademicCap)
                    ->url(fn (User $record): ?string => $record->student ? StudentResource::getUrl('view', ['record' => $record->student->id]) : null)
                    ->sortable(),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->colors([
                        'success' => fn ($state): bool => $state !== null,
                        'danger' => fn ($state): bool => $state === null,
                    ])
                    ->sortable(),

                IconColumn::make('app_authentication_secret')
                    ->label('App MFA')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->colors([
                        'success' => filled(...),
                        'warning' => blank(...),
                    ])
                    ->sortable(),

                IconColumn::make('has_email_authentication')
                    ->label('Email MFA')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope')
                    ->falseIcon('heroicon-o-envelope-open')
                    ->colors([
                        'success' => fn (bool $state): bool => $state,
                        'warning' => fn (bool $state): bool => ! $state,
                    ])
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_student')
                    ->label('Student Profile')
                    ->placeholder('All users')
                    ->trueLabel('Linked students')
                    ->falseLabel('Non-student accounts')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('student'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('student'),
                    ),

                TernaryFilter::make('email_verified_at')
                    ->label('Email Verified')
                    ->nullable(),

                TernaryFilter::make('app_authentication_secret')
                    ->label('App MFA Enabled')
                    ->nullable(),

                TernaryFilter::make('has_email_authentication')
                    ->label('Email MFA Enabled')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('link_student')
                    ->label('Link Student')
                    ->icon('heroicon-m-user-plus')
                    ->color('primary')
                    ->visible(fn (User $record): bool => $record->student === null)
                    ->modalHeading(fn (User $record): string => "Create Student Profile for {$record->name}")
                    ->modalDescription('Establish institutional student profile for tuition accounts, aid grants, and Arc payouts.')
                    ->modalSubmitActionLabel('Create student record')
                    ->schema([
                        TextInput::make('student_number')
                            ->label('Student number')
                            ->required()
                            ->default(fn (User $record): string => 'STU-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT))
                            ->unique(table: Student::class, column: 'student_number', ignoreRecord: false)
                            ->prefixIcon(Heroicon::Identification),

                        TextInput::make('program')
                            ->label('Degree Program')
                            ->required()
                            ->datalist([
                                'BS Information Technology',
                                'BS Computer Science',
                                'BS Information Systems',
                                'BS Data Science',
                                'BS Software Engineering',
                                'BS Business Administration',
                            ])
                            ->placeholder('BS Information Technology')
                            ->prefixIcon(Heroicon::BookOpen),

                        TextInput::make('year_level')
                            ->label('Curriculum Year Level')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(1)
                            ->required()
                            ->prefixIcon(Heroicon::AcademicCap),

                        Select::make('enrollment_status')
                            ->label('Enrollment Status')
                            ->options([
                                'enrolled' => 'Enrolled',
                                'not_enrolled' => 'Not enrolled',
                                'on_leave' => 'On leave',
                                'graduated' => 'Graduated',
                            ])
                            ->native(false)
                            ->default('enrolled')
                            ->required(),

                        Select::make('academic_status')
                            ->label('Academic Status')
                            ->options([
                                'qualified' => 'Qualified',
                                'probation' => 'Probation',
                                'suspended' => 'Suspended',
                                'dismissed' => 'Dismissed',
                            ])
                            ->native(false)
                            ->default('qualified')
                            ->required(),

                        TextInput::make('attendance_rate')
                            ->label('Attendance Rate (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->prefixIcon(Heroicon::Calendar),

                        TextInput::make('payout_address')
                            ->label('Arc Payout Wallet')
                            ->placeholder('0x…')
                            ->rule('regex:'.Student::PAYOUT_ADDRESS_PATTERN)
                            ->prefixIcon(Heroicon::Wallet),
                    ])
                    ->action(function (User $record, array $data): void {
                        Student::query()->create([
                            'user_id' => $record->id,
                            'student_number' => $data['student_number'],
                            'program' => $data['program'],
                            'year_level' => (int) $data['year_level'],
                            'enrollment_status' => $data['enrollment_status'],
                            'academic_status' => $data['academic_status'],
                            'attendance_rate' => $data['attendance_rate'] ?? null,
                            'payout_address' => $data['payout_address'] ?? null,
                        ]);

                        if (! $record->hasRole('student')) {
                            $record->assignRole('student');
                        }

                        Notification::make()
                            ->title('Student record linked')
                            ->body("Student profile #{$data['student_number']} created for {$record->name}.")
                            ->success()
                            ->send();
                    }),

                Action::make('view_student')
                    ->label('Student')
                    ->icon('heroicon-m-academic-cap')
                    ->color('primary')
                    ->visible(fn (User $record): bool => $record->student !== null)
                    ->url(fn (User $record): string => StudentResource::getUrl('view', ['record' => $record->student->id])),

                Action::make('impersonate')
                    ->label('Impersonate')
                    ->icon('heroicon-o-user-plus')
                    ->url(fn (User $user): string => route('impersonate', $user->id))
                    ->openUrlInNewTab()
                    ->visible(fn (User $record): bool => ($user = Auth::user()) instanceof User && $user->hasRole('admin') && $record->canBeImpersonated()),

                Action::make('reset_password')
                    ->label('Password reset')
                    ->icon(Heroicon::Key)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Send Password Reset Link')
                    ->modalDescription(fn (User $record): string => "Send a secure password reset link to {$record->email}?")
                    ->action(function (User $record): void {
                        Password::broker()->sendResetLink(['email' => $record->email]);

                        Notification::make()
                            ->title('Reset email sent')
                            ->body("Password reset link dispatched to {$record->email}.")
                            ->success()
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),

                Action::make('verify_email')
                    ->icon('heroicon-o-check')
                    ->label('Verify')
                    ->action(fn (User $user) => $user->forceFill(['email_verified_at' => now()])->save())
                    ->visible(fn (User $user): bool => $user->email_verified_at === null)
                    ->requiresConfirmation()
                    ->color('success'),

                Action::make('unverify_email')
                    ->icon('heroicon-o-x-mark')
                    ->label('Unverify')
                    ->action(fn (User $user) => $user->forceFill(['email_verified_at' => null])->save())
                    ->visible(fn (User $user): bool => $user->email_verified_at !== null)
                    ->requiresConfirmation()
                    ->color('danger'),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('verify_selected')
                        ->label('Verify Selected')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->forceFill(['email_verified_at' => now()])->each->save())
                        ->requiresConfirmation()
                        ->color('success'),
                    BulkAction::make('unverify_selected')
                        ->label('Unverify Selected')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->forceFill(['email_verified_at' => null])->each->save())
                        ->requiresConfirmation()
                        ->color('danger'),
                    BulkAction::make('assign_role')
                        ->label('Assign Role')
                        ->icon(Heroicon::ShieldCheck)
                        ->schema([
                            Select::make('role')
                                ->label('Select Role to assign')
                                ->options(fn (): array => Role::query()->pluck('name', 'name')->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $record) {
                                $record->assignRole($data['role']);
                            }

                            Notification::make()
                                ->title('Roles updated')
                                ->body("Assigned {$data['role']} role to {$records->count()} users.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
