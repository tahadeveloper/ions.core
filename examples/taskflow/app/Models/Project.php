<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ions\Database\HasIonsFactory;

/**
 * A project owned by a user, holding tasks and a member roster.
 *
 * `note` is the column that will hold an at-rest-encrypted value (encryption
 * wiring lands in 13.6 — here it is just a nullable text column). `share_token`
 * backs the public/shared board link added in 13.6.
 *
 * @property int     $id
 * @property int     $owner_id
 * @property string  $name
 * @property ?string $note
 * @property ?string $share_token
 */
class Project extends Model
{
    use HasIonsFactory;

    protected $table = 'projects';

    /** @var list<string> */
    protected $fillable = [
        'owner_id',
        'name',
        'note',
        'share_token',
    ];

    /**
     * The user who owns this project.
     *
     * @return BelongsTo<User, Project>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The project's tasks.
     *
     * @return HasMany<Task>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    /**
     * The project's members (many-to-many via project_members).
     *
     * @return BelongsToMany<User>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members', 'project_id', 'user_id')
            ->withTimestamps();
    }
}
