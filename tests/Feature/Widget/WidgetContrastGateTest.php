<?php

declare(strict_types=1);

use App\Domain\Webchat\Designer\WidgetConfigPublisher;
use App\Support\Theme\ContrastValidator;

require_once __DIR__.'/WidgetDesignerSupport.php';

/*
|--------------------------------------------------------------------------
| A-62 / N-18 — the contrast HARD GATE: a failing brand is refused INLINE with the
| ratio and the suggested shade; the suggestion publishes; and NO override path
| exists — by reflection (no method of the publisher or the validator takes a
| bypass-shaped parameter) and by registry (no `contrast.*` setting key, the
| RegistryCheck pattern).
|--------------------------------------------------------------------------
*/

it('refuses a failing brand with the ratio and the suggestion; the suggestion publishes; the neutral default publishes', function (): void {
    [, $user] = designerScene();
    $id = designer($user, 'POST', '', ['name' => '[FIXTURE] W'])->json('id');
    $refused = designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(['appearance' => ['brand' => ['accent' => '#F2C94C']]])]);
    $refused->assertStatus(422)->assertJsonPath('refusal_code', 'contrast')->assertJsonPath('surface', 'light')->assertJsonPath('on', '#ffffff');
    expect($refused->json('ratio'))->toBeLessThan(4.5)->and($refused->json('required'))->toBe(4.5)
        ->and($refused->json('suggestion'))->toMatch('/^#[0-9a-f]{6}$/')->and($refused->json('suggestion_ratio'))->toBeGreaterThanOrEqual(4.5);
    expect(Illuminate\Support\Facades\DB::table('widget_config_versions')->count())->toBe(0, 'a refusal writes nothing');
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig(['appearance' => ['brand' => ['accent' => $refused->json('suggestion')]]])])->assertStatus(201);
    designer($user, 'POST', '/'.$id.'/config/publish', ['config' => designerConfig()])->assertStatus(201);
    // the dark surface has its own threshold (3:1, UI): a near-black brand reads on white but not on the dark panel
    designer($user, 'POST', '/'.$id.'/config/validate', ['config' => designerConfig(['appearance' => ['brand' => ['accent' => '#202020']]])])
        ->assertStatus(422)->assertJsonPath('refusal_code', 'contrast')->assertJsonPath('surface', 'dark');
});

it('NO override path exists: no bypass-shaped parameter on the publisher or the validator, no contrast.* setting key, the gate runs inside publish()', function (): void {
    $forbidden = '/override|bypass|force|skip|ignore|unsafe|allow_fail/i';
    foreach ([WidgetConfigPublisher::class, ContrastValidator::class] as $class) {
        $ref = new ReflectionClass($class);
        foreach ($ref->getMethods() as $method) {
            foreach ($method->getParameters() as $param) {
                expect(preg_match($forbidden, $param->getName()))->toBe(0, $class.'::'.$method->getName().'($'.$param->getName().')');
            }
            expect(preg_match($forbidden, $method->getName()))->toBe(0, $class.'::'.$method->getName());
        }
    }
    $settings = require base_path('registries/settings.php');
    $keys = array_map(static fn (array $s): string => (string) $s['key'], $settings);
    expect(array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'contrast.') || str_contains($k, 'contrast_override')))->toBe([]);
    $source = (string) file_get_contents((new ReflectionClass(WidgetConfigPublisher::class))->getFileName());
    expect($source)->toContain('$this->contrastGate($merged);');
});
