<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * ¿Esta fila es de la clínica de quien entró?
     *
     * Es la pareja de las relaciones de arriba: si `leads()` lista por
     * `cuenta_id`, el permiso para abrir o tocar una de esas filas tiene que
     * mirar lo MISMO. Compararla contra `$user->id` —que es lo que hacían los
     * controladores— le devolvía 403 a todo el equipo salvo a la dueña: veían
     * las listas (porque la relación sí iba por cuenta) y no podían abrir ni
     * escribir nada.
     */
    public function esDeSuCuenta(Model $fila): bool
    {
        return (int) $fila->getAttribute('user_id') === (int) $this->cuenta_id;
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

    /**
     * Un usuario por CUENTA, para los procesos automáticos.
     *
     * Los datos no cuelgan de `users.id` sino de `users.cuenta_id`: todos los
     * usuarios de una clínica ven exactamente las mismas conversaciones y
     * citas. Los comandos programados iteraban `User::all()`, así que con dos
     * logins en la misma cuenta —la doctora y su asistente— cada corrida
     * procesaba la misma clínica DOS VECES. No llegaba a duplicar mensajes
     * porque cada envío deja su marca (`reminder_24h_sent_at`,
     * `reactivation_sent_at`, `payment_links.appointment_id`) antes de la
     * segunda pasada, pero duplicaba el trabajo, inflaba los contadores del
     * resumen y, si un envío fallaba en la primera pasada, la segunda lo
     * reintentaba en la misma corrida. Con un tercer login se triplicaba.
     *
     * Se prefiere el propietario activo; si no hay, cualquiera de la cuenta:
     * lo automático pertenece a la clínica, no al login.
     *
     * @return Collection<int,User>
     */
    public static function unoPorCuenta(): Collection
    {
        return static::query()
            ->orderByDesc('activo')
            ->orderByDesc('es_propietario')
            ->orderBy('id')
            ->get()
            ->unique('cuenta_id')
            ->values();
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
