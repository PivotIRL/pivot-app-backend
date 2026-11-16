<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hobby extends Model
{
    protected $fillable = ['name', 'icon_url', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function users()
    {
        return $this->belongsToMany(
            Users::class,
            'user_hobbies',
            'hobby_id',
            'user_id'
        );
    }
}
