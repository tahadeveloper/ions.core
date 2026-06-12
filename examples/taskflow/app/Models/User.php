<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\VerifiesEmail;
use Ions\Database\HasIonsFactory;

/**
 * A Taskflow user: owns projects, belongs to projects as a member and is the
 * assignee on tasks. Implements the framework auth contracts so it works with
 * JWT/Gate ({@see Authenticatable}) and the email-verification gate
 * ({@see VerifiesEmail}).
 *
 * @property int                    $id
 * @property string                 $name
 * @property string                 $email
 * @property string                 $password
 * @property ?string                $email_verified_at
 * @property ?string                $two_factor_secret
 * @property ?string                $two_factor_recovery_codes
 */
class User extends Model implements Authenticatable, VerifiesEmail
{
    use HasIonsFactory;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * Projects this user owns (one-to-many; Project.owner_id).
     *
     * @return HasMany<Project>
     */
    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    /**
     * Projects this user is a member of (many-to-many via project_members).
     *
     * @return BelongsToMany<Project>
     */
    public function memberProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members', 'user_id', 'project_id')
            ->withTimestamps();
    }

    /**
     * Tasks assigned to this user (one-to-many; Task.assignee_id).
     *
     * @return HasMany<Task>
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    // -----------------------------------------------------------------------
    // Ions\Auth\Contracts\Authenticatable
    // -----------------------------------------------------------------------

    public function getAuthIdentifier(): string|int
    {
        return $this->getKey();
    }

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    // -----------------------------------------------------------------------
    // Ions\Auth\Contracts\VerifiesEmail
    // -----------------------------------------------------------------------

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }

    public function getKeyForVerification(): string|int
    {
        return $this->getKey();
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function getEmailVerifiedAt(): ?DateTimeInterface
    {
        if ($this->email_verified_at === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $this->email_verified_at);
    }
}
