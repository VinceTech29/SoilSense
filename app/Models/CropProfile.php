<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CropProfile extends Model
{
    use HasFactory;

    protected $table = 'crop_profiles';

    protected $fillable = [
        'name',
        'scientific_name',
        'category',
        'description',
        'image_url',
    ];

    public function requirements(): HasOne
    {
        return $this->hasOne(CropRequirement::class, 'crop_profile_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AssessmentCropEvaluation::class, 'crop_profile_id');
    }
}
