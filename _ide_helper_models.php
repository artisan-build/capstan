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
 * @property BrandAssetKind $kind
 * @property string $content_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property string $created_by_user_id
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandAsset query()
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BrandVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandKit query()
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BrandVersion query()
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkTask> $workTasks
 * @property-read int|null $work_tasks_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeam {}
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
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkBriefRevision {}
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
 * @property int $fence
 * @property int $attempts
 * @property string|null $fetched_brief_revision_id
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
 * @property-read int|null $milestones_count
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkItem query()
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
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkMilestone {}
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
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $parent_task_id
 * @property string|null $workflow_ref
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Team $team
 * @property-read Spoke|null $deviceSpoke
 * @property-read WorkTask|null $parent
 * @property-read Collection<int, WorkTask> $children
 * @property-read Collection<int, WorkBriefRevision> $briefRevisions
 * @property-read WorkBriefRevision|null $latestBriefRevision
 * @property-read Collection<int, WorkItem> $items
 * @property-read Collection<int, WorkMilestone> $milestones
 * @property-read Collection<int, WorkSteer> $steers
 * @property-read int|null $brief_revisions_count
 * @property-read int|null $children_count
 * @property-read int|null $items_count
 * @property-read int|null $milestones_count
 * @property-read int|null $steers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkTask query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkTask {}
}
