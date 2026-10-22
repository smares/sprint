<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/**
 * One setting of the installation by its key; the value is JSON.
 *
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['key', 'value'])]
#[WithoutIncrementing]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
