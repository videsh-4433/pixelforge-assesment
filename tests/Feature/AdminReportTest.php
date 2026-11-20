<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

function usageResponse(string $cost, int $generations): array
{
    $day = ['date' => '2026-11-20', 'generations' => $generations, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => $cost];

    return ['object' => 'usage', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'data' => [$day], 'total' => $day];
}

it('is only available to admins', function () {
    fakePixelforge();
    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('grants admin to emails listed in ADMIN_EMAILS', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse('0.000000000', 0))]);
    config(['pixelforge.admin_emails' => ['boss@example.com']]);

    $this->actingAs(User::factory()->create(['email' => 'Boss@Example.com']))->get('/admin/report')->assertOk();
    $this->actingAs(User::factory()->create(['email' => 'someone@example.com']))->get('/admin/report')->assertForbidden();
});

it('reports spend, provider cost and profit per day and user, reconciled with /v1/usage', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse('0.338160000', 2))]);
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $user = User::factory()->create();

    Generation::factory()->for($user)->count(2)->create([
        'status' => GenerationStatus::Succeeded,
        'cost_nano' => 169_080_000,
        'charge_nano' => 211_350_000,
        'completed_at' => '2026-11-20 23:59:00', // billed by completion day (UTC)
    ]);
    Generation::factory()->for($user)->create(['status' => GenerationStatus::Failed, 'completed_at' => '2026-11-20 10:00:00']);

    $this->actingAs($admin)->get('/admin/report?start=2026-11-20&end=2026-11-20')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Report')
            ->where('days.0.date', '2026-11-20')
            ->where('days.0.generations', 2)
            ->where('days.0.charge', '0.422700000')
            ->where('days.0.cost', '0.338160000')
            ->where('days.0.profit', '0.084540000')
            ->where('days.0.pixelforge.matches', true)
            ->where('days.0.users.0.email', $user->email));
});
