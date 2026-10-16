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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Team> $teams
 * @property-read int|null $teams_count
 * @method static \Database\Factories\ArtifactFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereContentHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereContentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereStorageKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Artifact whereVisibility($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperArtifact {}
}

namespace App\Models{
/**
 * @property string $id
 * @property BrandAssetKind $kind
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property string $created_by_user_id
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereContentHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereContentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereCreatedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereStorageKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperBrandAsset {}
}

namespace App\Models{
/**
 * @property string $installation_key
 * @property string|null $current_version_id
 * @property int $current_version_number
 * @property array<string, mixed>|null $draft
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BrandVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereCurrentVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereCurrentVersionNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereDraft($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereInstallationKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperBrandKit {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $brand_kit_key
 * @property int $version_number
 * @property array<string, mixed> $configuration
 * @property string $applied_by_user_id
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereAppliedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereBrandKitKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereConfiguration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion whereVersionNumber($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperBrandVersion {}
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereAckedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereRefs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereSignature($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereSigningKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereToAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereToLocalPart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereToServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Envelope whereVersion($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox whereLocalPart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inbox whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperInbox {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $owner_user_id
 * @property string $title
 * @property string|null $description
 * @property string $refresh_recipe
 * @property PageLayout $layout
 * @property string|null $latest_version_id
 * @property int $version_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PageVersion|null $latestVersion
 * @property-read Collection<int, PageVersion> $versions
 * @property-read Collection<int, PageShare> $shares
 * @property-read int|null $shares_count
 * @property-read int|null $versions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereLatestVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereLayout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereOwnerUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereRefreshRecipe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Page whereVersionCount($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPage {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $page_id
 * @property string $created_by_user_id
 * @property PageShareKind $kind
 * @property string|null $internal_recipient_user_id
 * @property string|null $recipient_email
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Page $page
 * @property-read User|null $internalRecipient
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereCreatedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereInternalRecipientUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereRecipientEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPageShare {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $page_id
 * @property int $version_number
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property string $publisher_user_id
 * @property string $publisher_source
 * @property string $publisher_tool
 * @property string $refresh_recipe
 * @property PageLayout $layout
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Page $page
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereContentHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereContentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereLayout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion wherePublisherSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion wherePublisherTool($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion wherePublisherUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereRefreshRecipe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereStorageKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageVersion whereVersionNumber($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPageVersion {}
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereLastCursor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereLastPolledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereProbeFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereProbeStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereUpdatedAt($value)
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
 * @property-read \App\Models\Spoke $spoke
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereProbeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereRespondedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpokeProbe whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSpokeProbe {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Artifact> $artifacts
 * @property-read int|null $artifacts_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeam {}
}
