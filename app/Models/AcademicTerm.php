<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AcademicTermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    /** @use HasFactory<AcademicTermFactory> */
    use HasFactory;

    protected $fillable = ['name', 'starts_on', 'ends_on'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function isActive(): bool
    {
        return $this->starts_on !== null
                    && $this->ends_on !== null
                    && $this->starts_on->lte(today())
                    && $this->ends_on->gte(today());
    }
}
