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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineTeamWorkSetting query()
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
 * @property-read int|null $windows_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageProvider query()
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
 * @property string|null $failure_code
 * @property-read Spoke $spoke
 * @property-read Collection<int, MachineUsageProvider> $providers
 * @property-read int|null $providers_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageReport query()
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
 * @property-read MachineUsageProvider $provider
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineUsageWindow query()
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
 * @property int $concurrency_ceiling
 * @property CarbonImmutable $observed_at
 * @property-read Spoke $spoke
 * @property-read Team $team
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MachineWorkPresence query()
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
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkBriefRevision {}
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
 * @property-read \App\Models\WorkItem|null $workItem
 * @property-read \App\Models\WorkTask|null $workTask
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkEscalation query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkEscalation {}
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
 * @property int|null $turn_cursor
 * @property int|null $turn_upper_bound
 * @property int|null $seat_fence
 * @property string|null $addressed_actor
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
 * @property string $triggering_event_id
 * @property string $decision
 * @property string $rationale
 * @property array<string, mixed> $evidence
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationDecision query()
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestrationEvent query()
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkOrchestratorSeat query()
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkStateRevision query()
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

namespace App\Models{
/**
 * @property string $id
 * @property string $work_task_id
 * @property string $request_key
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $fired_at
 * @property string $reason
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WorkWake query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperWorkWake {}
}
