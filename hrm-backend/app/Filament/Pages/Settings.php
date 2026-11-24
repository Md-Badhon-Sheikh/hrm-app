<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Company settings';
    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    private const BOOLS = ['require_late_reason', 'require_early_reason', 'multi_checkin', 'location_service', 'ip_restriction', 'single_device_login'];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin || (bool) auth()->user()?->is_hr;
    }

    public function mount(): void
    {
        $values = Setting::pluck('value', 'key')->all();
        foreach (self::BOOLS as $b) {
            $values[$b] = ($values[$b] ?? '0') === '1';
        }
        $values['weekends'] = array_filter(explode(',', $values['weekends'] ?? 'Friday'));
        $values['hidden_menus'] = array_filter(explode(',', $values['hidden_menus'] ?? ''));
        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        $days = array_combine($d = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $d);

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Company')->columns(3)->schema([
                Forms\Components\TextInput::make('company_name')->required(),
                Forms\Components\TextInput::make('currency_code')->required()->maxLength(5),
                Forms\Components\Select::make('app_theme')->options(['app_theme_1' => 'Theme 1', 'app_theme_2' => 'Theme 2', 'app_theme_3' => 'Theme 3']),
            ]),
            Forms\Components\Section::make('Office time & attendance')->columns(3)->schema([
                Forms\Components\TimePicker::make('office_start')->seconds(false)->required(),
                Forms\Components\TimePicker::make('office_end')->seconds(false)->required(),
                Forms\Components\CheckboxList::make('weekends')->options($days)->columns(4)->columnSpanFull(),
                Forms\Components\TextInput::make('late_grace_minutes')->numeric()->suffix('min')->helperText('Check-in after start + this = late'),
                Forms\Components\TextInput::make('early_grace_minutes')->numeric()->suffix('min'),
                Forms\Components\Toggle::make('multi_checkin')->label('Allow multiple check-in per day')->inline(false),
                Forms\Components\Toggle::make('require_late_reason')->label('Ask reason for late check-in')->inline(false),
                Forms\Components\Toggle::make('require_early_reason')->label('Ask reason for early check-out')->inline(false),
                Forms\Components\Toggle::make('location_service')->label('Use location')->inline(false),
            ]),
            Forms\Components\Section::make('Security')->columns(3)->schema([
                Forms\Components\Toggle::make('single_device_login')->label('One phone per employee')->inline(false)
                    ->helperText('Reset a phone from Employees → ⋮ → Reset phone binding.'),
                Forms\Components\Toggle::make('ip_restriction')->label('Office IP only')->inline(false)->live(),
                Forms\Components\TextInput::make('allowed_ips')->label('Allowed IPs (comma separated)')->visible(fn (Forms\Get $get) => $get('ip_restriction')),
            ]),
            Forms\Components\Section::make('Mobile app')->columns(3)->schema([
                Forms\Components\TextInput::make('app_sync_time')->label('Live tracking sync')->numeric()->suffix('min'),
                Forms\Components\TextInput::make('live_data_store_time')->label('Live location save')->numeric()->suffix('min'),
                Forms\Components\TextInput::make('barikoi_key')->label('Barikoi API key (optional)'),
                Forms\Components\CheckboxList::make('hidden_menus')->label('Hide menu items in the app')->columns(4)->columnSpanFull()->options([
                    'attendance' => 'Attendance', 'break' => 'Break', 'leave' => 'Leave', 'daily-leave' => 'Daily leave', 'approval' => 'Approval',
                    'visit' => 'Visit', 'meeting' => 'Meeting', 'appointments' => 'Appointment', 'video_conference' => 'Video conference',
                    'notice' => 'Notice', 'phonebook' => 'Phonebook', 'support' => 'Support', 'expense' => 'Expense', 'payroll' => 'Payroll',
                    'task' => 'Task', 'report' => 'Report',
                ]),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = implode(',', $value);
            } elseif (in_array($key, ['office_start', 'office_end'], true) && $value) {
                $value = substr($value, 0, 5);
            }
            Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
            cache()->forget("setting.$key");
        }
        Notification::make()->title('Settings saved')->success()->send();
    }
}
