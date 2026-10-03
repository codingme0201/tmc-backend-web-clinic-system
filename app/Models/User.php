<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id', 'status', 'patient_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Roles that use the mobile self-service app (the `/api/me/*` routes)
     * instead of the web clinic system.
     */
    public const PATIENT_ROLES = ['student', 'patient'];

    /**
     * Clinicians who can be assigned to appointments and consultations.
     */
    public const CLINICIAN_ROLES = ['doctor', 'nurse'];

    /**
     * Clinic personnel shown in the medical staff directory.
     */
    public const CLINIC_STAFF_ROLES = ['doctor', 'nurse', 'front_desk'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The role assigned to this user (Module 2 — Roles & Permissions).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function patient(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    public function staffSchedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function assignedAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'staff_id');
    }

    /**
     * Active doctor/nurse accounts that can be assigned to patients.
     */
    public function scopeClinicians(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::CLINICIAN_ROLES));
    }

    public function isClinician(): bool
    {
        return in_array($this->role?->name, self::CLINICIAN_ROLES, true);
    }

    public function createdEvents(): HasMany
    {
        return $this->hasMany(ClinicEvent::class, 'created_by');
    }

    public function unavailableSchedules(): HasMany
    {
        return $this->hasMany(UnavailableSchedule::class, 'created_by');
    }

    /**
     * Whether this user is a student/patient (mobile self-service) account.
     */
    public function isPatientUser(): bool
    {
        return in_array($this->role?->name, self::PATIENT_ROLES, true);
    }

    /**
     * Whether the user's role grants the given permission.
     *
     * This is the single authorization check used by middleware, controllers,
     * and future modules. Permission names follow `module.action` (e.g.
     * 'roles.create', 'appointments.view').
     */
    public function hasPermission(string $permission): bool
    {
        return $this->role?->permissions()->where('name', $permission)->exists() ?? false;
    }
}
