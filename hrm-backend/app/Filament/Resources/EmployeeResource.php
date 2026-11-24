<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Employees';
    protected static ?string $modelLabel = 'employee';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone', 'employee_id'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make()->columnSpanFull()->persistTabInQueryString()->tabs([
                Forms\Components\Tabs\Tab::make('Official')->icon('heroicon-o-briefcase')->columns(3)->schema([
                    Forms\Components\FileUpload::make('avatar')->image()->avatar()->disk('public')->directory('avatar')->columnSpanFull(),
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('phone')->tel()->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('employee_id')->label('Employee ID')->unique(ignoreRecord: true),
                    Forms\Components\Select::make('department_id')->relationship('department', 'title')->searchable()->preload()
                        ->createOptionForm([Forms\Components\TextInput::make('title')->required()]),
                    Forms\Components\Select::make('designation_id')->relationship('designation', 'title')->searchable()->preload()
                        ->createOptionForm([Forms\Components\TextInput::make('title')->required()]),
                    Forms\Components\Select::make('manager_id')->label('Reports to (manager)')->relationship('manager', 'name', fn ($query, $record) => $query->when($record, fn ($q) => $q->where('id', '!=', $record->id)))->searchable()->preload(),
                    Forms\Components\Select::make('employee_type')->options(array_combine($t = ['Permanent', 'Probation', 'Contractual', 'Intern', 'Part Time'], $t)),
                    Forms\Components\TextInput::make('grade'),
                    Forms\Components\DatePicker::make('joining_date')->native(false),
                    Forms\Components\TextInput::make('basic_salary')->numeric()->prefix(fn () => \App\Models\Setting::get('currency_code', 'BDT'))->default(0),
                ]),
                Forms\Components\Tabs\Tab::make('Personal')->icon('heroicon-o-user')->columns(3)->schema([
                    Forms\Components\Select::make('gender')->options(['Male' => 'Male', 'Female' => 'Female', 'Other' => 'Other']),
                    Forms\Components\DatePicker::make('birth_date')->native(false),
                    Forms\Components\Select::make('blood_group')->options(array_combine($b = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], $b)),
                    Forms\Components\Select::make('marital_status')->options(['Single' => 'Single', 'Married' => 'Married']),
                    Forms\Components\TextInput::make('nationality')->default('Bangladeshi'),
                    Forms\Components\TextInput::make('nid_card_number')->label('NID number'),
                    Forms\Components\TextInput::make('passport_number'),
                    Forms\Components\DatePicker::make('passport_expire_date')->native(false),
                    Forms\Components\Textarea::make('address')->rows(2)->columnSpanFull(),
                    Forms\Components\FileUpload::make('nid_file')->label('NID copy')->disk('public')->directory('documents'),
                    Forms\Components\FileUpload::make('passport_file')->label('Passport copy')->disk('public')->directory('documents'),
                    Forms\Components\TextInput::make('facebook_link')->url(),
                    Forms\Components\TextInput::make('linkedin_link')->url(),
                    Forms\Components\TextInput::make('instagram_link')->url(),
                ]),
                Forms\Components\Tabs\Tab::make('Emergency & bank')->icon('heroicon-o-phone')->columns(3)->schema([
                    Forms\Components\TextInput::make('emergency_name'),
                    Forms\Components\TextInput::make('emergency_mobile_number')->tel(),
                    Forms\Components\TextInput::make('emergency_mobile_relationship')->label('Relationship'),
                    Forms\Components\TextInput::make('bank_name'),
                    Forms\Components\TextInput::make('bank_account'),
                    Forms\Components\TextInput::make('tin')->label('TIN'),
                ]),
                Forms\Components\Tabs\Tab::make('App access')->icon('heroicon-o-device-phone-mobile')->columns(3)->schema([
                    Forms\Components\TextInput::make('password')->password()->revealable()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText('Leave empty to keep the current password.'),
                    Forms\Components\Toggle::make('is_active')->label('Active (can log in)')->default(true)->inline(false),
                    Forms\Components\Toggle::make('is_hr')->label('HR (admin panel + approvals)')->inline(false),
                    Forms\Components\Toggle::make('is_admin')->label('Admin')->inline(false),
                    Forms\Components\Placeholder::make('device_id')->label('Registered phone')
                        ->content(fn (?User $record) => $record?->device_id ?: 'Not logged in yet'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')->label('')->circular()->getStateUsing(fn (User $r) => $r->avatarUrl()),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->description(fn (User $r) => $r->email),
                Tables\Columns\TextColumn::make('employee_id')->label('ID')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('department.title')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('designation.title')->toggleable(),
                Tables\Columns\TextColumn::make('phone')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('manager.name')->label('Manager')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('role')->badge()
                    ->getStateUsing(fn (User $r) => $r->is_admin ? 'Admin' : ($r->is_hr ? 'HR' : ($r->team()->exists() ? 'Manager' : 'Staff')))
                    ->color(fn ($state) => match ($state) { 'Admin' => 'danger', 'HR' => 'warning', 'Manager' => 'info', default => 'gray' }),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('department')->relationship('department', 'title'),
                Tables\Filters\SelectFilter::make('designation')->relationship('designation', 'title'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('resetDevice')->label('Reset phone binding')->icon('heroicon-o-device-phone-mobile')
                        ->requiresConfirmation()->visible(fn (User $r) => filled($r->device_id))
                        ->action(function (User $r) {
                            $r->forceFill(['device_id' => null])->save();
                            Notification::make()->title('Phone binding cleared')->success()->send();
                        }),
                    Tables\Actions\Action::make('logoutEverywhere')->label('Log out from app')->icon('heroicon-o-arrow-left-on-rectangle')
                        ->requiresConfirmation()
                        ->action(function (User $r) {
                            $r->tokens()->delete();
                            Notification::make()->title('All app sessions revoked')->success()->send();
                        }),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
