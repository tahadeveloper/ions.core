<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ions\Database\HasIonsFactory;

/**
 * A file attached to a task. `path` is the stored (disk) path; `original_name`
 * the client filename; `size` the byte count.
 *
 * @property int    $id
 * @property int    $task_id
 * @property string $path
 * @property string $original_name
 * @property int    $size
 */
class Attachment extends Model
{
    use HasIonsFactory;

    protected $table = 'attachments';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'path',
        'original_name',
        'size',
    ];

    /**
     * The task this attachment belongs to.
     *
     * @return BelongsTo<Task, Attachment>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
