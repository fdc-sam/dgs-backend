<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Creates/updates staff accounts, their branch & service assignments and weekly schedules; manages branches. */
class StaffAdminService
{
    public function __construct(
        private readonly FileUploadService $uploads,
        private readonly AuditLogger $audit,
    ) {}

    public function saveStaff(?StaffProfile $staff, array $data, ?UploadedFile $photo): StaffProfile
    {
        return DB::transaction(function () use ($staff, $data, $photo) {
            $user = $staff?->user ?? new User(['role' => UserRole::Staff]);
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Deactivating a profile also blocks admin-portal sign-in.
                'status' => $data['status'] === 'active' ? UserStatus::Active : UserStatus::Inactive,
            ]);
            if (isset($data['account_type'])) {
                $user->is_test = $data['account_type'] === 'test';
            }
            $passwordChanged = $user->exists && ! empty($data['password']);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if ($photo) {
                $user->profile_photo = $this->uploads->storeImage($photo, 'staff', $user->profile_photo);
            }
            $user->save();
            if ($passwordChanged) {
                $user->signOutOtherDevices(); // a password set by an admin ends the staff member's open sessions
            }

            $staff ??= new StaffProfile(['user_id' => $user->id]);
            $original = $staff->getAttributes();
            $staff->fill([
                'position' => $data['position'] ?? null,
                'bio' => $data['bio'] ?? null,
                'employee_code' => $data['employee_code'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'status' => $data['status'],
            ])->save();

            $primary = $data['primary_branch'] ?? $data['branches'][0];
            $staff->branches()->sync(collect($data['branches'])->mapWithKeys(fn ($id) => [$id => ['is_primary' => $id === $primary]])->all());
            $staff->services()->sync($data['services'] ?? []);

            foreach (range(0, 6) as $day) {
                $row = $data['schedule'][$day] ?? [];
                if (empty($row['is_working'])) {
                    $staff->schedules()->where('day_of_week', $day)->delete();

                    continue;
                }
                $staff->schedules()->updateOrCreate(['day_of_week' => $day], [
                    'branch_id' => $row['branch_id'],
                    'is_working' => true,
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'break_start' => $row['break_start'] ?: null,
                    'break_end' => $row['break_end'] ?: null,
                ]);
            }

            $this->audit->changes($staff->wasRecentlyCreated ? 'staff.created' : 'staff.updated', $staff, $original);

            return $staff;
        });
    }

    public function deleteStaff(StaffProfile $staff): void
    {
        DB::transaction(function () use ($staff) {
            $this->audit->log('staff.deleted', $staff, ['name' => $staff->user?->name]);
            $staff->user?->update(['status' => UserStatus::Inactive]);
            $staff->user?->tokens()->delete();
            $staff->delete();
        });
    }

    public function saveBranch(?Branch $branch, array $data, string $slug): Branch
    {
        return DB::transaction(function () use ($branch, $data, $slug) {
            $branch ??= new Branch;
            $original = $branch->getAttributes();
            $firstOpen = collect($data['hours'])->first(fn ($h) => ! empty($h['is_open']));

            $branch->fill([
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'opening_time' => $firstOpen['opening_time'] ?? null,
                'closing_time' => $firstOpen['closing_time'] ?? null,
                'status' => $data['status'],
            ])->save();

            foreach (range(0, 6) as $day) {
                $row = $data['hours'][$day] ?? [];
                $open = ! empty($row['is_open']);
                $branch->businessHours()->updateOrCreate(['day_of_week' => $day], [
                    'is_open' => $open,
                    'opening_time' => $open ? $row['opening_time'] : null,
                    'closing_time' => $open ? $row['closing_time'] : null,
                ]);
            }

            $this->audit->changes($branch->wasRecentlyCreated ? 'branch.created' : 'branch.updated', $branch, $original);

            return $branch;
        });
    }
}
