<?php

namespace App\Http\Controllers\Api;

use App\Models\Appreciation;
use App\Models\User;
use App\Support\Files;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends ApiController
{
    private function official(User $u): array
    {
        $u->loadMissing('department', 'designation', 'manager');

        return [
            'name' => $u->name,
            'email' => $u->email,
            'avatar' => $u->avatarUrl(),
            'department_id' => $u->department_id,
            'department' => $u->department?->title ?? '',
            'designation_id' => $u->designation_id,
            'designation' => $u->designation?->title ?? '',
            'employee_type' => (string) $u->employee_type,
            'employee_id' => (string) $u->employee_id,
            'manager_id' => $u->manager_id,
            'manager' => $u->manager?->name ?? '',
            'grade' => (string) $u->grade,
            'joining_date_db' => $u->joining_date?->toIso8601String(),
            'joining_date' => $u->joining_date?->format('d M Y') ?? '',
        ];
    }

    private function personal(User $u): array
    {
        return [
            'gender' => (string) $u->gender,
            'phone' => (string) $u->phone,
            'birth_date_db' => $u->birth_date?->toIso8601String(),
            'birth_date' => $u->birth_date?->format('d M Y') ?? '',
            'address' => (string) $u->address,
            'nationality' => (string) $u->nationality,
            'nid_card_number' => (string) $u->nid_card_number,
            'nid_card_id' => (string) Files::url($u->nid_file),
            'marital_status' => (string) $u->marital_status,
            'blood_group' => (string) $u->blood_group,
            'facebook_link' => (string) $u->facebook_link,
            'linkedin_link' => (string) $u->linkedin_link,
            'instagram_link' => (string) $u->instagram_link,
            'passport_number' => (string) $u->passport_number,
            'passport_file' => (string) Files::url($u->passport_file),
            'passport_expire_date' => $u->passport_expire_date?->format('d M Y') ?? '',
            // Middle-East specific fields kept for app compatibility
            'eid_number' => '', 'eid_file' => '', 'eid_expire_date' => '',
            'visa_number' => '', 'visa_file' => '', 'visa_expire_date' => '',
            'insurance_number' => '', 'insurance_file' => '', 'insurance_expire_date' => '',
            'labour_card_number' => '', 'labour_card_file' => '', 'labour_card_expire_date' => '',
        ];
    }

    private function financial(User $u): array
    {
        return ['tin' => (string) $u->tin, 'bank_name' => (string) $u->bank_name, 'bank_account' => (string) $u->bank_account];
    }

    private function emergency(User $u): array
    {
        return [
            'emergency_name' => (string) $u->emergency_name,
            'emergency_mobile_number' => (string) $u->emergency_mobile_number,
            'emergency_mobile_relationship' => (string) $u->emergency_mobile_relationship,
        ];
    }

    /** POST /user/profile/{official|personal|financial|emergency} {user_id} */
    public function show(Request $request, string $slug)
    {
        $u = $this->targetUser($request);

        return match ($slug) {
            'official' => $this->ok($this->official($u), 'Official info'),
            'personal' => $this->ok($this->personal($u), 'Personal info'),
            'financial' => $this->ok($this->financial($u), 'Financial info'),
            'emergency' => $this->ok($this->emergency($u), 'Emergency info'),
            default => $this->fail('Unknown profile section', 404),
        };
    }

    /** POST /user/profile/update/official — HR/admin can edit anyone; staff can edit own name/email. */
    public function updateOfficial(Request $request)
    {
        $me = $request->user();
        $u = $this->targetUser($request);
        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('users')->ignore($u->id)],
        ]);

        $fields = ['name', 'email'];
        if ($me->is_admin || $me->is_hr) {
            $fields = array_merge($fields, ['department_id', 'designation_id', 'manager_id', 'employee_type', 'employee_id', 'grade']);
        }
        $data = array_filter($request->only($fields), fn ($v) => $v !== null && $v !== '');
        if (($me->is_admin || $me->is_hr) && $request->filled('joining_date')) {
            $data['joining_date'] = Carbon::parse($request->joining_date)->toDateString();
        }
        $u->update($data);
        $o = $this->official($u->fresh());
        unset($o['avatar'], $o['joining_date']);

        return $this->ok($o, 'Official info updated');
    }

    /** POST /user/profile/update/personal (multipart) */
    public function updatePersonal(Request $request)
    {
        $u = $this->targetUser($request);
        $request->validate(['nid_file' => 'nullable|file|max:5120', 'passport_file' => 'nullable|file|max:5120']);
        $data = array_filter($request->only([
            'gender', 'phone', 'address', 'nationality', 'nid_card_number', 'passport_number', 'marital_status',
            'blood_group', 'facebook_link', 'linkedin_link', 'instagram_link',
        ]), fn ($v) => $v !== null);
        if ($request->filled('birth_date')) {
            $data['birth_date'] = Carbon::parse($request->birth_date)->toDateString();
        }
        if ($f = Files::store($request->file('nid_file'), 'documents')) {
            $data['nid_file'] = $f;
        }
        if ($f = Files::store($request->file('passport_file'), 'documents')) {
            $data['passport_file'] = $f;
        }
        $u->update($data);

        $p = $this->personal($u->fresh());

        return $this->ok(array_intersect_key($p, array_flip([
            'gender', 'phone', 'birth_date_db', 'address', 'nationality', 'nid_card_number', 'nid_card_id', 'passport_number',
            'passport_file', 'marital_status', 'blood_group', 'facebook_link', 'linkedin_link', 'instagram_link', 'birth_date',
        ])), 'Personal info updated');
    }

    /** POST /user/profile/update/financial */
    public function updateFinancial(Request $request)
    {
        $u = $this->targetUser($request);
        $u->update(array_filter($request->only('tin', 'bank_name', 'bank_account'), fn ($v) => $v !== null));

        return $this->ok($this->financial($u->fresh()), 'Financial info updated');
    }

    /** POST /user/profile/update/emergency */
    public function updateEmergency(Request $request)
    {
        $request->validate(['emergency_mobile_number' => 'nullable|string|max:20']);
        $u = $this->targetUser($request);
        $u->update(array_filter($request->only('emergency_name', 'emergency_mobile_number', 'emergency_mobile_relationship'), fn ($v) => $v !== null));

        return $this->ok($this->emergency($u->fresh()), 'Emergency info updated');
    }

    /** POST /user/avatar-update (multipart: avatar) */
    public function avatar(Request $request)
    {
        $request->validate(['avatar' => 'required|image|max:4096']);
        $u = $this->targetUser($request);
        $u->update(['avatar' => Files::store($request->file('avatar'), 'avatar')]);

        return $this->ok(['avatar' => $u->avatarUrl()], 'Profile picture updated');
    }

    /** POST /user/profile/appreciates {user_id} */
    public function appreciates(Request $request)
    {
        $id = (int) $request->input('user_id') ?: $request->user()->id;
        $items = Appreciation::with('by')->where('user_id', $id)->latest()->get()->map(fn ($a) => [
            'appreciate_by' => $a->by?->name,
            'message' => $a->message,
            'date' => $a->created_at->format('d M Y'),
            'day' => $a->created_at->format('l'),
        ]);

        return $this->ok(['appreciates' => $items], 'Appreciations');
    }

    /** POST /appreciate/create {appreciate_to, message} */
    public function appreciate(Request $request)
    {
        $request->validate(['appreciate_to' => 'required|exists:users,id', 'message' => 'required|string|max:1000']);
        $me = $request->user();
        if ((int) $request->appreciate_to === $me->id) {
            return $this->fail('You cannot appreciate yourself 🙂', 422);
        }
        Appreciation::create(['user_id' => $request->appreciate_to, 'appreciate_by' => $me->id, 'message' => $request->message]);
        Notifier::send((int) $request->appreciate_to, 'You got appreciated! 🎉', "{$me->name}: {$request->message}", 'appreciate', $me->id);

        return $this->ok(null, 'Appreciation sent');
    }
}
