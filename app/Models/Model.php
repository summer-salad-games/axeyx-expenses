<?php

declare(strict_types=1);

namespace App\Models;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = ['*'];
}
