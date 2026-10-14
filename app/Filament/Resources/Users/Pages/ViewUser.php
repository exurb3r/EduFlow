<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserProfileSummaryWidget;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            UserProfileSummaryWidget::class,
        ];
    }

    #[\Override]
    public function getRecord(): User
    {
        /** @var User $record */
        $record = parent::getRecord();

        return $record;
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $user = $this->getRecord();
        $role = $user->roles->pluck('name')->first() ?? 'user';
        $studentPart = $user->student
            ? "• Linked Student: {$user->student->student_number} ({$user->student->program})"
            : '• Non-Student Account';

        return "{$user->email} • Role: {$role} {$studentPart}";
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewStudent')
                ->label('Student Dossier')
                ->icon(Heroicon::AcademicCap)
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->student !== null)
                ->url(fn (): string => StudentResource::getUrl('view', ['record' => $this->getRecord()->student->id])),

            Action::make('linkStudent')
                ->label('Link Student Profile')
                ->icon(Heroicon::PlusCircle)
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->student === null)
                ->modalHeading(fn (): string => "Create Student Profile for {$this->getRecord()->name}")
                ->modalDescription('Establish institutional student profile for tuition accounts, aid grants, and Arc payouts.')
                ->modalSubmitActionLabel('Create student record')
                ->schema([
                    TextInput::make('student_number')
                        ->label('Student number')
                        ->required()
                        ->default(fn (): string => 'STU-'.str_pad((string) $this->getRecord()->id, 6, '0', STR_PAD_LEFT))
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
                ->action(function (array $data): void {
                    $user = $this->getRecord();

                    Student::query()->create([
                        'user_id' => $user->id,
                        'student_number' => $data['student_number'],
                        'program' => $data['program'],
                        'year_level' => (int) $data['year_level'],
                        'enrollment_status' => $data['enrollment_status'],
                        'academic_status' => $data['academic_status'],
                        'attendance_rate' => $data['attendance_rate'] ?? null,
                        'payout_address' => $data['payout_address'] ?? null,
                    ]);

                    if (! $user->hasRole('student')) {
                        $user->assignRole('student');
                    }

                    Notification::make()
                        ->title('Student record linked')
                        ->body("Student profile #{$data['student_number']} created for {$user->name}.")
                        ->success()
                        ->send();
                }),

            Action::make('reset_password')
                ->label('Password reset')
                ->icon(Heroicon::Key)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send Password Reset Link')
                ->modalDescription(fn (): string => "Send a secure password reset link to {$this->getRecord()->email}?")
                ->action(function (): void {
                    Password::broker()->sendResetLink(['email' => $this->getRecord()->email]);

                    Notification::make()
                        ->title('Reset email sent')
                        ->body("Password reset link dispatched to {$this->getRecord()->email}.")
                        ->success()
                        ->send();
                }),

            Action::make('toggleEmailVerification')
                ->label(fn (): string => $this->getRecord()->email_verified_at ? 'Revoke verification' : 'Verify email')
                ->icon(fn (): string => $this->getRecord()->email_verified_at ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color(fn (): string => $this->getRecord()->email_verified_at ? 'danger' : 'success')
                ->requiresConfirmation()
                ->action(function (): void {
                    $user = $this->getRecord();
                    $newVerified = $user->email_verified_at === null ? now() : null;
                    $user->forceFill(['email_verified_at' => $newVerified])->save();

                    Notification::make()
                        ->title($newVerified ? 'Email marked as verified' : 'Email verification revoked')
                        ->success()
                        ->send();
                }),

            Action::make('impersonate')
                ->label('Impersonate')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->url(fn (): string => route('impersonate', $this->getRecord()->id))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($u = Auth::user()) instanceof User && $u->hasRole('admin') && $this->getRecord()->canBeImpersonated()),

            Action::make('email')
                ->label('Email user')
                ->icon(Heroicon::Envelope)
                ->color('gray')
                ->url(fn (): string => "mailto:{$this->getRecord()->email}"),

            EditAction::make()
                ->icon(Heroicon::PencilSquare),
        ];
    }
}
