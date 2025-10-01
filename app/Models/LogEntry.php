<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEntry extends Model
{
    protected $fillable = ['level', 'message', 'context'];

    // Мутатор для поля level
    public function setLevelAttribute($value)
    {
        $this->attributes['level'] = strtoupper($value);
    }

    public $timestamps = true; // Если вы хотите автоматически сохранять время создания записи

}
