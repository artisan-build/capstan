<?php

namespace App\Mcp;

use App\Mcp\Tools\AckPostmasterMessagesTool;
use App\Mcp\Tools\ApplyBrandDraftTool;
use App\Mcp\Tools\CreateArtifactShareTool;
use App\Mcp\Tools\GetBrandKitTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\ListLayoutsTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\MachineBudgetsTool;
use App\Mcp\Tools\PostmasterMessagesTool;
use App\Mcp\Tools\PostmasterSpokesTool;
use App\Mcp\Tools\PreviewBrandDraftTool;
use App\Mcp\Tools\PublishPageTool;
use App\Mcp\Tools\SendPostmasterMessageTool;
use App\Mcp\Tools\UpdateBrandDraftTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Mcp\Tools\UploadBrandAssetTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Capstan')]
#[Version('1.0.0')]
#[Instructions('Read and operate the authenticated actor own Capstan data. When the user asks for a UI, report, dashboard, or brief, read get_brand_kit, build it as a Capstan page with the documented tokens and components, and include a refresh recipe that explains how to rebuild it. To match a website, fetch the site with your own tools because Capstan never fetches arbitrary URLs. Extract its palette, typography, logo, and header/footer feel. Upload only images the team owns, choose the nearest allowed system font stack, update the draft, and show preview_brand_draft. Call apply_brand_draft with the returned draft fingerprint only after the user explicitly approves the preview. Font uploads are not supported.')]
final class CapstanServer extends Server
{
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        Tools\CreateTaskTool::class,
        Tools\ReviseBriefTool::class,
        Tools\QueueItemTool::class,
        Tools\CancelItemTool::class,
        Tools\ListWorkTool::class,
        Tools\GetTaskTool::class,
        Tools\SteerTool::class,
        Tools\CancelTaskTool::class,
        Tools\GetTeamWorkPolicyTool::class,
        Tools\SetTeamWorkPolicyTool::class,
        Tools\TakeSeatTool::class,
        Tools\ReleaseSeatTool::class,
        Tools\ListEscalationsTool::class,
        Tools\AnswerEscalationTool::class,
        Tools\OrchestrateTool::class,
        PostmasterSpokesTool::class,
        MachineBudgetsTool::class,
        PostmasterMessagesTool::class,
        SendPostmasterMessageTool::class,
        AckPostmasterMessagesTool::class,
        CreateArtifactShareTool::class,
        PublishPageTool::class,
        UpdatePageTool::class,
        ListPagesTool::class,
        GetPageTool::class,
        GetBrandKitTool::class,
        ListLayoutsTool::class,
        PreviewBrandDraftTool::class,
        UpdateBrandDraftTool::class,
        UploadBrandAssetTool::class,
        ApplyBrandDraftTool::class,
    ];
}
