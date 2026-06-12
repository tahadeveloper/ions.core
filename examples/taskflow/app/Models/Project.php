<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ions\Database\HasIonsFactory;
use Ions\Security\DecryptException;
use Ions\Security\Encrypter;

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

    // -----------------------------------------------------------------------
    // Encrypted note (13.6)
    //
    // `note` is encrypted AT REST via the framework Encrypter (app('encrypter'),
    // XChaCha20-Poly1305 over a key derived from APP_KEY). The controller calls
    // encryptNote() on write and noteText() on read, so the DB column only ever
    // holds ciphertext and the plaintext is shown only to authorized users.
    // -----------------------------------------------------------------------

    /**
     * Encrypt a plaintext note for storage (null/empty stays null).
     */
    public static function encryptNote(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        /** @var Encrypter $encrypter */
        $encrypter = app('encrypter');

        return $encrypter->encrypt($plaintext);
    }

    /**
     * Decrypt the stored note for display. Returns null when there is no note,
     * and degrades gracefully (null) on a DecryptException — e.g. a value that
     * predates encryption or was written under a rotated key — rather than
     * blowing up the page.
     */
    public function noteText(): ?string
    {
        $stored = $this->note;
        if ($stored === null || $stored === '') {
            return null;
        }

        /** @var Encrypter $encrypter */
        $encrypter = app('encrypter');

        try {
            return $encrypter->decrypt((string) $stored);
        } catch (DecryptException) {
            return null;
        }
    }

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
