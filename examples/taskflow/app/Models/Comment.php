<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ions\Database\HasIonsFactory;

/**
 * A comment authored by a user on a task.
 *
 * @property int    $id
 * @property int    $task_id
 * @property int    $author_id
 * @property string $body
 */
class Comment extends Model
{
    use HasIonsFactory;

    protected $table = 'comments';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'author_id',
        'body',
    ];

    /**
     * The task this comment belongs to.
     *
     * @return BelongsTo<Task, Comment>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * The user who wrote this comment.
     *
     * @return BelongsTo<User, Comment>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
