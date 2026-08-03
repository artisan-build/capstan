<?php

namespace App\Actions;

use App\Models\Invitation;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationAccess
{
    public function canView(?string $code): bool
    {
        if (! User::query()->exists()) {
            return true;
        }

        return is_string($code)
            && $code !== ''
            && Invitation::query()->where('code', $code)->unused()->exists();
    }

    public function ensureCanView(?string $code): void
    {
        if (! $this->canView($code)) {
            throw new HttpException(403, 'A valid invitation code is required to register.');
        }
    }
}
