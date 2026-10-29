<?php

namespace App\Policies;

use App\MedicineBoxUserRole;
use App\Models\MedicineBox;
use App\Models\MedicineBoxUser;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MedicineBoxPolicy
{
    use HandlesAuthorization;

    /**
     * Определение роли пользователя в конкретной аптечке.
     */
    protected function getUserRole(User $user, MedicineBox $box): ?MedicineBoxUserRole
    {
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
     * Разрешить просмотр содержимого аптечки (owner, editor, viewer).
     * Защита от IDOR: неавторизованные пользователи получают 403 Forbidden.
     */
    public function view(User $user, MedicineBox $box): bool
    {
        $role = $this->getUserRole($user, $box);

        return in_array($role, [
            MedicineBoxUserRole::OWNER,
            MedicineBoxUserRole::EDITOR,
            MedicineBoxUserRole::VIEWER,
        ], true);
    }

    /**
     * Разрешить обновление метаданных аптечки (только owner или editor).
     */
    public function update(User $user, MedicineBox $box): bool
    {
        $role = $this->getUserRole($user, $box);

        return in_array($role, [
            MedicineBoxUserRole::OWNER,
            MedicineBoxUserRole::EDITOR,
        ], true);
    }

    /**
     * Разрешить удаление аптечки (исключительно owner).
     */
    public function delete(User $user, MedicineBox $box): bool
    {
        $role = $this->getUserRole($user, $box);

        return $role === MedicineBoxUserRole::OWNER;
    }

    /**
     * Разрешить приглашение новых членов семьи в аптечку.
     */
    public function invite(User $user, MedicineBox $box): bool
    {
        $role = $this->getUserRole($user, $box);

        return $role === MedicineBoxUserRole::OWNER;
    }

    /**
     * Разрешить исключение участников из аптечки (только owner).
     */
    public function removeMember(User $user, MedicineBox $box): bool
    {
        $role = $this->getUserRole($user, $box);

        return $role === MedicineBoxUserRole::OWNER;
    }
}
