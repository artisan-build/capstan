<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $amp_user_id
 * @property int $user_id
 *
 * @mixin IdeHelperAmpIdentity
 */
final class AmpIdentity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'amp_user_id',
        'user_id',
    ];
}
