<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'cuenta_id',
        'es_propietario',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'es_propietario' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Quien se crea sin clínica es dueño de la suya.
        //
        // Va DESPUÉS de insertar porque el id no existe antes, y va aquí y no
        // en cada sitio que crea usuarios para que ninguno tenga que acordarse:
        // el registro, los seeders, las factories y las pruebas siguen escritos
        // igual y todos quedan bien.
        static::created(function (self $user) {
            if ($user->cuenta_id === null) {
                $user->forceFill([
                    'cuenta_id' => $user->id,
                    'es_propietario' => true,
                ])->saveQuietly();
            }
        });
    }

    /**
     * La clínica a la que pertenece esta persona.
     *
     * Los datos NO se movieron: cada fila conserva su `user_id` apuntando a la
     * doctora, y `cuenta_id` dice de qué clínica es cada usuario. Por eso las
     * relaciones de abajo leen por `cuenta_id` y no por `id`: así una consulta
     * escrita como `$request->user()->leads()` devuelve las pacientes de la
     * CLÍNICA, sea quien sea el que entró.
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cuenta_id');
    }

    /** Las personas que trabajan en esta clínica, incluida la dueña. */
    public function equipo(): HasMany
    {
        return $this->hasMany(self::class, 'cuenta_id', 'cuenta_id');
    }

    /** Solo la dueña administra el equipo: dar y quitar accesos es cosa suya. */
    public function puedeAdministrarEquipo(): bool
    {
        return (bool) $this->es_propietario;
    }

    /**
     * Los servicios estéticos creados por la doctora.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Stage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<KnowledgeEntry, $this> */
    public function knowledgeEntries(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'user_id', 'cuenta_id');
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'user_id', 'cuenta_id');
    }
}
