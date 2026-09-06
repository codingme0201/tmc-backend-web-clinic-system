<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference', 'patient', 'patient_id', 'consultation_id', 'medical_record_id',
    'issued_by_id', 'requested_by_id', 'approved_by_id', 'rejected_by_id',
    'issued_by', 'requested_by', 'approved_by', 'approved_at', 'rejected_by',
    'rejected_at', 'rejection_reason',
    'purpose', 'diagnosis', 'recommendation', 'issue_date',
    'valid_until', 'status',
])]
class MedicalCertificate extends Model
{
    /**
     * Certificate lifecycle statuses, matching the frontend exactly.
     *
     * A certificate starts as a `Pending` request, is reviewed into either
     * `Approved` or `Rejected`, then `Approved` requests are `Issued`; an
     * issued certificate may later be `Void`ed for audit purposes.
     */
    public const STATUSES = ['Pending', 'Approved', 'Issued', 'Rejected', 'Void'];

    /**
     * Allowed status transitions (state machine — the dedicated workflow
     * endpoints enforce these so the frontend cannot jump to an arbitrary
     * status, and terminal states stay terminal).
     */
    public const TRANSITIONS = [
        'Pending' => ['Approved', 'Rejected'],
        'Approved' => ['Issued'],
        'Issued' => ['Void'],
        'Rejected' => [],
        'Void' => [],
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * The consultation this certificate was generated from, when applicable.
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    /**
     * The medical record this certificate draws patient history from, when applicable.
     */
    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    /**
     * Whether this certificate may move to the given status.
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Next sequential reference for the given issue date, e.g. MC-2026-001.
     * Mirrors Consultation::nextReference.
     */
    public static function nextReference(string $date, bool $lock = false): string
    {
        $year = date('Y', strtotime($date));
        $prefix = "MC-{$year}-";
        $query = static::query()->where('reference', 'like', $prefix.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $max = $query->max('reference');
        $next = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
