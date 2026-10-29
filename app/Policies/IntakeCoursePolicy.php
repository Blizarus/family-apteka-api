<?php

namespace App\Policies;

use App\Models\IntakeCourse;
use App\Models\Medicine;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class IntakeCoursePolicy
{
    use HandlesAuthorization;

    /**
     * Разрешить просмотр конкретного курса приёма.
     * Защита от IDOR: только владелец профиля.
     */
    public function view(User $user, IntakeCourse $intakeCourse): bool
    {
        return $intakeCourse->profile->user_id === $user->id;
    }

    /**
     * Проверка прав на создание курса приёма.
     * Защита от IDOR:
     * 1) Профиль должен принадлежать текущему пользователю;
     * 2) Препарат должен находиться в аптечке, к которой у пользователя есть доступ.
     */
    public function create(User $user, Profile $profile, Medicine $medicine): bool
    {
        // 1. Профиль должен принадлежать пользователю
        if ($profile->user_id !== $user->id) {
            return false;
        }

        // 2. Аптечка с лекарством должна быть доступна пользователю
        $accessibleBoxIds = $user->medicineBoxes()->pluck('medicine_boxes.id');

        return $accessibleBoxIds->contains($medicine->medicine_box_id);
    }

    /**
     * Разрешить обновление курса приёма.
     */
    public function update(User $user, IntakeCourse $intakeCourse): bool
    {
        return $intakeCourse->profile->user_id === $user->id;
    }

    /**
     * Разрешить удаление курса приёма.
     */
    public function delete(User $user, IntakeCourse $intakeCourse): bool
    {
        return $intakeCourse->profile->user_id === $user->id;
    }
}
