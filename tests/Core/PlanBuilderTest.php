<?php

namespace Dylf\GaleSync\Tests\Core;

use Dylf\GaleSync\Core\InstalledMod;
use Dylf\GaleSync\Core\InstallState;
use Dylf\GaleSync\Core\Loader\LoaderRulesFactory;
use Dylf\GaleSync\Core\Manifest;
use Dylf\GaleSync\Core\ManifestMod;
use Dylf\GaleSync\Core\PlanAction;
use Dylf\GaleSync\Core\PlanBuilder;
use Dylf\GaleSync\Core\SyncPlan;
use PHPUnit\Framework\TestCase;

class PlanBuilderTest extends TestCase
{
    /**
     * @param  list<array{string, string, 1?: bool}>  $mods  [name, version, enabled]
     */
    private function manifest(array $mods): Manifest
    {
        return new Manifest('p', 'valheim', array_map(function (array $mod) {

            return new ManifestMod($mod[0], $mod[1], $mod[2] ?? true, 'Thunderstore');
        }, $mods));
    }

    /**
     * @param  array<string, string>  $versions
     */
    private function state(array $versions): InstallState
    {
        return new InstallState(array_map(fn (string $version) => new InstalledMod($version, 'Thunderstore'), $versions));
    }

    private function plan(Manifest $manifest, InstallState $state, array $clientOnly = [], bool $skip = true): SyncPlan
    {
        $rules = LoaderRulesFactory::fromGame(['name' => 'BepInEx', 'packageName' => 'denikson-BepInExPack_Valheim']);

        return (new PlanBuilder())->build($manifest, $state, $rules, $clientOnly, $skip);
    }

    /** @return array<string, string> */
    private function actions(SyncPlan $plan): array
    {
        $out = [];

        foreach ($plan->items as $item) {
            $out[$item->fullName] = $item->action->value;
        }

        return $out;
    }

    public function test_everything_in_the_profile_is_installed_on_an_empty_server(): void
    {
        $plan = $this->plan($this->manifest([['A-One', '1.0.0'], ['B-Two', '2.0.0']]), new InstallState());

        $this->assertSame(['A-One' => 'install', 'B-Two' => 'install'], $this->actions($plan));
        $this->assertTrue($plan->hasChanges());
    }

    public function test_a_different_version_is_an_update_and_the_same_version_is_kept(): void
    {
        $plan = $this->plan(
            $this->manifest([['A-One', '1.1.0'], ['B-Two', '2.0.0']]),
            $this->state(['A-One' => '1.0.0', 'B-Two' => '2.0.0']),
        );

        $this->assertSame(['A-One' => 'update', 'B-Two' => 'keep'], $this->actions($plan));
        $this->assertSame('1.0.0', $plan->with(PlanAction::Update)[0]->from);
        $this->assertSame('1.1.0', $plan->with(PlanAction::Update)[0]->to);
    }

    public function test_it_is_a_downgrade_too_when_the_profile_goes_back(): void
    {
        $plan = $this->plan($this->manifest([['A-One', '1.0.0']]), $this->state(['A-One' => '1.2.0']));

        $this->assertSame(['A-One' => 'update'], $this->actions($plan));
    }

    public function test_nothing_to_do_means_no_changes(): void
    {
        $plan = $this->plan($this->manifest([['A-One', '1.0.0']]), $this->state(['A-One' => '1.0.0']));

        $this->assertFalse($plan->hasChanges());
    }

    public function test_a_mod_that_left_the_profile_is_removed_only_if_we_installed_it(): void
    {
        $plan = $this->plan($this->manifest([['A-One', '1.0.0']]), $this->state(['A-One' => '1.0.0', 'Old-Mod' => '3.0.0']));

        $this->assertSame(['Old-Mod' => 'remove', 'A-One' => 'keep'], $this->actions($plan));
        $this->assertSame('no longer in the profile', $plan->with(PlanAction::Remove)[0]->reason);
    }

    public function test_the_loader_package_is_skipped_and_never_removed(): void
    {
        $plan = $this->plan($this->manifest([['denikson-BepInExPack_Valheim', '5.4.2351']]), $this->state(['denikson-BepInExPack_Valheim' => '5.4.2000']));

        $this->assertSame(['denikson-BepInExPack_Valheim' => 'skip'], $this->actions($plan));
    }

    public function test_client_side_mods_are_skipped_unless_asked_for(): void
    {
        $manifest = $this->manifest([['A-One', '1.0.0'], ['C-Client', '1.0.0']]);

        $this->assertSame(['A-One' => 'install', 'C-Client' => 'skip'], $this->actions($this->plan($manifest, new InstallState(), ['C-Client' => true])));
        $this->assertSame(['A-One' => 'install', 'C-Client' => 'install'], $this->actions($this->plan($manifest, new InstallState(), ['C-Client' => true], skip: false)));
    }

    public function test_a_mod_we_installed_that_is_now_client_only_is_removed(): void
    {
        $plan = $this->plan($this->manifest([['C-Client', '1.0.0']]), $this->state(['C-Client' => '1.0.0']), ['C-Client' => true]);

        $this->assertSame(['C-Client' => 'remove'], $this->actions($plan));
        $this->assertSame('client-side only', $plan->items[0]->reason);
    }

    public function test_a_mod_disabled_in_the_profile_is_not_installed_and_is_removed_if_present(): void
    {
        $manifest = $this->manifest([['A-One', '1.0.0', false], ['B-Two', '1.0.0', false]]);
        $plan = $this->plan($manifest, $this->state(['B-Two' => '1.0.0']));

        $this->assertSame(['B-Two' => 'remove', 'A-One' => 'skip'], $this->actions($plan));
    }

    public function test_changes_are_ordered_removals_then_updates_then_installs(): void
    {
        $plan = $this->plan(
            $this->manifest([['Z-New', '1.0.0'], ['A-Up', '2.0.0'], ['M-Same', '1.0.0']]),
            $this->state(['A-Up' => '1.0.0', 'M-Same' => '1.0.0', 'Gone-Mod' => '1.0.0']),
        );

        $this->assertSame(['Gone-Mod', 'A-Up', 'Z-New', 'M-Same'], array_map(fn ($item) => $item->fullName, $plan->items));
        $this->assertSame(['install' => 1, 'update' => 1, 'remove' => 1, 'keep' => 1, 'skip' => 0], $plan->counts());
    }
}
