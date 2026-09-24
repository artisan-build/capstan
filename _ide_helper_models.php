<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property string $id
 * @property string $actor_id
 * @property ArtifactVisibility $visibility
 * @property Carbon|null $expires_at
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Team> $teams
 * @property-read int|null $teams_count
 * @method static \Database\Factories\ArtifactFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperArtifact {}
}

namespace App\Models{
/**
 * @property string $id
 * @property MessageType $type
 * @property int $version
 * @property string $from_address
 * @property string $to_address
 * @property string $to_local_part
 * @property string $to_server_id
 * @property array<int, mixed>|stdClass $body
 * @property array<array-key, mixed> $refs
 * @property string $message_id
 * @property string $signature
 * @property string $signing_key_id
 * @property MessageStatus $status
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $acked_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperEnvelope {}
}

namespace App\Models{
/**
 * A claimed local part. Exactly one user owns a local part; any number of that
 * user's spokes may route for it (a pool). Ownership persists after every spoke
 * stops advertising the inbox.
 *
 * @property int $id
 * @property string $actor_id
 * @property string $local_part
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Spoke> $spokes
 * @property-read int|null $spokes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperInbox {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $actor_id
 * @property string $credential_id
 * @property string|null $name
 * @property CarbonImmutable|null $last_polled_at
 * @property string|null $last_cursor
 * @property SpokeLiveness $probe_status
 * @property CarbonImmutable|null $probe_failed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $inboxes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Inbox> $inboxes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SpokeProbe> $probes
 * @property-read int|null $probes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSpoke {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $spoke_id
 * @property string $probe_id
 * @property string $nonce
 * @property ProbeStatus $status
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read \App\Models\Spoke|null $spoke
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSpokeProbe {}
}

namespace App\Models{
/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Artifact> $artifacts
 * @property-read int|null $artifacts_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeam {}
}
