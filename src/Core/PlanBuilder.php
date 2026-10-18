<?php

namespace Dylf\GaleSync\Core;

use Dylf\GaleSync\Core\Loader\LoaderRules;

/**
 * Works out what a sync has to do: compares a profile's mod list with what this plugin installed before.
 */
final class PlanBuilder
{
    /**
     * @param  array<string, bool>  $clientOnly  mods known to be client-side only, by `Owner-Name`
     */
    public function build(Manifest $manifest, InstallState $state, LoaderRules $rules, array $clientOnly = [], bool $skipClientOnly = true): SyncPlan
    {
        $removes = [];
        $changes = [];
        $others = [];

        foreach ($manifest->mods as $mod) {
            $installed = $state->mods[$mod->fullName] ?? null;

            $skipReason = match (true) {
                $rules->isLoaderPackage($mod->fullName) => 'the mod loader comes with the server',
                !$mod->enabled => 'disabled in the profile',
                $skipClientOnly && ($clientOnly[$mod->fullName] ?? false) => 'client-side only',
                default => null,
            };

            if ($skipReason !== null) {
                // A mod we installed earlier that is now skipped is taken off the server again. The loader never is.
                if ($installed !== null && !$rules->isLoaderPackage($mod->fullName)) {
                    $removes[] = new PlanItem($mod->fullName, PlanAction::Remove, $installed->version, null, $installed->source, $skipReason);
                } else {
                    $others[] = new PlanItem($mod->fullName, PlanAction::Skip, $installed?->version, $mod->version, $mod->source, $skipReason);
                }

                continue;
            }

            if ($installed === null) {
                $changes[] = new PlanItem($mod->fullName, PlanAction::Install, null, $mod->version, $mod->source);
            } elseif ($installed->version !== $mod->version) {
                $changes[] = new PlanItem($mod->fullName, PlanAction::Update, $installed->version, $mod->version, $mod->source);
            } else {
                $others[] = new PlanItem($mod->fullName, PlanAction::Keep, $installed->version, $mod->version, $installed->source);
            }
        }

        $listed = array_map(fn (ManifestMod $mod) => $mod->fullName, $manifest->mods);

        foreach ($state->mods as $fullName => $installed) {
            if (!in_array($fullName, $listed, true)) {
                $removes[] = new PlanItem($fullName, PlanAction::Remove, $installed->version, null, $installed->source, 'no longer in the profile');
            }
        }

        usort($changes, fn (PlanItem $a, PlanItem $b) => [$a->action === PlanAction::Update ? 0 : 1, $a->fullName] <=> [$b->action === PlanAction::Update ? 0 : 1, $b->fullName]);
        usort($removes, fn (PlanItem $a, PlanItem $b) => $a->fullName <=> $b->fullName);

        return new SyncPlan([...$removes, ...$changes, ...$others]);
    }
}
