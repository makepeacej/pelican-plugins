<?php

namespace ThemeSwitcher\Models;

use Illuminate\Database\Eloquent\Model;

class ThemePreference extends Model
{
    protected $fillable = ['user_id', 'theme_id'];
}
