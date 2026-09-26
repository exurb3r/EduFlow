<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TuitionAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TuitionAccount extends Model
{
    /** @use HasFactory<TuitionAccountFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'academic_term_id', 'total_amount', 'paid_amount'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'academic_term_id' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function remainingAmount(): int
    {
        return $this->total_amount - $this->paid_amount;
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }
}
