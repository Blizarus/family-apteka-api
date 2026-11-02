<?php

namespace App\Policies;

use App\MedicineBoxUserRole;
use App\Models\Medicine;
use App\Models\MedicineBoxUser;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MedicinePolicy
{
    use HandlesAuthorization;

    /**
     * Определение роли пользователя в аптечке, которой принадлежит лекарство.
     */
    protected function getUserRole(User $user, Medicine $medicine): ?MedicineBoxUserRole
    {
        $box = $medicine->medicineBox;
        if (! $box) {
            return null;
        }

        $pivot = MedicineBoxUser::where('medicine_box_id', $box->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $pivot) {
            return null;
        }

        return $pivot->role instanceof MedicineBoxUserRole
            ? $pivot->role
            : MedicineBoxUserRole::tryFrom((string) $pivot->role);
    }

    /**
     * Просмотр лекарства разрешен владельцу, редактору и наблюдателю.
     */
    public function view(User $user, Medicine $medicine): bool
    {
        $role = $this->getUserRole($user, $medicine);

        return in_array($role, [
            MedicineBoxUserRole::OWNER,
            MedicineBoxUserRole::EDITOR,
            MedicineBoxUserRole::VIEWER,
        ], true);
    }

    /**
     * Обновление лекарства разрешено владельцу и редактору.
     */
    public function update(User $user, Medicine $medicine): bool
    {
        $role = $this->getUserRole($user, $medicine);

        return in_array($role, [
            MedicineBoxUserRole::OWNER,
            MedicineBoxUserRole::EDITOR,
        ], true);
    }

    /**
     * Удаление препарата разрешено владельцу и редактору (MedicineBoxUserRole::EDITOR).
     * Наблюдатель (VIEWER) не имеет права удалять медикаменты.
     */
    public function delete(User $user, Medicine $medicine): bool
    {
        $role = $this->getUserRole($user, $medicine);

        return in_array($role, [
            MedicineBoxUserRole::OWNER,
            MedicineBoxUserRole::EDITOR,
        ], true);
    }
}
