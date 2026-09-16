<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('educations')]
#[Fillable([
    'institution',
    'degree',
    'field_of_study',
    'start_date',
    'end_date',
    'is_current',
    'description',
])]
class Education extends MasterProfileEntry {}
