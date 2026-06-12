<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ions\Database\HasIonsFactory;

/**
 * A task within a project, optionally assigned to a user. `status` is one of
 * the {@see Task::STATUSES} values (todo|doing|done).
 *
 * @property int     $id
 * @property int     $project_id
 * @property ?int    $assignee_id
 * @property string  $title
 * @property ?string $description
 * @property string  $status
 */
class Task extends Model
{
    use HasIonsFactory;

    public const STATUS_TODO = 'todo';
    public const STATUS_DOING = 'doing';
    public const STATUS_DONE = 'done';

    /** @var list<string> The allowed status values. */
    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_DOING,
        self::STATUS_DONE,
    ];

    protected $table = 'tasks';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'assignee_id',
        'title',
        'description',
        'status',
    ];

    /** @var array<string, string> */
    protected $attributes = [
        'status' => self::STATUS_TODO,
    ];

    /**
     * The project this task belongs to.
     *
     * @return BelongsTo<Project, Task>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * The user this task is assigned to (nullable).
     *
     * @return BelongsTo<User, Task>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * The task's file attachments.
     *
     * @return HasMany<Attachment>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'task_id');
    }

    /**
     * The task's comments.
     *
     * @return HasMany<Comment>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id');
    }
}
