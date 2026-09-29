<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\AssistancePolicyVersion;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Database\Seeders\EduFlowPlanSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(EduFlowPlanSeeder::class);
    Role::findOrCreate('student', 'web');

    $this->user = User::factory()->create();
    $this->user->assignRole('student');

    $this->student = Student::factory()->create([
        'user_id' => $this->user->id,
        'student_number' => 'STU-2026-0001',
    ]);

    $this->term = AcademicTerm::factory()->create([
        'starts_on' => today()->startOfYear(),
        'ends_on' => today()->endOfYear(),
    ]);

    $this->account = TuitionAccount::factory()->create([
        'student_id' => $this->student->id,
        'academic_term_id' => $this->term->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);
});

test('guest cannot query ask eduflow endpoint', function (): void {
    $this->postJson(route('student.ask'), ['question' => 'What is my tuition balance?'])
        ->assertUnauthorized();
});

test('student receives structured answer for tuition balance question', function (): void {
    $response = $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => 'What is my tuition balance?',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'question',
                'answer',
                'topic',
                'suggestedFollowups',
                'context',
                'answered_at',
            ],
        ]);

    $data = $response->json('data');
    expect($data['answer'])->toContain('300.00 USDC')
        ->and($data['answer'])->toContain('PHP')
        ->and($data['topic'])->toBe('tuition_balance');
});

test('student receives an explanation of the split that quotes the live policy', function (): void {
    $response = $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => 'Why was my request split?',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $limit = number_format(AssistancePolicyVersion::active()->auto_limit_base_units / 1000000, 2);
    $answer = $response->json('data.answer');

    // The limit must come from the policy, not a hardcoded figure that could
    // contradict the decision it is supposed to explain.
    expect($answer)->toContain("autonomous assistance limit is {$limit} USDC")
        ->and($answer)->toContain('escalated to a human reviewer')
        ->and($answer)->toContain('rate was locked');
});

test('the split explanation never invents request or remainder figures', function (): void {
    $response = $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => "Why didn't you send the full 999 USDC?",
    ]);

    $response->assertOk();

    $answer = (string) $response->json('data.answer');
    $limit = number_format(AssistancePolicyVersion::active()->auto_limit_base_units / 1000000, 2);

    // Only the auto-limit may appear as a USDC figure. Any other value would be a
    // fabricated request or remainder.
    preg_match_all('/(\d+(?:\.\d+)?) USDC/', $answer, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe([$limit]);
});

test('student receives assistance policy guidelines', function (): void {
    $response = $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => 'What are the assistance guidelines and eligibility criteria?',
    ]);

    $response->assertOk();
    $answer = $response->json('data.answer');

    expect($answer)->toContain('Enrolled')
        ->and($answer)->toContain('attendance')
        // The auto-limit comes from the active policy version, not a constant.
        ->and($answer)->toContain(
            number_format(AssistancePolicyVersion::active()->auto_limit_base_units / 1000000, 2).' USDC'
        );
});

test('validation rejects empty or overly short questions', function (): void {
    $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => '',
    ])->assertUnprocessable();

    $this->actingAs($this->user)->postJson(route('student.ask'), [
        'question' => 'a',
    ])->assertUnprocessable();
});
