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
 * @property int $id
 * @property int $spoke_id
 * @property string $finding_code
 * @property bool $active
 * @property string|null $transition_key
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property-read Spoke $spoke
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereFindingCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereOpenedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereTransitionKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineConditionState whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineConditionState {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $spoke_id
 * @property int $team_id
 * @property bool $enabled
 * @property list<string> $roles
 * @property list<string> $capabilities
 * @property array<string, string> $role_providers
 * @property int $concurrency_ceiling
 * @property string $updated_by_actor
 * @property-read Spoke $spoke
 * @property-read Team $team
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereCapabilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereConcurrencyCeiling($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereRoleProviders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereRoles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting whereUpdatedByActor($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineTeamWorkSetting {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $machine_usage_report_id
 * @property string $provider
 * @property string|null $plan
 * @property CarbonImmutable $fetched_at
 * @property bool|null $source_stale
 * @property CarbonImmutable|null $expires_at
 * @property string $status
 * @property-read MachineUsageReport $report
 * @property-read Collection<int, MachineUsageWindow> $windows
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read int|null $windows_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereMachineUsageReportId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider wherePlan($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereSourceStale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineUsageProvider {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $spoke_id
 * @property string $source
 * @property string|null $source_version
 * @property string $availability
 * @property CarbonImmutable|null $collected_at
 * @property CarbonImmutable $last_attempt_at
 * @property CarbonImmutable|null $last_success_received_at
 * @property CarbonImmutable|null $unavailable_since
 * @property string|null $failure_code
 * @property-read Spoke $spoke
 * @property-read Collection<int, MachineUsageProvider> $providers
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read int|null $providers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereAvailability($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereCollectedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereFailureCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereLastAttemptAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereLastSuccessReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereSourceVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereUnavailableSince($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineUsageReport {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $machine_usage_provider_id
 * @property string $slot
 * @property string $kind
 * @property int|null $window_seconds
 * @property string $remaining_percent
 * @property CarbonImmutable|null $resets_at
 * @property string $window_identity
 * @property-read MachineUsageProvider $provider
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereMachineUsageProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereRemainingPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereResetsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereSlot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereWindowIdentity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow whereWindowSeconds($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineUsageWindow {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $spoke_id
 * @property int $team_id
 * @property list<string> $roles
 * @property list<string> $capabilities
 * @property array<string, string> $role_providers
 * @property int $current_claimed_item_count
 * @property int $available_capacity
 * @property int $concurrency_ceiling
 * @property CarbonImmutable $observed_at
 * @property-read Spoke $spoke
 * @property-read Team $team
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereAvailableCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereCapabilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereConcurrencyCeiling($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereCurrentClaimedItemCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereObservedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereRoleProviders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereRoles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineWorkPresence {}
}

namespace App\Models{
/**
 * @property string $id
 * @property int $spoke_id
 * @property int $team_id
 * @property bool|null $old_enabled
 * @property list<string>|null $old_roles
 * @property list<string>|null $old_capabilities
 * @property array<string, string>|null $old_role_providers
 * @property int|null $old_concurrency_ceiling
 * @property bool $new_enabled
 * @property list<string> $new_roles
 * @property list<string> $new_capabilities
 * @property array<string, string> $new_role_providers
 * @property int $new_concurrency_ceiling
 * @property string $updated_by_actor
 * @property CarbonImmutable $occurred_at
 * @property-read Spoke $spoke
 * @property-read Team $team
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereNewCapabilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereNewConcurrencyCeiling($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereNewEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereNewRoleProviders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereNewRoles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOldCapabilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOldConcurrencyCeiling($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOldEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOldRoleProviders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereOldRoles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkSettingChange whereUpdatedByActor($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMachineWorkSettingChange {}
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
 * @property bool $credential_backed
 * @property string|null $name
 * @property string|null $device_name
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $inactive_at
 * @property CarbonImmutable|null $orphaned_at
 * @property CarbonImmutable|null $work_heartbeat_notified_at
 * @property string|null $last_cursor
 * @property SpokeLiveness $probe_status
 * @property CarbonImmutable|null $probe_failed_at
 * @property CarbonImmutable|null $retired_at
 * @property string|null $retired_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $inboxes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MachineConditionState> $conditionStates
 * @property-read int|null $condition_states_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Inbox> $inboxes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SpokeProbe> $probes
 * @property-read int|null $probes_count
 * @property-read \App\Models\MachineUsageReport|null $usageReport
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MachineWorkPresence> $workPresence
 * @property-read int|null $work_presence_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MachineWorkSettingChange> $workSettingChanges
 * @property-read int|null $work_setting_changes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MachineTeamWorkSetting> $workSettings
 * @property-read int|null $work_settings_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereCredentialBacked($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereDeviceName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereInactiveAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereLastCursor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereLastPolledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereOrphanedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereProbeFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereProbeStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereRetiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereRetiredBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke whereWorkHeartbeatNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Spoke workEligible()
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
 * @property int $work_preemption_revision
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property bool $pool_work
 * @property bool $default_failover
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Artifact> $artifacts
 * @property-read int|null $artifacts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkTask> $workTasks
 * @property-read int|null $work_tasks_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereDefaultFailover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereIsDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team wherePoolWork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereWorkPreemptionRevision($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeam {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $team_id
 * @property string $actor
 * @property string $surface
 * @property bool $old_pool_work
 * @property bool $new_pool_work
 * @property CarbonImmutable $created_at
 * @property-read Team $team
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereNewPoolWork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereOldPoolWork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereSurface($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamWorkPolicyChange whereTeamId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeamWorkPolicyChange {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property int $sequence
 * @property string $content
 * @property string $author_actor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereAuthorActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkBriefRevision whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkBriefRevision {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_item_id
 * @property int $claimant_spoke_id
 * @property int $fence
 * @property int $sequence
 * @property string $kind
 * @property string $reason
 * @property array<string, mixed> $payload
 * @property string $status
 * @property array<string, mixed>|null $outcome
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $completed_at
 * @property-read WorkItem $workItem
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereClaimantSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereOutcome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkDirective whereWorkItemId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkDirective {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property string $request_key
 * @property string $addressed_actor
 * @property string $question
 * @property array<string, mixed> $metadata
 * @property string|null $answer
 * @property string|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $notified_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\WorkItem $workItem
 * @property-read \App\Models\WorkTask $workTask
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereAddressedActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereAnswer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereAnsweredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereAnsweredBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereQuestion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereRequestKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereWorkItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkEscalation {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property string $public_token
 * @property string $storage_key
 * @property string $content_type
 * @property string $extension
 * @property int $size_bytes
 * @property CarbonImmutable $created_at
 * @property-read WorkTask $workTask
 * @property-read WorkItem $workItem
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereContentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereExtension($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence wherePublicToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereStorageKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereWorkItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEvidence whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkEvidence {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $role
 * @property WorkItemState $state
 * @property int|null $claimant_spoke_id
 * @property string|null $claimant_actor
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $handed_off_at
 * @property string|null $handed_off_session_ref
 * @property string|null $launch_nonce
 * @property CarbonImmutable|null $first_fetched_at
 * @property string|null $agent_model
 * @property int $fence
 * @property int $attempts
 * @property CarbonImmutable|null $release_requested_at
 * @property CarbonImmutable|null $release_deadline
 * @property string|null $release_for_item_id
 * @property int|null $turn_cursor
 * @property int|null $turn_upper_bound
 * @property int|null $seat_fence
 * @property string|null $addressed_actor
 * @property string|null $fetched_brief_revision_id
 * @property string|null $fetched_page_version_id
 * @property string|null $last_progress
 * @property CarbonImmutable|null $stalled_at
 * @property CarbonImmutable|null $waiting_on_host_at
 * @property CarbonImmutable|null $waiting_on_host_notified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read Spoke|null $claimantSpoke
 * @property-read WorkBriefRevision|null $fetchedBriefRevision
 * @property-read Collection<int, WorkItemTransition> $transitions
 * @property-read Collection<int, WorkMilestone> $milestones
 * @property-read Collection<int, WorkEvidence> $evidence
 * @property-read Collection<int, WorkDirective> $directives
 * @property-read int|null $directives_count
 * @property-read int|null $evidence_count
 * @property-read int|null $milestones_count
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereAddressedActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereAgentModel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereClaimantActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereClaimantSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereClaimedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereFetchedBriefRevisionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereFetchedPageVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereFirstFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereHandedOffAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereHandedOffSessionRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereLastProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereLaunchNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereLeaseUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereReleaseDeadline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereReleaseForItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereReleaseRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereSeatFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereStalledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereTurnCursor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereTurnUpperBound($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereWaitingOnHostAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereWaitingOnHostNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkItem {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_item_id
 * @property WorkItemState $from_state
 * @property WorkItemState $to_state
 * @property string $actor_kind
 * @property string $actor_id
 * @property int $fence
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkItem $workItem
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereActorKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereFromState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereToState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItemTransition whereWorkItemId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkItemTransition {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_item_id
 * @property int $fence
 * @property WorkMilestoneKind $kind
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 * @property-read WorkItem $workItem
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone whereFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkMilestone whereWorkItemId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkMilestone {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $triggering_event_id
 * @property string $decision
 * @property string $rationale
 * @property array<string, mixed> $evidence
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereDecision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereEvidence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereRationale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereTriggeringEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkOrchestrationDecision {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property int $sequence
 * @property string $source_key
 * @property string $kind
 * @property array<string, mixed> $evidence
 * @property string|null $author_task_id
 * @property bool $wakes
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereAuthorTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereEvidence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereSourceKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereWakes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkOrchestrationEvent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string|null $holder_actor
 * @property int|null $holder_spoke_id
 * @property string|null $session_ref
 * @property bool $interactive
 * @property CarbonImmutable|null $lease_until
 * @property int $fence
 * @property bool $failover
 * @property CarbonImmutable|null $waiting_at
 * @property CarbonImmutable|null $notified_at
 * @property string|null $active_item_id
 * @property int $event_sequence
 * @property int $completed_cursor
 * @property int $turns_completed
 * @property int $turns_started
 * @property int $children_created
 * @property CarbonImmutable|null $stopped_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereActiveItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereChildrenCreated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereCompletedCursor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereEventSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereFailover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereFence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereHolderActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereHolderSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereInteractive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereLeaseUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereSessionRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereStoppedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereTurnsCompleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereTurnsStarted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereWaitingAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkOrchestratorSeat {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $work_item_id
 * @property int $sequence
 * @property int $through_cursor
 * @property string $content
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereThroughCursor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereWorkItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkStateRevision {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string|null $work_item_id
 * @property string $text
 * @property string $author_actor
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WorkTask $workTask
 * @property-read WorkItem|null $workItem
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereAuthorActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereWorkItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkSteer whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkSteer {}
}

namespace App\Models{
/**
 * @property string $id
 * @property int $team_id
 * @property string $title
 * @property string|null $user_actor_address
 * @property int|null $device_spoke_id
 * @property bool $failover
 * @property list<string> $required_capabilities
 * @property int $timebox_minutes
 * @property string $creator_actor
 * @property string $owner_actor
 * @property string|null $escalation_actor
 * @property WorkPriority $priority
 * @property int $dependency_version
 * @property bool $routing_pool_work
 * @property CarbonImmutable|null $expires_at
 * @property array{repo: string, number: int, url: string}|null $pr
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $parent_task_id
 * @property string|null $workflow_ref
 * @property string|null $workflow_sha
 * @property string|null $brief_page_id
 * @property string|null $brief_page_version_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Team $team
 * @property-read Spoke|null $deviceSpoke
 * @property-read WorkTask|null $parent
 * @property-read Collection<int, WorkTask> $children
 * @property-read Collection<int, WorkTask> $dependencies
 * @property-read Collection<int, WorkTask> $dependents
 * @property-read WorkTaskExpiration|null $expiration
 * @property-read Collection<int, WorkBriefRevision> $briefRevisions
 * @property-read WorkBriefRevision|null $latestBriefRevision
 * @property-read Collection<int, WorkItem> $items
 * @property-read Collection<int, WorkMilestone> $milestones
 * @property-read Collection<int, WorkEvidence> $evidence
 * @property-read Collection<int, WorkSteer> $steers
 * @property-read int|null $brief_revisions_count
 * @property-read int|null $children_count
 * @property-read int|null $dependencies_count
 * @property-read int|null $dependents_count
 * @property-read int|null $evidence_count
 * @property-read int|null $items_count
 * @property-read int|null $milestones_count
 * @property-read int|null $steers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereBriefPageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereBriefPageVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereCancelledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereCreatorActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereDependencyVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereDeviceSpokeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereEscalationActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereFailover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereOwnerActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereParentTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask wherePr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereRequiredCapabilities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereRoutingPoolWork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereTimeboxMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereUserActorAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereWorkflowRef($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask whereWorkflowSha($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkTask {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $owner_actor
 * @property WorkPriority $priority
 * @property int $waited_seconds
 * @property WorkExpirationProvenance $provenance
 * @property WorkExpirationCause $cause
 * @property CarbonImmutable $expired_at
 * @property CarbonImmutable $created_at
 * @property-read WorkTask $workTask
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereCause($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereExpiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereOwnerActor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereProvenance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereWaitedSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTaskExpiration whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkTaskExpiration {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $request_key
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $fired_at
 * @property string $reason
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereDueAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereFiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereRequestKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake whereWorkTaskId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkWake {}
}
