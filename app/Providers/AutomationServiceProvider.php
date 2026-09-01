<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Automation\Actions\ActionRegistry;
use App\Domain\Automation\Actions\AddNoteAction;
use App\Domain\Automation\Actions\AssignAction;
use App\Domain\Automation\Actions\SetPriorityAction;
use App\Domain\Automation\Actions\SetStatusAction;
use Illuminate\Support\ServiceProvider;

/**
 * Doc 12 FR-03 — the arm bindings. The registry is a singleton whose bound set
 * IS the shipped action surface: an unbound type answers `unbuilt_action`
 * honestly (no fake success ships). Step 5 binds the four DOOR-LAWFUL arms;
 * transfer/tags/directed-assign/escalate await packet P-DOORS' countersign;
 * the external arms (notify/webhook/sms/run-ai-task/suppress) land at step 13;
 * send_macro_reply at step 9; set_custom_field at step 8.
 */
final class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionRegistry::class, function (\Illuminate\Contracts\Foundation\Application $app): ActionRegistry {
            $registry = new ActionRegistry($app->make(\App\Domain\Automation\Rules\SystemActorFloor::class));
            $registry->bind('set_status', $app->make(SetStatusAction::class));
            $registry->bind('set_priority', $app->make(SetPriorityAction::class));
            $registry->bind('add_note', $app->make(AddNoteAction::class));
            $registry->bind('assign', $app->make(AssignAction::class));
            // P-DOORS (countersigned at the batch-B acceptance): the system-door arms.
            $registry->bind('transfer_department', $app->make(\App\Domain\Automation\Actions\TransferDepartmentAction::class));
            $registry->bind('add_tags', new \App\Domain\Automation\Actions\TagsAction($app->make(\App\Domain\Tickets\Tags\TicketTags::class), adding: true));
            $registry->bind('remove_tags', new \App\Domain\Automation\Actions\TagsAction($app->make(\App\Domain\Tickets\Tags\TicketTags::class), adding: false));
            $registry->bind('escalate', $app->make(\App\Domain\Automation\Actions\EscalateAction::class));
            // Step 8: A-59's ticket value door.
            $registry->bind('set_custom_field', $app->make(\App\Domain\Automation\Actions\SetCustomFieldAction::class));
            // Step 9: the resolver-guarded system reply (T-06's automation half).
            $registry->bind('send_macro_reply', $app->make(\App\Domain\Automation\Actions\SendMacroReplyAction::class));

            return $registry;
        });
    }
}
